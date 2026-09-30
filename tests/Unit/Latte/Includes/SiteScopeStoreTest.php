<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\SliceClassName;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function getmypid;
use function sys_get_temp_dir;
use function uniqid;

final class SiteScopeStoreTest extends BaseTestCase
{

	public function testKeyFormatIsHashJoinedFields(): void
	{
		self::assertSame(
			'a.latte#12#b.latte#ctxabc',
			SiteScopeStore::key('a.latte', 12, 'b.latte', 'ctxabc'),
		);
	}

	public function testBootstrapWritesOneCanonicalEmptySlicePerIncluderIntoTheDirectory(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';

			SiteScopeStore::bootstrap($storeDir, ['a.latte']);

			self::assertDirectoryExists($storeDir);
			$file = $storeDir . '/' . SliceClassName::forPath('a.latte') . '.php';
			self::assertFileExists($file);

			$store = new SiteScopeStore($storeDir);
			self::assertTrue($store->hasSlice('a.latte'));
			self::assertFalse($store->hasSlice('b.latte'), 'only the seeded includer gets a slice');
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testBootstrapNeverClobbersAnIncluderThatAlreadyHasASlice(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$storeDir = $dir . '/store';
			$key = SiteScopeStore::key('a.latte', 1, 'b.latte', 'ctx1');
			$entry = $this->entry('sha-a', ['x' => 'string'], []);

			$store = new SiteScopeStore($storeDir);
			$store->replaceForIncluders(['a.latte'], [$key => $entry]);

			SiteScopeStore::bootstrap($storeDir, ['a.latte', 'b.latte']);

			$fresh = new SiteScopeStore($storeDir);
			self::assertEquals($entry, $fresh->get($key, 'sha-a'), 'must not clobber an already-populated slice');
			self::assertTrue($fresh->hasSlice('b.latte'), 'a genuinely new includer still gets an empty slice');
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testMissingDirectoryYieldsEmptyStoreNoError(): void
	{
		$dir = $this->scratchDir();

		try {
			$store = new SiteScopeStore($dir . '/does-not-exist');

			self::assertNull($store->get('any#1#any#any', 'sha'));
			self::assertFalse($store->hasSlice('a.latte'));
			// sha1('[]'), the canonical serialization of an empty entry set.
			self::assertSame('97d170e1550eee4afc0af065b78cda302a97674c', $store->sliceHash('a.latte', 'sha'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Store degradation direction: an unparseable slice file
	// (e.g. a hand-edited file, a leftover git merge conflict) must degrade that ONE
	// file to zero entries - loadOne()'s tight Throwable catch around `include` - never throw out
	// of the constructor, never take any OTHER slice in the same directory down with it.
	public function testUnparseableSliceFileDegradesToNoEntriesWithoutThrowing(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$storeDir = $dir . '/store';
			SiteScopeStore::bootstrap($storeDir, ['broken.latte', 'healthy.latte']);

			$brokenFile = $storeDir . '/' . SliceClassName::forPath('broken.latte') . '.php';
			FileSystem::write($brokenFile, "<?php declare(strict_types = 1);\n\nthis is not valid php {{{\n");

			$key = SiteScopeStore::key('healthy.latte', 1, 'target.latte', 'ctx1');
			$healthyEntry = $this->entry('sha-healthy', ['x' => 'string'], []);
			(new SiteScopeStore($storeDir))->replaceForIncluders(['healthy.latte'], [$key => $healthyEntry]);

			$store = new SiteScopeStore($storeDir);

			self::assertNull(
				$store->get('broken.latte#1#target.latte#ctx1', 'any-sha'),
				'the unparseable file must contribute zero entries, never throw',
			);
			self::assertEquals(
				$healthyEntry,
				$store->get($key, 'sha-healthy'),
				"a sibling slice's own valid entries must be completely unaffected",
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Pins EdgeFingerprint's bootstrap adjudication: `make
	// phpstan-narrowing-init` creates an empty-but-present slice where none existed before - that
	// transition must NOT change that includer's sliceHash(), or the one-time bootstrap would
	// spuriously invalidate every .latte file's LATTE_EDGE_FINGERPRINT.
	public function testExistingEmptySliceYieldsSameSliceHashAsMissingDirectory(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$missing = new SiteScopeStore($dir . '/does-not-exist');

			$presentDir = $dir . '/store';
			SiteScopeStore::bootstrap($presentDir, ['a.latte']);
			$present = new SiteScopeStore($presentDir);

			self::assertSame($missing->sliceHash('a.latte', 'sha'), $present->sliceHash('a.latte', 'sha'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testRoundTripWriteThenReadAcrossFreshInstance(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$path = $dir . '/store';
			$key = SiteScopeStore::key('a.latte', 3, 'b.latte', 'ctx1');
			$entry = $this->entry('sha-a', ['x' => 'string'], ['y' => 'int']);

			$store = new SiteScopeStore($path);
			$changed = $store->replaceForIncluders(['a.latte'], [$key => $entry]);

			self::assertSame(['a.latte'], $changed);
			// assertEquals, not assertSame: the store canonicalizes (ksorts) entry key order by
			// design, so the returned array's key order need not match the caller's insertion order.
			self::assertEquals($entry, $store->get($key, 'sha-a'));

			$fresh = new SiteScopeStore($path);
			self::assertEquals($entry, $fresh->get($key, 'sha-a'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testGetReturnsNullOnAbsentKey(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$store = new SiteScopeStore($dir . '/store');

			self::assertNull($store->get('nope#1#nope#nope', 'sha'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testGetReturnsNullOnShaMismatch(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$path = $dir . '/store';
			$key = SiteScopeStore::key('a.latte', 3, 'b.latte', 'ctx1');
			$entry = $this->entry('sha-a', ['x' => 'string'], []);

			$store = new SiteScopeStore($path);
			$store->replaceForIncluders(['a.latte'], [$key => $entry]);

			self::assertNull($store->get($key, 'sha-b'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testCanonicalByteIdentityAcrossInsertionOrders(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$storeDirA = $dir . '/store-a';
			$storeDirB = $dir . '/store-b';

			$keyA = SiteScopeStore::key('a.latte', 1, 'x.latte', 'ctx1');
			$keyB = SiteScopeStore::key('b.latte', 2, 'y.latte', 'ctx2');

			$entryAOrder1 = $this->entry('sha-a', ['x' => 'string', 'y' => 'int'], ['p' => 'bool']);
			$entryAOrder2 = $this->entry('sha-a', ['y' => 'int', 'x' => 'string'], ['p' => 'bool']);
			$entryB = $this->entry('sha-b', ['z' => 'float'], []);

			$storeA = new SiteScopeStore($storeDirA);
			$storeA->replaceForIncluders(['a.latte', 'b.latte'], [
				$keyB => $entryB,
				$keyA => $entryAOrder1,
			]);

			$storeB = new SiteScopeStore($storeDirB);
			$storeB->replaceForIncluders(['b.latte', 'a.latte'], [
				$keyA => $entryAOrder2,
				$keyB => $entryB,
			]);

			self::assertSame(
				FileSystem::read($this->sliceFile($storeDirA, 'a.latte')),
				FileSystem::read($this->sliceFile($storeDirB, 'a.latte')),
			);
			self::assertSame(
				FileSystem::read($this->sliceFile($storeDirA, 'b.latte')),
				FileSystem::read($this->sliceFile($storeDirB, 'b.latte')),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testReplaceForIncludersDropsOnlyAnalyzedIncludersStaleEntries(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$path = $dir . '/store';

			$keyA1 = SiteScopeStore::key('a.latte', 1, 'x.latte', 'ctx1');
			$keyA2 = SiteScopeStore::key('a.latte', 2, 'x.latte', 'ctx1');
			$keyC1 = SiteScopeStore::key('c.latte', 1, 'x.latte', 'ctx1');

			$store = new SiteScopeStore($path);
			$store->replaceForIncluders(['a.latte', 'c.latte'], [
				$keyA1 => $this->entry('sha-a-old', ['x' => 'string'], []),
				$keyA2 => $this->entry('sha-a-old', ['y' => 'int'], []),
				$keyC1 => $this->entry('sha-c', ['z' => 'bool'], []),
			]);

			// a.latte re-analyzed: keyA2's site is gone this run, only keyA1 survives with a new sha.
			// c.latte was NOT analyzed this run, so its entry must be left untouched.
			$keyA1New = SiteScopeStore::key('a.latte', 1, 'x.latte', 'ctx1');
			$changed = $store->replaceForIncluders(['a.latte'], [
				$keyA1New => $this->entry('sha-a-new', ['x' => 'string'], []),
			]);

			self::assertSame(['a.latte'], $changed);
			self::assertNull($store->get($keyA2, 'sha-a-old'), 'stale site of an analyzed includer must be dropped');
			self::assertEquals(
				$this->entry('sha-a-new', ['x' => 'string'], []),
				$store->get($keyA1New, 'sha-a-new'),
			);
			self::assertEquals(
				$this->entry('sha-c', ['z' => 'bool'], []),
				$store->get($keyC1, 'sha-c'),
				'entry of a non-analyzed includer must survive untouched',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testReplaceForIncludersReportsNothingAndSkipsWriteWhenLogicallyUnchanged(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$path = $dir . '/store';
			$key1 = SiteScopeStore::key('a.latte', 1, 'x.latte', 'ctx1');
			$key2 = SiteScopeStore::key('a.latte', 2, 'x.latte', 'ctx1');

			$store = new SiteScopeStore($path);
			$store->replaceForIncluders(['a.latte'], [
				$key1 => $this->entry('sha-a', ['x' => 'string'], []),
				$key2 => $this->entry('sha-a', ['y' => 'int'], []),
			]);
			$bytesAfterFirstWrite = FileSystem::read($path);

			// Same logical content, opposite insertion order - must be recognized as unchanged.
			$changed = $store->replaceForIncluders(['a.latte'], [
				$key2 => $this->entry('sha-a', ['y' => 'int'], []),
				$key1 => $this->entry('sha-a', ['x' => 'string'], []),
			]);

			self::assertSame([], $changed);
			self::assertSame($bytesAfterFirstWrite, FileSystem::read($path));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testSliceHashCoversOnlyValidEntriesOfMatchingIncluderPrefix(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$path = $dir . '/store';

			$keyAValid = SiteScopeStore::key('a.latte', 1, 'x.latte', 'ctx1');
			$keyAStale = SiteScopeStore::key('a.latte', 2, 'x.latte', 'ctx1');
			$keyAliasPrefix = SiteScopeStore::key('a.latte2', 1, 'x.latte', 'ctx1');
			$keyOther = SiteScopeStore::key('other.latte', 1, 'x.latte', 'ctx1');

			$store = new SiteScopeStore($path);
			$store->replaceForIncluders(['a.latte', 'a.latte2', 'other.latte'], [
				$keyAValid => $this->entry('sha-a-current', ['x' => 'string'], []),
				$keyAliasPrefix => $this->entry('sha-a-current', ['x' => 'string'], []),
				$keyOther => $this->entry('sha-other', ['x' => 'string'], []),
			]);

			$baseline = $store->sliceHash('a.latte', 'sha-a-current');

			// Add a stale-sha entry under the same includer prefix: must NOT affect the slice hash
			// because it does not match the current includer sha (not a "VALID" entry).
			$store->replaceForIncluders(['a.latte'], [
				$keyAValid => $this->entry('sha-a-current', ['x' => 'string'], []),
				$keyAStale => $this->entry('sha-a-old', ['y' => 'int'], []),
			]);

			self::assertSame($baseline, $store->sliceHash('a.latte', 'sha-a-current'));
			self::assertNotSame(
				$store->sliceHash('a.latte', 'sha-a-current'),
				$store->sliceHash('a.latte2', 'sha-a-current'),
				'a key prefix must not bleed into a similarly-named includer',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testSliceHashIsOrderIndependentAndChangesWithContent(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$pathA = $dir . '/store-a';
			$pathB = $dir . '/store-b';

			$key1 = SiteScopeStore::key('a.latte', 1, 'x.latte', 'ctx1');
			$key2 = SiteScopeStore::key('a.latte', 2, 'x.latte', 'ctx1');

			$storeA = new SiteScopeStore($pathA);
			$storeA->replaceForIncluders(['a.latte'], [
				$key1 => $this->entry('sha-a', ['x' => 'string'], []),
				$key2 => $this->entry('sha-a', ['y' => 'int'], []),
			]);

			$storeB = new SiteScopeStore($pathB);
			$storeB->replaceForIncluders(['a.latte'], [
				$key2 => $this->entry('sha-a', ['y' => 'int'], []),
				$key1 => $this->entry('sha-a', ['x' => 'string'], []),
			]);

			self::assertSame($storeA->sliceHash('a.latte', 'sha-a'), $storeB->sliceHash('a.latte', 'sha-a'));

			$storeB->replaceForIncluders(['a.latte'], [
				$key2 => $this->entry('sha-a', ['y' => 'string'], []),
				$key1 => $this->entry('sha-a', ['x' => 'string'], []),
			]);

			self::assertNotSame($storeA->sliceHash('a.latte', 'sha-a'), $storeB->sliceHash('a.latte', 'sha-a'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testReplaceForIncludersListsOnlyTheIncludersWhoseSliceChangedSorted(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$path = $dir . '/store';
			$keyA = SiteScopeStore::key('a.latte', 1, 'x.latte', 'ctx1');
			$keyB = SiteScopeStore::key('b.latte', 1, 'x.latte', 'ctx1');
			$keyC = SiteScopeStore::key('c.latte', 1, 'x.latte', 'ctx1');

			$store = new SiteScopeStore($path);
			self::assertSame(
				['a.latte', 'b.latte', 'c.latte'],
				$store->replaceForIncluders(['c.latte', 'a.latte', 'b.latte'], [
					$keyA => $this->entry('sha-a', ['x' => 'string'], []),
					$keyB => $this->entry('sha-b', ['x' => 'string'], []),
					$keyC => $this->entry('sha-c', ['x' => 'string'], []),
				]),
			);

			self::assertSame(['c.latte', 'd.latte'], $store->replaceForIncluders(['d.latte', 'c.latte', 'a.latte'], [
				$keyA => $this->entry('sha-a', ['x' => 'string'], []),
				$keyC => $this->entry('sha-c', ['x' => 'int'], []),
			]));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testPruneExceptDeletesOnlySlicesOfIncludersOutsideTheGivenSet(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$path = $dir . '/store';
			SiteScopeStore::bootstrap($path, ['a.latte', 'gone.latte', 'also-gone.latte']);
			FileSystem::write($path . '/README', 'kept');

			$store = new SiteScopeStore($path);
			self::assertSame(
				[
					SliceClassName::forPath('also-gone.latte') . '.php',
					SliceClassName::forPath('gone.latte') . '.php',
				],
				$store->pruneExcept(['a.latte', 'new.latte']),
			);

			self::assertFileExists($this->sliceFile($path, 'a.latte'));
			self::assertFileDoesNotExist($this->sliceFile($path, 'gone.latte'));
			self::assertFileDoesNotExist($this->sliceFile($path, 'also-gone.latte'));
			self::assertFileExists($path . '/README');
			self::assertSame([], $store->pruneExcept(['a.latte']));
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param array<string, string> $vars
	 * @param array<string, string> $args
	 * @return array{sha: string, vars: array<string, string>, args: array<string, string>}
	 */
	private function entry(string $sha, array $vars, array $args): array
	{
		return ['sha' => $sha, 'vars' => $vars, 'args' => $args];
	}

	private function scratchDir(): string
	{
		return sys_get_temp_dir() . '/latte-sitescope-test-' . getmypid() . '-' . uniqid('', true);
	}

	private function sliceFile(string $storeDir, string $includerRel): string
	{
		return $storeDir . '/' . SliceClassName::forPath($includerRel) . '.php';
	}

}
