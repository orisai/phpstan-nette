<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Compile\DiscoveryClassName;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\AnalysedFileLog;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
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

// The discovery cascade: a body-only edit to a PHP file in a renderer's read set changes no
// exported node PHPStan itself can see, so no native dependency edge ever reanalyzes the linked
// template. The pre-analysis build re-derives every indexed class through the facts envelope, and
// the moved records reach exactly the templates owning them on the SAME warm run through their
// tracked neighbourhood value, and the renderer through its tracked render facts - granular by
// construction, never a whole-cache invalidation and never a template the edit does not reach.
/**
 * @group latte2
 */
final class DiscoveryStoreInvalidationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const ORPHAN_MESSAGE = 'No analysable render, include or layout path reaches this template file.';

	public function testHelperBodyEditReachesTheLinkedTemplateThroughTheStoreOnPlainWarmRuns(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $srcDir . '/discovery';
		$tmpDir = $scratch . '/pstmp';

		try {
			FileSystem::write(
				$srcDir . '/ScratchTemplateBase.php',
				"<?php declare(strict_types = 1);\n\n"
				. "abstract class ScratchTemplateBase extends Nette\\Application\\UI\\Control\n"
				. "{\n\n"
				. "\tprotected function applyTemplateFile(): void\n"
				. "\t{\n"
				. "\t\t\$this->template->setFile(__DIR__ . '/tpl-a.latte');\n"
				. "\t}\n\n"
				. "}\n",
			);
			FileSystem::write(
				$srcDir . '/ScratchRenderer.php',
				"<?php declare(strict_types = 1);\n\n"
				. "final class ScratchRenderer extends ScratchTemplateBase\n"
				. "{\n\n"
				. "\tpublic function render(): void\n"
				. "\t{\n"
				. "\t\t\$this->applyTemplateFile();\n"
				. "\t\t\$this->template->render();\n"
				. "\t}\n\n"
				. "}\n",
			);
			FileSystem::write($srcDir . '/tpl-a.latte', "<p>a</p>\n");
			FileSystem::write($srcDir . '/tpl-b.latte', "<p>b</p>\n");
			// Never linked by any renderer - its cached result must survive every store change
			// byte-for-byte, proving the mechanism is per-template, never whole-cache.
			FileSystem::write($srcDir . '/unrelated.latte', "{\$undefinedVar}\n");

			// Every template's store file exists BEFORE run 1 (mirrors the pre-analysis index
			// build): the template->store-class dependency edge must be baked into the cold
			// parse before any record change can propagate through it, and no brand-new file may
			// appear between runs to confound the reanalysis counts.
			DiscoveryStore::bootstrap($storeDir, [
				"$relSrc/tpl-a.latte",
				"$relSrc/tpl-b.latte",
				"$relSrc/unrelated.latte",
			]);

			// The two PHP-side lines are level 8's honest take on Control::$template's
			// Template|stdClass union (nette/application 3.1; 3.2 declares DefaultTemplate) - stable
			// across every run, so they pin output identity just as well as a clean fixture would
			// (guarding them away would turn the setFile write conditional and destroy the very
			// record this test exists to move).
			$expectedOutput = (InstalledVersionsGuard::satisfies('nette/application', '<3.2')
				? "$relSrc/ScratchRenderer.php:9:"
					. "Cannot call method render() on Nette\\Application\\UI\\Template|stdClass.\n"
					. "$relSrc/ScratchTemplateBase.php:8:"
					. "Cannot call method setFile() on Nette\\Application\\UI\\Template|stdClass.\n"
				: '')
				. "$relSrc/unrelated.latte:1:Undefined variable: \$undefinedVar\n";

			$run1 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertSame(
				$expectedOutput,
				$run1['output'],
				'cold run must show only the fixture-stable errors: ' . $run1['diagnostics'],
			);
			// The abstract base qualifies on its own too (the walk's honest read of its reachable
			// setFile), so the template carries records for both classes.
			$expectedRecords = [
				['class' => 'ScratchRenderer', 'view' => null, 'kind' => 'setFile', 'certainty' => 'unknown'],
				['class' => 'ScratchTemplateBase', 'view' => null, 'kind' => 'setFile', 'certainty' => 'unknown'],
			];

			$storeA = new DiscoveryStore($storeDir);
			self::assertSame(
				$expectedRecords,
				$storeA->recordsForTemplate("$relSrc/tpl-a.latte"),
				'run 1 must link tpl-a to its renderer through the writer',
			);
			self::assertSame(["$relSrc/tpl-a.latte"], $storeA->allLinkedTemplates());

			// Run 2: nothing. The cold run hashes the analysed files AFTER the meta extensions ran the
			// pre-analysis build (a warm restore hashes them before), so it recorded the store files
			// the build rewrote. tpl-a already saw its record on the cold run, which is the
			// generational hop this feature removed.
			$run2 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertSame($run1['output'], $run2['output']);
			self::assertStringContainsString(
				'Result cache restored. 0 files will be reanalysed.',
				$run2['diagnostics'],
				'run 2 must reanalyse nothing: ' . $run2['diagnostics'],
			);

			$run3 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertSame($run1['output'], $run3['output']);
			self::assertStringContainsString(
				'Result cache restored. 0 files will be reanalysed.',
				$run3['diagnostics'],
				'run 3 must be the settled fixpoint: ' . $run3['diagnostics'],
			);

			// Body-only helper edit: the literal moves inside a method body - no exported node of
			// ScratchTemplateBase changes, so PHPStan alone would never reach tpl-a or tpl-b.
			FileSystem::write(
				$srcDir . '/ScratchTemplateBase.php',
				"<?php declare(strict_types = 1);\n\n"
				. "abstract class ScratchTemplateBase extends Nette\\Application\\UI\\Control\n"
				. "{\n\n"
				. "\tprotected function applyTemplateFile(): void\n"
				. "\t{\n"
				. "\t\t\$this->template->setFile(__DIR__ . '/tpl-b.latte');\n"
				. "\t}\n\n"
				. "}\n",
			);

			// Run 4: the edited helper, the renderer whose render facts it moved and the two templates
			// whose records moved - never the unrelated template. The store files the build rewrote sit
			// inside the analysed paths here, and PHPStan re-analyses them on this run or the next one
			// depending on whether the rewrite landed in the second it hashed them; they are left out.
			$run4 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertSame($run1['output'], $run4['output']);
			self::assertSame(
				['ScratchRenderer.php', 'ScratchTemplateBase.php', 'tpl-a.latte', 'tpl-b.latte'],
				self::withoutStoreFiles($run4['analysed']),
				'run 4 must reanalyse exactly what the edit reaches: ' . $run4['diagnostics'],
			);
			$storeB = new DiscoveryStore($storeDir);
			self::assertSame([], $storeB->recordsForTemplate("$relSrc/tpl-a.latte"));
			self::assertSame(
				$expectedRecords,
				$storeB->recordsForTemplate("$relSrc/tpl-b.latte"),
				'the helper body edit must move the records to tpl-b through the envelope-driven writer',
			);

			// Run 5: at most PHPStan's own pass over the rewritten store files and their native
			// dependents, the templates referencing them; nothing else.
			$run5 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertSame($run1['output'], $run5['output']);
			self::assertSame(
				[],
				array_values(array_diff(self::withoutStoreFiles($run5['analysed']), ['tpl-a.latte', 'tpl-b.latte'])),
				$run5['diagnostics'],
			);

			$run6 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertSame($run1['output'], $run6['output']);
			self::assertStringContainsString(
				'Result cache restored. 0 files will be reanalysed.',
				$run6['diagnostics'],
				'run 6 must be the settled fixpoint again: ' . $run6['diagnostics'],
			);

			$sliceFile = $storeDir . '/' . DiscoveryClassName::forPath("$relSrc/tpl-b.latte") . '.php';
			$afterRun6 = FileSystem::read($sliceFile);
			self::assertStringContainsString('ScratchRenderer', $afterRun6);
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
		$dir = $projectRoot . '/var/tmp/latte-discovery-store-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

	/**
	 * @return array{output: string, diagnostics: string, analysed: list<string>}
	 */
	private function spawn(string $projectRoot, string $srcDir, string $tmpDir, string $storeDir): array
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			[
				'orisai.nette.latte.discovery.enabled' => true,
				'orisai.nette.latte.discovery.storePath' => $storeDir,
				'orisai.nette.latte.firstPartyPaths' => [$srcDir],
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
			// TemplateTypeChecker's reachability verdict is the one diagnostic this fixture corpus
			// makes a moving target BY DESIGN: the store starts empty and the writer links tpl-a
			// (later tpl-b) during the very runs whose reanalysis counts this test measures, so a
			// template's orphan status legitimately flips between them. Dropped here rather than
			// pinned - the subject is invalidation granularity, and its own reachability pins live in
			// TemplateTypeCheckerTest.
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
