<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use function array_filter;
use function array_values;
use function basename;
use function dirname;
use function glob;
use function is_array;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

final class DifferentialCacheTest extends FormShapeTestCase
{

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

	private function freshDir(string $tag): string
	{
		$dir = sys_get_temp_dir() . '/diff-cache-' . $tag . '-' . uniqid('', true);
		$this->dirs[] = $dir;

		return $dir;
	}

	/** @return iterable<string, array{string}> */
	public static function dataFixtures(): iterable
	{
		$files = glob(dirname(__DIR__, 2) . '/Doubles/Forms/MatrixAssert/*.php');
		$files = is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
		foreach ($files as $file) {
			yield basename($file) => [$file];
		}
	}

	/** @dataProvider dataFixtures */
	public function testColdEqualsWarmEqualsNoCache(string $file): void
	{
		$sharedDir = $this->freshDir('shared');
		$sharedCache = new FormShapeCache($sharedDir);

		$cold = $this->captureFixtureShapes($file, $sharedCache);
		self::assertNotSame([], $cold);

		// Second pass over the SAME cache dir => warm hits.
		$warm = $this->captureFixtureShapes($file, $sharedCache);
		self::assertSame($cold, $warm, 'warm cache must produce byte-identical shapes (no stale/wrong hits)');

		// No-cache = always-cold: a fresh empty dir for every call.
		$noCache = [];
		foreach ($this->captureFixtureShapes($file, new FormShapeCache($this->freshDir('nc'))) as $i => $v) {
			$noCache[$i] = $v;
		}

		self::assertSame($cold, $noCache, 'cold ≡ no-cache (caching introduces no divergence)');
	}

	public function testDifferentFileContentHashInvalidatesEntry(): void
	{
		$cache = new FormShapeCache($this->freshDir('inv'));

		$calls = 0;
		$compute = static function () use (&$calls): FormShape {
			$calls++;

			return FormShape::empty('Cold');
		};

		$cache->remember('hash-v1', 'node', $compute);
		$cache->remember('hash-v1', 'node', $compute);
		self::assertSame(1, $calls, 'same (hash,node) => served from cache');

		// Simulate an edited file: same nodeId, different content hash => MUST recompute.
		$editedCalls = 0;
		$edited = $cache->remember('hash-v2', 'node', static function () use (&$editedCalls): FormShape {
			$editedCalls++;

			return FormShape::empty('Edited');
		});
		self::assertSame('Edited', $edited->getClassName());
		self::assertSame(1, $editedCalls, 'changed file content hash must NOT serve the stale entry');

		// Original hash still serves its own cached entry.
		$staleCalls = 0;
		$still = $cache->remember('hash-v1', 'node', static function () use (&$staleCalls): FormShape {
			$staleCalls++;

			return FormShape::empty('Recomputed');
		});
		self::assertSame(0, $staleCalls, 'hash-v1 entry must still be cached');
		self::assertSame('Cold', $still->getClassName());
	}

}
