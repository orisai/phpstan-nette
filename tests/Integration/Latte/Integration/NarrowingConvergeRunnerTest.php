<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Converge\StoreChangeSignal;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_filter;
use function array_values;
use function dirname;
use function explode;
use function file_exists;
use function glob;
use function implode;
use function preg_match_all;
use function preg_replace;
use function rtrim;
use function sort;
use function str_replace;
use function strpos;
use function uniqid;
use function var_export;
use const PHP_BINARY;
use const SORT_STRING;

final class NarrowingConvergeRunnerTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const STORE_CHANGED = 'The Latte narrowing store changed for ';

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

	public function testATwoLevelChainConvergesInThreeRunsWithoutAFourth(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");

		$converge = $this->converge([], 'table');

		self::assertSame(0, $converge['exitCode'], $converge['output'] . $converge['stderr']);
		self::assertStringContainsString('[OK] No errors', $converge['output']);
		self::assertSame(['2', '3'], $this->reruns($converge['stderr']), $converge['stderr']);
		self::assertSame(['analyse', 'analyse', 'analyse'], $this->invocations());

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

	public function testAPlainRunReportsTheStoreChangeExactlyWhenTheStoreBytesChangedAndTheMarkerIsWritten(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");
		$report = $this->scratch . '/report';

		$observed = [];
		for ($run = 1; $run <= 4; $run++) {
			$before = $this->snapshotStore();
			$plain = $this->phpstan([], [StoreChangeSignal::REPORT_ENVIRONMENT_VARIABLE => $report]);
			$changed = $this->snapshotStore() !== $before;
			$reported = strpos($plain['output'], self::STORE_CHANGED) !== false;
			self::assertSame($changed, $reported, "run $run: " . $plain['output']);
			self::assertSame(
				$reported,
				file_exists($report),
				"run $run: the marker exists exactly when the error is reported",
			);
			FileSystem::delete($report);
			$observed[] = $changed;
		}

		self::assertSame([true, true, false, false], $observed);
	}

	public function testParallelWorkersNeverWriteTheMarker(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");
		$report = $this->scratch . '/report';

		$plain = $this->phpstan(
			['parallel.jobSize' => 1, 'parallel.maximumNumberOfProcesses' => 4, 'parallel.minimumNumberOfJobsPerProcess' => 1],
			[StoreChangeSignal::REPORT_ENVIRONMENT_VARIABLE => $report],
		);

		self::assertSame(1, $plain['exitCode'], $plain['output']);
		self::assertSame(
			self::STORE_CHANGED . '2 including templates: ' . $this->relSrc() . '/A.latte, ' . $this->relSrc() . '/B.latte. '
				. "Run the analysis again until this error disappears, then commit the store.\n",
			FileSystem::read($report),
			'only the coordinator sees both includers; a worker holds one file per job',
		);
	}

	public function testACrashRunsOnceAndIsForwarded(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");
		FileSystem::write(
			$this->scratch . '/crash.php',
			"<?php declare(strict_types = 1);\n\nthrow new RuntimeException('bootstrap crash');\n",
		);

		$converge = $this->converge([], 'raw', [$this->scratch . '/crash.php']);

		self::assertNotSame(0, $converge['exitCode']);
		self::assertStringContainsString('bootstrap crash', $converge['output'] . $converge['stderr']);
		self::assertSame(['analyse'], $this->invocations());
	}

	public function testAPlainRunOnAStaleStoreFailsWithTheStoreChange(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");

		$plain = $this->phpstan();

		self::assertSame(1, $plain['exitCode']);
		self::assertStringContainsString(
			self::STORE_CHANGED . '2 including templates: '
				. $this->relSrc() . '/A.latte, ' . $this->relSrc() . '/B.latte. '
				. 'Run the analysis again until this error disappears, then commit the store.',
			$plain['output'],
		);
	}

	public function testAnIgnoreErrorsEntryDoesNotHideTheStoreChange(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");

		$plain = $this->phpstan([
			'ignoreErrors' => [['identifier' => 'orisai.nette.latte.narrowingStoreChanged']],
			'reportUnmatchedIgnoredErrors' => false,
		]);

		self::assertSame(1, $plain['exitCode']);
		self::assertStringContainsString(self::STORE_CHANGED, $plain['output']);
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
	 * @param list<string> $bootstrapFiles
	 * @param list<string> $paths
	 * @return array{exitCode: int, output: string, stderr: string}
	 */
	private function converge(
		array $options = [],
		string $format = 'raw',
		array $bootstrapFiles = [],
		array $paths = []
	): array
	{
		$counter = $this->scratch . '/counting-phpstan.php';
		FileSystem::write(
			$counter,
			"<?php declare(strict_types = 1);\n\n"
				. 'file_put_contents(' . var_export($this->scratch . '/invocations', true)
				. ", \$argv[1] . \"\\n\", FILE_APPEND);\n"
				. '$process = proc_open(array_merge([PHP_BINARY, ' . var_export($this->phpstanBinary(), true)
				. '], array_slice($argv, 1)), [STDIN, STDOUT, STDERR], $pipes);' . "\n"
				. "exit(proc_close(\$process));\n",
		);
		FileSystem::delete($this->scratch . '/invocations');

		return $this->spawn(
			[PHP_BINARY, $this->projectRoot . '/bin/latte-converge', '--phpstan=' . $counter, ...$options],
			$format,
			[],
			[],
			$bootstrapFiles,
			$paths,
		);
	}

	/**
	 * @param array<string, mixed> $parameters
	 * @param array<string, string> $environment
	 * @return array{exitCode: int, output: string, stderr: string}
	 */
	private function phpstan(array $parameters = [], array $environment = []): array
	{
		return $this->spawn([PHP_BINARY, $this->phpstanBinary()], 'raw', $parameters, $environment);
	}

	private function phpstanBinary(): string
	{
		return $this->projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan';
	}

	/**
	 * @return list<string>
	 */
	private function invocations(): array
	{
		$path = $this->scratch . '/invocations';

		return file_exists($path)
			? array_values(
				array_filter(explode("\n", FileSystem::read($path)), static fn (string $line): bool => $line !== ''),
			)
			: [];
	}

	/**
	 * @param list<string> $command
	 * @param array<string, mixed> $parameters
	 * @param array<string, string> $environment
	 * @param list<string> $bootstrapFiles
	 * @param list<string> $paths
	 * @return array{exitCode: int, output: string, stderr: string}
	 */
	private function spawn(
		array $command,
		string $format,
		array $parameters,
		array $environment = [],
		array $bootstrapFiles = [],
		array $paths = []
	): array
	{
		$config = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$this->srcDir],
			$this->scratch . '/pstmp',
			['orisai.nette.latte.narrowing.storePath' => $this->storeDir] + $parameters,
			$bootstrapFiles,
		);
		FileSystem::createDir($this->scratch . '/tmp');

		$process = new Process(
			[
				...$command,
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=' . $format,
				'-c',
				$config->getConfigPath(),
				...$paths,
			],
			$this->projectRoot,
			[
				'XDEBUG_MODE' => 'off',
				'COMPOSER' => VendorDirectory::composerFile() ?? false,
				'TMPDIR' => $this->scratch . '/tmp',
			] + $environment,
			null,
			null,
		);
		$exitCode = $process->run();

		return [
			'exitCode' => $exitCode,
			'output' => $format === 'raw' ? $this->normalize($process->getOutput()) : $process->getOutput(),
			'stderr' => $process->getErrorOutput(),
		];
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

	/**
	 * @return list<string>
	 */
	private function reruns(string $stderr): array
	{
		preg_match_all('~running the analysis again \(run (\d+) of at most 6\)~', $stderr, $matches);

		return $matches[1];
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
