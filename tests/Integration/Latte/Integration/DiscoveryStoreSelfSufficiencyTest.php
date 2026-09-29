<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Cache\LatteResultCacheMeta;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function explode;
use function implode;
use function is_array;
use function preg_match;
use function sort;
use function str_replace;
use function strlen;
use function strncmp;
use function strpos;
use function substr;
use function uniqid;
use function unserialize;
use const PHP_BINARY;

// THE SELF-SUFFICIENCY INVARIANT: a run against an EMPTY discovery store must find exactly what a
// run against a warm one finds. Every store consumer that decides a template's scope is parse-time,
// so while the store was written at the aggregate stage the store a run READ was the previous run's
// - run 1 knew no template->renderer link and reported every factory-provided variable as undefined,
// and only run 2 agreed with reality. Committing the store to git is what used to hide that.
//
// The corpus is FactoryProvidedVarsIntegrationTest's: the factory-provided scope is the store
// consumer whose verdict a missing link actually moves, and $undefinedVar rides along so that
// "identical" can never be satisfied by a run that reported nothing at all.
/**
 * @group latte2
 */
final class DiscoveryStoreSelfSufficiencyTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const RESTORED = 'Result cache restored. 0 files will be reanalysed.';

	public function testAnEmptyStoreProducesTheSameFindingsAsAWarmOne(): void
	{
		$this->withCorpus(function (string $projectRoot, string $srcDir, string $storeDir, string $relSrc): void {
			$scratch = dirname($srcDir);

			// Emptied completely, and EXISTING: the aggregate writer returns early on an absent
			// directory, so a run against a directory that was never created is poor for a second,
			// unrelated reason and the two runs would agree at the poor answer. A tmpDir per spawn
			// keeps the result cache, the compile cache and the per-class fact cache from carrying
			// anything between them, so the STORE is the only state the two share.
			FileSystem::createDir($storeDir);

			$cold = $this->spawn($projectRoot, $srcDir, $scratch . '/tmp-cold', $storeDir);
			$warm = $this->spawn($projectRoot, $srcDir, $scratch . '/tmp-warm', $storeDir);

			self::assertSame(
				$warm['findings'],
				$cold['findings'],
				"a run against an empty store must find exactly what a run against a warm one finds\n"
				. 'cold: ' . implode("\n", $cold['findings']) . "\nwarm: " . implode("\n", $warm['findings']),
			);
			// Pins WHICH findings the two runs agree about. Identity alone is satisfiable by two
			// equally poor runs; these two say the agreement is at the RICH answer.
			// 3.2 leaves an unwired $user undefined, so the link shows on the renderer's own $control.
			self::assertNotContains(
				InstalledVersionsGuard::satisfies('nette/application', '>=3.2')
					? "$relSrc/tpl.latte:5 :: Undefined variable: \$control :: variable.undefined"
					: "$relSrc/tpl.latte:1 :: Undefined variable: \$user :: variable.undefined",
				$cold['findings'],
				'the first run must already read the link its own pre-analysis build wrote: '
				. implode("\n", $cold['findings']),
			);
			self::assertContains(
				"$relSrc/tpl.latte:7 :: Undefined variable: \$undefinedVar :: variable.undefined",
				$cold['findings'],
				'the corpus must keep reporting genuinely undefined variables, or identity proves nothing',
			);
		});
	}

	// The placement's whole safety story. A meta extension's RETURNED HASH drives a TOTAL
	// result-cache wipe when it moves, so the build has to be a side effect of that phase and never a
	// term in its value. Run 1 derives the whole index from nothing, which is the largest change the
	// build can possibly make; every later run's hash must still be that run's, byte for byte.
	//
	// Run 2 does legitimately reanalyse files - the store files run 1 created did not exist when its
	// analysed set was assembled, so this is the first run that sees them at all, and PHPStan's own
	// scannedFiles bookkeeping moves once for it. What must NOT move is metaExtensions.
	public function testTheBuildNeverMovesTheMetaHashAndTheCacheIsRestored(): void
	{
		$this->withCorpus(function (string $projectRoot, string $srcDir, string $storeDir): void {
			$tmpDir = dirname($srcDir) . '/pstmp';

			$this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
			$first = $this->metaHash($tmpDir);

			$second = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertSame(
				$first,
				$this->metaHash($tmpDir),
				'the run after the one that built the index from nothing must return the identical hash',
			);
			self::assertStringNotContainsString(
				'metaExtensions',
				$second['diagnostics'],
				'no meta extension may be among the reasons a run discards its cache: '
				. $second['diagnostics'],
			);

			for ($run = 3; $run <= 4; $run++) {
				$settled = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
				self::assertSame($first, $this->metaHash($tmpDir), "run $run must return the identical hash");
				self::assertStringContainsString(
					self::RESTORED,
					$settled['diagnostics'],
					"an unchanged tree must restore the whole result cache on run $run: " . $settled['diagnostics'],
				);
			}
		});
	}

	// The opt-out has to reach the build, not just the store's readers: a consumer that turned the
	// discovery store off has no business paying for a Latte index build, and a build behind no flag
	// would walk its whole configured universe on every run for an index nothing reads.
	public function testTheDisabledFlagSuppressesTheBuildEntirely(): void
	{
		$this->withCorpus(function (string $projectRoot, string $srcDir, string $storeDir): void {
			$this->spawn($projectRoot, $srcDir, dirname($srcDir) . '/pstmp-off', $storeDir, false);

			self::assertDirectoryDoesNotExist(
				$storeDir,
				'a run with the store disabled must write nothing at all - not even the directory',
			);
		});
	}

	// Straight out of the result cache file the run just wrote - the returned hash itself, not a
	// diagnostic that paraphrases it.
	private function metaHash(string $tmpDir): string
	{
		$meta = self::resultCacheMeta($tmpDir . '/resultCache.php');
		$hash = $meta['metaExtensions'][LatteResultCacheMeta::KEY] ?? null;
		self::assertIsString($hash);

		return $hash;
	}

	/**
	 * @param callable(string, string, string, string): void $test
	 */
	private function withCorpus(callable $test): void
	{
		$projectRoot = dirname(__DIR__, 4);
		// var/tmp/, never the system temp dir: ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file), so a corpus outside the project root never
		// relativizes and every store lookup misses.
		$scratch = $projectRoot . '/var/tmp/latte-self-sufficiency-test-' . uniqid('', true);
		$srcDir = $scratch . '/src';

		try {
			FileSystem::write(
				$srcDir . '/ScratchFactoryRenderer.php',
				"<?php declare(strict_types = 1);\n\n"
				. "/**\n"
				. " * @property-read Nette\\Bridges\\ApplicationLatte\\DefaultTemplate \$template\n"
				. " */\n"
				. "final class ScratchFactoryRenderer extends Nette\\Application\\UI\\Control\n"
				. "{\n\n"
				. "\tpublic function render(): void\n"
				. "\t{\n"
				. "\t\t\$this->template->setFile(__DIR__ . '/tpl.latte');\n"
				. "\t\t\$this->template->render();\n"
				. "\t}\n\n"
				. "}\n",
			);
			FileSystem::write(
				$srcDir . '/tpl.latte',
				"{if \$user === null}u{/if}\n"
				. "{if \$baseUrl === null}b{/if}\n"
				. "{if \$basePath === null}p{/if}\n"
				. "{if \$flashes === []}f{/if}\n"
				. "{if \$control === null}c{/if}\n"
				. "{if \$presenter === null}r{/if}\n"
				. "{\$undefinedVar}\n",
			);

			$test($projectRoot, $srcDir, $srcDir . '/discovery', str_replace($projectRoot . '/', '', $srcDir));
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// PHPStan up to 2.2.x wrote a PHP array file; later releases write length-prefixed serialized
	// frames behind a guard line, where the meta section is a plain `meta <length>` frame.

	/**
	 * @return array<mixed>
	 */
	private static function resultCacheMeta(string $file): array
	{
		$contents = FileSystem::read($file);
		$meta = null;
		if (strncmp($contents, '<?php return [', 14) === 0 || strncmp($contents, '<?php return array', 18) === 0) {
			$data = require $file;
			$meta = is_array($data) ? $data['meta'] ?? null : null;
		} else {
			// Each plain frame is a `<name> <length>` line followed directly by that many bytes.
			$offset = (int) strpos($contents, "\n") + 1;
			while ($meta === null && preg_match('~\G(\w+) (\d+)\n~', $contents, $match, 0, $offset) === 1) {
				$offset += strlen($match[0]);
				$frame = (string) substr($contents, $offset, (int) $match[2]);
				$offset += (int) $match[2];
				if ($match[1] === 'meta') {
					$meta = unserialize($frame);
				}
			}
		}

		self::assertIsArray($meta);

		return $meta;
	}

	/**
	 * @return array{findings: list<string>, diagnostics: string}
	 */
	private function spawn(
		string $projectRoot,
		string $srcDir,
		string $tmpDir,
		string $storeDir,
		bool $storeEnabled = true
	): array
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			[
				'orisaiNette.latte.discovery.enabled' => $storeEnabled,
				'orisaiNette.latte.discovery.storePath' => $storeDir,
				'orisaiNette.latte.firstPartyPaths' => [$srcDir],
			],
		);

		$process = new Process(
			[
				PHP_BINARY,
				$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=raw',
				// -vv is what makes the raw formatter append the [identifier=...] half of each line and
				// the result-cache diagnostics this file's second row reads.
				'-vv',
				'-c',
				$isolated->getConfigPath(),
			],
			$projectRoot,
		);
		$process->run();

		return [
			'findings' => $this->findings($process->getOutput(), $projectRoot),
			'diagnostics' => $process->getOutput() . $process->getErrorOutput(),
		];
	}

	// file, line, message AND identifier - strictly finer than a file/identifier pair, which this
	// corpus would collapse: five distinct factory variables and $undefinedVar all report under
	// variable.undefined on the one template file, so a pair-keyed set is identical whether the link
	// was read or not.

	/**
	 * @return list<string>
	 */
	private function findings(string $raw, string $projectRoot): array
	{
		$findings = [];
		foreach (explode("\n", str_replace($projectRoot . '/', '', $raw)) as $line) {
			if (preg_match('~^(.+?:\d+):(.*) \[identifier=([^\]]+)\]$~', $line, $matches) !== 1) {
				continue;
			}

			$findings[] = $matches[1] . ' :: ' . $matches[2] . ' :: ' . $matches[3];
		}

		sort($findings);

		return $findings;
	}

}
