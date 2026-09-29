<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function explode;
use function getmypid;
use function hrtime;
use function implode;
use function in_array;
use function rtrim;
use function sort;
use function str_replace;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use const PHP_BINARY;

// Regression tripwire, not a benchmark: loose thresholds guard against the result cache
// silently stopping doing its work (e.g. a manifest/cache-key regression that forces every
// spawn to re-fold the whole graph from scratch), not against absolute wall-clock speed.
final class PerfBudgetTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Integration/Fixtures/integration.neon';

	// Per Latte line: the Latte 2 spawn runs on PHP 7.4, the Latte 3 ones on PHP 8.
	private const CEILING_SECONDS = [
		'2' => 120.0,
		'3.0' => 60.0,
		'3.1' => 60.0,
	];

	public function testWarmSpawnIsFasterThanColdSpawnAndBothSucceed(): void
	{
		$projectRoot = dirname(__DIR__, 3);
		$tmpDir = sys_get_temp_dir() . '/latte-perfbudget-' . getmypid() . '-' . uniqid('', true);

		try {
			$coldStart = hrtime(true);
			[$coldOutput, $coldExitCode] = $this->spawn($projectRoot, $tmpDir);
			$coldDuration = (hrtime(true) - $coldStart) / 1.0e9;

			// Same $tmpDir as the cold spawn above: LattePhpstanConfig's resultCachePath defaults
			// under tmpDir, so this second spawn is a warm/cached run of the first one's analysis.
			$warmStart = hrtime(true);
			[$warmOutput, $warmExitCode] = $this->spawn($projectRoot, $tmpDir);
			$warmDuration = (hrtime(true) - $warmStart) / 1.0e9;

			self::assertTrue(
				in_array($coldExitCode, [0, 1], true),
				"cold spawn must complete without crashing, got exit code {$coldExitCode}. Output: "
				. $this->trimOutput($coldOutput),
			);
			self::assertTrue(
				in_array($warmExitCode, [0, 1], true),
				"warm spawn must complete without crashing, got exit code {$warmExitCode}. Output: "
				. $this->trimOutput($warmOutput),
			);
			self::assertSame(
				$coldOutput,
				$warmOutput,
				'cold and warm spawns over the unchanged fixture directory must agree on the analysis result',
			);

			self::assertLessThan(
				$coldDuration,
				$warmDuration,
				"warm spawn ({$warmDuration}s) must be faster than cold spawn ({$coldDuration}s) - "
				. 'the result cache must be doing work',
			);
			$ceiling = self::CEILING_SECONDS[InstalledVersionsGuard::latteLine()] ?? null;
			self::assertNotNull($ceiling);
			self::assertLessThan(
				$ceiling,
				$coldDuration,
				"cold spawn ({$coldDuration}s) exceeded the perf budget ceiling of {$ceiling}s",
			);
		} finally {
			FileSystem::delete($tmpDir);
		}
	}

	/**
	 * @return array{string, int}
	 */
	private function spawn(string $projectRoot, string $tmpDir): array
	{
		// integration.neon turns narrowing on; LattePhpstanConfig::create() defaults
		// orisaiNette.latte.narrowing.storePath to scratch under $tmpDir, so this spawn never touches the
		// default phpstan-latte-store/.
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[__DIR__ . '/Integration/Fixtures'],
			$tmpDir,
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

		return [$this->normalize($process->getOutput(), $projectRoot), (int) $process->getExitCode()];
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

		sort($lines);

		return implode("\n", $lines) . "\n";
	}

	private function trimOutput(string $output): string
	{
		return (string) substr($output, 0, 500);
	}

}
