<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\SliceClassName;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function explode;
use function implode;
use function preg_replace;
use function rtrim;
use function sort;
use function str_replace;
use function uniqid;
use const PHP_BINARY;
use const SORT_STRING;

// Opt-in gate: orisai.nette.latte.narrowing.enabled defaults false and is
// independent of orisai.nette.latte.enabled. Both scenarios below reuse
// ResultCacheInvalidationTest::testSliceFileContentChangePropagatesToItsTargetOnAPlainWarmRun's
// exact fixture shape (an {if $x !== null} guard around an {include} into an undeclared-var
// target). Rather than hand-deriving TemplateContext::canonicalHash() to construct a store entry
// by hand, both tests populate the store with a REAL capturing spawn first (narrowing on) and
// then re-run the actual scenario under test against that genuinely-populated store - the same
// "empirically verified before writing assertions" discipline the sibling ResultCacheInvalidationTest
// payoff test uses.
/**
 * @group latte2
 */
final class NarrowingFlagTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	// "no store reads/writes... nothing committed": a genuinely matching, valid store entry
	// (not stale, not corrupt) must still be ignored - the wide nullability error must survive,
	// and the slice file's bytes must be byte-identical before and after the run.
	public function testFlagOffIgnoresAPrePopulatedStoreAndLeavesItByteUnchanged(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $srcDir . '/sitescope';

		try {
			$this->populateRealCapture($projectRoot, $srcDir, $relSrc, $storeDir, $scratch . '/capture-pstmp');

			$sliceFile = $this->sliceFile($storeDir, "$relSrc/narrow-includer.latte");
			$before = FileSystem::read($sliceFile);

			$run = $this->spawnWithStore($projectRoot, $srcDir, $scratch . '/pstmp', $storeDir, false);

			self::assertSame(
				"$relSrc/narrow-target.latte:1:Cannot call method getMessage() on Exception|null.\n"
				. "$relSrc/unrelated.latte:1:Undefined variable: \$undefinedVar\n",
				$run['output'],
				'flag off must report the WIDE nullability error even though the store already '
				. 'contains a matching, valid narrowing entry: ' . $run['diagnostics'],
			);
			self::assertSame(
				$before,
				FileSystem::read($sliceFile),
				'flag off must never write to the store - the pre-seeded slice must survive byte-for-byte',
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// "on = current behavior": the exact same populated store, flag explicitly turned on, and a
	// FRESH (cold) tmpDir reproduce the narrowed result the store-capture mechanism already pins -
	// the flag gates the mechanism without changing it.
	public function testFlagOnAppliesAPrePopulatedStoreMatchingTheExistingMechanism(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $srcDir . '/sitescope';

		try {
			$this->populateRealCapture($projectRoot, $srcDir, $relSrc, $storeDir, $scratch . '/capture-pstmp');

			$run = $this->spawnWithStore($projectRoot, $srcDir, $scratch . '/pstmp', $storeDir, true);

			self::assertSame(
				"$relSrc/unrelated.latte:1:Undefined variable: \$undefinedVar\n",
				$run['output'],
				'flag on must narrow $x to Exception, dropping the nullability error, exactly like '
				. 'narrowing behaved before the opt-in flag existed: ' . $run['diagnostics'],
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// Bootstraps the store, then runs the real narrowing-enabled TWO-spawn sequence
	// ResultCacheInvalidationTest's own payoff test pins (the intrinsic one-run lag: a
	// capture is born during analysis and can only feed the NEXT run's parse) so the includer's
	// slice ends up with the actual key/sha/type the mechanism computes - never a hand-derived
	// guess, and the store reaches the genuinely-converged, already-committed state both
	// scenarios under test are about.
	private function populateRealCapture(
		string $projectRoot,
		string $srcDir,
		string $relSrc,
		string $storeDir,
		string $captureTmp
	): void
	{
		FileSystem::write(
			$srcDir . '/narrow-includer.latte',
			"{varType Exception|null \$x}\n{if \$x !== null}\n\t{include 'narrow-target.latte'}\n{/if}\n",
		);
		FileSystem::write($srcDir . '/narrow-target.latte', "{\$x->getMessage()}\n");
		FileSystem::write($srcDir . '/unrelated.latte', "{\$undefinedVar}\n");

		SiteScopeStore::bootstrap($storeDir, [
			"$relSrc/narrow-includer.latte",
			"$relSrc/narrow-target.latte",
			"$relSrc/unrelated.latte",
		]);

		// Run 1: cold, empty store - the one-run lag means this still reports the WIDE error even
		// with narrowing on, but writes the real capture.
		$captureRun1 = $this->spawnWithStore($projectRoot, $srcDir, $captureTmp, $storeDir, true);
		self::assertSame(
			"$relSrc/narrow-target.latte:1:Cannot call method getMessage() on Exception|null.\n"
			. "$relSrc/sitescope/" . SliceClassName::forPath("$relSrc/narrow-includer.latte") . '.php:1:'
			. "The Latte narrowing store changed for 1 including template: $relSrc/narrow-includer.latte. "
			. "Run the analysis again until this error disappears, then commit the store.\n"
			. "$relSrc/unrelated.latte:1:Undefined variable: \$undefinedVar\n",
			$captureRun1['output'],
			'sanity check: run 1 (empty store) must report the wide error: ' . $captureRun1['diagnostics'],
		);

		// Run 2: SAME tmpDir (warm) and SAME store dir - the fingerprint fold re-analyses the
		// target once the changed slice file's own bytes trip PHPStan's restore() gate.
		$captureRun2 = $this->spawnWithStore($projectRoot, $srcDir, $captureTmp, $storeDir, true);
		self::assertSame(
			"$relSrc/unrelated.latte:1:Undefined variable: \$undefinedVar\n",
			$captureRun2['output'],
			'sanity check: run 2 must already narrow (mechanism precondition), or the scenarios '
			. 'below would be vacuous: ' . $captureRun2['diagnostics'],
		);

		$sliceFile = $this->sliceFile($storeDir, "$relSrc/narrow-includer.latte");
		self::assertStringContainsString(
			"'x' => 'Exception'",
			FileSystem::read($sliceFile),
			'sanity check: the capturing run must have written a real narrowing entry',
		);
	}

	private function sliceFile(string $storeDir, string $includerRel): string
	{
		return $storeDir . '/' . SliceClassName::forPath($includerRel) . '.php';
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir: ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file) - a path outside $projectRoot never
		// relativizes, breaking every include-target/edge lookup that assumes project-relative
		// paths.
		$dir = $projectRoot . '/var/tmp/latte-narrowing-flag-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

	/**
	 * @return array{output: string, diagnostics: string}
	 */
	private function spawnWithStore(
		string $projectRoot,
		string $srcDir,
		string $tmpDir,
		string $storeDir,
		bool $narrowingEnabled
	): array
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
				'-vv',
				'-c',
				$isolated->getConfigPath(),
			],
			$projectRoot,
		);
		$process->run();

		$rawOutput = preg_replace('/ \[identifier=[^\]]+\]$/m', '', $process->getOutput());

		return [
			'output' => $this->normalize($rawOutput ?? $process->getOutput(), $projectRoot),
			'diagnostics' => $process->getErrorOutput(),
		];
	}

	private function normalize(string $raw, string $projectRoot): string
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

}
