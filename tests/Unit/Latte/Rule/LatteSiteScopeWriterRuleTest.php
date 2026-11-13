<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use Nette\IOException;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\SliceClassName;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Rule\LatteAnalyzedFileMarkerCollector;
use OriPhpstan\Nette\Latte\Rule\LatteEdgeScopeCollector;
use OriPhpstan\Nette\Latte\Rule\LatteSiteScopeWriterRule;
use OriPhpstan\Nette\Support\RerunValueExtension;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\FileRuleError;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\LineRuleError;
use PHPStan\Rules\NonIgnorableRuleError;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Toolkit\TrackingScope;
use function chmod;
use function getmypid;
use function sys_get_temp_dir;
use function uniqid;

final class LatteSiteScopeWriterRuleTest extends BaseTestCase
{

	// Opt-in gate: disabled must never write, even into an already-existing,
	// already-populated store directory - proven by seeding real content first and asserting the
	// directory's file listing is byte-for-byte unchanged after processNode() runs with data that
	// WOULD trigger a write if enabled.
	public function testDisabledNeverWritesEvenIntoAnAlreadyPopulatedStoreDirectory(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			SiteScopeStore::bootstrap($storeDir, []);

			$seed = new SiteScopeStore($storeDir);
			$seed->replaceForIncluders(
				['a.latte'],
				['a.latte#1#old-target.latte#ctx1' => ['sha' => 'sha-a', 'vars' => ['x' => 'string'], 'args' => []]],
			);
			$sliceFile = $storeDir . '/' . SliceClassName::forPath('a.latte') . '.php';
			$before = FileSystem::read($sliceFile);

			$rule = $this->writer($dir, ['/project/app'], ['/project/app/'], false);
			$errors = $rule->processNode($this->collectedDataNode([
				$this->entry('a.latte#2#new-target.latte#ctx1', 'sha-a2', ['z' => 'bool'], []),
			]), $this->scope());

			self::assertSame([], $errors);
			self::assertSame(
				$before,
				FileSystem::read($sliceFile),
				'disabled must never touch an already-existing slice file, even with new collected data',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testAnAbsentStoreDirectoryIsCreatedByTheFirstWrite(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$errors = $this->writer($dir)->processNode($this->collectedDataNode([
				$this->entry('a.latte#1#b.latte#ctx', 'sha-a', ['x' => 'string'], []),
			]), $this->scope());

			$this->assertStoreChanged(
				'The Latte narrowing store changed for 1 including template: a.latte. '
					. 'Run PHPStan again until this error is gone, then commit the regenerated store files.',
				$storeDir . '/' . SliceClassName::forPath('a.latte') . '.php',
				$errors,
			);
			self::assertEquals(
				['sha' => 'sha-a', 'vars' => ['x' => 'string'], 'args' => []],
				(new SiteScopeStore($storeDir))->get('a.latte#1#b.latte#ctx', 'sha-a'),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The writer's errors and its writes depend on the store on disk, never on the collected data
	// alone, so reuseCollectedDataRuleErrors must never skip it: it declares a value no two runs share.
	public function testTheWriterIsNeverReplayedFromAPreviousRun(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$rule = new LatteSiteScopeWriterRule(
				TestGuard::latte(true, true),
				new SiteScopeStore($storeDir),
				$dir,
				[],
				[],
			);
			$scope = $this->createMock(TrackingScope::class);
			$scope->expects(self::once())
				->method('trackValueDependency')
				->with(RerunValueExtension::class, LatteSiteScopeWriterRule::class);

			$rule->processNode($this->collectedDataNode([]), $scope);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testGroupsCollectedEntriesByIncluderRelPathAndWritesThem(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			SiteScopeStore::bootstrap($storeDir, []);

			$keyA = 'a.latte#1#target.latte#ctx1';
			$keyB = 'b.latte#5#target.latte#ctx2';
			$rule = $this->writer($dir);

			$errors = $rule->processNode($this->collectedDataNode([
				$this->entry($keyA, 'sha-a', ['x' => 'string'], ['item' => 'int']),
				$this->entry($keyB, 'sha-b', [], ['item' => 'string']),
			]), $this->scope());

			$this->assertStoreChanged(
				'The Latte narrowing store changed for 2 including templates: a.latte, b.latte. '
					. 'Run PHPStan again until this error is gone, then commit the regenerated store files.',
				$storeDir . '/' . SliceClassName::forPath('a.latte') . '.php',
				$errors,
			);

			// assertEquals, not assertSame: the store canonicalizes (ksorts) entry key order by
			// design, so the returned array's key order need not match the caller's insertion order.
			$fresh = new SiteScopeStore($storeDir);
			self::assertEquals(
				['sha' => 'sha-a', 'vars' => ['x' => 'string'], 'args' => ['item' => 'int']],
				$fresh->get($keyA, 'sha-a'),
			);
			self::assertEquals(
				['sha' => 'sha-b', 'vars' => [], 'args' => ['item' => 'string']],
				$fresh->get($keyB, 'sha-b'),
			);
			self::assertTrue($fresh->hasSlice('a.latte'));
			self::assertTrue($fresh->hasSlice('b.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testClearsStaleEntriesForIncludersReanalysedThisRunButNotOtherIncluders(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$this->templates($dir, ['b.latte']);
			SiteScopeStore::bootstrap($storeDir, []);

			$seed = new SiteScopeStore($storeDir);
			$staleKey = 'a.latte#1#old-target.latte#ctx1';
			$untouchedKey = 'b.latte#1#target.latte#ctx1';
			$seed->replaceForIncluders(
				['a.latte', 'b.latte'],
				[
					$staleKey => ['sha' => 'sha-a', 'vars' => ['x' => 'string'], 'args' => []],
					$untouchedKey => ['sha' => 'sha-b', 'vars' => ['y' => 'int'], 'args' => []],
				],
			);

			$rule = $this->writer($dir);
			$freshKey = 'a.latte#2#new-target.latte#ctx1';
			$rule->processNode($this->collectedDataNode([
				$this->entry($freshKey, 'sha-a2', ['z' => 'bool'], []),
			]), $this->scope());

			$fresh = new SiteScopeStore($storeDir);
			self::assertNull($fresh->get($staleKey, 'sha-a'), 'a.latte was reanalysed - its stale key must be dropped');
			self::assertEquals(
				['sha' => 'sha-a2', 'vars' => ['z' => 'bool'], 'args' => []],
				$fresh->get($freshKey, 'sha-a2'),
			);
			self::assertEquals(
				['sha' => 'sha-b', 'vars' => ['y' => 'int'], 'args' => []],
				$fresh->get($untouchedKey, 'sha-b'),
				'b.latte was not part of this run - its entry must survive untouched',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDropsStaleEntriesForAnIncluderReanalyzedWithZeroCapturesThisRun(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			SiteScopeStore::bootstrap($storeDir, []);

			$seed = new SiteScopeStore($storeDir);
			$staleKey = 'a.latte#1#old-target.latte#ctx1';
			$seed->replaceForIncluders(['a.latte'], [
				$staleKey => ['sha' => 'sha-a', 'vars' => ['x' => 'string'], 'args' => []],
			]);

			$rule = $this->writer($dir);

			// a.latte was genuinely reanalyzed this run (e.g. a pipeline change that stopped
			// emitting the anchor it used to) but LatteEdgeScopeCollector captured nothing for it -
			// only the file marker collector reports it.
			$data = new CollectedDataNode(
				['/project/a.latte' => [LatteAnalyzedFileMarkerCollector::class => ['a.latte']]],
				false,
			);

			$rule->processNode($data, $this->scope());

			$fresh = new SiteScopeStore($storeDir);
			self::assertNull(
				$fresh->get($staleKey, 'sha-a'),
				'a.latte was reanalyzed this run (per the file marker) despite zero captures - its stale entry must be dropped',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testKeepsEntriesForAnIncluderNotAnalyzedThisRunAtAll(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$this->templates($dir, ['b.latte']);
			SiteScopeStore::bootstrap($storeDir, []);

			$seed = new SiteScopeStore($storeDir);
			$untouchedKey = 'b.latte#1#target.latte#ctx1';
			$seed->replaceForIncluders(['b.latte'], [
				$untouchedKey => ['sha' => 'sha-b', 'vars' => ['y' => 'int'], 'args' => []],
			]);

			$rule = $this->writer($dir);

			// Only a.latte is analyzed this run - b.latte appears in neither collector's output at all.
			$data = new CollectedDataNode(
				['/project/a.latte' => [LatteAnalyzedFileMarkerCollector::class => ['a.latte']]],
				false,
			);

			$rule->processNode($data, $this->scope());

			$fresh = new SiteScopeStore($storeDir);
			self::assertEquals(
				['sha' => 'sha-b', 'vars' => ['y' => 'int'], 'args' => []],
				$fresh->get($untouchedKey, 'sha-b'),
				'b.latte was not part of this run at all - its entry must survive untouched',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testRunningTwiceWithTheSameDataLeavesTheSliceFileByteIdentical(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			SiteScopeStore::bootstrap($storeDir, []);

			$key = 'a.latte#1#target.latte#ctx1';
			$data = $this->collectedDataNode([
				$this->entry($key, 'sha-a', ['x' => 'string'], []),
			]);

			$this->writer($dir)->processNode(
				$data,
				$this->scope(),
			);
			$sliceFile = $storeDir . '/' . SliceClassName::forPath('a.latte') . '.php';
			$afterFirstRun = FileSystem::read($sliceFile);

			$secondErrors = $this->writer($dir)->processNode(
				$data,
				$this->scope(),
			);
			$afterSecondRun = FileSystem::read($sliceFile);

			self::assertSame([], $secondErrors, 'an unchanged store must not be reported');

			self::assertSame($afterFirstRun, $afterSecondRun, 'value-equal write must leave the slice file untouched');
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testWriteFailurePropagatesInsteadOfBeingSilentlySwallowed(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			SiteScopeStore::bootstrap($storeDir, []);

			chmod($storeDir, 0555);

			try {
				$rule = $this->writer($dir);

				$this->expectException(IOException::class);

				$rule->processNode($this->collectedDataNode([
					$this->entry('a.latte#1#target.latte#ctx1', 'sha-a', ['x' => 'string'], []),
				]), $this->scope());
			} finally {
				chmod($storeDir, 0755);
			}
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testReportsAnIncluderReanalysedWithZeroCapturesWhoseSliceChanged(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$this->templates($dir, ['b.latte']);
			SiteScopeStore::bootstrap($storeDir, ['b.latte']);

			$seed = new SiteScopeStore($storeDir);
			$seed->replaceForIncluders(['a.latte'], [
				'a.latte#1#old-target.latte#ctx1' => ['sha' => 'sha-a', 'vars' => ['x' => 'string'], 'args' => []],
			]);

			$rule = $this->writer($dir);
			$errors = $rule->processNode(new CollectedDataNode(
				[
					'/project/a.latte' => [LatteAnalyzedFileMarkerCollector::class => ['a.latte']],
					'/project/b.latte' => [LatteAnalyzedFileMarkerCollector::class => ['b.latte']],
				],
				false,
			), $this->scope());

			$this->assertStoreChanged(
				'The Latte narrowing store changed for 1 including template: a.latte. '
					. 'Run PHPStan again until this error is gone, then commit the regenerated store files.',
				$storeDir . '/' . SliceClassName::forPath('a.latte') . '.php',
				$errors,
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testCapsTheListedIncluders(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			SiteScopeStore::bootstrap($storeDir, []);

			$captures = [];
			for ($i = 10; $i < 23; $i++) {
				$captures[] = $this->entry('t' . $i . '.latte#1#target.latte#ctx', 'sha', ['x' => 'string'], []);
			}

			$rule = $this->writer($dir);
			$errors = $rule->processNode($this->collectedDataNode($captures), $this->scope());

			$this->assertStoreChanged(
				'The Latte narrowing store changed for 13 including templates: t10.latte, t11.latte, t12.latte, '
					. 't13.latte, t14.latte, t15.latte, t16.latte, t17.latte, t18.latte, t19.latte (+3 more). '
					. 'Run PHPStan again until this error is gone, then commit the regenerated store files.',
				$storeDir . '/' . SliceClassName::forPath('t10.latte') . '.php',
				$errors,
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testPrunesTheSliceOfADeletedTemplateInAnyRun(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$this->templates($dir, ['a.latte', 'unanalysed.latte']);
			SiteScopeStore::bootstrap($storeDir, ['a.latte', 'unanalysed.latte', 'gone.latte']);
			$data = new CollectedDataNode(
				['/project/a.latte' => [LatteAnalyzedFileMarkerCollector::class => ['a.latte']]],
				false,
			);

			$errors = $this->writer($dir, ['/project/app/Admin'])->processNode($data, $this->scope());

			$this->assertStoreChanged(
				'The Latte narrowing store removed 1 slice of deleted templates: '
					. SliceClassName::forPath('gone.latte') . '.php. '
					. 'Run PHPStan again until this error is gone, then commit the regenerated store files.',
				$storeDir . '/' . SliceClassName::forPath('a.latte') . '.php',
				$errors,
			);
			self::assertFileDoesNotExist($storeDir . '/' . SliceClassName::forPath('gone.latte') . '.php');
			self::assertFileExists(
				$storeDir . '/' . SliceClassName::forPath('unanalysed.latte') . '.php',
				'a template outside this run still exists, so its slice stays',
			);
			self::assertSame([], $this->writer($dir)->processNode($data, $this->scope()));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testNamesChangedAndRemovedSlicesInOneError(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$this->templates($dir, ['a.latte']);
			SiteScopeStore::bootstrap($storeDir, ['a.latte', 'gone.latte']);

			$errors = $this->writer($dir)->processNode(
				$this->collectedDataNode([$this->entry('a.latte#1#t.latte#ctx', 'sha', ['x' => 'int'], [])]),
				$this->scope(),
			);

			$this->assertStoreChanged(
				'The Latte narrowing store changed for 1 including template: a.latte. '
					. 'The Latte narrowing store removed 1 slice of deleted templates: '
					. SliceClassName::forPath('gone.latte') . '.php. '
					. 'Run PHPStan again until this error is gone, then commit the regenerated store files.',
				$storeDir . '/' . SliceClassName::forPath('a.latte') . '.php',
				$errors,
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testAnEmptyHeaderlessSliceIsPrunedOnlyByARunOverTheConfiguredPaths(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$this->templates($dir, ['a.latte']);
			SiteScopeStore::bootstrap($storeDir, ['a.latte']);
			$legacy = $storeDir . '/' . SliceClassName::forPath('legacy.latte') . '.php';
			FileSystem::write($legacy, "<?php declare(strict_types = 1);\n\nreturn [];\n");
			$data = new CollectedDataNode(
				['/project/a.latte' => [LatteAnalyzedFileMarkerCollector::class => ['a.latte']]],
				false,
			);

			self::assertSame([], $this->writer($dir, ['/project/app/Admin'])->processNode($data, $this->scope()));
			self::assertFileExists($legacy, 'a run over part of the paths cannot tell which template it belonged to');

			self::assertSame([], $this->writer($dir)->processNode(new CollectedDataNode([], false), $this->scope()));
			self::assertFileExists($legacy, 'a run which analysed no template does not empty the store');

			$errors = $this->writer($dir)->processNode($data, $this->scope());
			self::assertCount(1, $errors);
			self::assertStringStartsWith(
				'The Latte narrowing store removed 1 slice of deleted templates: ',
				$errors[0]->getMessage(),
			);
			self::assertFileDoesNotExist($legacy);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $analysedPaths
	 * @param list<string> $analysedPathsFromConfig
	 */
	private function writer(
		string $dir,
		array $analysedPaths = ['/project/app'],
		array $analysedPathsFromConfig = ['/project/app/'],
		bool $enabled = true
	): LatteSiteScopeWriterRule
	{
		return new LatteSiteScopeWriterRule(
			TestGuard::latte(true, $enabled),
			new SiteScopeStore($dir . '/store'),
			$dir . '/project',
			$analysedPaths,
			$analysedPathsFromConfig,
		);
	}

	/**
	 * @param list<string> $rels
	 */
	private function templates(string $dir, array $rels): void
	{
		foreach ($rels as $rel) {
			FileSystem::write($dir . '/project/' . $rel, '');
		}
	}

	/**
	 * @param list<IdentifierRuleError> $errors
	 */
	private function assertStoreChanged(string $message, string $file, array $errors): void
	{
		self::assertCount(1, $errors);
		$error = $errors[0];
		self::assertSame($message, $error->getMessage());
		self::assertSame('orisai.nette.latte.narrowingStoreChanged', $error->getIdentifier());
		self::assertInstanceOf(NonIgnorableRuleError::class, $error);
		self::assertInstanceOf(FileRuleError::class, $error);
		self::assertSame($file, $error->getFile());
		self::assertInstanceOf(LineRuleError::class, $error);
		self::assertSame(1, $error->getLine());
	}

	/**
	 * @param list<array{key: string, sha: string, vars: array<string, string>, args: array<string, string>}> $captures
	 */
	private function collectedDataNode(array $captures): CollectedDataNode
	{
		return new CollectedDataNode(
			['/project/anyfile.latte' => [LatteEdgeScopeCollector::class => $captures]],
			false,
		);
	}

	/**
	 * @param array<string, string> $vars
	 * @param array<string, string> $args
	 * @return array{key: string, sha: string, vars: array<string, string>, args: array<string, string>}
	 */
	private function entry(string $key, string $sha, array $vars, array $args): array
	{
		return ['key' => $key, 'sha' => $sha, 'vars' => $vars, 'args' => $args];
	}

	/**
	 * @return Scope&DependencyTracker
	 */
	private function scope(): Scope
	{
		return $this->createStub(TrackingScope::class);
	}

	private function scratchDir(): string
	{
		return sys_get_temp_dir() . '/latte-sitescope-writer-test-' . getmypid() . '-' . uniqid('', true);
	}

}
