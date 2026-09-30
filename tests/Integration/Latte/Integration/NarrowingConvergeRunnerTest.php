<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function explode;
use function glob;
use function implode;
use function preg_match_all;
use function preg_replace;
use function rtrim;
use function sort;
use function str_replace;
use function uniqid;
use const PHP_BINARY;
use const SORT_STRING;

final class NarrowingConvergeRunnerTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private string $projectRoot;

	private string $scratch;

	private string $srcDir;

	private string $storeDir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->projectRoot = dirname(__DIR__, 4);
		$this->scratch = $this->projectRoot . '/var/tmp/latte-converge-runner-test-' . uniqid('', true);
		$this->srcDir = $this->scratch . '/src';
		$this->storeDir = $this->srcDir . '/sitescope';
	}

	protected function tearDown(): void
	{
		FileSystem::delete($this->scratch);
		parent::tearDown();
	}

	public function testATwoLevelChainConvergesInThreeRunsAndStaysSettled(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");

		$converge = $this->converge();

		self::assertSame(0, $converge['exitCode'], $converge['output'] . $converge['stderr']);
		self::assertSame("(no errors)\n", $converge['output']);
		self::assertSame(['2', '3'], $this->reruns($converge['stderr']), $converge['stderr']);

		$store = $this->snapshotStore();
		$warm = $this->phpstan();
		self::assertSame(0, $warm['exitCode'], $warm['output']);
		self::assertSame("(no errors)\n", $warm['output']);
		self::assertSame($store, $this->snapshotStore());

		FileSystem::delete($this->scratch . '/pstmp/resultCache.php');
		$cold = $this->phpstan();
		self::assertSame($warm, $cold);
		self::assertSame($store, $this->snapshotStore());

		$again = $this->converge();
		self::assertSame(0, $again['exitCode']);
		self::assertSame([], $this->reruns($again['stderr']), $again['stderr']);
	}

	public function testAPlainRunOnAStaleStoreFailsWithTheStoreChange(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");

		$plain = $this->phpstan();

		self::assertSame(1, $plain['exitCode']);
		self::assertStringContainsString(
			'The Latte narrowing store changed for 2 including templates: '
				. $this->relSrc() . '/A.latte, ' . $this->relSrc() . '/B.latte. '
				. 'Run the analysis again until this error disappears, then commit the store.',
			$plain['output'],
		);
	}

	public function testAFindingCoexistingWithAStoreChangeIsReportedOnceTheStoreSettles(): void
	{
		$this->writeChain("{\$x->getMessage()}\n{\$x->nope()}\n");

		$converge = $this->converge();

		self::assertSame(1, $converge['exitCode'], $converge['output'] . $converge['stderr']);
		self::assertSame(['2', '3'], $this->reruns($converge['stderr']), $converge['stderr']);
		self::assertStringNotContainsString('narrowing store', $converge['output']);
		self::assertStringNotContainsString('Exception|null', $converge['output']);
		self::assertStringContainsString('Call to an undefined method Exception::nope().', $converge['output']);
	}

	public function testPruneRemovesSlicesOfDeletedTemplatesOnly(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");
		SiteScopeStore::bootstrap($this->storeDir, [$this->relSrc() . '/Deleted.latte']);
		$orphan = (new SiteScopeStore($this->storeDir))->slicePath($this->relSrc() . '/Deleted.latte');

		$converge = $this->converge();
		self::assertSame(0, $converge['exitCode'], $converge['output'] . $converge['stderr']);
		self::assertFileExists($orphan);

		$prune = $this->converge(['--prune']);
		self::assertSame(0, $prune['exitCode'], $prune['output'] . $prune['stderr']);
		self::assertSame("(no errors)\n", $prune['output']);
		self::assertSame(['2'], $this->reruns($prune['stderr']), $prune['stderr']);
		self::assertFileDoesNotExist($orphan);
		self::assertCount(3, $this->snapshotStore());
	}

	private function writeChain(string $leaf): void
	{
		FileSystem::write(
			$this->srcDir . '/A.latte',
			"{varType Exception|null \$x}\n{if \$x !== null}\n\t{include 'B.latte'}\n{/if}\n",
		);
		FileSystem::write($this->srcDir . '/B.latte', "{include 'C.latte'}\n");
		FileSystem::write($this->srcDir . '/C.latte', $leaf);

		$relSrc = $this->relSrc();
		SiteScopeStore::bootstrap($this->storeDir, ["$relSrc/A.latte", "$relSrc/B.latte", "$relSrc/C.latte"]);
	}

	private function relSrc(): string
	{
		return str_replace($this->projectRoot . '/', '', $this->srcDir);
	}

	/**
	 * @param list<string> $options
	 * @return array{exitCode: int, output: string, stderr: string}
	 */
	private function converge(array $options = []): array
	{
		return $this->spawn([
			PHP_BINARY,
			$this->projectRoot . '/bin/latte-converge',
			'--phpstan=' . $this->phpstanBinary(),
			...$options,
		]);
	}

	/**
	 * @return array{exitCode: int, output: string, stderr: string}
	 */
	private function phpstan(): array
	{
		return $this->spawn([PHP_BINARY, $this->phpstanBinary()]);
	}

	private function phpstanBinary(): string
	{
		return $this->projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan';
	}

	/**
	 * @param list<string> $command
	 * @return array{exitCode: int, output: string, stderr: string}
	 */
	private function spawn(array $command): array
	{
		$config = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$this->srcDir],
			$this->scratch . '/pstmp',
			['orisai.nette.latte.narrowing.storePath' => $this->storeDir],
		);

		$process = new Process(
			[
				...$command,
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=raw',
				'-c',
				$config->getConfigPath(),
			],
			$this->projectRoot,
			['XDEBUG_MODE' => 'off', 'COMPOSER' => VendorDirectory::composerFile() ?? false],
		);
		$exitCode = $process->run();

		return [
			'exitCode' => $exitCode,
			'output' => $this->normalize($process->getOutput()),
			'stderr' => $process->getErrorOutput(),
		];
	}

	/**
	 * @return list<string>
	 */
	private function reruns(string $stderr): array
	{
		preg_match_all('~running the analysis again \(run (\d+) of at most 6\)~', $stderr, $matches);

		return $matches[1];
	}

	/**
	 * @return array<string, string>
	 */
	private function snapshotStore(): array
	{
		$snapshot = [];
		$files = glob($this->storeDir . '/LatteSlice_*.php');
		foreach ($files === false ? [] : $files as $file) {
			$snapshot[$file] = FileSystem::read($file);
		}

		return $snapshot;
	}

	private function normalize(string $raw): string
	{
		$raw = (string) preg_replace('/ \[identifier=[^\]]+\]$/m', '', $raw);
		$lines = [];
		foreach (explode("\n", str_replace($this->projectRoot . '/', '', $raw)) as $line) {
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
