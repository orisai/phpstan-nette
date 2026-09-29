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
use function in_array;
use function rtrim;
use function sort;
use function str_replace;
use function uniqid;
use const PHP_BINARY;
use const SORT_STRING;

// Store degradation direction: missing/stale/unparseable
// slice => today's declared-wide behavior for that variable, never an error, never a crash. Both
// scenarios below reuse ResultCacheInvalidationTest's narrow-includer/narrow-target fixture pair
// (an {if $x !== null} guard around an {include} into an undeclared-var target) so "empty-store
// behavior" has a known, already-pinned baseline: the wide nullability error.
final class NarrowingDegradationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	// A slice file whose PHP is genuinely unparseable (ParseError, not just a wrong shape) sits
	// INSIDE the analysed paths (slices must live where PHPStan's
	// restore() can observe their hash), so PHPStan's OWN top-level parser reports the broken PHP
	// as a plain syntax-error diagnostic for that one file - a normal finding, never a crash and
	// never an internal error. SiteScopeStoreTest::testUnparseableSliceFileDegradesToNoEntriesWithoutThrowing
	// pins the NARROWING-SPECIFIC half of this contract at the class level (loadOne()'s own tight
	// Throwable catch around `include`, isolated from PHPStan's own project-wide parse-severity
	// short-circuiting, which this spawn-level test pins instead): that a broken slice can never
	// crash the whole process end-to-end, exactly as a real checkout with a corrupted/merge-
	// conflicted slice file would behave.
	public function testCorruptedSliceFileNeverCrashesTheProcessEndToEnd(): void
	{
		[$projectRoot, $scratch, $srcDir, $relSrc, $storeDir] = $this->buildFixture();

		try {
			FileSystem::createDir($storeDir);
			$sliceFile = $storeDir . '/' . SliceClassName::forPath("$relSrc/narrow-includer.latte") . '.php';
			FileSystem::write($sliceFile, "<?php declare(strict_types = 1);\n\nthis is not valid php {{{\n");

			$run = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp', $storeDir);

			self::assertTrue(
				in_array($run['exitCode'], [0, 1], true),
				'a corrupted slice file must never crash the process (exit 0/1 only), got '
				. $run['exitCode'] . ': ' . $run['diagnostics'],
			);
			self::assertStringNotContainsString(
				'Internal error',
				$run['diagnostics'],
				'a corrupted slice file must never surface as an internal PHPStan error: ' . $run['diagnostics'],
			);
			self::assertStringContainsString(
				'Syntax error',
				$run['output'],
				'the broken file must be reported as a normal syntax-error finding, never silently dropped: '
				. $run['diagnostics'],
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// A slice entry that is structurally well-formed but STALE (its recorded sha no longer
	// matches the includer's current content) - SiteScopeStore::get()'s own sha comparison must
	// reject it, degrading that one entry to the declared-wide type.
	public function testStaleSliceEntryDegradesToEmptyStoreBehaviorWithoutCrashing(): void
	{
		[$projectRoot, $scratch, $srcDir, $relSrc, $storeDir] = $this->buildFixture();

		try {
			SiteScopeStore::bootstrap($storeDir, ["$relSrc/narrow-includer.latte", "$relSrc/narrow-target.latte"]);
			$store = new SiteScopeStore($storeDir);
			$key = SiteScopeStore::key(
				"$relSrc/narrow-includer.latte",
				3,
				"'narrow-target.latte'",
				'stale-context-hash-that-cannot-match',
			);
			$store->replaceForIncluders(
				["$relSrc/narrow-includer.latte"],
				[
					$key => [
						'sha' => 'deliberately-wrong-sha-that-can-never-match-the-real-includer',
						'vars' => ['x' => 'Exception'],
						'args' => [],
					],
				],
			);

			$run = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp', $storeDir);

			self::assertTrue(
				in_array($run['exitCode'], [0, 1], true),
				'a stale slice entry must never crash the process (exit 0/1 only), got '
				. $run['exitCode'] . ': ' . $run['diagnostics'],
			);
			self::assertSame(
				$this->emptyStoreBaseline($projectRoot, $srcDir, $relSrc, $scratch),
				$run['output'],
				'a stale slice entry must degrade to exactly the empty-store (wide) result: ' . $run['diagnostics'],
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	private function emptyStoreBaseline(string $projectRoot, string $srcDir, string $relSrc, string $scratch): string
	{
		$baselineStoreDir = $scratch . '/baseline-sitescope';
		SiteScopeStore::bootstrap($baselineStoreDir, ["$relSrc/narrow-includer.latte", "$relSrc/narrow-target.latte"]);

		return $this->spawn($projectRoot, $srcDir, $scratch . '/baseline-pstmp', $baselineStoreDir)['output'];
	}

	/**
	 * @return array{string, string, string, string, string}
	 */
	private function buildFixture(): array
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
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

	private function createScratchDir(string $projectRoot): string
	{
		$dir = $projectRoot . '/var/tmp/latte-narrowing-degradation-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

	/**
	 * @return array{output: string, diagnostics: string, exitCode: int}
	 */
	private function spawn(string $projectRoot, string $srcDir, string $tmpDir, string $storeDir): array
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			['orisaiNette.latte.narrowing.storePath' => $storeDir],
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

		return [
			'output' => $this->normalize($process->getOutput(), $projectRoot),
			'diagnostics' => $process->getErrorOutput(),
			'exitCode' => (int) $process->getExitCode(),
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
