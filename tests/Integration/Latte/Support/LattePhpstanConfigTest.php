<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Support;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function glob;
use function uniqid;
use const PHP_BINARY;

// Pins the structural isolation default LattePhpstanConfig::create() now provides: a caller that
// never thinks about orisaiNette.latte.narrowing.storePath at all (no extraParameters entry) must still be
// impossible to point at the default phpstan-latte-store/ store - the exact
// pollution bug 4 call sites had to be individually patched for before this default existed.
final class LattePhpstanConfigTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/../Integration/Fixtures/integration.neon';

	public function testCreateWithoutExplicitStorePathWritesOnlyUnderScratch(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$realStoreDir = $projectRoot . '/phpstan-latte-store';
		$realStoreSnapshotBefore = $this->snapshotStore($realStoreDir);

		$scratch = $projectRoot . '/var/tmp/latte-phpstan-config-test-' . uniqid('', true);
		$srcDir = $scratch . '/src';

		try {
			FileSystem::write($srcDir . '/target.latte', "{\$x}\n");
			FileSystem::write($srcDir . '/includer.latte', "{include 'target.latte', x: 1}\n");

			// No orisaiNette.latte.narrowing.storePath entry at all - the call under test.
			$isolated = LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				[$srcDir],
				$scratch . '/pstmp',
			);

			$defaultStoreDir = $isolated->getTmpDir() . '/sitescope-unused';
			// LatteSiteScopeWriterRule only ever writes into a store directory that already exists
			// (same precondition NarrowingInitTest pins for an explicit store path) -
			// mkdir it so this test can tell "isolated" from "the writer silently no-op'd because
			// nothing existed to write into".
			FileSystem::createDir($defaultStoreDir);

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
			$process->setTimeout(120.0);
			$process->run();

			$scratchSlices = glob($defaultStoreDir . '/LatteSlice_*.php');
			self::assertNotSame(
				[],
				$scratchSlices === false ? [] : $scratchSlices,
				'sanity check: the spawn must have actually written narrowing slices SOMEWHERE, '
				. 'otherwise this test cannot tell isolation from a no-op: ' . $process->getOutput()
				. $process->getErrorOutput(),
			);

			self::assertSame(
				$realStoreSnapshotBefore,
				$this->snapshotStore($realStoreDir),
				'a spawn with no explicit orisaiNette.latte.narrowing.storePath must never write into the real '
				. 'committed store',
			);
		} finally {
			FileSystem::delete($scratch);
		}
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

}
