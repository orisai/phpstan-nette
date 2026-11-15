<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\AnalysedFileLog;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_diff;
use function array_filter;
use function array_values;
use function dirname;
use function explode;
use function implode;
use function preg_replace;
use function rtrim;
use function sort;
use function str_replace;
use function strpos;
use function uniqid;
use const PHP_BINARY;

// Task 1 (DiscoveryRecords::mayReachALayout()) stops emitting a layout record for a class that
// provably renders no view. That gate only matters if the dropped record then reaches the
// previously-linked layout template on a WARM run - otherwise the layout keeps analysing under a
// stale link forever. This mirrors
// DiscoveryStoreInvalidationTest's spawn/store harness (its own header comment explains the two-hop
// mechanism in full) with LatteDiscoveryDiagnosticsIntegrationTest's mapping-loader trick standing in
// for the missing container, which is what makes formatLayoutTemplateFiles() produce a layout
// candidate at all.
/**
 * @group latte2
 */
final class DiscoveryLayoutLinkInvalidationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const ORPHAN_MESSAGE = 'No analysable render, include or layout path reaches this template file.';

	public function testDroppingTheOnlyViewReanalysesThePreviouslyLinkedLayout(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $srcDir . '/discovery';
		$tmpDir = $scratch . '/pstmp';

		try {
			FileSystem::write($scratch . '/mapping-loader.php', <<<'PHP'
<?php declare(strict_types = 1);

return [
	'spawn' => new class extends \Nette\DI\Container {

		public function getByType(string $type, bool $throw = true): ?object
		{
			$factory = new \Nette\Application\PresenterFactory();
			$factory->setMapping(['Spawn' => 'LatteSpawnLayout\\*Presenter']);

			return $factory instanceof $type ? $factory : null;
		}

	},
];

PHP);
			FileSystem::write($srcDir . '/SpawnLayoutPresenter.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace LatteSpawnLayout;

final class SpawnLayoutPresenter extends \Nette\Application\UI\Presenter
{

	public function renderDefault(): void
	{
	}

}

PHP);
			FileSystem::write($srcDir . '/templates/SpawnLayout/default.latte', "<p>view</p>\n");
			FileSystem::write($srcDir . '/templates/@layout.latte', "{block content}{/block}\n");

			$layoutRel = "$relSrc/templates/@layout.latte";
			$viewRel = "$relSrc/templates/SpawnLayout/default.latte";
			$mappingLoader = $scratch . '/mapping-loader.php';

			// Every template's store file exists BEFORE run 1, mirroring
			// DiscoveryStoreInvalidationTest's own pre-analysis-index precondition: the
			// template->store-class dependency edge must be baked into the cold parse before any
			// record change can propagate through it.
			DiscoveryStore::bootstrap($storeDir, [$layoutRel, $viewRel]);

			$expectedLayoutRecord = [
				'class' => 'LatteSpawnLayout\\SpawnLayoutPresenter',
				'view' => null,
				'kind' => 'layout',
				'certainty' => 'unknown',
			];

			$run1 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, $mappingLoader);
			self::assertSame(
				"(no errors)\n",
				$run1['output'],
				'the fixture must be otherwise clean: ' . $run1['diagnostics'],
			);

			// The probe's own precondition (spec-mandated): if this fails, the layout candidate
			// path list FormulaVocabulary::layoutCandidates() derives for this fixture does not
			// include templates/@layout.latte, and there is nothing for run 4 to drop.
			$store1 = new DiscoveryStore($storeDir);
			self::assertSame(
				[$expectedLayoutRecord],
				$store1->recordsForTemplate($layoutRel),
				'run 1 must link the layout template to the presenter through the writer - if this '
				. 'fails, print $storeDir\'s contents and correct the fixture paths above; do not '
				. 'proceed with an empty precondition',
			);

			// Run 2: nothing, exactly as DiscoveryStoreInvalidationTest's own run 2 (the cold run
			// hashes the analysed files after the pre-analysis build rewrote the store files).
			$run2 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, $mappingLoader);
			self::assertSame($run1['output'], $run2['output']);
			self::assertStringContainsString(
				'Result cache restored. 0 files will be reanalysed.',
				$run2['diagnostics'],
				'run 2 must reanalyse nothing: ' . $run2['diagnostics'],
			);

			$run3 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, $mappingLoader);
			self::assertSame($run1['output'], $run3['output']);
			self::assertStringContainsString(
				'Result cache restored. 0 files will be reanalysed.',
				$run3['diagnostics'],
				'run 3 must be the settled fixpoint: ' . $run3['diagnostics'],
			);

			// The presenter now renders no view: no discovery opaques, no open view set, and the
			// one view candidate that WAS chosen no longer exists - DiscoveryRecords::forClass()'s
			// mayReachALayout() gate must therefore stop appending the layout record.
			FileSystem::delete($srcDir . '/templates/SpawnLayout/default.latte');

			// Run 4: the pre-analysis build re-derives the presenter's facts ahead of this run's own
			// parse and rewrites both template store files to empty content. The layout template's
			// tracked neighbourhood value moves with its record and the presenter's tracked render facts
			// with its vanished view, so both are re-analysed on this very run. The rewritten store files
			// sit inside the analysed paths here, and PHPStan re-analyses them on this run or the next
			// one depending on whether the rewrite landed in the second it hashed them; they are left out.
			$run4 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, $mappingLoader);
			self::assertSame($run1['output'], $run4['output']);
			self::assertSame(
				['SpawnLayoutPresenter.php', 'templates/@layout.latte'],
				self::withoutStoreFiles($run4['analysed']),
				'run 4 must reanalyse the previously linked layout: ' . $run4['diagnostics'],
			);
			$store4 = new DiscoveryStore($storeDir);
			self::assertSame(
				[],
				$store4->recordsForTemplate($layoutRel),
				'the dropped view must empty the layout link through the gated writer',
			);

			// Run 5: at most PHPStan's own pass over the rewritten store files and their native
			// dependents, the templates referencing them; nothing else.
			$run5 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, $mappingLoader);
			self::assertSame($run1['output'], $run5['output']);
			self::assertSame(
				[],
				array_values(array_diff(self::withoutStoreFiles($run5['analysed']), ['templates/@layout.latte'])),
				$run5['diagnostics'],
			);
			$store5 = new DiscoveryStore($storeDir);
			self::assertSame([], $store5->recordsForTemplate($layoutRel));
		} finally {
			FileSystem::delete($scratch);
		}
	}

	/**
	 * @param list<string> $analysed
	 * @return list<string>
	 */
	private static function withoutStoreFiles(array $analysed): array
	{
		return array_values(
			array_filter($analysed, static fn (string $file): bool => strpos($file, 'discovery/') !== 0),
		);
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir: ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file) - a path outside $projectRoot never
		// relativizes, breaking every rel-path lookup that assumes project-relative paths.
		$dir = $projectRoot . '/var/tmp/latte-discovery-layout-link-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

	/**
	 * @return array{output: string, diagnostics: string, analysed: list<string>}
	 */
	private function spawn(
		string $projectRoot,
		string $srcDir,
		string $tmpDir,
		string $storeDir,
		string $mappingLoader
	): array
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			[
				'orisai.nette.latte.discovery.enabled' => true,
				'orisai.nette.latte.discovery.storePath' => $storeDir,
				'orisai.nette.latte.firstPartyPaths' => [$srcDir],
				'orisai.nette.latte.templateFactoryContainerLoader' => $mappingLoader,
			],
		);

		$log = new AnalysedFileLog($isolated->getConfigPath(), $tmpDir);
		$process = new Process(
			[
				PHP_BINARY,
				$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=raw',
				'-vv',
				'-c',
				$log->getConfigPath(),
			],
			$projectRoot,
			$log->env(),
		);
		$process->run();

		$rawOutput = preg_replace('/ \[identifier=[^\]]+\]$/m', '', $process->getOutput());

		return [
			'output' => $this->normalize($rawOutput ?? $process->getOutput(), $projectRoot),
			'diagnostics' => $process->getErrorOutput(),
			'analysed' => $log->read($srcDir),
		];
	}

	private function normalize(string $raw, string $projectRoot): string
	{
		$lines = [];
		foreach (explode("\n", str_replace($projectRoot . '/', '', $raw)) as $line) {
			$line = rtrim($line);
			// Never actually reached by this fixture (checkAggregate() bails out the whole
			// reachability half the moment the store links nothing anywhere, which is exactly the
			// end state runs 4-5 settle into) - kept for parity with
			// DiscoveryStoreInvalidationTest's own normalize() since spawn() is copied unchanged.
			if ($line !== '' && strpos($line, self::ORPHAN_MESSAGE) === false) {
				$lines[] = $line;
			}
		}

		if ($lines === []) {
			return "(no errors)\n";
		}

		sort($lines);

		return implode("\n", $lines) . "\n";
	}

}
