<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Parser\LatteRoutingParser;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Parser\Parser;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use function basename;
use function dirname;
use function getenv;
use function glob;
use function sys_get_temp_dir;
use function uniqid;

final class SnapshotTest extends BaseTestCase
{

	/**
	 * @dataProvider provideFixtures
	 */
	public function testRawCompilationSnapshot(string $lattePath): void
	{
		self::requireFixtureTags($lattePath);
		$className = TemplateClassName::forPath('fixtures/' . basename($lattePath));
		$result = (new LatteCompiler())->compile(FileSystem::read($lattePath), $className);
		self::assertNotNull($result->getPhpSource(), 'fixture must compile');
		self::assertSame([], $result->getDiagnostics(), 'fixture must compile without diagnostics');

		$this->assertSnapshot(
			dirname(__DIR__, 2) . '/Unit/Latte/Fixtures/__snapshots__/raw/' . basename($lattePath) . '.php',
			$result->getPhpSource(),
		);
	}

	/**
	 * @dataProvider provideFixtures
	 */
	public function testProcessedSnapshot(string $lattePath): void
	{
		self::requireFixtureTags($lattePath);
		$className = TemplateClassName::forPath('fixtures/' . basename($lattePath));
		$latteSource = FileSystem::read($lattePath);
		$result = (new LatteCompiler())->compile($latteSource, $className);
		self::assertNotNull($result->getPhpSource(), 'fixture must compile');

		$declarations = (new DeclarationScanner())->scan($latteSource);
		$processed = PipelineFactory::create()->dump($result, $declarations);

		$this->assertSnapshot(
			dirname(__DIR__, 2) . '/Unit/Latte/Fixtures/__snapshots__/processed/' . basename($lattePath) . '.php',
			$processed,
		);
	}

	// {ifCurrent} is gone from nette/application 3.3 (UIMacros dropped with the Latte 2 path).
	private static function requireFixtureTags(string $lattePath): void
	{
		if (basename($lattePath) === 'ifcurrent.latte') {
			InstalledVersionsGuard::requireNetteLine('nette/application', '<3.3');
		}
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public function provideFixtures(): iterable
	{
		foreach ((array) glob(dirname(__DIR__, 2) . '/Unit/Latte/Fixtures/*.latte') as $path) {
			yield basename((string) $path) => [(string) $path];
		}
	}

	// Not part of provideFixtures()/Fixtures/*.latte: those are compiled with a bare
	// `new LatteCompiler()` (no harvester), where the gettext family is genuinely unknown and would
	// fail the "compiles without diagnostics" assertion every other fixture in that glob relies on.
	public function testGettextFamilySnapshotProvesNativeCompilation(): void
	{
		$engineLoaderFile = dirname(__DIR__, 2) . '/Unit/Latte/Customs/Fixtures/engine-loader-gettext.php';
		$harvester = new CustomsHarvester(new EngineSource(null, $engineLoaderFile));
		$lattePath = dirname(__DIR__, 2) . '/Unit/Latte/Customs/Fixtures/gettext-family.latte';
		$className = TemplateClassName::forPath('fixtures/gettext-family.latte');

		$result = (new LatteCompiler(null, $harvester))->compile(FileSystem::read($lattePath), $className);

		self::assertNotNull($result->getPhpSource(), 'fixture must compile');
		self::assertSame([], $result->getDiagnostics(), 'harvested gettext macros must compile without diagnostics');

		$this->assertSnapshot(
			dirname(__DIR__, 2) . '/Unit/Latte/Customs/Fixtures/__snapshots__/gettext-family.latte.php',
			$result->getPhpSource(),
		);
	}

	public function testRouterProducesAstForFixture(): void
	{
		$projectRoot = dirname(__DIR__, 2) . '/Unit/Latte/Fixtures';
		$fixturePath = $projectRoot . '/parameters.latte';

		$stmts = $this->buildRouter($projectRoot)->parseFile($fixturePath);

		self::assertNotSame([], $stmts);
		$class = (new NodeFinder())->findFirstInstanceOf($stmts, Class_::class);
		self::assertInstanceOf(Class_::class, $class);
		self::assertNotNull($class->name);
		self::assertMatchesRegularExpression('~^LatteTpl_~', $class->name->toString());

		// Parity pin (design point 2), part 1: if the production-wired parser had failed to
		// resolve `use Latte\Runtime as LR;`, EscapingEliminator would still match here too - it
		// also accepts the raw `LR\Filters` alias form defensively. See
		// testRouterEliminatesCachingIteratorOnlyWhenNamesAreResolved below for the strict,
		// non-alias-tolerant pin that actually distinguishes resolved from unresolved names.
		$printed = (new Standard())->prettyPrintFile($stmts);
		self::assertStringNotContainsString('escapeHtml', $printed);
	}

	public function testRouterEliminatesCachingIteratorOnlyWhenNamesAreResolved(): void
	{
		$projectRoot = dirname(__DIR__, 2) . '/Unit/Latte/Fixtures';
		$fixturePath = $projectRoot . '/foreach-iterator.latte';

		$stmts = $this->buildRouter($projectRoot)->parseFile($fixturePath);
		$printed = (new Standard())->prettyPrintFile($stmts);

		// Parity pin (design point 2), part 2 - the strong one: IteratorEliminator's
		// isCachingIteratorClass() matches a SINGLE fully-qualified string
		// ('Latte\Runtime\CachingIterator'), unlike EscapingEliminator it does NOT also accept the
		// raw `LR\CachingIterator` alias form. This assertion can only pass if the router's parser
		// (PipelineFactory's currentPhpVersionSimpleParser) actually ran NameResolver over the
		// `use Latte\Runtime as LR;` import - proving parser parity between the router's real service
		// graph and what the eliminators/rewriters (matchers written against fully-qualified names)
		// require; IntegrationSnapshotTest pins the same property against the production wiring.
		self::assertStringContainsString('new \Latte\Runtime\CachingIterator(', $printed);
		self::assertStringNotContainsString('ʟ_it', $printed);
	}

	public function testRouterReportsDiagnosticsForFixtureWithUnknownFilter(): void
	{
		$this->withTempProjectFile(
			"{varType string \$s}\n{\$s|totallyUnknownFilterForRouterTest}\n",
			function (string $projectRoot, string $fixturePath): void {
				$stmts = $this->buildRouter($projectRoot)->parseFile($fixturePath);
				$printed = (new Standard())->prettyPrintFile($stmts);

				self::assertStringContainsString("Diag::report('orisaiNette.latte.unknownFilter'", $printed);
			},
		);
	}

	public function testRouterMemoizesRepeatedParsesOfTheSamePath(): void
	{
		// Superseded design point: this used to pin assertNotSame() across repeat parses of one
		// path, guarding against a *content-keyed* PHPStan CachedParser wrapping the whole router
		// (which would collide on two DIFFERENT files sharing byte-identical source - see
		// testRouterKeepsDiagnosticsIndependentAcrossFilesWithIdenticalContent below for that actual
		// hazard). LatteRoutingParser's own memo is keyed by absolute PATH, not content, so it can't
		// reproduce that collision; sharing node instances across repeat same-path parses now matches
		// PHPStan's own CachedParser semantics for .php files (analysis and reflection deliberately
		// share mutated node instances there too).
		$this->withTempProjectFile(
			"{varType string \$s}\n{\$s|totallyUnknownFilterForRepeatTest}\n",
			function (string $projectRoot, string $fixturePath): void {
				$router = $this->buildRouter($projectRoot);

				$stmtsA = $router->parseFile($fixturePath);
				$stmtsB = $router->parseFile($fixturePath);

				foreach ([$stmtsA, $stmtsB] as $stmts) {
					$printed = (new Standard())->prettyPrintFile($stmts);
					self::assertStringContainsString("Diag::report('orisaiNette.latte.unknownFilter'", $printed);
				}

				self::assertSame($stmtsA, $stmtsB);
			},
		);
	}

	public function testRouterKeepsDiagnosticsIndependentAcrossFilesWithIdenticalContent(): void
	{
		// The real hazard a content-keyed cache would hit: two DIFFERENT paths sharing
		// byte-identical .latte source must still compile to independent classes/ASTs, since
		// TemplateClassName bakes each file's own relative path into its class name.
		$source = "{varType string \$s}\n{\$s|totallyUnknownFilterForIdenticalContentTest}\n";
		$projectRoot = sys_get_temp_dir() . '/latte-router-snapshot-test-' . uniqid('', true);
		$pathA = $projectRoot . '/a.latte';
		$pathB = $projectRoot . '/b.latte';
		FileSystem::write($pathA, $source);
		FileSystem::write($pathB, $source);

		try {
			$router = $this->buildRouter($projectRoot);

			$stmtsA = $router->parseFile($pathA);
			$stmtsB = $router->parseFile($pathB);

			self::assertNotSame($stmtsA, $stmtsB);

			$classA = (new NodeFinder())->findFirstInstanceOf($stmtsA, Class_::class);
			$classB = (new NodeFinder())->findFirstInstanceOf($stmtsB, Class_::class);
			self::assertInstanceOf(Class_::class, $classA);
			self::assertInstanceOf(Class_::class, $classB);
			self::assertNotNull($classA->name);
			self::assertNotNull($classB->name);
			self::assertNotSame($classA->name->toString(), $classB->name->toString());
		} finally {
			FileSystem::delete($projectRoot);
		}
	}

	private function buildRouter(string $projectRoot): LatteRoutingParser
	{
		$delegate = $this->createMock(Parser::class);
		$delegate->expects(self::never())->method('parseFile');
		$contextResolver = PipelineFactory::createContextResolver($projectRoot);

		return new LatteRoutingParser(
			$delegate,
			new LatteCompiler(),
			new DeclarationScanner(),
			PipelineFactory::create(),
			$contextResolver,
			PipelineFactory::createIncludeContractChecker($projectRoot, $contextResolver),
			PipelineFactory::createDeclarationConsistencyChecker($projectRoot),
			PipelineFactory::createTemplateEdgeIndex($projectRoot),
			PipelineFactory::createSiteScopeStore($projectRoot),
			PipelineFactory::createRichAttributeDecorator(),
			$projectRoot,
			true,
			true,
		);
	}

	/**
	 * @param callable(string, string): void $test
	 */
	private function withTempProjectFile(string $latteSource, callable $test): void
	{
		$projectRoot = sys_get_temp_dir() . '/latte-router-snapshot-test-' . uniqid('', true);
		$fixturePath = $projectRoot . '/unknown-filter.latte';
		FileSystem::write($fixturePath, $latteSource);

		try {
			$test($projectRoot, $fixturePath);
		} finally {
			FileSystem::delete($projectRoot);
		}
	}

	private function assertSnapshot(string $snapshotPath, string $actual): void
	{
		if (getenv('UPDATE_SNAPSHOTS') === '1') {
			FileSystem::write($snapshotPath, $actual);
			self::assertFileExists($snapshotPath);

			return;
		}

		self::assertFileExists(
			$snapshotPath,
			"Committed snapshot $snapshotPath is missing. "
			. 'Run with UPDATE_SNAPSHOTS=1 to generate it, then review the diff before committing.',
		);
		self::assertStringEqualsFile($snapshotPath, $actual);
	}

}
