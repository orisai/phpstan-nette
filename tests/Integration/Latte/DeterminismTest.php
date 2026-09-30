<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Includes\ContextResolver;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Postprocess\AnalysisPipeline;
use OriPhpstan\Nette\Latte\Version\Latte2\DeclarationScanner;
use OriPhpstan\Nette\Latte\Version\Latte2\LatteCompiler;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function basename;
use function dirname;
use function explode;
use function glob;
use function implode;
use function rtrim;
use function sort;
use function str_replace;
use function uniqid;
use const PHP_BINARY;
use const SORT_STRING;

/**
 * @group latte2
 */
final class DeterminismTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Integration/Fixtures/integration.neon';

	/**
	 * @dataProvider provideFixtures
	 */
	public function testPipelineIsPure(string $lattePath): void
	{
		if (basename($lattePath) === 'ifcurrent.latte') {
			InstalledVersionsGuard::requireNetteLine('nette/application', '<3.3');
		}

		$dump1 = $this->dumpFresh($lattePath);
		$dump2 = $this->dumpFresh($lattePath);

		self::assertSame($dump1, $dump2, 'Pipeline must produce byte-identical dumps for identical input');
	}

	public function testPipelineIsDeterminantAcrossOrder(): void
	{
		$fixtureA = dirname(__DIR__, 2) . '/Unit/Latte/Fixtures/blocks-snippets.latte';
		$fixtureB = dirname(__DIR__, 2) . '/Unit/Latte/Fixtures/forms-macros.latte';

		$compiler1 = new LatteCompiler();
		$scanner1 = new DeclarationScanner();
		$pipeline1 = PipelineFactory::create();
		$dumpA1 = $this->dumpShared($compiler1, $scanner1, $pipeline1, $fixtureA);
		$dumpB1 = $this->dumpShared($compiler1, $scanner1, $pipeline1, $fixtureB);

		$compiler2 = new LatteCompiler();
		$scanner2 = new DeclarationScanner();
		$pipeline2 = PipelineFactory::create();
		$dumpB2 = $this->dumpShared($compiler2, $scanner2, $pipeline2, $fixtureB);
		$dumpA2 = $this->dumpShared($compiler2, $scanner2, $pipeline2, $fixtureA);

		self::assertSame($dumpA1, $dumpA2, 'processing another template first must not change output');
		self::assertSame($dumpB1, $dumpB2, 'processing another template first must not change output');
	}

	// DeterministicCacheMacro exists precisely because a vendor macro CAN embed nondeterminism
	// (Random::generate()) - this probes the harvested gettext macro set (h4kuna\Gettext\Macros\
	// Gettext, installed natively by LatteCompiler as of the harvested-macros task) the same way,
	// rather than assuming pure string manipulation never emits anything ambient.
	public function testHarvestedGettextMacroCompilationIsDeterministic(): void
	{
		$engineLoaderFile = dirname(__DIR__, 2) . '/Unit/Latte/Customs/Fixtures/engine-loader-gettext.php';
		$harvester = TestAdapter::harvester(new EngineSource(null, $engineLoaderFile));
		$lattePath = dirname(__DIR__, 2) . '/Unit/Latte/Customs/Fixtures/gettext-family.latte';
		$className = TemplateClassName::forPath('fixtures/gettext-family.latte');
		$source = FileSystem::read($lattePath);

		$first = (new LatteCompiler(null, $harvester))->compile($source, $className);
		$second = (new LatteCompiler(null, $harvester))->compile($source, $className);

		self::assertNotNull($first->getPhpSource());
		self::assertSame(
			$first->getPhpSource(),
			$second->getPhpSource(),
			'harvested gettext macro compilation must be byte-identical across two fresh compiles',
		);
		self::assertEquals($first->getDiagnostics(), $second->getDiagnostics());
	}

	// No cache involved (PipelineFactory::createTemplateEdgeIndex never wires a
	// LatteAnalysisCache) - two independent instances must fold the same on-disk corpus into the
	// same maps every time, since the manifest (and therefore the cache key production instances
	// key their fold by) is computed from the very same content hashes.
	public function testEdgeIndexFoldIsPureAcrossFreshInstances(): void
	{
		$indexA = $this->freshTreeEdgeIndex();
		$indexB = $this->freshTreeEdgeIndex();

		self::assertSame($indexA->manifest(), $indexB->manifest());

		foreach (['partial.latte', '@layout.latte', 'importer.latte'] as $basename) {
			self::assertEquals(
				$indexA->incomingEdges($basename),
				$indexB->incomingEdges($basename),
				"incoming edges for {$basename} must be fold-pure across fresh instances",
			);
		}
	}

	// Untainted nodes are memoized permanently (see ContextResolver::resolveContextsTracked's own
	// comment), so resolving unrelated includers first must not perturb partial.latte's own
	// contexts once resolved fresh.
	public function testContextResolverIsOrderIndependentForPartial(): void
	{
		$direct = $this->freshTreeContextResolver()->contextsFor('partial.latte');

		$viaOthersFirst = $this->freshTreeContextResolver();
		$viaOthersFirst->contextsFor('root.latte');
		$viaOthersFirst->contextsFor('other-root.latte');
		$afterOthers = $viaOthersFirst->contextsFor('partial.latte');

		self::assertEquals(
			$direct,
			$afterOthers,
			"resolving other includers first must not change partial.latte's contexts",
		);
	}

	public function testCrossFilePipelineIsPureAcrossFreshGraphsWithRealContexts(): void
	{
		$dump1 = $this->dumpFreshWithTreeContexts('partial.latte');
		$dump2 = $this->dumpFreshWithTreeContexts('partial.latte');

		self::assertSame(
			$dump1,
			$dump2,
			'pipeline must produce byte-identical dumps across fully fresh graphs when threaded with real cross-file contexts',
		);
	}

	// Amended determinism contract: "cold == warm, always" (the old, pre-narrowing
	// claim) is replaced by "same sources + same store => byte-identical results, cold or warm" -
	// the store is now genuine external state a plain in-process dump can't exercise, so these
	// four tests spawn a real phpstan process (mirrors ResultCacheInvalidationTest's own
	// technique) with a FIXED, pre-populated slice directory that never changes across any of
	// these runs. "Cold" = a fresh, empty tmpDir (no result cache to restore from) every time;
	// "warm" = the SAME tmpDir reused for a second spawn. Both the empty-store and the
	// populated-store variant are proven independently: internally deterministic (two fresh cold
	// spawns of the SAME store agree) AND cold==warm (a cold spawn agrees with a warm repeat).

	// Two SEPARATE store directories, both bootstrapped empty and never shared: the writer runs
	// unconditionally whenever narrowing is enabled and the store directory exists (it is NOT
	// itself a stable fixpoint for this fixture - A's own {if $x !== null} guard is a genuine
	// capturable anchor, so a single real analysis run already writes a non-empty entry). Sharing
	// one store directory across both spawns would let the first spawn's own write contaminate
	// what the second spawn reads, which is exactly the cross-run contamination this test must
	// NOT exercise - each spawn's diagnostics are a pure function of what it loads AT START, so
	// two independently-empty starting states prove the same thing a single shared one could not.
	public function testEmptyStoreIsInternallyDeterministicAcrossTwoFreshColdSpawns(): void
	{
		[$projectRoot, $scratch, $srcDir, $relSrc] = $this->buildStoreParametrizedFixture();

		try {
			$dump1 = $this->spawn(
				$projectRoot,
				$srcDir,
				$this->freshTmpDir($scratch),
				$this->freshEmptyStore($scratch, $relSrc),
			);
			$dump2 = $this->spawn(
				$projectRoot,
				$srcDir,
				$this->freshTmpDir($scratch),
				$this->freshEmptyStore($scratch, $relSrc),
			);

			self::assertSame($dump1, $dump2, 'two independent cold spawns of an equally-empty store must agree');
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// Narrowing-off runs keep the ORIGINAL cold==warm contract untouched: with the opt-in flag
	// off, the store is never read OR written, so it is unconditionally stable across a cold and
	// a warm run of the exact same sources - the pre-narrowing-plan guarantee, verified explicitly rather than merely
	// assumed. A pre-populated store directory is passed deliberately (not an absent one) to
	// prove disabled truly never reads it, matching NarrowingFlagTest's own contract.
	public function testNarrowingDisabledProducesTheSameWideResultColdOrWarmEvenWithAPopulatedStore(): void
	{
		[$projectRoot, $scratch, $srcDir, $relSrc, $storeDir] = $this->buildStoreParametrizedFixture();

		try {
			$this->seedPopulatedStore($projectRoot, $scratch, $srcDir, $relSrc, $storeDir);
			$tmpDir = $this->freshTmpDir($scratch);

			$cold = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, false);
			$warm = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, false);

			self::assertSame($cold, $warm, 'narrowing disabled must produce the same result cold or warm');
			self::assertSame(
				"$relSrc/narrow-target.latte:1:Cannot call method getMessage() on Exception|null.\n",
				$cold,
				'narrowing disabled must report the WIDE error even though the store is populated',
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	public function testPopulatedStoreIsInternallyDeterministicAcrossTwoFreshColdSpawns(): void
	{
		[$projectRoot, $scratch, $srcDir, $relSrc, $storeDir] = $this->buildStoreParametrizedFixture();

		try {
			$this->seedPopulatedStore($projectRoot, $scratch, $srcDir, $relSrc, $storeDir);

			$dump1 = $this->spawn($projectRoot, $srcDir, $this->freshTmpDir($scratch), $storeDir);
			$dump2 = $this->spawn($projectRoot, $srcDir, $this->freshTmpDir($scratch), $storeDir);

			self::assertSame($dump1, $dump2, 'two independent cold spawns of the same populated store must agree');
		} finally {
			FileSystem::delete($scratch);
		}
	}

	public function testPopulatedStoreProducesTheSameNarrowedResultColdOrWarm(): void
	{
		[$projectRoot, $scratch, $srcDir, $relSrc, $storeDir] = $this->buildStoreParametrizedFixture();

		try {
			$this->seedPopulatedStore($projectRoot, $scratch, $srcDir, $relSrc, $storeDir);
			$tmpDir = $this->freshTmpDir($scratch);

			$cold = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
			$warm = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);

			self::assertSame($cold, $warm, 'the same populated store must produce the same result cold or warm');
			self::assertSame(
				"(no errors)\n",
				$cold,
				'the populated (narrowed) store must eliminate the nullability error an empty/disabled '
				. 'store leaves (see testNarrowingDisabledProducesTheSameWideResultColdOrWarmEvenWithAPopulatedStore)',
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	/**
	 * @return array{string, string, string, string, string}
	 */
	private function buildStoreParametrizedFixture(): array
	{
		$projectRoot = dirname(__DIR__, 3);
		$scratch = $projectRoot . '/var/tmp/latte-determinism-test-' . uniqid('', true);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $srcDir . '/sitescope';

		FileSystem::write(
			$srcDir . '/narrow-includer.latte',
			"{varType Exception|null \$x}\n{if \$x !== null}\n\t{include 'narrow-target.latte'}\n{/if}\n",
		);
		FileSystem::write($srcDir . '/narrow-target.latte', "{\$x->getMessage()}\n");

		// [projectRoot, scratch, srcDir, relSrc, storeDir]
		return [$projectRoot, $scratch, $srcDir, $relSrc, $storeDir];
	}

	// Rather than hand-deriving TemplateContext::canonicalHash() to construct a store entry key
	// by hand, this runs the REAL two-spawn capturing sequence (mirrors
	// ResultCacheInvalidationTest's own payoff test - a fresh capture
	// only takes effect on the FOLLOWING run, one-run lag) so the store ends up in a genuinely-converged,
	// already-committed state. This test is about the determinism of CONSUMING a fixed store, not
	// about the capturing mechanism itself (NarrowingConvergenceTest's job).
	private function seedPopulatedStore(
		string $projectRoot,
		string $scratch,
		string $srcDir,
		string $relSrc,
		string $storeDir
	): void
	{
		SiteScopeStore::bootstrap($storeDir, ["$relSrc/narrow-includer.latte", "$relSrc/narrow-target.latte"]);

		$captureTmp = $this->freshTmpDir($scratch);
		$this->spawn($projectRoot, $srcDir, $captureTmp, $storeDir);
		$converged = $this->spawn($projectRoot, $srcDir, $captureTmp, $storeDir);

		self::assertSame(
			"(no errors)\n",
			$converged,
			'sanity check: the capturing sequence itself must already narrow, or this fixture is not '
			. 'exercising a genuinely populated store',
		);
	}

	private function freshTmpDir(string $scratch): string
	{
		$dir = $scratch . '/pstmp-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

	private function freshEmptyStore(string $scratch, string $relSrc): string
	{
		$storeDir = $scratch . '/sitescope-' . uniqid('', true);
		SiteScopeStore::bootstrap($storeDir, ["$relSrc/narrow-includer.latte", "$relSrc/narrow-target.latte"]);

		return $storeDir;
	}

	private function spawn(
		string $projectRoot,
		string $srcDir,
		string $tmpDir,
		string $storeDir,
		bool $narrowingEnabled = true
	): string
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			[
				'orisai.nette.latte.narrowing.storePath' => $storeDir,
				'orisai.nette.latte.narrowing.enabled' => $narrowingEnabled,
			],
		);

		$process = new Process(
			[
				PHP_BINARY,
				$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=raw',
				'-c',
				$isolated->getConfigPath(),
			],
			$projectRoot,
		);
		$process->run();

		return $this->normalizeSpawnOutput($process->getOutput(), $projectRoot);
	}

	private function normalizeSpawnOutput(string $raw, string $projectRoot): string
	{
		$lines = [];
		foreach (explode("\n", str_replace($projectRoot . '/', '', $raw)) as $line) {
			$line = rtrim($line);
			if ($line !== '') {
				$lines[] = $line;
			}
		}

		if ($lines === []) {
			return "(no errors)\n";
		}

		sort($lines, SORT_STRING);

		return implode("\n", $lines) . "\n";
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

	private function dumpFresh(string $lattePath): string
	{
		return $this->dumpShared(
			new LatteCompiler(),
			new DeclarationScanner(),
			PipelineFactory::create(),
			$lattePath,
		);
	}

	private function dumpShared(
		LatteCompiler $compiler,
		DeclarationScanner $scanner,
		AnalysisPipeline $pipeline,
		string $lattePath
	): string
	{
		$className = TemplateClassName::forPath('fixtures/' . basename($lattePath));
		$latteSource = FileSystem::read($lattePath);

		$result = $compiler->compile($latteSource, $className);
		self::assertNotNull($result->getPhpSource(), 'fixture must compile');

		$declarations = $scanner->scan($latteSource);

		return $pipeline->dump($result, $declarations);
	}

	private function dumpFreshWithTreeContexts(string $basename): string
	{
		$lattePath = $this->treeDir() . '/' . $basename;
		$latteSource = FileSystem::read($lattePath);
		$className = TemplateClassName::forPath('tree/' . $basename);

		$result = (new LatteCompiler())->compile($latteSource, $className);
		self::assertNotNull($result->getPhpSource(), 'fixture must compile');

		$declarations = (new DeclarationScanner())->scan($latteSource);
		$contexts = $this->freshTreeContextResolver()->contextsFor($basename);

		return PipelineFactory::create()->dump($result, $declarations, $contexts);
	}

	private function freshTreeEdgeIndex(): TemplateEdgeIndex
	{
		return PipelineFactory::createTemplateEdgeIndex($this->treeDir());
	}

	private function freshTreeContextResolver(): ContextResolver
	{
		return PipelineFactory::createContextResolver($this->treeDir());
	}

	private function treeDir(): string
	{
		return dirname(__DIR__, 2) . '/Unit/Latte/Includes/Fixtures/tree';
	}

}
