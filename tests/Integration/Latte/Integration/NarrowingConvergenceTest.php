<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function count;
use function dirname;
use function explode;
use function glob;
use function implode;
use function preg_match;
use function preg_replace;
use function rtrim;
use function sort;
use function str_replace;
use function uniqid;
use const PHP_BINARY;
use const SORT_STRING;

// Convergence: plain repeated `make phpstan`
// runs, warm result cache kept throughout (the whole point of the slice-file design - no
// clearing), converge within depth+1 runs. This depth-3 chain (A -> B -> C, narrowing at each
// hop: A's own {if $x !== null} guard narrows the edge it captures for B; B forwards $x on
// unguarded, so its own capture and - independently, via the SAME ambient-context-propagation
// EdgeScope/ContextResolver already use - the already-overlaid context it hands to C, both
// narrow together) does NOT need a hand-derived expectation: the test itself repeats plain warm
// runs and stops at the first pair of byte-identical slice-directory snapshots - convergence
// defined operationally, as the fixpoint of the store itself.
/**
 * @group latte2
 */
final class NarrowingConvergenceTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	// D=2 edges (A->B, B->C); the bound is depth+1 counting the initial file, i.e. 3 nodes -> 4.
	private const MAX_RUNS = 4;

	public function testDepthThreeChainConvergesWithinDepthPlusOneWarmRunsWithShrinkingReanalysis(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $srcDir . '/sitescope';
		$tmpDir = $scratch . '/pstmp';

		try {
			$this->writeChainFixture($srcDir);

			// Slices seeded empty (mirrors
			// `make phpstan-narrowing-init`): the target->slice dependency edge must exist BEFORE
			// any capture can propagate through it.
			SiteScopeStore::bootstrap($storeDir, [
				"$relSrc/A.latte",
				"$relSrc/B.latte",
				"$relSrc/C.latte",
			]);

			$previousSnapshot = null;
			$warmReanalysisCounts = [];
			$lastOutput = null;
			$convergedAtRun = null;

			for ($run = 1; $run <= self::MAX_RUNS; $run++) {
				$result = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
				$lastOutput = $result['output'];

				if ($run > 1) {
					$warmReanalysisCounts[] = $this->reanalysisCount($result['diagnostics']);
				}

				$snapshot = $this->snapshotStore($storeDir);
				if ($previousSnapshot !== null && $snapshot === $previousSnapshot) {
					$convergedAtRun = $run;

					break;
				}

				$previousSnapshot = $snapshot;
			}

			self::assertNotNull(
				$convergedAtRun,
				'slice directory must reach byte-stability (two consecutive identical plain warm '
				. 'runs) within the depth+1 bound of ' . self::MAX_RUNS . ' runs; reanalysis counts '
				. 'observed: ' . implode(', ', $warmReanalysisCounts),
			);
			self::assertLessThanOrEqual(
				self::MAX_RUNS,
				$convergedAtRun,
				'a depth-3 chain must converge in at most depth+1 = ' . self::MAX_RUNS . ' plain warm runs',
			);
			self::assertGreaterThan(
				1,
				$convergedAtRun,
				'sanity check: convergence must take a REAL propagation run, never the cold run alone',
			);

			self::assertSame(
				"(no errors)\n",
				$lastOutput,
				'the converged, fully-narrowed state must show zero errors - $x narrows to non-null '
				. 'Exception through both hops: ' . $lastOutput,
			);

			// One more plain warm run after the declared convergence point: proves the slice dir
			// and the result are durably stable, never a one-off fluke, and gives the fully-settled
			// "0 files will be reanalysed" confirmation that results stay identical thereafter.
			$confirmRun = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
			$warmReanalysisCounts[] = $this->reanalysisCount($confirmRun['diagnostics']);

			self::assertSame(
				$lastOutput,
				$confirmRun['output'],
				'one more plain warm run after convergence must produce a byte-identical result',
			);
			self::assertSame(
				$previousSnapshot,
				$this->snapshotStore($storeDir),
				'one more plain warm run after convergence must leave the slice directory byte-identical',
			);

			// Reanalysis counts must never grow across the warm-run sequence, and the sequence
			// must genuinely shrink (not stay flat at zero, which would mean the chain never
			// narrowed anything in the first place) all the way down to the confirmed fixpoint.
			for ($i = 1; $i < count($warmReanalysisCounts); $i++) {
				self::assertLessThanOrEqual(
					$warmReanalysisCounts[$i - 1],
					$warmReanalysisCounts[$i],
					'reanalysis counts must shrink (never grow) across the warm-run sequence: '
					. implode(', ', $warmReanalysisCounts),
				);
			}

			self::assertGreaterThan(
				0,
				$warmReanalysisCounts[0],
				'the first warm run must show genuine (nonzero) reanalysis - otherwise this fixture '
				. 'never exercised the propagation mechanism at all',
			);
			self::assertSame(
				0,
				$warmReanalysisCounts[count($warmReanalysisCounts) - 1],
				'the confirming run must show zero reanalysis - the true, settled fixpoint: '
				. implode(', ', $warmReanalysisCounts),
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// A depth-3 chain (2 edges), narrowing $x: Exception|null down to non-null Exception across
	// both hops. A declares $x and narrows it locally before its own {include}; B does NOT
	// declare $x (ambient/provided, forwarded on unguarded) and includes C the same way; C does
	// not declare $x either, so its only source of a non-null type is the two-hop capture chain -
	// {$x->getMessage()} is a genuine error (Cannot call method on Exception|null) until both
	// hops have contributed.
	private function writeChainFixture(string $srcDir): void
	{
		FileSystem::write(
			$srcDir . '/A.latte',
			"{varType Exception|null \$x}\n{if \$x !== null}\n\t{include 'B.latte'}\n{/if}\n",
		);
		FileSystem::write($srcDir . '/B.latte', "{include 'C.latte'}\n");
		FileSystem::write($srcDir . '/C.latte', "{\$x->getMessage()}\n");
	}

	/**
	 * @return array<string, string>
	 */
	private function snapshotStore(string $storeDir): array
	{
		$snapshot = [];
		$files = glob($storeDir . '/LatteSlice_*.php');
		foreach ($files === false ? [] : $files as $file) {
			$snapshot[$file] = FileSystem::read($file);
		}

		return $snapshot;
	}

	// -vv's own diagnostic line ("Result cache restored. N files will be reanalysed.") is the
	// authoritative source for this count (same mechanism ResultCacheInvalidationTest's payoff
	// test reads); a cold run (no cache yet) never prints it, hence callers only probe this from
	// the second run onward.
	private function reanalysisCount(string $diagnostics): int
	{
		if (preg_match('/(\d+) files will be reanalysed/', $diagnostics, $m) !== 1) {
			self::fail("expected a warm-run reanalysis diagnostic, found none: $diagnostics");
		}

		return (int) $m[1];
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir: ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file) - a path outside $projectRoot never
		// relativizes, breaking every include-target/edge lookup that assumes project-relative
		// paths.
		$dir = $projectRoot . '/var/tmp/latte-narrowing-convergence-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

	/**
	 * @return array{output: string, diagnostics: string}
	 */
	private function spawn(string $projectRoot, string $srcDir, string $tmpDir, string $storeDir): array
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			['orisai.nette.latte.narrowing.storePath' => $storeDir],
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
