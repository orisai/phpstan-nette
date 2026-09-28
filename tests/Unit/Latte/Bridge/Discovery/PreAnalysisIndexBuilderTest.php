<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Discovery;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\Discovery\PreAnalysisIndexBuilder;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use PHPStan\File\FileExcluder;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryStoreActionPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryStoreLinkedControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryStoreTrailingRendererControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LiteralAssignmentTarget;
use function basename;
use function chmod;
use function getmypid;
use function glob;
use function is_readable;
use function ksort;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

final class PreAnalysisIndexBuilderTest extends PHPStanTestCase
{

	private const LINKED_TEMPLATE_REL = 'App/discoveryStoreLinked.latte';

	private const ACTION_TEMPLATE_REL = 'App/discoveryStoreAction.detail.latte';

	public function testConfiguredUniverseProducesEveryRendererRecordBeforeAnalysis(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';

			$this->builder($storeDir, $dir, [
				$this->fixtureFile('DiscoveryStoreLinkedControl.php'),
				$this->fixtureFile('DiscoveryStoreActionPresenter.php'),
			])->build();

			$fresh = new DiscoveryStore($storeDir);
			self::assertSame(
				[
					[
						'class' => DiscoveryStoreLinkedControl::class,
						'view' => null,
						'kind' => 'setFile',
						'certainty' => 'unknown',
					],
				],
				$fresh->recordsForTemplate(self::LINKED_TEMPLATE_REL),
			);
			self::assertSame(
				[
					[
						'class' => DiscoveryStoreActionPresenter::class,
						'view' => 'detail',
						'kind' => 'setFile',
						'certainty' => 'happens',
					],
				],
				$fresh->recordsForTemplate(self::ACTION_TEMPLATE_REL),
			);
			self::assertSame(
				[self::ACTION_TEMPLATE_REL, self::LINKED_TEMPLATE_REL],
				$fresh->allLinkedTemplates(),
			);
			self::assertSame(
				[DiscoveryStoreActionPresenter::class, DiscoveryStoreLinkedControl::class],
				$fresh->linkedClasses(),
				'the index is derived from the configured universe alone - no collected data, no '
				. 'previous run\'s index',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testClassCarryingNoDiscoveryIsSkipped(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';

			$this->builder($storeDir, $dir, [
				$this->fixtureFile('DiscoveryStoreLinkedControl.php'),
				$this->fixtureFile('LiteralAssignmentTarget.php'),
			])->build();

			$fresh = new DiscoveryStore($storeDir);
			self::assertNotContains(
				LiteralAssignmentTarget::class,
				$fresh->linkedClasses(),
				'a first-party class with no template surface carries no discovery at all - indexing it '
				. 'would make the store claim a link nothing derived',
			);
			self::assertSame([DiscoveryStoreLinkedControl::class], $fresh->linkedClasses());
			self::assertSame([self::LINKED_TEMPLATE_REL], $fresh->allLinkedTemplates());
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The store directory is itself inside the universe here - the layout a consumer who points
	// orisaiNette.latte.discovery.storePath back inside %paths% gets, to keep the granular invalidation channel. The
	// second build therefore enumerates the first build's OWN generated LatteDiscovery_* files, and
	// byte-identity is what proves that output never feeds back into the index.
	public function testBuildingTwiceOverAnUnchangedTreeWritesIdenticalBytes(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$paths = [
				$this->fixtureFile('DiscoveryStoreLinkedControl.php'),
				$this->fixtureFile('DiscoveryStoreActionPresenter.php'),
				$storeDir,
			];

			$this->builder($storeDir, $dir, $paths)->build();
			$afterFirst = $this->snapshot($storeDir);

			$this->builder($storeDir, $dir, $paths)->build();

			self::assertSame($afterFirst, $this->snapshot($storeDir));
			self::assertNotSame([], $afterFirst);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// excludePaths is part of the universe's identity, not a refinement of it: an excluded file is
	// never analysed, so no analysis of this configuration can attribute a render to the class in it.
	// A build ignoring the exclusions claims links no such analysis can see - measured on this project
	// as three fixture templates and their classes.
	public function testAnExcludedFileNamesNoClassTheIndexMayHold(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$excluded = $this->fixtureFile('DiscoveryStoreActionPresenter.php');

			$this->builder(
				$storeDir,
				$dir,
				[$this->fixtureFile('DiscoveryStoreLinkedControl.php'), $excluded],
				null,
				new FileExcluder(new FileHelper($dir), [$excluded]),
			)->build();

			$fresh = new DiscoveryStore($storeDir);
			self::assertSame([DiscoveryStoreLinkedControl::class], $fresh->linkedClasses());
			self::assertSame([self::LINKED_TEMPLATE_REL], $fresh->allLinkedTemplates());
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The class-kind gate is part of that identity too: a trait's body only ever runs as part of the
	// class using it, so a trait carrying a render-side surface is a name no analysis of the same
	// configuration ever attributes a render to, and it must stay out of the store.
	public function testATraitCarryingARenderSurfaceIsNotAClassAndStaysOutOfTheIndex(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';

			$this->builder($storeDir, $dir, [
				$this->fixtureFile('DiscoveryStoreLinkedControl.php'),
				$this->fixtureFile('DiscoveryStoreRenderingTrait.php'),
			])->build();

			$fresh = new DiscoveryStore($storeDir);
			self::assertSame([DiscoveryStoreLinkedControl::class], $fresh->linkedClasses());
			self::assertSame([self::LINKED_TEMPLATE_REL], $fresh->allLinkedTemplates());
		} finally {
			FileSystem::delete($dir);
		}
	}

	// A file may declare more than one class, and PHP moving a class INTO a file that already
	// declares one is an ordinary refactor - FileLevelInvalidationTest's class-moved row is exactly
	// that. The universe must therefore be every class-like in the file, never the file's first: the
	// aggregate collector this build replaces reported per CLASS, so a first-only walk silently drops
	// the second renderer's records the moment such a move happens.
	public function testEveryClassInAFileEntersTheUniverseNotJustTheFirst(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';

			$this->builder($storeDir, $dir, [
				$this->fixtureFile('DiscoveryStoreTrailingRendererControl.php'),
			])->build();

			$fresh = new DiscoveryStore($storeDir);
			self::assertSame(
				[DiscoveryStoreTrailingRendererControl::class],
				$fresh->linkedClasses(),
				'the renderer is the file\'s SECOND class-like - a first-only universe never sees it',
			);
			self::assertSame(['App/discoveryStoreTrailingRenderer.latte'], $fresh->allLinkedTemplates());
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testAbsentAndUnreadableClassesAreSkippedRatherThanFatal(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$junkDir = $dir . '/junk';

			FileSystem::write(
				$junkDir . '/AbsentRenderer.php',
				"<?php declare(strict_types = 1);\n\nnamespace NoSuchNamespace;\n\n"
				. "final class AbsentRenderer\n{\n\n}\n",
			);
			FileSystem::write($junkDir . '/no-class-like.php', "<?php declare(strict_types = 1);\n\nreturn [];\n");

			$unreadable = $junkDir . '/Unreadable.php';
			FileSystem::write(
				$unreadable,
				"<?php declare(strict_types = 1);\n\nnamespace NoSuchNamespace;\n\n"
				. "final class Unreadable\n{\n\n}\n",
			);
			chmod($unreadable, 0);
			self::assertFalse(is_readable($unreadable), 'the unreadable half of this row needs a file it cannot read');

			$this->builder($storeDir, $dir, [
				$this->fixtureFile('DiscoveryStoreLinkedControl.php'),
				$junkDir,
				$dir . '/never-existed.php',
			])->build();

			$fresh = new DiscoveryStore($storeDir);
			self::assertSame(
				[DiscoveryStoreLinkedControl::class],
				$fresh->linkedClasses(),
				'a file declaring a class reflection cannot find, one declaring no class-like at all, one '
				. 'that cannot be read and a path that does not exist are all skipped - the universe walk '
				. 'must survive every one of them and still index the renderer beside them',
			);
			self::assertSame([self::LINKED_TEMPLATE_REL], $fresh->allLinkedTemplates());
		} finally {
			// No chmod back: unlink needs write access on the DIRECTORY, never on the file itself.
			FileSystem::delete($dir);
		}
	}

	// The template half of the same universe: every .latte under %paths% MINUS %excludePaths% gets a
	// (possibly empty) store file, so DiscoveryRefResolver's file-exists gate lets it reference its own
	// discovery class from its very first parse. An analysed template INCLUDING an excluded one must
	// not resurrect it - an include edge is not authority over a file this configuration never
	// analyses, and the aggregate writer this replaces could not have materialized one either, because
	// its set came from LatteAnalyzedFileMarkerCollector firing on analysed files only.
	public function testAnExcludedTemplateGetsNoStoreFileEvenWhenAnAnalysedTemplateIncludesIt(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$templates = $dir . '/templates';
			$excluded = $templates . '/excluded.latte';
			FileSystem::write($templates . '/includer.latte', "{include 'excluded.latte'}\n");
			FileSystem::write($excluded, "<p>excluded</p>\n");

			$this->builder(
				$storeDir,
				$dir,
				[],
				new LatteUniverse([$templates], $dir),
				new FileExcluder(new FileHelper($dir), [$excluded]),
			)->build();

			$fresh = new DiscoveryStore($storeDir);
			self::assertTrue($fresh->hasTemplateFile('templates/includer.latte'));
			self::assertFalse(
				$fresh->hasTemplateFile('templates/excluded.latte'),
				'an excluded template must not gain a store file merely because an analysed template '
				. 'includes it',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The other half of the same rule, and the one that keeps it from being read as "templates the run
	// has nothing to say about": only excludePaths removes a template. ignoreErrors is not an input to
	// this walk at all - a template whose every finding the project silences is analysed exactly like
	// any other, so it must still get its store file or its self-ref never exists and no record can
	// ever link it.
	public function testATemplateWhoseFindingsAreAllSuppressedStillGetsItsStoreFile(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			$templates = $dir . '/templates';
			FileSystem::write($templates . '/all-errors-ignored.latte', "{\$undefinedVariable}\n");

			$this->builder(
				$storeDir,
				$dir,
				[],
				new LatteUniverse([$templates], $dir),
				new FileExcluder(new FileHelper($dir), []),
			)->build();

			$fresh = new DiscoveryStore($storeDir);
			self::assertTrue(
				$fresh->hasTemplateFile('templates/all-errors-ignored.latte'),
				'membership is %paths% minus %excludePaths% and nothing else - a suppressed finding '
				. 'never removes a template from the store',
			);
			self::assertSame([], $fresh->recordsForTemplate('templates/all-errors-ignored.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $paths
	 */
	private function builder(
		string $storeDir,
		string $scratchDir,
		array $paths,
		?LatteUniverse $universe = null,
		?FileExcluder $fileExcluder = null
	): PreAnalysisIndexBuilder
	{
		return new PreAnalysisIndexBuilder(
			$paths,
			new DiscoveryStore($storeDir),
			$this->recordSource($scratchDir),
			self::createReflectionProvider(),
			$universe ?? new LatteUniverse([], $scratchDir),
			$fileExcluder,
		);
	}

	private function recordSource(string $scratchDir): DiscoveryRecordSource
	{
		$fixturesRoot = realpath(__DIR__ . '/../Fixtures');
		self::assertNotFalse($fixturesRoot);

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$templateFactoryDefault = new TemplateFactoryDefaultResolver(null);
		$discoveryResolver = new DiscoveryResolver(null, [], $fixturesRoot);

		return new DiscoveryRecordSource(
			new PhpFactsCache(
				new LatteAnalysisCache($scratchDir . '/cache', 'testv1'),
				$templateFactoryDefault,
				$discoveryResolver,
				[],
			),
			new PhpRenderWalk(
				self::createReflectionProvider(),
				$parser,
				[$this->appRoot()],
				$templateFactoryDefault,
				$discoveryResolver,
			),
		);
	}

	private function fixtureFile(string $name): string
	{
		$file = realpath(__DIR__ . '/../Fixtures/App/' . $name);
		self::assertNotFalse($file);

		return $file;
	}

	private function appRoot(): string
	{
		$appRoot = realpath(__DIR__ . '/../Fixtures/App');
		self::assertNotFalse($appRoot);

		return $appRoot;
	}

	/**
	 * @return array<string, string>
	 */
	private function snapshot(string $storeDir): array
	{
		$files = glob($storeDir . '/*.php');
		self::assertNotFalse($files);

		$snapshot = [];
		foreach ($files as $file) {
			$snapshot[basename($file)] = FileSystem::read($file);
		}

		ksort($snapshot);

		return $snapshot;
	}

	private function scratchDir(): string
	{
		return sys_get_temp_dir() . '/latte-pre-analysis-index-test-' . getmypid() . '-' . uniqid('', true);
	}

}
