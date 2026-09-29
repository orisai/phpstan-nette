<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_diff_key;
use function array_key_first;
use function array_merge;
use function dirname;
use function glob;
use function is_array;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;
use function unserialize;
use const GLOB_ONLYDIR;
use const PHP_BINARY;

/**
 * C1(b) + fix round: two configs that analyse the identical universe but resolve a different
 * phpVersion must never serve each other's cached extraction — the ParserErrorsException swallow in
 * FileFactIndex::fileClassLikes makes phpVersion load-bearing (a php8-only file folds to zero facts
 * under php7.4 and to real facts under php8.4). Both the fold-blob manifest AND every per-file fact
 * entry are salted with FileFactIndex::configIdentity(); this spawns a php7.4 and a php8.4 config
 * over one shared, unwrapped form-shape-cache directory (both configs resolve the same
 * orisaiNette.forms.defaultContainerClass, so they land in the same code-versioned subdirectory) and asserts:
 * distinct blobs (manifest salt), the php7.4 blob untouched by the php8.4 run, and — the content
 * observable that catches an un-salted fact entry — the php8.4 blob carries the php8-only file's
 * registration while the php7.4 blob does not. Regidx blobs are identified by their on-disk
 * structural shape ({h, p}, written by FormShapeCache::rememberRegistrationIndex) rather than the
 * {v, d} shape every other cache entry uses.
 */
final class ManifestConfigIdentityTest extends BaseTestCase
{

	private const KEY_PHP74_VISIBLE = 'mp:Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\ManifestIdentity\Registrant::orderSucceeded#0';

	private const KEY_PHP8_ONLY = 'mp:Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\ManifestIdentity\Php8Registrant::modernSucceeded#0';

	/** @var list<string> */
	private array $dirs = [];

	protected function tearDown(): void
	{
		foreach ($this->dirs as $dir) {
			if (is_dir($dir)) {
				FileSystem::delete($dir);
			}
		}

		$this->dirs = [];
	}

	public function testPhp74AndPhp84ConfigsNeverShareBlobsNorFactEntriesInOneCacheDir(): void
	{
		$universe = __DIR__ . '/Fixtures/ManifestIdentity';
		$sharedTmpDir = $this->makeDir();

		$php74 = $this->wrap(__DIR__ . '/shadow-compare.neon', $sharedTmpDir, $universe, 70_400);
		$php84 = $this->wrap(__DIR__ . '/shadow-compare-php84.neon', $sharedTmpDir, $universe, 80_400);

		$this->spawnAnalysis($php74, $universe . '/Registrant.php');
		$afterFirst = $this->regidxBlobs($sharedTmpDir);
		self::assertCount(1, $afterFirst, 'the php7.4 run must persist exactly one regidx blob');

		$php74BlobFile = array_key_first($afterFirst);
		self::assertNotNull($php74BlobFile);
		$php74Blob = $afterFirst[$php74BlobFile];
		self::assertArrayHasKey(
			self::KEY_PHP74_VISIBLE,
			$php74Blob['h'],
			'the php7.4 fold must carry the php7.4-parseable registration',
		);
		self::assertArrayNotHasKey(
			self::KEY_PHP8_ONLY,
			$php74Blob['h'],
			'the php7.4 fold must swallow the php8-only file to zero facts',
		);

		$this->spawnAnalysis($php84, $universe . '/Registrant.php');
		$afterSecond = $this->regidxBlobs($sharedTmpDir);

		self::assertCount(
			2,
			$afterSecond,
			'the php8.4 run sharing the same cache directory must persist its OWN regidx blob, '
				. 'never collide with (reuse or overwrite) the php7.4 run\'s blob',
		);
		self::assertSame(
			[$php74BlobFile => $php74Blob],
			array_diff_key($afterSecond, array_diff_key($afterSecond, $afterFirst)),
			"the php7.4 run's blob must survive the php8.4 run untouched",
		);

		$php84Only = array_diff_key($afterSecond, $afterFirst);
		self::assertCount(1, $php84Only);
		$php84BlobFile = array_key_first($php84Only);
		self::assertNotNull($php84BlobFile);
		$php84Blob = $php84Only[$php84BlobFile];
		self::assertArrayHasKey(
			self::KEY_PHP74_VISIBLE,
			$php84Blob['h'],
			'the php8.4 fold must carry the both-versions-parseable registration',
		);
		self::assertArrayHasKey(
			self::KEY_PHP8_ONLY,
			$php84Blob['h'],
			'the php8.4 fold must extract the php8-only registration itself — a shared un-salted '
				. "per-file fact entry would serve it the php7.4 run's swallowed-empty facts",
		);
	}

	private function wrap(string $realConfigPath, string $tmpDir, string $paths, int $phpVersion): string
	{
		$dir = $this->makeDir();
		$configPath = $dir . '/wrapper.neon';
		FileSystem::write(
			$configPath,
			Neon::encode(
				[
					'includes' => [$realConfigPath],
					'parameters' => [
						'tmpDir' => $tmpDir,
						'paths!' => [$paths],
						'phpVersion' => $phpVersion,
					],
				],
				true,
			),
		);

		return $configPath;
	}

	/**
	 * @return array<string, array{h: array<string, mixed>, p: array<string, mixed>}> blob file => decoded content
	 */
	private function regidxBlobs(string $tmpDir): array
	{
		$versions = glob($tmpDir . '/form-shape-cache/v*', GLOB_ONLYDIR);
		self::assertNotFalse($versions);

		$blobs = [];
		foreach ($versions as $versionDir) {
			$files = glob($versionDir . '/*.ser');
			self::assertNotFalse($files);

			foreach ($files as $file) {
				$decoded = $this->decodeRegidxBlob($file);
				if ($decoded !== null) {
					$blobs[$file] = $decoded;
				}
			}
		}

		return $blobs;
	}

	/**
	 * @return array{h: array<string, mixed>, p: array<string, mixed>}|null
	 */
	private function decodeRegidxBlob(string $file): ?array
	{
		$decoded = unserialize(FileSystem::read($file));
		if (!is_array($decoded) || !isset($decoded['h'], $decoded['p']) || isset($decoded['v'])) {
			return null;
		}

		/** @var array{h: array<string, mixed>, p: array<string, mixed>} $decoded */
		return $decoded;
	}

	private function spawnAnalysis(string $config, string $file): void
	{
		$root = dirname(__DIR__, 4);
		$process = new Process(
			array_merge(
				[PHP_BINARY, $root . '/' . VendorDirectory::name() . '/bin/phpstan', 'analyse', $file],
				[
					'-c',
					$config,
					'--error-format=raw',
					'--no-progress',
					'--memory-limit=2048M',
				],
			),
			$root,
		);
		$process->setTimeout(600.0);
		$process->run();
	}

	private function makeDir(): string
	{
		$dir = sys_get_temp_dir() . '/manifest-config-identity-' . uniqid('', true);
		FileSystem::createDir($dir);
		$this->dirs[] = $dir;

		return $dir;
	}

}
