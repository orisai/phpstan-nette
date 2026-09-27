<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Analyzer\CalleeShapeResolver;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\NetteEffectiveControlValueTypeResolver;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummary;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummaryFactory;
use OriPhpstan\Nette\Forms\Graph\TaggedNode;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PhpParser\Node;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPStan\Type\ObjectType;
use Tests\OriPhpstan\Nette\Unit\Forms\Support\UnannotatedRegistrarContainer;
use function assert;
use function dirname;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The vendor half of the analysed/vendor split: a registering method the walk cannot see the body of
 * either matches the walk's own naming convention or, refuting it and declaring nothing, opens.
 *
 * The support container lives under Unit/Support, so pointing the resolver's analysed paths at
 * Fixtures makes it foreign and pointing them at Support makes it ours — the same file, the same
 * body, and the difference is only where the project says its own code lives.
 */
final class UnannotatedRegistrarTest extends TypeInferenceTestCase
{

	private const OURS = __DIR__ . '/Support';

	private const FOREIGN = __DIR__ . '/../../Doubles/Forms';

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [dirname(__DIR__, 2) . '/Fixtures/Forms/phpstan-test.neon'];
	}

	/**
	 * @param list<string> $analysedPaths
	 */
	private function catalog(array $analysedPaths): NetteEffectiveControlValueTypeResolver
	{
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');
		assert($parser instanceof Parser);

		return new NetteEffectiveControlValueTypeResolver(
			new ControlAnnotationValueTypeReader(
				self::createReflectionProvider(),
				self::getContainer()->getByType(TypeStringResolver::class),
			),
			new CalleeShapeResolver(
				$parser,
				self::createReflectionProvider(),
				new FormShapeCache(sys_get_temp_dir() . '/forms-unannotated-registrar-test-' . uniqid('', true)),
			),
			$analysedPaths,
		);
	}

	/**
	 * @param list<string> $analysedPaths
	 * @return array<string, NodeContributionSummary>
	 */
	private function summarize(array $analysedPaths): array
	{
		$factory = new NodeContributionSummaryFactory($this->catalog($analysedPaths));
		$summaries = [];

		self::processFile(
			dirname(__DIR__, 2) . '/Doubles/Forms/UnannotatedRegistrarCalls.php',
			static function (Node $node, Scope $scope) use ($factory, &$summaries): void {
				if (!$node instanceof Expression) {
					return;
				}

				foreach ((new NodeFinder())->find(
					$node,
					static fn (Node $n): bool => $n->getAttribute(TaggedNode::ATTRIBUTE) instanceof TaggedNode,
				) as $tagged) {
					$meta = $tagged->getAttribute(TaggedNode::ATTRIBUTE);
					$summaries[$meta->getOperationKind()] = $factory->fromTaggedNode($node, $tagged, $meta, $scope);

					break;
				}
			},
		);

		return $summaries;
	}

	public function testAForeignBodyRefutingTheConventionDropsTheNameAndOpens(): void
	{
		$s = $this->summarize([self::FOREIGN]);

		self::assertSame('plain', $s['addPlain']->getName());
		self::assertSame([], $s['addPlain']->getNodeUnknownReasons());

		self::assertNull($s['addLabelled']->getName());
		self::assertTrue($s['addLabelled']->hasDynamicName());
		self::assertSame(
			[UnknownReason::DYNAMIC_NAME, UnknownReason::UNANNOTATED_REGISTRAR],
			$s['addLabelled']->getNodeUnknownReasons(),
		);

		// The tag is the fix, and it works on the same declaration in the same foreign file.
		self::assertSame('declared', $s['addDeclaredLabelled']->getName());
		self::assertSame([], $s['addDeclaredLabelled']->getNodeUnknownReasons());

		// Undetected registrations still close: nothing this recognises registers here, so the walk's
		// own answer stands rather than degrading everything it cannot read.
		self::assertSame('nothing', $s['addNothing']->getName());
		self::assertSame([], $s['addNothing']->getNodeUnknownReasons());
	}

	public function testTheSameDeclarationInsideTheAnalysedPathsKeepsItsName(): void
	{
		$s = $this->summarize([self::OURS]);

		self::assertSame('Label', $s['addLabelled']->getName());
		self::assertSame([], $s['addLabelled']->getNodeUnknownReasons());
	}

	public function testWithNoDeclaredUniverseNothingIsForeign(): void
	{
		$s = $this->summarize([]);

		self::assertSame('Label', $s['addLabelled']->getName());
		self::assertSame([], $s['addLabelled']->getNodeUnknownReasons());
	}

	public function testTheConventionIsJudgedPerDeclaration(): void
	{
		$asserted = false;

		self::processFile(
			dirname(__DIR__, 2) . '/Doubles/Forms/UnannotatedRegistrarCalls.php',
			function (Node $node, Scope $scope) use (&$asserted): void {
				if ($asserted || !$node instanceof Expression) {
					return;
				}

				$asserted = true;
				$resolver = $this->catalog([self::FOREIGN]);
				$receiver = new ObjectType(UnannotatedRegistrarContainer::class);

				self::assertFalse($resolver->bodyRefutesNameConvention('addPlain', $receiver, $scope));
				self::assertTrue($resolver->bodyRefutesNameConvention('addLabelled', $receiver, $scope));
				// Several components under one call: argument 0 accounts for one of them at best.
				self::assertTrue($resolver->bodyRefutesNameConvention('addPair', $receiver, $scope));
				self::assertFalse($resolver->bodyRefutesNameConvention('addNothing', $receiver, $scope));
				// Declared by a trait of OURS, composed into a foreign class: the declaration's own file
				// decides, not the class the method was reached through.
				self::assertFalse($resolver->bodyRefutesNameConvention('addSharedLabelled', $receiver, $scope));
				// Nette's own catalog follows the convention it established.
				self::assertFalse($resolver->bodyRefutesNameConvention('addText', $receiver, $scope));
				self::assertFalse($resolver->bodyRefutesNameConvention('addSelect', $receiver, $scope));
				self::assertFalse($resolver->bodyRefutesNameConvention('addContainer', $receiver, $scope));
			},
		);

		self::assertTrue($asserted);
	}

}
