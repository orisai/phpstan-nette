<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_keys;
use function basename;
use function dirname;
use function explode;
use function glob;
use function implode;
use function preg_replace;
use function rtrim;
use function sort;
use function str_replace;
use function strpos;
use function uniqid;
use const PHP_BINARY;
use const SORT_STRING;

final class NarrowingStoreRunsTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const STORE_CHANGED = 'The Latte narrowing store changed for ';

	private const RUN_AGAIN = 'Run PHPStan again until this error is gone, then commit the regenerated store files.';

	private string $projectRoot;

	private string $scratch;

	private string $srcDir;

	private string $storeDir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->projectRoot = dirname(__DIR__, 4);
		$this->scratch = $this->projectRoot . '/var/tmp/latte-store-runs-test-' . uniqid('', true);
		$this->srcDir = $this->scratch . '/src';
		$this->storeDir = $this->srcDir . '/sitescope';
	}

	protected function tearDown(): void
	{
		FileSystem::delete($this->scratch);
		parent::tearDown();
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public function provideRuleErrorReuse(): iterable
	{
		yield 'rules on collected data always run' => [[]];
		yield 'their errors are reused' => [['featureToggles.reuseCollectedDataRuleErrors' => true]];
	}

	/**
	 * @param array<string, mixed> $parameters
	 *
	 * @dataProvider provideRuleErrorReuse
	 */
	public function testAPlainRunReportsTheStoreChangeExactlyWhenTheStoreBytesChanged(array $parameters): void
	{
		$this->writeChain("{\$x->getMessage()}\n");

		self::assertSame([true, true, false, false], $this->runsUntilSettled($parameters, 4));

		$store = $this->snapshotStore();
		FileSystem::delete($this->scratch . '/pstmp/resultCache.php');
		$cold = $this->phpstan($parameters);
		self::assertSame(0, $cold['exitCode'], $cold['output']);
		self::assertSame("(no errors)\n", $cold['output']);
		self::assertSame($store, $this->snapshotStore());
	}

	public function testAReusedRunNeverSkipsAStoreWrite(): void
	{
		$reuse = ['featureToggles.reuseCollectedDataRuleErrors' => true];
		$this->writeChain("{\$x->getMessage()}\n");
		$this->runsUntilSettled($reuse, 3);
		$settled = $this->snapshotStore();
		$this->phpstan($reuse);

		FileSystem::write(
			$this->srcDir . '/A.latte',
			"{varType RuntimeException|null \$x}\n{if \$x !== null}\n\t{include 'B.latte'}\n{/if}\n",
		);
		$before = $this->snapshotStore();
		self::assertSame([true], $this->runsUntilSettled($reuse, 1));
		$moved = $this->snapshotStore();
		self::assertNotSame($before, $moved);

		foreach ($before as $file => $contents) {
			FileSystem::write($file, $contents);
		}

		self::assertSame([true], $this->runsUntilSettled($reuse, 1), 'a store reverted by hand is written again');
		self::assertSame($moved, $this->snapshotStore());
		self::assertNotSame($settled, $moved);
	}

	public function testAnAbsentStoreDirectoryIsCreatedByTheFirstRun(): void
	{
		$this->writeTemplates("{\$x->getMessage()}\n");
		self::assertDirectoryDoesNotExist($this->storeDir);

		$first = $this->phpstan();
		self::assertSame(1, $first['exitCode'], $first['output']);
		self::assertStringContainsString(self::STORE_CHANGED . '3 including templates: ', $first['output']);
		self::assertSame(
			['A.latte', 'B.latte', 'C.latte'],
			$this->storeIncluders(),
			'every analysed template gets a slice',
		);

		self::assertSame([true, false], $this->runsUntilSettled([], 2));
		$this->assertSettledOnAChainStore();
	}

	public function testAPlainRunOnAStaleStoreFailsWithTheStoreChange(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");

		$plain = $this->phpstan();

		self::assertSame(1, $plain['exitCode']);
		self::assertStringContainsString(
			self::STORE_CHANGED . '2 including templates: '
				. $this->relSrc() . '/A.latte, ' . $this->relSrc() . '/B.latte. ' . self::RUN_AGAIN,
			$plain['output'],
		);
	}

	public function testOnlyTheCoordinatorWritesTheStore(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");

		$plain = $this->phpstan(
			['parallel.jobSize' => 1, 'parallel.maximumNumberOfProcesses' => 4, 'parallel.minimumNumberOfJobsPerProcess' => 1],
		);

		self::assertSame(1, $plain['exitCode'], $plain['output']);
		self::assertStringContainsString(
			self::STORE_CHANGED . '2 including templates: ' . $this->relSrc() . '/A.latte, ' . $this->relSrc() . '/B.latte. ',
			$plain['output'],
			'only the coordinator sees both includers; a worker holds one file per job',
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

		$this->runsUntilSettled([], 3);
		$settled = $this->phpstan();

		self::assertSame(1, $settled['exitCode'], $settled['output']);
		self::assertStringNotContainsString('narrowing store', $settled['output']);
		self::assertStringNotContainsString('Exception|null', $settled['output']);
		self::assertStringContainsString('Call to an undefined method Exception::nope().', $settled['output']);
	}

	/**
	 * @param array<string, mixed> $parameters
	 *
	 * @dataProvider provideRuleErrorReuse
	 */
	public function testAWarmRunPrunesTheSliceOfADeletedTemplate(array $parameters): void
	{
		$this->writeChain("{\$x->getMessage()}\n");
		FileSystem::write($this->srcDir . '/D.latte', "{include 'C.latte'}\n");
		$this->runsUntilSettled($parameters, 4);
		self::assertSame(['A.latte', 'B.latte', 'C.latte', 'D.latte'], $this->storeIncluders());

		FileSystem::delete($this->srcDir . '/D.latte');
		$warm = $this->phpstan($parameters);

		self::assertSame(1, $warm['exitCode'], $warm['output']);
		self::assertStringContainsString(
			'The Latte narrowing store removed 1 slice of deleted templates: LatteSlice_',
			$warm['output'],
		);
		self::assertSame(['A.latte', 'B.latte', 'C.latte'], $this->storeIncluders());
		self::assertSame([false], $this->runsUntilSettled($parameters, 1));
		$this->assertSettledOnAChainStore();
	}

	public function testARunOverPartOfThePathsKeepsTheSlicesOfTemplatesItDidNotAnalyse(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");
		$this->runsUntilSettled([], 4);
		$store = $this->snapshotStore();

		$partial = $this->phpstan([], [$this->srcDir . '/C.latte']);

		self::assertStringNotContainsString('narrowing store', $partial['output']);
		self::assertSame($store, $this->snapshotStore());
	}

	public function testASliceOfADeletedTemplateFromAnOlderStoreIsPruned(): void
	{
		$this->writeChain("{\$x->getMessage()}\n");
		$deleted = $this->relSrc() . '/Deleted.latte';
		SiteScopeStore::bootstrap($this->storeDir, [$deleted]);
		$orphan = (new SiteScopeStore($this->storeDir))->slicePath($deleted);
		FileSystem::write(
			$orphan,
			(string) preg_replace('~^\t\tpublic const INCLUDER = .*\n\n~m', '', FileSystem::read($orphan)),
		);

		$partial = $this->phpstan(
			[],
			[$this->srcDir . '/A.latte', $this->srcDir . '/B.latte', $this->srcDir . '/C.latte'],
		);
		self::assertSame(1, $partial['exitCode'], $partial['output']);
		self::assertFileExists(
			$orphan,
			'an empty slice without the header is pruned only by a run over the configured paths',
		);

		$full = $this->phpstan();
		self::assertStringContainsString(
			'The Latte narrowing store removed 1 slice of deleted templates: ',
			$full['output'],
		);
		self::assertFileDoesNotExist($orphan);
	}

	private function assertSettledOnAChainStore(): void
	{
		$settled = $this->phpstan();
		self::assertSame(0, $settled['exitCode'], $settled['output']);
		self::assertSame("(no errors)\n", $settled['output']);
	}

	/**
	 * @param array<string, mixed> $parameters
	 * @return list<bool>
	 */
	private function runsUntilSettled(array $parameters, int $runs): array
	{
		$observed = [];
		for ($run = 1; $run <= $runs; $run++) {
			$before = $this->snapshotStore();
			$plain = $this->phpstan($parameters);
			$changed = $this->snapshotStore() !== $before;
			self::assertSame(
				$changed,
				strpos($plain['output'], 'The Latte narrowing store ') !== false,
				"run $run: the store change is reported exactly when the store bytes moved: " . $plain['output'],
			);
			$observed[] = $changed;
		}

		return $observed;
	}

	/**
	 * @return list<string>
	 */
	private function storeIncluders(): array
	{
		$includers = [];
		foreach (array_keys($this->snapshotStore()) as $file) {
			foreach (['A', 'B', 'C', 'D'] as $name) {
				if (strpos(basename($file), '_' . $name . '_latte_') !== false) {
					$includers[] = $name . '.latte';
				}
			}
		}

		sort($includers, SORT_STRING);

		return $includers;
	}

	private function writeTemplates(string $leaf): void
	{
		FileSystem::write(
			$this->srcDir . '/A.latte',
			"{varType Exception|null \$x}\n{if \$x !== null}\n\t{include 'B.latte'}\n{/if}\n",
		);
		FileSystem::write($this->srcDir . '/B.latte', "{include 'C.latte'}\n");
		FileSystem::write($this->srcDir . '/C.latte', $leaf);
	}

	private function writeChain(string $leaf): void
	{
		$this->writeTemplates($leaf);

		$relSrc = $this->relSrc();
		SiteScopeStore::bootstrap($this->storeDir, ["$relSrc/A.latte", "$relSrc/B.latte", "$relSrc/C.latte"]);
	}

	private function relSrc(): string
	{
		return str_replace($this->projectRoot . '/', '', $this->srcDir);
	}

	/**
	 * @param array<string, mixed> $parameters
	 * @param list<string> $paths
	 * @return array{exitCode: int, output: string}
	 */
	private function phpstan(array $parameters = [], array $paths = []): array
	{
		$config = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$this->srcDir],
			$this->scratch . '/pstmp',
			['orisai.nette.latte.narrowing.storePath' => $this->storeDir] + $parameters,
		);

		$process = new Process(
			[
				PHP_BINARY,
				$this->projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=raw',
				'-c',
				$config->getConfigPath(),
				...$paths,
			],
			$this->projectRoot,
			[
				'XDEBUG_MODE' => 'off',
				'COMPOSER' => VendorDirectory::composerFile() ?? false,
			],
			null,
			null,
		);
		$exitCode = $process->run();

		return ['exitCode' => $exitCode, 'output' => $this->normalize($process->getOutput())];
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
