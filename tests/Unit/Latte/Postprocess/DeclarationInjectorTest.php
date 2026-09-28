<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\ClosureType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use function array_keys;
use function explode;
use function getmypid;
use function sha1_file;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;

final class DeclarationInjectorTest extends BaseTestCase
{

	public function testHeaderVarTypesBecomeTypedParams(): void
	{
		$php = $this->process("{varType int \$count}\n{\$count}\n");

		self::assertStringContainsString('@param int $count', $php);
		self::assertStringContainsString('function latteMain($count)', $php);
		self::assertStringNotContainsString('extract(', $php);
	}

	public function testTypedVarChecksAndNarrows(): void
	{
		$php = $this->process("{var int \$x = 1}\n{\$x}\n");

		self::assertStringContainsString('@var int', $php);
		self::assertStringContainsString('self::$prop_0_x = 1', $php);
		self::assertStringContainsString('$x = self::$prop_0_x', $php);
	}

	public function testDefaultBecomesNullCoalesceAssign(): void
	{
		$php = $this->process("{default \$y = 2}\n{\$y}\n");

		self::assertStringContainsString('$y ??= 2', $php);
		self::assertStringNotContainsString('EXTR_SKIP', $php);
	}

	public function testParametersReplaceParamModel(): void
	{
		$php = $this->process("{parameters int \$a, string \$b = 'x'}\n{\$a}{\$b}\n");

		self::assertStringContainsString("function latteMain(\$a, \$b = 'x')", $php);
		self::assertStringContainsString('@param int $a', $php);
		self::assertStringContainsString('@param string $b', $php);
	}

	public function testTypedDefaultChecksAndNarrows(): void
	{
		$php = $this->process("{default int \$x = 1}\n{\$x}\n");

		self::assertStringContainsString('self::$prop_0_x = 1', $php);
		self::assertStringContainsString('$x ??= self::$prop_0_x', $php);
		self::assertStringContainsString('@var int', $php);
	}

	public function testMidFileVarTypeInsertsNarrowedRead(): void
	{
		$php = $this->process("{varType int \$a}\n{\$a}\n{varType string \$b}\n{\$b}\n");

		self::assertStringContainsString('function latteMain($a)', $php);
		self::assertStringContainsString('$b = self::$prop_0_b', $php);
	}

	public function testMainAndPrepareRenamedEvenWithoutInjectedParams(): void
	{
		// No templateType/headerVarType/{parameters} at all - main()/prepare() would stay
		// zero-parameter, a compatible override of Latte\Runtime\Template. Renamed anyway: the
		// rename is an unconditional invariant of every generated class, not a per-file decision.
		$php = $this->process("{var int \$x = 1}\n{\$x}\n");

		self::assertStringContainsString('function latteMain()', $php);
		self::assertStringContainsString('function lattePrepare()', $php);
		self::assertStringNotContainsString('function main(', $php);
		self::assertStringNotContainsString('function prepare(', $php);
	}

	public function testSpacedGenericPropertyTypeSurvivesInFull(): void
	{
		$php = $this->process(
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Fixtures\\Support\\FixtureTemplate}\n{\$title}\n",
		);

		self::assertStringContainsString('@param array<int, string> $labels', $php);
	}

	public function testKnownTemplateTypeClassProducesNoUnknownTypeDiagnostic(): void
	{
		$diagnostics = $this->diagnosticsFor(
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Fixtures\\Support\\FixtureTemplate}\n{\$title}\n",
		);

		self::assertSame([], $diagnostics);
	}

	public function testUnknownTemplateTypeClassProducesDiagnosticAtDeclarationLine(): void
	{
		$diagnostics = $this->diagnosticsFor("\n\n{templateType Totally\\Missing\\ClassName}\n");

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.unknownType', $diagnostics[0]->getIdentifier());
		self::assertSame('Unknown template type class Totally\Missing\ClassName.', $diagnostics[0]->getMessage());
		self::assertSame(3, $diagnostics[0]->getLatteLine());
	}

	public function testUnknownTemplateTypeStillOmitsItsPropertiesFromMain(): void
	{
		// A reflection failure degrades to "no properties found" for the header params themselves
		// (pre-existing behavior) - the diagnostic is additive, it does not change main()'s shape.
		$php = $this->process("{templateType Totally\\Missing\\ClassName}\n<p>static</p>\n");

		self::assertStringContainsString('function latteMain()', $php);
	}

	public function testTwoContextsProduceTwoMainClones(): void
	{
		$contexts = [
			new TemplateContext(['x' => 'int'], []),
			new TemplateContext(['x' => 'string'], []),
		];
		$php = $this->processWithContexts("{\$x}\n", $contexts);

		self::assertStringContainsString('function latteMain_ctx0($x)', $php);
		self::assertStringContainsString('function latteMain_ctx1($x)', $php);
		self::assertStringNotContainsString('function latteMain(', $php);
		self::assertSame(1, substr_count($php, '@param int $x'));
		self::assertStringContainsString('@param string $x', $php);
	}

	public function testEmptyContextsPreservePlanABehavior(): void
	{
		$php = $this->process("{varType int \$n}\n{\$n}\n");

		self::assertStringContainsString('function latteMain($n)', $php);
		self::assertStringNotContainsString('latteMain_ctx', $php);
	}

	public function testBlockParamsUseContextUnionJoin(): void
	{
		$contexts = [
			new TemplateContext(['u' => 'int'], []),
			new TemplateContext(['u' => 'string', 'v' => 'bool'], []),
		];
		$php = $this->processWithContexts("{block b}{\$u}{\$v}{/block}\n", $contexts);

		self::assertStringContainsString('@param mixed $u', $php);
		self::assertStringContainsString('@param bool $v', $php);
	}

	public function testParametersDeclaredVarReachesBlockHeaderParams(): void
	{
		// Runtime pin: RuntimeParityTest::testParametersDeclaredVarIsVisibleInsideBlock (vendor
		// BlockMacros::extractMethod always `extract($this->params)` inside a top-level block,
		// regardless of {parameters}) shows a block DOES see a {parameters}-declared var - so the
		// no-context ("Plan A") block header params must include it too, same as templateType/varType.
		$php = $this->process("{parameters string \$p}\n{block b}{\$p}{/block}\n");

		self::assertStringContainsString('@param string $p', $php);
		self::assertStringContainsString('function blockB($p)', $php);
	}

	public function testContextCloneOrderIsDeterministicRegardlessOfConstructionOrder(): void
	{
		$intFirst = new TemplateContext(['x' => 'int'], []);
		$stringSecond = new TemplateContext(['x' => 'string'], []);

		$forward = $this->processWithContexts("{\$x}\n", [$intFirst, $stringSecond]);
		$swapped = $this->processWithContexts("{\$x}\n", [$stringSecond, $intFirst]);

		self::assertSame(
			$forward,
			$swapped,
			'clone index assignment must be ordered by canonicalHash, never by construction order',
		);
	}

	public function testMainCloneDeepIndependenceAcrossClones(): void
	{
		// Same vars/types on purpose: this isolates object-identity independence (proving a real
		// deep clone, not a shallow one sharing child nodes) from any content difference between
		// the two contexts.
		$contexts = [
			new TemplateContext(['x' => 'int'], []),
			new TemplateContext(['x' => 'int'], []),
		];
		$stmts = $this->processStmtsWithContexts("{\$x}\n", $contexts);

		$class = (new NodeFinder())->findFirstInstanceOf($stmts, Class_::class);
		self::assertInstanceOf(Class_::class, $class);

		$ctx0 = $this->findClassMethod($class, 'latteMain_ctx0');
		$ctx1 = $this->findClassMethod($class, 'latteMain_ctx1');
		self::assertNotNull($ctx0);
		self::assertNotNull($ctx1);
		self::assertNotSame($ctx0, $ctx1);

		self::assertNotNull($ctx0->stmts);
		self::assertNotNull($ctx1->stmts);
		self::assertNotSame([], $ctx0->stmts);
		self::assertNotSame([], $ctx1->stmts);

		self::assertNotSame($ctx0->params[0], $ctx1->params[0]);
		self::assertNotSame($ctx0->stmts[0], $ctx1->stmts[0]);
		self::assertSame($ctx0->stmts[0]->getStartLine(), $ctx1->stmts[0]->getStartLine());
	}

	public function testVarTypeThisNeverBecomesAParameter(): void
	{
		$php = $this->process("{varType App\\Foo\\Bar \$this}\n<p>static</p>\n");

		self::assertStringContainsString('function latteMain()', $php);
		self::assertStringContainsString('function lattePrepare()', $php);
		self::assertStringNotContainsString('@param', $php);
	}

	public function testContextVarNamedThisNeverBecomesAParameter(): void
	{
		$contexts = [TemplateContext::root(['this' => 'App\Foo\Bar', 'x' => 'int'])];
		$php = $this->processWithContexts("{\$x}\n", $contexts);

		self::assertStringContainsString('function latteMain_ctx0($x)', $php);
		self::assertStringContainsString('function lattePrepare($x)', $php);
		self::assertSame(2, substr_count($php, '@param'));
	}

	public function testDefineOwnParamWinsOverCollidingHeaderVarType(): void
	{
		$php = $this->process("{varType int \$shared}\n{define block1, string \$shared}{\$shared}{/define}\n");

		self::assertStringContainsString('function blockBlock1($shared)', $php);
		self::assertStringNotContainsString('function blockBlock1($shared, $shared)', $php);
		self::assertStringContainsString(
			"@param string \$shared\n     */\n    public function blockBlock1(\$shared)",
			$php,
		);
	}

	public function testDefineOwnParamWinsOverCollidingContextVar(): void
	{
		$contexts = [TemplateContext::root(['shared' => 'int'])];
		$php = $this->processWithContexts("{define block1, string \$shared}{\$shared}{/define}\n", $contexts);

		self::assertStringContainsString('function blockBlock1($shared)', $php);
		self::assertStringNotContainsString('function blockBlock1($shared, $shared)', $php);
	}

	public function testRootOnlySingleContextParamsMatchPlanAMain(): void
	{
		$latte = "{varType int \$count}\n{\$count}\n";

		$planA = $this->process($latte);
		$withContext = $this->processWithContexts($latte, [TemplateContext::root(['count' => 'int'])]);

		self::assertStringContainsString('function latteMain($count)', $planA);
		self::assertStringContainsString('@param int $count', $planA);

		self::assertStringContainsString('function latteMain_ctx0($count)', $withContext);
		self::assertStringContainsString('@param int $count', $withContext);
		self::assertStringNotContainsString('latteMain_ctx1', $withContext);
	}

	public function testUntypedBlockParamConsumesCapturedArgTypeFromSingleSite(): void
	{
		$dir = $this->isolatedDir('single-site');
		$latte = "{define wrap, \$inner}{\$inner}{/define}\n{include #wrap, 'a'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$context = TemplateContext::root([]);
			$site = PipelineFactory::createTemplateEdgeIndex($dir)->outgoingSites('wrap.latte')[0];
			$store = $this->seededStore($dir, [
				$this->entryFor(
					$dir,
					'wrap.latte',
					$site->getLatteLine(),
					'wrap',
					$context,
					['inner' => 'non-falsy-string'],
				),
			]);

			$php = $this->dumpWithStore($dir, $store, $latte, [$context], 'wrap.latte');

			self::assertStringContainsString('@param non-falsy-string $inner', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testUntypedBlockParamUnionsCapturedTypesAcrossCallSites(): void
	{
		$dir = $this->isolatedDir('multi-site');
		$latte = "{define wrap, \$inner}{\$inner}{/define}\n{include #wrap, 'a'}\n{include #wrap, 1}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$context = TemplateContext::root([]);
			$sites = PipelineFactory::createTemplateEdgeIndex($dir)->outgoingSites('wrap.latte');
			self::assertCount(2, $sites, 'fixture must declare two block-dispatch sites');

			$store = $this->seededStore($dir, [
				$this->entryFor($dir, 'wrap.latte', $sites[0]->getLatteLine(), 'wrap', $context, ['inner' => 'Foo']),
				$this->entryFor($dir, 'wrap.latte', $sites[1]->getLatteLine(), 'wrap', $context, ['inner' => 'Bar']),
			]);

			$php = $this->dumpWithStore($dir, $store, $latte, [$context], 'wrap.latte');

			self::assertStringContainsString('@param Bar|Foo $inner', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testUntypedBlockParamUnionsCapturedTypesAcrossContexts(): void
	{
		$dir = $this->isolatedDir('multi-context');
		$latte = "{define wrap, \$inner}{\$inner}{/define}\n{include #wrap, 'a'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$contextA = TemplateContext::root(['p' => 'int']);
			$contextB = TemplateContext::root(['p' => 'string']);
			$site = PipelineFactory::createTemplateEdgeIndex($dir)->outgoingSites('wrap.latte')[0];

			$store = $this->seededStore($dir, [
				$this->entryFor($dir, 'wrap.latte', $site->getLatteLine(), 'wrap', $contextA, ['inner' => 'Foo']),
				$this->entryFor($dir, 'wrap.latte', $site->getLatteLine(), 'wrap', $contextB, ['inner' => 'Bar']),
			]);

			$php = $this->dumpWithStore($dir, $store, $latte, [$contextA, $contextB], 'wrap.latte');

			self::assertStringContainsString('@param Bar|Foo $inner', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testUntypedBlockParamDedupesIdenticalCapturedTypes(): void
	{
		$dir = $this->isolatedDir('dedupe');
		$latte = "{define wrap, \$inner}{\$inner}{/define}\n{include #wrap, 'a'}\n{include #wrap, 'b'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$context = TemplateContext::root([]);
			$sites = PipelineFactory::createTemplateEdgeIndex($dir)->outgoingSites('wrap.latte');

			$store = $this->seededStore($dir, [
				$this->entryFor(
					$dir,
					'wrap.latte',
					$sites[0]->getLatteLine(),
					'wrap',
					$context,
					['inner' => 'non-falsy-string'],
				),
				$this->entryFor(
					$dir,
					'wrap.latte',
					$sites[1]->getLatteLine(),
					'wrap',
					$context,
					['inner' => 'non-falsy-string'],
				),
			]);

			$php = $this->dumpWithStore($dir, $store, $latte, [$context], 'wrap.latte');

			self::assertStringContainsString('@param non-falsy-string $inner', $php);
			self::assertStringNotContainsString('non-falsy-string|non-falsy-string', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testUnionMergeOfDifferentlyOrderedEqualUnionsStaysStringExactButParsesCorrectly(): void
	{
		// Pins the CURRENT dedup mechanism: it dedupes by exact captured STRING (a set keyed by
		// $type), not by semantic type identity. Two sites capturing the SAME semantic union
		// (stdClass|null) with different member order - plausible, since PHPStan's own
		// Type::describe() member order isn't guaranteed canonical across different inference
		// paths - are NOT deduped against each other, so the merged doc stays cosmetically
		// redundant. Each already-unioned member is parenthesized on join (see
		// parenthesizeUnionMember()), so the merged doc reads `(null|stdClass)|(stdClass|null)`,
		// not a flattened `null|stdClass|stdClass|null`. This is safe rather than a bug: PHPStan's
		// own TypeStringResolver/TypeCombinator dedupes identical members by type identity when
		// THIS string is parsed on the next analysis pass, asserted below directly rather than
		// only reasoned about.
		$dir = $this->isolatedDir('reordered-union');
		$latte = "{define wrap, \$inner}{\$inner}{/define}\n{include #wrap, 'a'}\n{include #wrap, 'b'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$context = TemplateContext::root([]);
			$sites = PipelineFactory::createTemplateEdgeIndex($dir)->outgoingSites('wrap.latte');

			$store = $this->seededStore($dir, [
				$this->entryFor(
					$dir,
					'wrap.latte',
					$sites[0]->getLatteLine(),
					'wrap',
					$context,
					['inner' => 'stdClass|null'],
				),
				$this->entryFor(
					$dir,
					'wrap.latte',
					$sites[1]->getLatteLine(),
					'wrap',
					$context,
					['inner' => 'null|stdClass'],
				),
			]);

			$php = $this->dumpWithStore($dir, $store, $latte, [$context], 'wrap.latte');

			self::assertStringContainsString('@param (null|stdClass)|(stdClass|null) $inner', $php);

			$typeStringResolver = PHPStanTestCase::getContainer()->getByType(TypeStringResolver::class);
			$merged = $typeStringResolver->resolve('(null|stdClass)|(stdClass|null)');
			$minimal = $typeStringResolver->resolve('stdClass|null');

			self::assertSame(
				$minimal->describe(VerbosityLevel::precise()),
				$merged->describe(VerbosityLevel::precise()),
				'redundant members must normalize away once PHPStan\'s own type system parses the merged string',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testUnionMergeParenthesizesASuffixTypedMemberSoItRoundTripsToTheIntendedUnion(): void
	{
		// A captured member carrying its own top-level `|`/`:`/`(` (a callable shape here) can
		// re-parse with different precedence once joined into a wider union by a bare `|` - the
		// merge must parenthesize such a member so the join point is unambiguous.
		$dir = $this->isolatedDir('callable-shaped-member');
		$latte = "{define wrap, \$inner}{\$inner}{/define}\n{include #wrap, 'a'}\n{include #wrap, 1}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$context = TemplateContext::root([]);
			$sites = PipelineFactory::createTemplateEdgeIndex($dir)->outgoingSites('wrap.latte');
			self::assertCount(2, $sites, 'fixture must declare two block-dispatch sites');

			$store = $this->seededStore($dir, [
				$this->entryFor(
					$dir,
					'wrap.latte',
					$sites[0]->getLatteLine(),
					'wrap',
					$context,
					['inner' => 'Closure(): int'],
				),
				$this->entryFor($dir, 'wrap.latte', $sites[1]->getLatteLine(), 'wrap', $context, ['inner' => 'Foo']),
			]);

			$php = $this->dumpWithStore($dir, $store, $latte, [$context], 'wrap.latte');

			self::assertStringContainsString('@param (Closure(): int)|Foo $inner', $php);

			$typeStringResolver = PHPStanTestCase::getContainer()->getByType(TypeStringResolver::class);
			$merged = $typeStringResolver->resolve('(Closure(): int)|Foo');

			self::assertInstanceOf(UnionType::class, $merged);
			self::assertCount(
				2,
				$merged->getTypes(),
				'the merged string must round-trip to a TWO-member union (the callable UNIONED with '
				. 'Foo) - a bare, unparenthesized join would instead be swallowed into a single '
				. 'callable-returning-Foo type',
			);
			self::assertInstanceOf(ClosureType::class, $merged->getTypes()[0]);
			self::assertInstanceOf(ObjectType::class, $merged->getTypes()[1]);
			self::assertSame('Foo', $merged->getTypes()[1]->getClassName());
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testTypedBlockOwnParamNeverTakesCapturedType(): void
	{
		// Per-variable priority pin: a {define}'s own TYPED param is a real declaration, so it must
		// win over a captured type even when a (deliberately wrong) store entry disagrees - exactly
		// like ContextResolverTest::testDeclaredVarNotOverriddenByStorePerVariablePin.
		$dir = $this->isolatedDir('typed-pin');
		$latte = "{define wrap, string \$inner}{\$inner}{/define}\n{include #wrap, 'a'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$context = TemplateContext::root([]);
			$site = PipelineFactory::createTemplateEdgeIndex($dir)->outgoingSites('wrap.latte')[0];
			$store = $this->seededStore($dir, [
				$this->entryFor($dir, 'wrap.latte', $site->getLatteLine(), 'wrap', $context, ['inner' => 'array']),
			]);

			$php = $this->dumpWithStore($dir, $store, $latte, [$context], 'wrap.latte');

			self::assertSame(1, substr_count($php, '@param string $inner'));
			self::assertStringNotContainsString('@param array $inner', $php);
			self::assertStringNotContainsString('string|array', $php);
			self::assertStringNotContainsString('array|string', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testCapturedTypeNeverOverridesBodyVarTypeForUntypedOwnParam(): void
	{
		// An untyped own param's ONLY declared type source is the block's own body-depth-0
		// {varType} (an untyped native param carries no type of its own, so the varType IS the
		// declared type) - it must win over a captured arg type even when a store entry disagrees,
		// exactly like testTypedBlockOwnParamNeverTakesCapturedType above pins for a TYPED own param.
		$dir = $this->isolatedDir('body-vartype-pin');
		$latte = "{define wrap, \$inner}{varType Foo \$inner}{\$inner}{/define}\n{include #wrap, 'a'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$context = TemplateContext::root([]);
			$site = PipelineFactory::createTemplateEdgeIndex($dir)->outgoingSites('wrap.latte')[0];
			$store = $this->seededStore($dir, [
				$this->entryFor($dir, 'wrap.latte', $site->getLatteLine(), 'wrap', $context, ['inner' => 'array']),
			]);

			$php = $this->dumpWithStore($dir, $store, $latte, [$context], 'wrap.latte');

			self::assertSame(1, substr_count($php, '@param Foo $inner'));
			self::assertStringNotContainsString('@param array $inner', $php);
			self::assertStringNotContainsString('Foo|array', $php);
			self::assertStringNotContainsString('array|Foo', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testExtraBlockArgTypeNeverOverridesBodyVarTypeForParamlessBlock(): void
	{
		// Same per-variable priority, param-less-block-args-channel side (F4/probe-5): a body
		// {varType} must win over extraBlockArgTypes()'s own compile-time ArgTyper inference too,
		// not only over a narrowing-store captured type.
		$dir = $this->isolatedDir('paramless-body-vartype-pin');
		$latte = "{define b}{varType Foo \$x}{\$x}{/define}\n{include b, x: 'val'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$php = $this->dumpFile($dir, 'wrap.latte');

			self::assertStringContainsString('@param Foo $x', $php);
			self::assertStringNotContainsString('@param string $x', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testUntypedBlockParamUnaffectedByAbsentStore(): void
	{
		// Degradation parity: a real edge graph (TemplateEdgeIndex/LatteUniverse actively wired),
		// but no store entry at all - must produce no captured-type behavior (no @param line).
		$dir = $this->isolatedDir('absent-store');
		$latte = "{define wrap, \$inner}{\$inner}{/define}\n{include #wrap, 'a'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$compiled = (new LatteCompiler())->compile($latte, 'LatteTpl_test');
			$declarations = (new DeclarationScanner())->scan($latte);
			$php = PipelineFactory::create($dir)->dump(
				$compiled,
				$declarations,
				[TemplateContext::root([])],
				'wrap.latte',
			);

			self::assertStringNotContainsString('@param', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testUntypedBlockParamUnaffectedByShaMismatch(): void
	{
		$dir = $this->isolatedDir('sha-mismatch');
		$latte = "{define wrap, \$inner}{\$inner}{/define}\n{include #wrap, 'a'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$context = TemplateContext::root([]);
			$site = PipelineFactory::createTemplateEdgeIndex($dir)->outgoingSites('wrap.latte')[0];
			$key = SiteScopeStore::key('wrap.latte', $site->getLatteLine(), 'wrap', $context->canonicalHash());
			$store = $this->seededStore($dir, [
				[$key, ['sha' => '0000000000000000000000000000000000dead', 'vars' => [], 'args' => ['inner' => 'Foo']]],
			]);

			$php = $this->dumpWithStore($dir, $store, $latte, [$context], 'wrap.latte');

			self::assertStringNotContainsString('@param', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// F4 (probe-5 parity: CrossFileScopeParityTest::testImportExplicitIncludeArgWinsOverParamAndLocal)
	// - explicit include-args bind into a PARAM-LESS block via Latte's universal extract($ʟ_args),
	// independent of whether the block declares a matching param. The paired runtime probe pins
	// 'explicit-arg' actually winning at runtime; these pin the model no longer reporting
	// $x undefined for it and typing it live (never falling back to mixed).

	public function testParamlessDefineBlockMaterializesParamFromNamedIncludeArgSameFile(): void
	{
		$dir = $this->isolatedDir('paramless-define-samefile');
		$latte = "{define b}{\$x}{/define}\n{include b, x: 'val'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$php = $this->dumpFile($dir, 'wrap.latte');

			self::assertStringContainsString('@param string $x', $php);
			self::assertStringContainsString('function blockB($x)', $php);
			self::assertStringContainsString("blockB('val')", $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testParamlessBlockTagMaterializesParamFromNamedIncludeArg(): void
	{
		// {block name} (non-define) - carried-forward request from the per-block-facts task: blocks
		// and defines share one body-gated compile path, proven end-to-end here too.
		$dir = $this->isolatedDir('paramless-block-tag');
		$latte = "{block b}{\$x}{/block}\n{include b, x: 'val'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$php = $this->dumpFile($dir, 'wrap.latte');

			self::assertStringContainsString('@param string $x', $php);
			self::assertStringContainsString('function blockB($x)', $php);
			self::assertStringContainsString("blockB('val')", $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testParamlessBlockNeverPassedArgGetsNoExtraParam(): void
	{
		// No blanket suppression: a SIBLING param-less block referencing the same free variable
		// name, but never reached by any site passing it, must stay exactly as before - still
		// zero-param, still relying on native PHPStan undefined-variable detection.
		$dir = $this->isolatedDir('paramless-no-blanket-suppression');
		$latte = "{define b}{\$x}{/define}\n{define c}{\$x}{/define}\n{include b, x: 'val'}\n{include c}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$php = $this->dumpFile($dir, 'wrap.latte');

			self::assertStringContainsString('function blockB($x)', $php);
			self::assertStringContainsString('function blockC(): void', $php);
			self::assertStringNotContainsString('function blockC($x)', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testParamlessBlockInImportedFileMaterializesParamFromImporterIncludeArg(): void
	{
		// Cross-file closure of the same gap (the literal probe-5 shape: import + explicit
		// include-arg into a block the IMPORTED file itself declares with no params) - sourced via
		// TemplateEdgeIndex::incomingEdges() rather than capturedBlockArgTypes()'s own-file-only
		// outgoingSites(), since the block's defining file never sees the importer's call site any
		// other way.
		$dir = $this->isolatedDir('paramless-cross-file');
		FileSystem::write($dir . '/lib.latte', "{define b}{\$x}{/define}\n");
		FileSystem::write($dir . '/main.latte', "{import 'lib.latte'}\n{include b, x: 'explicit-arg'}\n");

		try {
			$contexts = PipelineFactory::createContextResolver($dir)->contextsFor('lib.latte');
			$php = $this->dumpFile($dir, 'lib.latte', $contexts);

			self::assertStringContainsString('@param string $x', $php);
			self::assertStringContainsString('function blockB($x)', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testExtraBlockArgTypeNeverDuplicatesOwnDeclaredParam(): void
	{
		$dir = $this->isolatedDir('paramless-own-param-collision');
		$latte = "{define b, \$x}{\$x}{/define}\n{include b, x: 'val'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$php = $this->dumpFile($dir, 'wrap.latte');

			self::assertSame(1, substr_count($php, 'function blockB($x)'));
			self::assertStringNotContainsString('function blockB($x, $x)', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testExtraBlockArgTypeNeverDuplicatesHeaderParam(): void
	{
		$dir = $this->isolatedDir('paramless-header-collision');
		$latte = "{varType string \$x}\n{define b}{\$x}{/define}\n{include b, x: 'val'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$php = $this->dumpFile($dir, 'wrap.latte');

			self::assertSame(1, substr_count($php, 'function blockB($x)'));
			self::assertStringNotContainsString('function blockB($x, $x)', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testMultipleExtraBlockArgsThreadByNameNotDeclarationPosition(): void
	{
		// Regression pin: extraBlockArgTypes() unions/sorts param names alphabetically (ksort), so
		// the materialized param ORDER need not match the call site's own arg order ('isSubItem'
		// sorts before 'item', but the site writes item first) - BlockDispatchEliminator's direct
		// -call rewrite must still thread each value to the param with the MATCHING name, never by
		// position (a real bug this pin caught: it silently swapped the two values before the
		// eliminator was taught to match by name).
		$dir = $this->isolatedDir('multi-extra-arg-order');
		$latte = "{define item}{\$item}{\$isSubItem}{/define}\n{include item, item: 'ITEM-VAL', isSubItem: true}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$php = $this->dumpFile($dir, 'wrap.latte');

			// Params materialize in ksort'd name order (isSubItem, item) - the call must match
			// VALUES to that same order by NAME (true -> isSubItem, 'ITEM-VAL' -> item), not swap
			// them to the call site's own textual order (item first, isSubItem second).
			self::assertStringContainsString("blockItem(\\true, 'ITEM-VAL')", $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testExplicitNamedArgWinsOverSameNamedCallerLocalInRecursiveSelfInclude(): void
	{
		// Regression pin (real-world shape: app/Component/Base/NavbarControl/templates/
		// navbarControl.latte's recursive menu {define item}): a block that includes ITSELF, where
		// the callee's own materialized param name collides with the CALLER's (its own) same-named
		// param - the call site's own explicit arg must win (round-1 parity probe
		// testImportExplicitIncludeArgWinsOverParamAndLocal: extract($ʟ_args) always overwrites a
		// same-named caller local), never silently fall back to threading the caller's own unrelated
		// value through untouched.
		$dir = $this->isolatedDir('recursive-self-include-arg-wins');
		$latte = "{define item}{\$item}{if \$item}{include item, item: 'CHILD'}{/if}{/define}\n"
			. "{include item, item: 'ROOT'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$php = $this->dumpFile($dir, 'wrap.latte');

			self::assertStringContainsString("\$this->blockItem('CHILD')", $php);
			self::assertStringNotContainsString('$this->blockItem($item)', $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testOwnParamBlockNeverMaterializesUnmatchedExtraArg(): void
	{
		// Real Latte's extractMethod only emits the universal extract($ʟ_args) fallback for a block
		// with ZERO own declared params (BlockMacros.php's `$params ? ... : null` branch) - a
		// block that declares even one own param never gets that fallback, so a named arg beyond
		// its declared params is genuinely never bound at runtime (paired runtime probe:
		// CrossFileScopeParityTest::testOwnParamBlockNeverExtractsUnmatchedExtraArg, which compiles
		// and renders this exact shape through the real Latte\Engine and observes 'OWN-VAL|UNDEF' -
		// $extra stays undefined). extraBlockArgTypes() must therefore never materialize a param for
		// 'extra' here: blockB keeps its own single positional param only, and the direct-call
		// rewrite threads only that one value through.
		$dir = $this->isolatedDir('own-param-extra-not-bound');
		$latte = "{define b, \$y}{\$y}{\$extra}{/define}\n{include b, extra: 'EXTRA-VAL', 'OWN-VAL'}\n";
		FileSystem::write($dir . '/wrap.latte', $latte);

		try {
			$php = $this->dumpFile($dir, 'wrap.latte');

			// blockB's OWN body still reads $extra (echo $extra;) - that free-variable read is
			// exactly what must surface as a genuine PHPStan variable.undefined finding now, so it's
			// deliberately NOT asserted absent here; only the SIGNATURE/call-site shape (single own
			// param, never a materialized 'extra' param) is what this gate is responsible for.
			self::assertStringContainsString('function blockB($y): void', $php);
			self::assertStringNotContainsString('function blockB($y, $extra)', $php);
			self::assertStringContainsString("\$this->blockB('OWN-VAL')", $php);
			self::assertStringNotContainsString("\$this->blockB('OWN-VAL', 'EXTRA-VAL')", $php);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<TemplateContext> $contexts
	 */
	private function dumpFile(string $dir, string $relativePath, array $contexts = []): string
	{
		// Force universe enumeration so cross-file targets (imports/includes) actually resolve
		// before the dump - matches EdgeAnchorInjectorTest::processIncluder's own preamble.
		PipelineFactory::createTemplateEdgeIndex($dir)->outgoingSites($relativePath);

		$source = FileSystem::read($dir . '/' . $relativePath);
		$compiled = (new LatteCompiler())->compile($source, 'LatteTpl_test');
		$declarations = (new DeclarationScanner())->scan($source);

		return PipelineFactory::create($dir)->dump($compiled, $declarations, $contexts, $relativePath);
	}

	/**
	 * @param array<string, string> $args
	 * @return array{string, array{sha: string, vars: array<string, string>, args: array<string, string>}}
	 */
	private function entryFor(
		string $dir,
		string $file,
		int $latteLine,
		string $rawTarget,
		TemplateContext $context,
		array $args
	): array
	{
		$key = SiteScopeStore::key($file, $latteLine, $rawTarget, $context->canonicalHash());

		return [$key, ['sha' => (string) sha1_file($dir . '/' . $file), 'vars' => [], 'args' => $args]];
	}

	/**
	 * @param list<array{string, array{sha: string, vars: array<string, string>, args: array<string, string>}}> $entries
	 */
	private function seededStore(string $dir, array $entries): SiteScopeStore
	{
		$store = new SiteScopeStore($dir . '/store.php');

		$byKey = [];
		$includers = [];
		foreach ($entries as [$key, $entry]) {
			$byKey[$key] = $entry;
			[$includer] = explode('#', $key, 2);
			$includers[$includer] = true;
		}

		$store->replaceForIncluders(array_keys($includers), $byKey);

		return $store;
	}

	/**
	 * @param list<TemplateContext> $contexts
	 */
	private function dumpWithStore(
		string $dir,
		SiteScopeStore $store,
		string $latte,
		array $contexts,
		string $relativePath
	): string
	{
		$compiled = (new LatteCompiler())->compile($latte, 'LatteTpl_test');
		$declarations = (new DeclarationScanner())->scan($latte);

		return PipelineFactory::create($dir, $store)->dump($compiled, $declarations, $contexts, $relativePath);
	}

	private function isolatedDir(string $prefix): string
	{
		return sys_get_temp_dir() . '/latte-declaration-injector-' . $prefix . '-' . getmypid() . '-' . uniqid(
			'',
			true,
		);
	}

	private function process(string $latte): string
	{
		$compiled = (new LatteCompiler())->compile($latte, 'LatteTpl_test');
		$declarations = (new DeclarationScanner())->scan($latte);

		return PipelineFactory::create()->dump($compiled, $declarations);
	}

	/**
	 * @return array<Diagnostic>
	 */
	private function diagnosticsFor(string $latte): array
	{
		$compiled = (new LatteCompiler())->compile($latte, 'LatteTpl_test');
		$declarations = (new DeclarationScanner())->scan($latte);

		$pipeline = PipelineFactory::create();
		$pipeline->dump($compiled, $declarations);

		return $pipeline->getDiagnostics();
	}

	/**
	 * @param list<TemplateContext> $contexts
	 */
	private function processWithContexts(string $latte, array $contexts): string
	{
		$compiled = (new LatteCompiler())->compile($latte, 'LatteTpl_test');
		$declarations = (new DeclarationScanner())->scan($latte);

		return PipelineFactory::create()->dump($compiled, $declarations, $contexts);
	}

	/**
	 * @param list<TemplateContext> $contexts
	 * @return array<Stmt>
	 */
	private function processStmtsWithContexts(string $latte, array $contexts): array
	{
		$compiled = (new LatteCompiler())->compile($latte, 'LatteTpl_test');
		$declarations = (new DeclarationScanner())->scan($latte);

		return PipelineFactory::create()->process($compiled, $declarations, $contexts);
	}

	private function findClassMethod(Class_ $class, string $name): ?ClassMethod
	{
		foreach ($class->stmts as $stmt) {
			if ($stmt instanceof ClassMethod && $stmt->name->toString() === $name) {
				return $stmt;
			}
		}

		return null;
	}

}
