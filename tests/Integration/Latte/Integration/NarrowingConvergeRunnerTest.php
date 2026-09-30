<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Converge\StoreDigest;
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
		self::assertSame(['dump-parameters', 'analyse', 'analyse', 'analyse'], $this->invocations());

		$store = StoreDigest::of($this->storeDir);
		$warm = $this->phpstan();
		self::assertSame(0, $warm['exitCode'], $warm['output']);
		self::assertSame("(no errors)\n", $warm['output']);
		self::assertSame($store, StoreDigest::of($this->storeDir));

		FileSystem::delete($this->scratch . '/pstmp/resultCache.php');
		$cold = $this->phpstan();
		self::assertSame($warm, $cold);
		self::assertSame($store, StoreDigest::of($this->storeDir));

		$again = $this->converge();
		self::assertSame(0, $again['exitCode']);
		self::assertSame([], $this->reruns($again['stderr']), $again['stderr']);
	}

	public function testAPlainRunReportsTheStoreChangeExactlyWhenTheStoreBytesChanged(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");

		$observed = [];
		for ($run = 1; $run <= 4; $run++) {
			$before = StoreDigest::of($this->storeDir);
			$plain = $this->phpstan();
			$changed = StoreDigest::of($this->storeDir) !== $before;
			$reported = strpos($plain['output'], self::STORE_CHANGED) !== false;
			self::assertSame($changed, $reported, "run $run: " . $plain['output']);
			$observed[] = $changed;
		}

		self::assertSame([true, true, false, false], $observed);
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
		self::assertCount(3, StoreDigest::of($this->storeDir)['slices']);
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
	private function converge(array $options = [], string $format = 'raw'): array
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
		);
	}

	/**
	 * @param array<string, mixed> $parameters
	 * @return array{exitCode: int, output: string, stderr: string}
	 */
	private function phpstan(array $parameters = []): array
	{
		return $this->spawn([PHP_BINARY, $this->phpstanBinary()], 'raw', $parameters);
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
	 * @return array{exitCode: int, output: string, stderr: string}
	 */
	private function spawn(array $command, string $format, array $parameters): array
	{
		$config = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$this->srcDir],
			$this->scratch . '/pstmp',
			['orisai.nette.latte.narrowing.storePath' => $this->storeDir] + $parameters,
		);

		$process = new Process(
			[
				...$command,
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=' . $format,
				'-c',
				$config->getConfigPath(),
			],
			$this->projectRoot,
			['XDEBUG_MODE' => 'off', 'COMPOSER' => VendorDirectory::composerFile() ?? false],
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
