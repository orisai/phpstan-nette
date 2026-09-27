<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\DependencyRecorder;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function is_dir;
use function is_file;
use function sys_get_temp_dir;
use function uniqid;

final class DependencyValidatedCacheTest extends BaseTestCase
{

	/** @var list<string> */
	private array $paths = [];

	protected function tearDown(): void
	{
		parent::tearDown();

		foreach ($this->paths as $path) {
			if (is_dir($path) || is_file($path)) {
				FileSystem::delete($path);
			}
		}

		$this->paths = [];
	}

	public function testPersistsAcrossInstancesWhileDependencyUnchanged(): void
	{
		$dir = $this->freshDir();
		$dep = $this->tempFile('<?php // v1');

		$recorder = new DependencyRecorder();
		$first = new FormShapeCache($dir, $recorder);
		$first->loadInterprocedural('k', static function () use ($recorder, $dep): FormShape {
			$recorder->record($dep);

			return FormShape::empty('Persisted');
		});

		// A separate instance (a new run) reads the entry from disk; the file is unchanged, so
		// it must be reused rather than recomputed.
		$second = new FormShapeCache($dir, new DependencyRecorder());
		$served = $second->loadInterprocedural('k', static function (): FormShape {
			self::fail('a persisted entry must be reused while its dependency is unchanged');
		});

		self::assertNotNull($served);
		self::assertSame('Persisted', $served->getClassName());
	}

	public function testEditedDependencyInvalidatesAcrossInstances(): void
	{
		$dir = $this->freshDir();
		$dep = $this->tempFile('<?php // v1');

		$recorder = new DependencyRecorder();
		$first = new FormShapeCache($dir, $recorder);
		$first->loadInterprocedural('k', static function () use ($recorder, $dep): FormShape {
			$recorder->record($dep);

			return FormShape::empty('Old');
		});

		FileSystem::write($dep, '<?php // v2 edited');

		$recorder2 = new DependencyRecorder();
		$second = new FormShapeCache($dir, $recorder2);
		$recomputed = 0;
		$after = $second->loadInterprocedural('k', static function () use (&$recomputed, $recorder2, $dep): FormShape {
			$recomputed++;
			$recorder2->record($dep);

			return FormShape::empty('New');
		});

		self::assertSame(1, $recomputed, 'an edited dependency must invalidate the persisted entry');
		self::assertNotNull($after);
		self::assertSame('New', $after->getClassName());
	}

	public function testReusedSubShapeContributesItsDependencies(): void
	{
		$dir = $this->freshDir();
		$childDep = $this->tempFile('<?php // child v1');

		$recorder = new DependencyRecorder();
		$build = new FormShapeCache($dir, $recorder);
		// The child records its own source. The parent then reuses the child straight from the
		// in-memory cache; that reuse must still carry the child's dependency into the parent.
		$build->loadInterprocedural('child', static function () use ($recorder, $childDep): FormShape {
			$recorder->record($childDep);

			return FormShape::empty('Child');
		});
		$build->loadInterprocedural('parent', static function () use ($build): FormShape {
			self::assertNotNull($build->lookupInterprocedural('child'));

			return FormShape::empty('Parent');
		});

		FileSystem::write($childDep, '<?php // child v2');

		// A fresh run must recompute the PARENT: it transitively depends on the edited child
		// source even though the parent's own generator never read that file directly.
		$nextRecorder = new DependencyRecorder();
		$next = new FormShapeCache($dir, $nextRecorder);
		$next->loadInterprocedural('child', static function () use ($nextRecorder, $childDep): FormShape {
			$nextRecorder->record($childDep);

			return FormShape::empty('Child2');
		});
		$parentRecomputed = 0;
		$next->loadInterprocedural('parent', static function () use (&$parentRecomputed, $next): FormShape {
			$parentRecomputed++;
			$next->lookupInterprocedural('child');

			return FormShape::empty('Parent2');
		});

		self::assertSame(
			1,
			$parentRecomputed,
			'editing a reused sub-shape source must invalidate the parent that reused it',
		);
	}

	public function testCodeVersionChangeIsolatesInterproceduralEntries(): void
	{
		$base = $this->freshDir();

		$v1 = new FormShapeCache($base, null, null, 'code-v1');
		$v1->storeInterprocedural('Sample\\X#form', FormShape::empty('FromV1'), []);
		self::assertNotNull($v1->lookupInterprocedural('Sample\\X#form'));

		// A changed analyser version lands in a separate namespaced directory, so it must not
		// see interprocedural entries written under the previous version (their keys, unlike
		// the remember cache, do not embed the version — the directory is their only isolation).
		$v2 = new FormShapeCache($base, null, null, 'code-v2');
		self::assertNull(
			$v2->lookupInterprocedural('Sample\\X#form'),
			'a code-version change must not serve interprocedural entries from the prior version',
		);

		// Isolation, not deletion: the original version still serves its own entry from disk.
		$served = (new FormShapeCache($base, null, null, 'code-v1'))->lookupInterprocedural('Sample\\X#form');
		self::assertNotNull($served);
		self::assertSame('FromV1', $served->getClassName());
	}

	private function freshDir(): string
	{
		$dir = sys_get_temp_dir() . '/dep-validated-cache-' . uniqid('', true);
		$this->paths[] = $dir;

		return $dir;
	}

	private function tempFile(string $content): string
	{
		$file = sys_get_temp_dir() . '/dep-validated-src-' . uniqid('', true) . '.php';
		FileSystem::write($file, $content);
		$this->paths[] = $file;

		return $file;
	}

}
