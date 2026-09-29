<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Forms\Analyzer\CalleeShapeResolver;
use OriPhpstan\Nette\Forms\Analyzer\FormShapeAnalyzer;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\NetteEffectiveControlValueTypeResolver;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummaryFactory;
use OriPhpstan\Nette\Forms\Graph\TaggedNode;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPStan\Type\VerbosityLevel;
use function array_map;
use function assert;
use function dirname;
use function spl_object_id;
use function usort;

abstract class FormShapeTestCase extends TypeInferenceTestCase
{

	use VersionGroupGate;
	use ShapeSnapshotAssertions;

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [dirname(__DIR__) . '/Fixtures/Forms/phpstan-test.neon'];
	}

	/** @return list<string> */
	public static function getAdditionalAnalysedFiles(): array
	{
		return [dirname(__DIR__, 2) . '/src/Forms/Testing/assertComponent.php'];
	}

	protected function createCatalog(FormShapeCache $cache): NetteEffectiveControlValueTypeResolver
	{
		return new NetteEffectiveControlValueTypeResolver(
			new ControlAnnotationValueTypeReader(
				self::createReflectionProvider(),
				self::getContainer()->getByType(TypeStringResolver::class),
			),
			$this->createCallees($cache),
		);
	}

	/**
	 * The rich parser the production wiring wraps and hands every analyzer. Tests that build an
	 * analyzer by hand must use the same one: PathRoutingParser routes non-analysed files to a
	 * CleaningParser, and a stripped body would make callee following silently answer "adds
	 * nothing". The production wrapper adds dependency recording, which CalleeShapeResolver drives
	 * explicitly, so the bare service is the right one here.
	 */
	protected function createRichParser(): Parser
	{
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');
		assert($parser instanceof Parser);

		return $parser;
	}

	protected function createCallees(FormShapeCache $cache): CalleeShapeResolver
	{
		return new CalleeShapeResolver($this->createRichParser(), self::createReflectionProvider(), $cache);
	}

	/** @return list<string> produced className.describe(precise) per assertComponent, in source order */
	protected function captureFixtureShapes(string $file, FormShapeCache $cache): array
	{
		$analyzer = new FormShapeAnalyzer(
			new NodeContributionSummaryFactory($this->createCatalog($cache), $cache->recorder()),
			$cache,
			$this->createCallees($cache),
		);

		/** @var list<FunctionLike> $functionLikes */
		$functionLikes = [];
		/** @var list<array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $taggedRecords */
		$taggedRecords = [];
		/** @var array<int, Scope> $nodeScopes */
		$nodeScopes = [];
		$finder = new NodeFinder();
		/** @var list<array{pos: int, value: string}> $produced */
		$produced = [];

		self::processFile(
			$file,
			static function (Node $node, Scope $scope) use ($analyzer, $finder, &$functionLikes, &$taggedRecords, &$nodeScopes, &$produced): void {
				if ($node instanceof FunctionLike) {
					$functionLikes[] = $node;

					return;
				}

				if ($node->getAttribute(TaggedNode::ATTRIBUTE) instanceof TaggedNode) {
					$nodeScopes[spl_object_id($node)] = $scope;
				}

				if ($node instanceof Node\Stmt\Expression || $node instanceof Unset_) {
					foreach ($finder->find(
						$node,
						static fn (Node $n): bool => $n->getAttribute(TaggedNode::ATTRIBUTE) instanceof TaggedNode,
					) as $taggedNode) {
						$taggedRecords[] = ['node' => $taggedNode, 'stmt' => $node, 'scope' => $scope, 'tagged' => $taggedNode->getAttribute(
							TaggedNode::ATTRIBUTE,
						)];
					}

					return;
				}

				if (!$node instanceof FuncCall || !$node->name instanceof Node\Name) {
					return;
				}

				if ($node->name->toString() !== 'OriPhpstan\Nette\Forms\Testing\\assertComponent') {
					return;
				}

				$enclosing = self::innermostEnclosing($functionLikes, $node);
				self::assertNotNull($enclosing);

				$records = self::recordsWithin($taggedRecords, $enclosing);
				foreach ($records as $i => $record) {
					$id = spl_object_id($record['node']);
					if (isset($nodeScopes[$id])) {
						$records[$i]['scope'] = $nodeScopes[$id];
					}
				}

				$args = $node->getArgs();
				$shape = $analyzer->analyzeFormValue($args[0]->value, $enclosing, $records, $scope);
				$produced[] = ['pos' => $node->getStartFilePos(), 'value' => $shape->getClassName() . $shape->describe(
					VerbosityLevel::precise(),
				)];
			},
		);

		usort($produced, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);

		return array_map(static fn (array $r): string => $r['value'], $produced);
	}

	/** @param list<FunctionLike> $functionLikes */
	private static function innermostEnclosing(array $functionLikes, Node $node): ?FunctionLike
	{
		$pos = $node->getStartFilePos();
		$best = null;
		$bestSpan = null;
		foreach ($functionLikes as $candidate) {
			$start = $candidate->getStartFilePos();
			$end = $candidate->getEndFilePos();
			if ($pos < $start || $pos > $end) {
				continue;
			}

			$span = $end - $start;
			if ($bestSpan === null || $span < $bestSpan) {
				$best = $candidate;
				$bestSpan = $span;
			}
		}

		return $best;
	}

	/**
	 * @param list<array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $taggedRecords
	 * @return list<array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}>
	 */
	private static function recordsWithin(array $taggedRecords, FunctionLike $functionLike): array
	{
		$start = $functionLike->getStartFilePos();
		$end = $functionLike->getEndFilePos();

		$records = [];
		foreach ($taggedRecords as $record) {
			$nodePos = $record['node']->getStartFilePos();
			if ($nodePos >= $start && $nodePos <= $end) {
				$records[] = $record;
			}
		}

		usort(
			$records,
			static fn (array $a, array $b): int => $a['node']->getStartFilePos() <=> $b['node']->getStartFilePos(),
		);

		return $records;
	}

}
