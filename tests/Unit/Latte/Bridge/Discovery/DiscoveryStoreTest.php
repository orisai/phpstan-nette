<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Discovery;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Compile\DiscoveryClassName;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function getmypid;
use function md5;
use function sha1;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

final class DiscoveryStoreTest extends BaseTestCase
{

	public function testBootstrapCreatesEmptyTemplateFilesAndAnEmptyIndex(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, ['a.latte', 'b.latte']);

			$store = new DiscoveryStore($storeDir);
			self::assertTrue($store->hasTemplateFile('a.latte'));
			self::assertTrue($store->hasTemplateFile('b.latte'));
			self::assertFalse($store->hasTemplateFile('c.latte'));
			self::assertSame([], $store->recordsForTemplate('a.latte'));
			self::assertSame([], $store->allLinkedTemplates());
			self::assertSame([], $store->linkedClasses());
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testBootstrapNeverClobbersAnExistingTemplateFile(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, ['a.latte']);

			$store = new DiscoveryStore($storeDir);
			$store->replaceWith(
				['a.latte' => [self::record('App\\Foo', 'detail', 'setFile', 'happens')]],
				['App\\Foo'],
				[],
			);
			$file = $storeDir . '/' . DiscoveryClassName::forPath('a.latte') . '.php';
			$populated = FileSystem::read($file);

			DiscoveryStore::bootstrap($storeDir, ['a.latte']);

			self::assertSame($populated, FileSystem::read($file));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testReplaceWithRoundTripsRecordsThroughAFreshInstance(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, []);

			$store = new DiscoveryStore($storeDir);
			$store->replaceWith(
				[
					'app/b.latte' => [self::record('App\\Foo', null, 'layout', 'unknown')],
					'app/a.latte' => [
						self::record('App\\Foo', 'detail', 'setFile', 'happens'),
						self::record('App\\Bar', null, 'convention', 'unknown'),
					],
				],
				['App\\Foo', 'App\\Bar'],
				[],
			);

			$fresh = new DiscoveryStore($storeDir);
			self::assertSame(
				[
					self::record('App\\Bar', null, 'convention', 'unknown'),
					self::record('App\\Foo', 'detail', 'setFile', 'happens'),
				],
				$fresh->recordsForTemplate('app/a.latte'),
			);
			self::assertSame(
				[self::record('App\\Foo', null, 'layout', 'unknown')],
				$fresh->recordsForTemplate('app/b.latte'),
			);
			self::assertSame(['app/a.latte', 'app/b.latte'], $fresh->allLinkedTemplates());
			self::assertSame(['App\\Bar', 'App\\Foo'], $fresh->linkedClasses());
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testCanonicalOrderingMakesInsertionOrderIrrelevantByteForByte(): void
	{
		$dir = $this->scratchDir();

		try {
			$recordsInOneOrder = [
				'app/a.latte' => [
					self::record('App\\Foo', 'detail', 'setFile', 'happens'),
					self::record('App\\Bar', 'index', 'formula', 'maybe'),
					self::record('App\\Foo', 'default', 'formula', 'happens'),
				],
			];
			$recordsInAnotherOrder = [
				'app/a.latte' => [
					self::record('App\\Foo', 'default', 'formula', 'happens'),
					self::record('App\\Foo', 'detail', 'setFile', 'happens'),
					self::record('App\\Bar', 'index', 'formula', 'maybe'),
				],
			];

			$storeDirA = $dir . '/store-a';
			DiscoveryStore::bootstrap($storeDirA, []);
			(new DiscoveryStore($storeDirA))->replaceWith($recordsInOneOrder, ['App\\Foo', 'App\\Bar'], []);

			$storeDirB = $dir . '/store-b';
			DiscoveryStore::bootstrap($storeDirB, []);
			(new DiscoveryStore($storeDirB))->replaceWith($recordsInAnotherOrder, ['App\\Bar', 'App\\Foo'], []);

			$file = DiscoveryClassName::forPath('app/a.latte') . '.php';
			self::assertSame(
				FileSystem::read($storeDirA . '/' . $file),
				FileSystem::read($storeDirB . '/' . $file),
			);
			self::assertSame(
				FileSystem::read($storeDirA . '/class-index.php'),
				FileSystem::read($storeDirB . '/class-index.php'),
			);
			$templateClass = TemplateClassName::forPath('app/a.latte');
			self::assertSame(
				(new DiscoveryStore($storeDirA))->recordsSaltForTemplateClass($templateClass),
				(new DiscoveryStore($storeDirB))->recordsSaltForTemplateClass($templateClass),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testIdenticalReplaceLeavesEveryFileByteIdentical(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, ['app/a.latte']);

			$records = ['app/a.latte' => [self::record('App\\Foo', null, 'setFile', 'unknown')]];
			(new DiscoveryStore($storeDir))->replaceWith($records, ['App\\Foo'], ['app/a.latte']);

			$file = $storeDir . '/' . DiscoveryClassName::forPath('app/a.latte') . '.php';
			$afterFirst = FileSystem::read($file);
			$indexAfterFirst = FileSystem::read($storeDir . '/class-index.php');

			$changed = (new DiscoveryStore($storeDir))->replaceWith($records, ['App\\Foo'], ['app/a.latte']);

			self::assertFalse($changed);
			self::assertSame($afterFirst, FileSystem::read($file));
			self::assertSame($indexAfterFirst, FileSystem::read($storeDir . '/class-index.php'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testVanishedRecordsEmptyTheTemplateFileButNeverDeleteIt(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, []);

			(new DiscoveryStore($storeDir))->replaceWith(
				['app/a.latte' => [self::record('App\\Foo', null, 'setFile', 'unknown')]],
				['App\\Foo'],
				[],
			);

			(new DiscoveryStore($storeDir))->replaceWith([], [], []);

			$fresh = new DiscoveryStore($storeDir);
			self::assertTrue(
				$fresh->hasTemplateFile('app/a.latte'),
				'a template file must survive its records vanishing - a dangling class ref would be class.notFound',
			);
			self::assertSame([], $fresh->recordsForTemplate('app/a.latte'));
			self::assertSame([], $fresh->allLinkedTemplates());
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testMaterializeRelsGetEmptyTemplateFiles(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, []);

			(new DiscoveryStore($storeDir))->replaceWith([], [], ['app/x.latte']);

			$fresh = new DiscoveryStore($storeDir);
			self::assertTrue($fresh->hasTemplateFile('app/x.latte'));
			self::assertSame([], $fresh->recordsForTemplate('app/x.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testRecordSaltTracksRecordContentButNotEmptyTemplateFiles(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, []);
			$templateClass = TemplateClassName::forPath('app/a.latte');

			$store = new DiscoveryStore($storeDir);
			$store->replaceWith(
				['app/a.latte' => [self::record('App\\Foo', null, 'setFile', 'unknown')]],
				['App\\Foo'],
				[],
			);
			$initial = (new DiscoveryStore($storeDir))->recordsSaltForTemplateClass($templateClass);

			(new DiscoveryStore($storeDir))->replaceWith(
				['app/a.latte' => [self::record('App\\Foo', null, 'setFile', 'happens')]],
				['App\\Foo'],
				[],
			);
			$afterRecordChange = (new DiscoveryStore($storeDir))->recordsSaltForTemplateClass($templateClass);
			self::assertNotSame($initial, $afterRecordChange);

			(new DiscoveryStore($storeDir))->replaceWith(
				['app/a.latte' => [self::record('App\\Foo', null, 'setFile', 'happens')]],
				['App\\Foo'],
				['app/never-linked.latte'],
			);
			self::assertSame(
				$afterRecordChange,
				(new DiscoveryStore($storeDir))->recordsSaltForTemplateClass($templateClass),
				'an empty template file appearing must not churn its own record salt',
			);

			$fresh = new DiscoveryStore($storeDir);
			self::assertSame(
				'norecords',
				$fresh->recordsSaltForTemplateClass(TemplateClassName::forPath('app/never-linked.latte')),
				'a record-less template must get a stable salt value, never an empty string',
			);
			self::assertSame(
				'norecords',
				$fresh->recordsSaltForTemplateClass(TemplateClassName::forPath('app/not-in-store.latte')),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testRecordSaltIsPerTemplateSoAnotherTemplatesRecordsNeverMoveIt(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, []);
			$classA = TemplateClassName::forPath('app/a.latte');
			$classB = TemplateClassName::forPath('app/b.latte');

			$records = [
				'app/a.latte' => [self::record('App\\Foo', null, 'setFile', 'unknown')],
				'app/b.latte' => [self::record('App\\Bar', null, 'setFile', 'unknown')],
			];
			(new DiscoveryStore($storeDir))->replaceWith($records, ['App\\Bar', 'App\\Foo'], []);

			$before = new DiscoveryStore($storeDir);
			$beforeA = $before->recordsSaltForTemplateClass($classA);
			$beforeB = $before->recordsSaltForTemplateClass($classB);

			$records['app/a.latte'] = [self::record('App\\Foo', null, 'setFile', 'happens')];
			(new DiscoveryStore($storeDir))->replaceWith($records, ['App\\Bar', 'App\\Foo'], []);

			$after = new DiscoveryStore($storeDir);
			self::assertNotSame($beforeA, $after->recordsSaltForTemplateClass($classA));
			self::assertSame(
				$beforeB,
				$after->recordsSaltForTemplateClass($classB),
				'one template\'s record change must never move another template\'s salt - the compile cache '
				. 'persists across runs and would otherwise lose every entry on any single-record change',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testTemplateSetHashTracksTheFileSetButNotRecordContent(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, ['app/a.latte']);

			$initial = (new DiscoveryStore($storeDir))->templateSetHash();

			(new DiscoveryStore($storeDir))->replaceWith(
				['app/a.latte' => [self::record('App\\Foo', null, 'setFile', 'unknown')]],
				['App\\Foo'],
				[],
			);
			self::assertSame(
				$initial,
				(new DiscoveryStore($storeDir))->templateSetHash(),
				'record content must never enter the template-set hash - record changes propagate through '
				. 'per-template file refs, never a whole-cache invalidation',
			);

			(new DiscoveryStore($storeDir))->replaceWith([], [], ['app/b.latte']);
			self::assertNotSame(
				$initial,
				(new DiscoveryStore($storeDir))->templateSetHash(),
				'a template file appearing must change the template-set hash - its cached parse predates the self-ref',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testTemplateFileShapeIsPinnedByteForByte(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, []);

			(new DiscoveryStore($storeDir))->replaceWith(
				['a.latte' => [self::record('App\\Foo', null, 'setFile', 'unknown')]],
				['App\\Foo'],
				[],
			);

			$className = 'LatteDiscovery_a_latte_' . substr(md5('a.latte'), 0, 8);
			$recordsExport = "[\n"
				. "\t\t[\n"
				. "\t\t\t'class' => 'App\\\\Foo',\n"
				. "\t\t\t'view' => null,\n"
				. "\t\t\t'kind' => 'setFile',\n"
				. "\t\t\t'certainty' => 'unknown',\n"
				. "\t\t],\n"
				. "\t]";
			$hash = sha1($recordsExport);

			self::assertSame(
				"<?php declare(strict_types = 1);\n\n"
				. "if (!class_exists(\\{$className}::class, false)) {\n"
				. "\tclass {$className}\n"
				. "\t{\n\n"
				. "\t\tpublic const RECORDS_HASH = '{$hash}';\n\n"
				. "\t}\n"
				. "}\n\n"
				. "return [\n"
				. "\t'template' => 'a.latte',\n"
				. "\t'records' => {$recordsExport},\n"
				. "];\n",
				FileSystem::read($storeDir . '/' . DiscoveryClassName::forPath('a.latte') . '.php'),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testMalformedStoreFilesAreSkippedInsteadOfCrashing(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, []);

			(new DiscoveryStore($storeDir))->replaceWith(
				['app/a.latte' => [self::record('App\\Foo', null, 'setFile', 'unknown')]],
				['App\\Foo'],
				[],
			);

			FileSystem::write($storeDir . '/LatteDiscovery_broken_00000000.php', "<?php return 'not an array';\n");
			FileSystem::write(
				$storeDir . '/LatteDiscovery_badshape_00000000.php',
				"<?php return ['template' => 'x.latte', 'records' => [['class' => 42]]];\n",
			);

			$fresh = new DiscoveryStore($storeDir);
			self::assertSame(['app/a.latte'], $fresh->allLinkedTemplates());
			self::assertSame([], $fresh->recordsForTemplate('x.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testCorruptIndexRecomputesTheUniverseInsteadOfDroppingRecords(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, []);
			(new DiscoveryStore($storeDir))->replaceWith(
				[
					'app/a.latte' => [self::record('App\\Foo', null, 'setFile', 'unknown')],
					'app/b.latte' => [self::record('App\\Bar', null, 'layout', 'unknown')],
				],
				['App\\Bar', 'App\\Foo'],
				[],
			);

			$corrupt = [
				'malformed entry' => ["<?php return ['App\\\\Kept', 42];\n", ['App\\Bar', 'App\\Foo', 'App\\Kept']],
				'not an array' => ["<?php return 'nope';\n", ['App\\Bar', 'App\\Foo']],
				'unparseable' => ["<?php return [;\n", ['App\\Bar', 'App\\Foo']],
				'deleted' => [null, ['App\\Bar', 'App\\Foo']],
			];

			foreach ($corrupt as $case => [$contents, $expected]) {
				if ($contents === null) {
					FileSystem::delete($storeDir . '/class-index.php');
				} else {
					FileSystem::write($storeDir . '/class-index.php', $contents);
				}

				$store = new DiscoveryStore($storeDir);
				self::assertSame(
					$expected,
					$store->linkedClasses(),
					$case . ': an unusable index means "unavailable, recompute" - dropping the classes that own '
					. 'records would make the writer empty their store files',
				);
				self::assertSame(
					[self::record('App\\Foo', null, 'setFile', 'unknown')],
					$store->recordsForTemplate('app/a.latte'),
					$case . ': records must still resolve',
				);
			}
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testMissingStoreDirectoryDegradesToEmptyEverything(): void
	{
		$store = new DiscoveryStore($this->scratchDir() . '/never-created');

		self::assertSame([], $store->recordsForTemplate('a.latte'));
		self::assertSame([], $store->allLinkedTemplates());
		self::assertSame([], $store->linkedClasses());
		self::assertFalse($store->hasTemplateFile('a.latte'));
	}

	/**
	 * @return array{class: string, view: string|null, kind: string, certainty: string}
	 */
	private static function record(string $class, ?string $view, string $kind, string $certainty): array
	{
		return ['class' => $class, 'view' => $view, 'kind' => $kind, 'certainty' => $certainty];
	}

	private function scratchDir(): string
	{
		return sys_get_temp_dir() . '/latte-discovery-store-test-' . getmypid() . '-' . uniqid('', true);
	}

}
