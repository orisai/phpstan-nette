<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function glob;
use function str_replace;
use function uniqid;
use const PHP_BINARY;

// Naming a .latte file DIRECTLY on the command line makes PHPStan build an
// OptimizedSingleFileSourceLocator over it, and such a locator answers EVERY identifier lookup by
// fetching that one file's nodes through LatteRoutingParser - unlike the directory-mode locator,
// whose class->file map comes from tokenizing raw text and so never reaches a template at all.
// Parsing a template in turn asks the ReflectionProvider about classes (PairingJudge, on a template
// some renderer records), so the parse is re-entered for the file it is already parsing:
// unboundedly, until the C stack overflows and PHP 7.4 (which has no zend.max_allowed_stack_size)
// SIGSEGVs the process with no catchable error at all. Hence assertions on the child's signal and
// exit code - a segfaulted child writes no output to assert on.
/**
 * @group latte2
 */
final class SingleFileReentrancySpawnTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const SIGSEGV = 11;

	public function testATemplateSurvivesTheReflectionLookupsItsOwnParseMakes(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $scratch . '/discovery';

		try {
			$this->writeFixture($srcDir);
			DiscoveryStore::bootstrap($storeDir, ["$relSrc/reentrant.latte"]);

			$parameters = [
				'orisaiNette.latte.discovery.enabled' => true,
				'orisaiNette.latte.discovery.storePath' => $storeDir,
				'orisaiNette.latte.firstPartyPaths' => [$srcDir],
			];

			// Run 1 analyses the whole scratch directory, which is what links the renderer to the
			// template in the store; only then does run 2's single-file parse have a record to
			// resolve, and therefore a PairingJudge reflection lookup to make mid-parse.
			$directoryRun = $this->spawn($projectRoot, [$srcDir], $scratch . '/pstmp', $parameters);
			self::assertNull($directoryRun['signal'], $directoryRun['output']);
			// Non-vacuity: without the renderer in the store, run 2 resolves no template class, makes
			// no reflection call and could never have recursed in the first place.
			self::assertStringContainsString(
				'SpawnReentrantControl',
				$this->storeContents($storeDir),
				'run 1 must have recorded the renderer against the template',
			);

			$singleFileRun = $this->spawn(
				$projectRoot,
				["$srcDir/reentrant.latte"],
				$scratch . '/pstmp',
				$parameters,
			);

			self::assertNotSame(
				self::SIGSEGV,
				$singleFileRun['signal'],
				'analysing a recorded template on its own must not overflow the stack',
			);
			// The other way the guard can go wrong: answering the re-entrant fetch with an empty AST
			// terminates just as well, but the locator latches that empty symbol set for the rest of
			// the run and PHPStan then aborts the very file it was asked to analyse.
			self::assertStringNotContainsString('Internal error', $singleFileRun['output']);
			self::assertSame(0, $singleFileRun['exitCode'], $singleFileRun['output']);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	private function storeContents(string $storeDir): string
	{
		$files = glob($storeDir . '/*.php');
		$contents = '';
		foreach ($files === false ? [] : $files as $file) {
			$contents .= FileSystem::read($file);
		}

		return $contents;
	}

	private function writeFixture(string $srcDir): void
	{
		FileSystem::write($srcDir . '/SpawnReentrantTemplate.php', <<<'PHP'
<?php declare(strict_types = 1);

class SpawnReentrantTemplate extends \Nette\Bridges\ApplicationLatte\Template
{

}

PHP);
		// A class-level template-class candidate is what sends the template's parse into
		// PairingJudge's hasClass() check - the reflection call the locator answers by re-parsing.
		FileSystem::write($srcDir . '/SpawnReentrantControl.php', <<<'PHP'
<?php declare(strict_types = 1);

final class SpawnReentrantControl extends \Nette\Application\UI\Control
{

	protected function createTemplate(?string $class = null): SpawnReentrantTemplate
	{
		return new SpawnReentrantTemplate();
	}

	public function render(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/reentrant.latte');
	}

}

PHP);
		FileSystem::write($srcDir . '/reentrant.latte', "<p>reentrant</p>\n");
	}

	/**
	 * @param list<string> $paths
	 * @param array<string, bool|int|string|list<string>> $parameters
	 * @return array{signal: int|null, exitCode: int|null, output: string}
	 */
	private function spawn(string $projectRoot, array $paths, string $tmpDir, array $parameters): array
	{
		$isolated = LattePhpstanConfig::create(self::REAL_CONFIG_PATH, $paths, $tmpDir, $parameters);

		try {
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

			$signal = null;

			try {
				$process->run();
			} catch (ProcessSignaledException $exception) {
				$signal = $exception->getSignal();
			}

			return [
				'signal' => $signal,
				'exitCode' => $process->getExitCode(),
				'output' => $process->getOutput() . $process->getErrorOutput(),
			];
		} finally {
			$isolated->cleanup();
		}
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir - see LatteDebugDumpIntegrationTest's own note on
		// ProjectRelativePath::relativize.
		$dir = $projectRoot . '/var/tmp/latte-single-file-reentrancy-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

}
