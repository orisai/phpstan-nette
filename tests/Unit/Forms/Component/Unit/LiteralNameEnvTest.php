<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Analyzer\CompositionState;
use OriPhpstan\Nette\Forms\Analyzer\FormShapeAnalyzer;
use OriPhpstan\Nette\Forms\Analyzer\WalkContext;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummaryFactory;
use OriPhpstan\Nette\Forms\Graph\TaggedNode;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use ReflectionMethod;
use ReflectionProperty;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use function assert;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

final class LiteralNameEnvTest extends FormShapeTestCase
{

	private ReflectionMethod $buildLiteralNameEnv;

	private FormShapeAnalyzer $analyzer;

	private string $cacheDir;

	protected function setUp(): void
	{
		parent::setUp();

		$this->cacheDir = sys_get_temp_dir() . '/literal-name-env-test-' . uniqid('', true);
		$cache = new FormShapeCache($this->cacheDir);
		$this->analyzer = new FormShapeAnalyzer(
			new NodeContributionSummaryFactory($this->createCatalog($cache)),
			$cache,
			$this->createCallees($cache),
		);

		$this->buildLiteralNameEnv = new ReflectionMethod(FormShapeAnalyzer::class, 'buildLiteralNameEnv');
		$this->buildLiteralNameEnv->setAccessible(true);
	}

	protected function tearDown(): void
	{
		if (is_dir($this->cacheDir)) {
			FileSystem::delete($this->cacheDir);
		}
	}

	public function testSingleTopLevelAssignIsKept(): void
	{
		self::assertSame(['name' => 'a'], $this->env('$name = \'a\';'));
	}

	public function testTopLevelDoubleAssignDisqualifies(): void
	{
		self::assertArrayNotHasKey('name', $this->env('$name = \'a\'; $name = \'b\';'));
	}

	public function testNestedIfReassignDisqualifies(): void
	{
		self::assertArrayNotHasKey(
			'name',
			$this->env('$name = \'a\'; if ($flag) { $name = \'b\'; }'),
		);
	}

	public function testForeachValueVarReassignDisqualifies(): void
	{
		self::assertArrayNotHasKey(
			'name',
			$this->env('$name = \'a\'; foreach ($items as $name) {}'),
		);
	}

	public function testForeachKeyVarReassignDisqualifies(): void
	{
		self::assertArrayNotHasKey(
			'name',
			$this->env('$name = \'a\'; foreach ($items as $name => $v) {}'),
		);
	}

	public function testClosureParamShadowDisqualifies(): void
	{
		self::assertArrayNotHasKey(
			'name',
			$this->env('$name = \'a\'; $cb = function (string $name) { return $name; };'),
		);
	}

	public function testClosureUseShadowDisqualifies(): void
	{
		self::assertArrayNotHasKey(
			'name',
			$this->env('$name = \'a\'; $cb = function () use ($name) { return $name; };'),
		);
	}

	public function testArrowFunctionParamShadowDisqualifies(): void
	{
		self::assertArrayNotHasKey(
			'name',
			$this->env('$name = \'a\'; $cb = fn (string $name) => $name;'),
		);
	}

	public function testCatchVarShadowDisqualifies(): void
	{
		self::assertArrayNotHasKey(
			'name',
			$this->env('$name = \'a\'; try { work(); } catch (\Exception $name) {}'),
		);
	}

	public function testListDestructureReassignDisqualifies(): void
	{
		self::assertArrayNotHasKey(
			'name',
			$this->env('$name = \'a\'; [$name, $other] = [\'b\', \'c\'];'),
		);
	}

	public function testAssignRefReassignDisqualifies(): void
	{
		self::assertArrayNotHasKey(
			'name',
			$this->env('$name = \'a\'; $name =& $other;'),
		);
	}

	public function testUnrelatedNestedAssignDoesNotDisqualifyOtherNames(): void
	{
		self::assertSame(
			['name' => 'a'],
			$this->env('$name = \'a\'; if ($flag) { $other = \'z\'; }'),
		);
	}

	public function testByRefUseShadowDisqualifies(): void
	{
		self::assertArrayNotHasKey(
			'name',
			$this->env('$name = \'a\'; $cb = function () use (&$name) { $name = \'b\'; };'),
		);
	}

	public function testClosureBodyEnvDropsOuterAndMultiWrittenNames(): void
	{
		$closure = $this->firstClosure(
			'$cb = function () use (&$form, $flag) {'
			. ' if ($flag) { $name = \'b\'; } else { $name = \'c\'; }'
			. ' $local = \'x\';'
			. ' $form->addText($name);'
			. ' };',
		);
		assert($closure instanceof Closure);

		self::assertSame(
			['local' => 'x'],
			$this->closureBodyEnv($closure, $closure->stmts, ['name' => 'outer']),
		);
	}

	public function testClosureBodyEnvExcludesParamsAndUses(): void
	{
		$closure = $this->firstClosure(
			'$cb = function (string $p) use ($u) { $p = \'x\'; $u = \'y\'; $other = \'z\'; };',
		);
		assert($closure instanceof Closure);

		self::assertSame(
			['other' => 'z'],
			$this->closureBodyEnv($closure, $closure->stmts, []),
		);
	}

	public function testArrowFunctionBodyCarriesInUnboundOuterLiterals(): void
	{
		$arrow = $this->firstClosure('$cb = fn (string $p) => $form->addText($outerName);');
		assert($arrow instanceof ArrowFunction);

		self::assertSame(
			['outerName' => 'a'],
			$this->closureBodyEnv($arrow, [new Expression($arrow->expr)], ['outerName' => 'a', 'p' => 'q']),
		);
	}

	public function testArrowFunctionBodyDoesNotCarryInBoundNames(): void
	{
		$arrow = $this->firstClosure('$cb = fn () => $x = \'2\';');
		assert($arrow instanceof ArrowFunction);

		self::assertSame(
			['x' => '2'],
			$this->closureBodyEnv($arrow, [new Expression($arrow->expr)], ['x' => '1']),
		);
	}

	public function testEnvAndMaskRestoredAfterAbsorbedClosureBodyWalk(): void
	{
		$envProperty = new ReflectionProperty(FormShapeAnalyzer::class, 'literalNameEnv');
		$envProperty->setAccessible(true);
		$envProperty->setValue($this->analyzer, ['name' => 'outer']);
		$maskProperty = new ReflectionProperty(FormShapeAnalyzer::class, 'inAbsorbedClosureBody');
		$maskProperty->setAccessible(true);
		$maskProperty->setValue($this->analyzer, false);

		$closure = $this->firstClosure('$cb = function () use (&$form) { $name = \'inner\'; };');
		assert($closure instanceof Closure);

		$walk = new ReflectionMethod(FormShapeAnalyzer::class, 'walkAbsorbedClosureBody');
		$walk->setAccessible(true);
		$walk->invoke(
			$this->analyzer,
			$closure,
			$closure->stmts,
			CompositionState::initial(),
			new WalkContext('form', 'Nette\\Forms\\Form', [], $closure->stmts, $this->anyScope()),
		);

		self::assertSame(['name' => 'outer'], $envProperty->getValue($this->analyzer));
		self::assertFalse($maskProperty->getValue($this->analyzer));
	}

	// Any real Scope: WalkContext carries one only to hand on to a followed callee's walk, and this
	// closure body calls nothing.
	private function anyScope(): Scope
	{
		$captured = null;
		self::processFile(
			__DIR__ . '/Support/MaskedNameFixture.php',
			static function (Node $node, Scope $scope) use (&$captured): void {
				$captured ??= $scope;
			},
		);
		assert($captured !== null);

		return $captured;
	}

	public function testMaskedVariableNameResolvesFromEnvOnly(): void
	{
		$factory = new NodeContributionSummaryFactory($this->createCatalog(new FormShapeCache($this->cacheDir)));
		$checked = false;

		self::processFile(
			__DIR__ . '/Support/MaskedNameFixture.php',
			static function (Node $node, Scope $scope) use ($factory, &$checked): void {
				if (!$node instanceof Expression) {
					return;
				}

				$tagged = (new NodeFinder())->findFirst(
					$node,
					static fn (Node $n): bool => $n->getAttribute(TaggedNode::ATTRIBUTE) instanceof TaggedNode,
				);
				if ($tagged === null) {
					return;
				}

				$meta = $tagged->getAttribute(TaggedNode::ATTRIBUTE);
				assert($meta instanceof TaggedNode);

				$unmasked = $factory->fromTaggedNode($node, $tagged, $meta, $scope);
				self::assertSame('kv', $unmasked->getName());

				$masked = $factory->fromTaggedNode($node, $tagged, $meta, $scope, null, null, [], [], false, true);
				self::assertNull($masked->getName());
				self::assertTrue($masked->hasDynamicName());

				$maskedWithEnv = $factory->fromTaggedNode(
					$node,
					$tagged,
					$meta,
					$scope,
					null,
					null,
					['known' => 'kv'],
					[],
					false,
					true,
				);
				self::assertSame('kv', $maskedWithEnv->getName());

				$checked = true;
			},
		);

		self::assertTrue($checked);
	}

	public function testCountVariableBindingsExcludesClosureCapturesWhenFlagged(): void
	{
		$body = '$nullable = true; $cb = function () use (&$form, $nullable) {}; $nullable = false;';

		self::assertEqualsCanonicalizing(
			['nullable' => 3, 'form' => 1, 'cb' => 1],
			$this->countBindings($body, true),
		);
		self::assertEqualsCanonicalizing(
			['nullable' => 2, 'cb' => 1],
			$this->countBindings($body, false),
		);
	}

	public function testClosureFixedBindingNamesCollectsParamsAndByRefUses(): void
	{
		$closure = $this->firstClosure('$cb = function (string $p) use (&$r, $v) { return $p; };');

		self::assertSame(['p' => true, 'r' => true], $this->fixedNames($closure));
	}

	public function testClosureFixedBindingNamesArrowFunctionCollectsParamsOnly(): void
	{
		$arrow = $this->firstClosure('$cb = fn (string $p) => $p + $v;');

		self::assertSame(['p' => true], $this->fixedNames($arrow));
	}

	/**
	 * @return array<string, int>
	 */
	private function countBindings(string $body, bool $countClosureBindings): array
	{
		$method = new ReflectionMethod(FormShapeAnalyzer::class, 'countVariableBindings');
		$method->setAccessible(true);

		/** @var array<string, int> $result */
		$result = $method->invoke($this->analyzer, $this->parse($body), $countClosureBindings);

		return $result;
	}

	/**
	 * @param Closure|ArrowFunction $closure
	 * @return array<string, true>
	 */
	private function fixedNames(Node $closure): array
	{
		$method = new ReflectionMethod(FormShapeAnalyzer::class, 'closureFixedBindingNames');
		$method->setAccessible(true);

		/** @var array<string, true> $result */
		$result = $method->invoke($this->analyzer, $closure);

		return $result;
	}

	/**
	 * @return array<string, string>
	 */
	private function env(string $body): array
	{
		/** @var array<string, string> $result */
		$result = $this->buildLiteralNameEnv->invoke($this->analyzer, $this->parse($body));

		return $result;
	}

	/**
	 * @param Closure|ArrowFunction $closure
	 * @param array<Node\Stmt> $bodyStmts
	 * @param array<string, string> $outerEnv
	 * @return array<string, string>
	 */
	private function closureBodyEnv(Node $closure, array $bodyStmts, array $outerEnv): array
	{
		$method = new ReflectionMethod(FormShapeAnalyzer::class, 'closureBodyLiteralNameEnv');
		$method->setAccessible(true);

		/** @var array<string, string> $result */
		$result = $method->invoke($this->analyzer, $closure, $bodyStmts, $outerEnv);

		return $result;
	}

	/**
	 * @return Closure|ArrowFunction
	 */
	private function firstClosure(string $body): Node
	{
		$closure = (new NodeFinder())->findFirst(
			$this->parse($body),
			static fn (Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction,
		);
		self::assertNotNull($closure);
		assert($closure instanceof Closure || $closure instanceof ArrowFunction);

		return $closure;
	}

	/**
	 * @return array<Node\Stmt>
	 */
	private function parse(string $body): array
	{
		$parser = (new ParserFactory())->createForNewestSupportedVersion();
		$stmts = $parser->parse("<?php\n{$body}\n");
		self::assertNotNull($stmts);

		return $stmts;
	}

}
