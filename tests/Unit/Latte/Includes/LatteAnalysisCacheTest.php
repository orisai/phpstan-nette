<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use FilesystemIterator;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Cache\LatteCodeVersion;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function dirname;
use function getmypid;
use function glob;
use function sha1_file;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use const GLOB_ONLYDIR;

final class LatteAnalysisCacheTest extends BaseTestCase
{

	public function testRememberByManifestComputesOnceAndReloads(): void
	{
		$dir = sys_get_temp_dir() . '/latte-cache-test-' . getmypid() . '-' . uniqid('', true);
		$cache = new LatteAnalysisCache($dir, 'testv1');
		$calls = 0;
		$compute = static function () use (&$calls): array {
			$calls++;

			return ['x' => 1];
		};

		self::assertSame(['x' => 1], $cache->rememberByManifest('edgeidx', 'm1', $compute));
		self::assertSame(['x' => 1], $cache->rememberByManifest('edgeidx', 'm1', $compute));
		self::assertSame(1, $calls);

		$fresh = new LatteAnalysisCache($dir, 'testv1');
		self::assertSame(['x' => 1], $fresh->rememberByManifest('edgeidx', 'm1', $compute));
		self::assertSame(1, $calls);

		self::assertSame(['x' => 1], $cache->rememberByManifest('edgeidx', 'm2', static fn (): array => ['x' => 1]));
		$cache->clear();
	}

	/**
	 * Sibling-version pruning runs once per baseDirectory per process (the static guard in
	 * LatteAnalysisCache::__construct): $a's construction prunes against a still-empty $dir
	 * (nothing to prune), and $b's construction - same $dir, same process - finds the guard
	 * already tripped and skips pruning entirely. $a's directory therefore survives; isolation
	 * between code versions comes from each instance addressing its own v* directory, not from
	 * deleting the other version's directory.
	 */
	public function testDifferentCodeVersionsDoNotShareEntries(): void
	{
		$dir = sys_get_temp_dir() . '/latte-cache-test-' . getmypid() . '-' . uniqid('', true);
		$a = new LatteAnalysisCache($dir, 'va');
		$a->rememberByManifest('k', 'm', static fn (): array => ['v' => 'a']);
		$b = new LatteAnalysisCache($dir, 'vb');
		$calls = 0;
		$b->rememberByManifest('k', 'm', static function () use (&$calls): array {
			$calls++;

			return ['v' => 'b'];
		});

		self::assertSame(1, $calls);

		$aCallsAfter = 0;
		self::assertSame(
			['v' => 'a'],
			$a->rememberByManifest('k', 'm', static function () use (&$aCallsAfter): array {
				$aCallsAfter++;

				return ['v' => 'a-recomputed'];
			}),
		);
		self::assertSame(0, $aCallsAfter, "a's entry must still be on disk - b's construction did not prune it");

		$dirs = glob($dir . '/v*', GLOB_ONLYDIR);
		self::assertNotFalse($dirs);
		self::assertCount(2, $dirs, 'both version directories coexist within one process');

		$a->clear();
		$b->clear();
	}

	public function testRememberContentAddressedComputesOnceAndReloads(): void
	{
		$dir = sys_get_temp_dir() . '/latte-cache-test-' . getmypid() . '-' . uniqid('', true);
		$cache = new LatteAnalysisCache($dir, 'testv1');
		$calls = 0;
		$compute = static function () use (&$calls): array {
			$calls++;

			return ['fact' => 'value'];
		};

		self::assertSame(['fact' => 'value'], $cache->rememberContentAddressed('hash1', 'node1', $compute));
		self::assertSame(['fact' => 'value'], $cache->rememberContentAddressed('hash1', 'node1', $compute));
		self::assertSame(1, $calls);

		$fresh = new LatteAnalysisCache($dir, 'testv1');
		self::assertSame(['fact' => 'value'], $fresh->rememberContentAddressed('hash1', 'node1', $compute));
		self::assertSame(1, $calls);

		$cache->clear();
	}

	public function testWriteContentAddressedThenReadReturnsWrittenValue(): void
	{
		$dir = sys_get_temp_dir() . '/latte-cache-test-' . getmypid() . '-' . uniqid('', true);
		$cache = new LatteAnalysisCache($dir, 'testv1');

		self::assertNull($cache->readContentAddressed('hash1', 'node1'));

		$cache->writeContentAddressed('hash1', 'node1', ['fact' => 'value']);
		self::assertSame(['fact' => 'value'], $cache->readContentAddressed('hash1', 'node1'));

		$fresh = new LatteAnalysisCache($dir, 'testv1');
		self::assertSame(['fact' => 'value'], $fresh->readContentAddressed('hash1', 'node1'));

		$cache->clear();
	}

	public function testReadWriteContentAddressedShareKeySpaceWithRememberContentAddressed(): void
	{
		$dir = sys_get_temp_dir() . '/latte-cache-test-' . getmypid() . '-' . uniqid('', true);
		$cache = new LatteAnalysisCache($dir, 'testv1');

		$cache->writeContentAddressed('hash1', 'node1', ['fact' => 'pre-written']);

		$calls = 0;
		$viaRemember = $cache->rememberContentAddressed('hash1', 'node1', static function () use (&$calls): array {
			$calls++;

			return ['fact' => 'recomputed'];
		});

		self::assertSame(['fact' => 'pre-written'], $viaRemember);
		self::assertSame(0, $calls);

		$cache->clear();
	}

	public function testCodeVersionIsStableAndDeterministic(): void
	{
		$version = LatteCodeVersion::get();
		self::assertSame($version, LatteCodeVersion::get());
		self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $version);
	}

	public function testCombineChangesWhenAnyTrackedPackageVersionInputChanges(): void
	{
		$base = LatteCodeVersion::combine('files-digest', '1.0.0', '2.0.0', '3.0.0', '4.0.0');

		self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $base);
		self::assertNotSame($base, LatteCodeVersion::combine('files-digest', '1.0.1', '2.0.0', '3.0.0', '4.0.0'));
		self::assertNotSame($base, LatteCodeVersion::combine('files-digest', '1.0.0', '2.0.1', '3.0.0', '4.0.0'));
		self::assertNotSame($base, LatteCodeVersion::combine('files-digest', '1.0.0', '2.0.0', '3.0.1', '4.0.0'));
		self::assertNotSame($base, LatteCodeVersion::combine('files-digest', '1.0.0', '2.0.0', '3.0.0', '4.0.1'));
		self::assertSame($base, LatteCodeVersion::combine('files-digest', '1.0.0', '2.0.0', '3.0.0', '4.0.0'));
	}

	// The package list is spelled out here rather than read from LatteCodeVersion::SALTED_PACKAGES:
	// dropping a cache-producing package from the salt is exactly the regression this pins, and a
	// test that followed the constant would drop with it.
	public function testEverySourceOfEveryCacheProducingPackageSaltsTheCodeVersion(): void
	{
		$digest = LatteCodeVersion::filesDigest();
		$root = dirname(__DIR__, 4) . '/src';

		foreach (['Latte'] as $package) {
			$files = $this->phpFilesIn($root . '/' . $package);
			self::assertNotCount(0, $files, $package . ' must have sources to salt with');

			foreach ($files as $file) {
				$key = $package . '/' . substr($file, strlen($root . '/' . $package) + 1);
				self::assertStringContainsString(
					$key . ':' . sha1_file($file) . '|',
					$digest,
					$key . ' must contribute to the Latte analysis cache salt',
				);
			}
		}
	}

	public function testManifestAndContentAddressedNamespacesAreDisjoint(): void
	{
		$dir = sys_get_temp_dir() . '/latte-cache-test-' . getmypid() . '-' . uniqid('', true);
		$cache = new LatteAnalysisCache($dir, 'testv1');

		// Collision pair: rememberByManifest('abc', 'def|ghi') and rememberContentAddressed('def|ghi', 'abc')
		// both produce sha1('abc|def|ghi') with the unfixed formulas.
		// This test writes via manifest path, then reads via content-addressed path and expects
		// to recompute (proving the namespaces are disjoint).
		$cache->rememberByManifest('abc', 'def|ghi', static fn (): array => ['from' => 'manifest']);
		$viaContent = $cache->rememberContentAddressed('def|ghi', 'abc', static fn (): array => ['from' => 'content']);

		self::assertSame(['from' => 'content'], $viaContent);
		$cache->clear();
	}

	/**
	 * @return list<string>
	 */
	private function phpFilesIn(string $directory): array
	{
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
		);

		$files = [];
		/** @var SplFileInfo $info */
		foreach ($iterator as $info) {
			if ($info->isFile() && substr($info->getFilename(), -4) === '.php') {
				$files[] = $info->getPathname();
			}
		}

		return $files;
	}

}
