<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\SliceClassName;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_map;
use function dirname;
use function glob;
use function pathinfo;
use function sort;
use function uniqid;
use const PATHINFO_FILENAME;
use const PHP_BINARY;
use const SORT_STRING;

// An absent store directory needs no init step: the first plain run creates it with one slice
// per template of the universe LatteUniverse derives from the resolved %paths% - every analysed
// includer gets a slice (SiteScopeStore::replaceForIncluders()), empty or not, because
// LatteAnalyzedFileMarkerCollector marks each analysed .latte file.
/**
 * @group latte2
 */
final class NarrowingInitTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	public function testTheFirstRunCreatesExactlyOneSlicePerLatteFileInTheResolvedUniverse(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$storeDir = $srcDir . '/sitescope';

		try {
			FileSystem::write($srcDir . '/a.latte', "hello\n");
			FileSystem::write($srcDir . '/nested/b.latte', "world\n");
			FileSystem::write($srcDir . '/c.latte', "{include 'a.latte'}\n");

			self::assertDirectoryDoesNotExist($storeDir);

			$isolated = LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				[$srcDir],
				$scratch . '/pstmp',
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
					'-c',
					$isolated->getConfigPath(),
				],
				$projectRoot,
			);
			$process->setTimeout(120.0);
			$process->run();

			// The SAME resolution LatteUniverse itself uses in production (wired off %paths%) -
			// not a re-derivation, the authoritative comparison source.
			$universe = new LatteUniverse([$srcDir], $projectRoot);
			$expectedClassNames = array_map(
				static fn (string $file): string => SliceClassName::forPath($universe->relativePath($file)),
				$universe->files(),
			);
			sort($expectedClassNames, SORT_STRING);

			self::assertNotSame([], $expectedClassNames, 'sanity check: fixture must contain real .latte files');

			$sliceFiles = glob($storeDir . '/LatteSlice_*.php');
			$actualClassNames = array_map(
				static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
				$sliceFiles === false ? [] : $sliceFiles,
			);
			sort($actualClassNames, SORT_STRING);

			self::assertSame(
				$expectedClassNames,
				$actualClassNames,
				'the created slice set must match the analysis-time universe exactly - neither '
				. 'missing a real includer nor materializing a phantom one: ' . $process->getOutput()
				. $process->getErrorOutput(),
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir: ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file) - a path outside $projectRoot never
		// relativizes, breaking every include-target/edge lookup that assumes project-relative
		// paths.
		$dir = $projectRoot . '/var/tmp/latte-narrowing-init-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

}
