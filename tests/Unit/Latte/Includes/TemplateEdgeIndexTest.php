<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Compile\ProjectRelativePath;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use function array_keys;
use function dirname;
use function getmypid;
use function sort;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @group latte2
 */
final class TemplateEdgeIndexTest extends BaseTestCase
{

	public function testIncomingEdgesForSharedPartial(): void
	{
		$index = $this->indexForTree();
		$edges = $index->incomingEdges($this->rel('partial.latte'));

		self::assertCount(3, $edges);
		self::assertSame($this->rel('other-root.latte'), $edges[0]['includer']);
		self::assertSame($this->rel('root.latte'), $edges[1]['includer']);
		self::assertSame($this->rel('third-root.latte'), $edges[2]['includer']);
	}

	public function testLayoutEdgeAndReachableBlocks(): void
	{
		$index = $this->indexForTree();
		self::assertCount(1, $index->incomingEdges($this->rel('@layout.latte')));
		self::assertContains('imported', $index->reachableBlockNames($this->rel('importer.latte')));
	}

	public function testMissingTargetNotAnEdgeButVisibleAsOutgoingSite(): void
	{
		$index = $this->indexForTree();
		self::assertSame([], $index->incomingEdges($this->rel('nope.latte')));
		$sites = $index->outgoingSites($this->rel('missing.latte'));
		self::assertFalse($index->targetExists($sites[0]));
	}

	public function testManifestStableAndFoldDeterministic(): void
	{
		self::assertSame($this->indexForTree()->manifest(), $this->indexForTree()->manifest());
		self::assertEquals(
			$this->indexForTree()->incomingEdges($this->rel('partial.latte')),
			$this->indexForTree()->incomingEdges($this->rel('partial.latte')),
		);
	}

	public function testColdFoldEqualsHydratedFoldThroughCache(): void
	{
		$cacheDir = sys_get_temp_dir() . '/latte-edgeidx-test-' . getmypid() . '-' . uniqid('', true);

		$cold = $this->indexForTree();
		$warm = $this->indexForTree(new LatteAnalysisCache($cacheDir, 'testv1'));

		self::assertEquals(
			$cold->incomingEdges($this->rel('partial.latte')),
			$warm->incomingEdges($this->rel('partial.latte')),
		);
		self::assertEquals(
			$cold->outgoingSites($this->rel('root.latte')),
			$warm->outgoingSites($this->rel('root.latte')),
		);
		self::assertEquals(
			$cold->outgoingSites($this->rel('missing.latte')),
			$warm->outgoingSites($this->rel('missing.latte')),
		);
		self::assertEquals(
			$cold->reachableBlockNames($this->rel('importer.latte')),
			$warm->reachableBlockNames($this->rel('importer.latte')),
		);

		// A second index instance pointed at the same cache directory must reload the persisted
		// (lowered) blob rather than recompute - the round trip through cache is exact, not just
		// equal by accident.
		$reloaded = $this->indexForTree(new LatteAnalysisCache($cacheDir, 'testv1'));
		self::assertEquals(
			$warm->incomingEdges($this->rel('partial.latte')),
			$reloaded->incomingEdges($this->rel('partial.latte')),
		);
	}

	public function testReachableBlockNamesThroughLayoutChain(): void
	{
		$index = $this->indexForTree();

		self::assertSame(['content'], $index->reachableBlockNames($this->rel('child.latte')));
	}

	public function testReachableBlockNamesCycleGuarded(): void
	{
		$index = $this->indexForTree();

		self::assertSame(['aBlock', 'bBlock'], $index->reachableBlockNames($this->rel('cycle-a.latte')));
		self::assertSame(['aBlock', 'bBlock'], $index->reachableBlockNames($this->rel('cycle-b.latte')));
	}

	public function testEmbedAndSandboxEdgeKinds(): void
	{
		$index = $this->indexForTree();
		$sites = $index->outgoingSites($this->rel('importer.latte'));

		$byTag = [];
		foreach ($sites as $site) {
			$byTag[$site->getTag()] = $site;
		}

		self::assertSame(IncludeTarget::KIND_STATIC_FILE, $byTag['embed']->getKind());
		self::assertTrue($index->targetExists($byTag['embed']));
		self::assertSame(IncludeTarget::KIND_STATIC_FILE, $byTag['sandbox']->getKind());
		self::assertTrue($index->targetExists($byTag['sandbox']));
	}

	public function testDynamicIncludeIsNeitherAnEdgeNorATarget(): void
	{
		$index = $this->indexForTree();
		$sites = $index->outgoingSites($this->rel('dynamic.latte'));

		self::assertCount(1, $sites);
		self::assertSame(IncludeTarget::KIND_DYNAMIC, $sites[0]->getKind());
		self::assertFalse($index->targetExists($sites[0]));
	}

	public function testEdgeTopologyListsIncluderTargetPairsAcrossTheUniverse(): void
	{
		$index = $this->indexForTree();
		$edges = $index->edgeTopology();

		$pairs = [];
		foreach ($edges as $edge) {
			$pairs[] = $edge['includer'] . '->' . $edge['target'];
		}

		self::assertContains($this->rel('root.latte') . '->' . $this->rel('partial.latte'), $pairs);
		// Dynamic/unresolvable sites never became an incomingEdges() entry (see fold()), so they
		// must not appear in the topology either.
		self::assertNotContains($this->rel('dynamic.latte') . '->' . $this->rel('dynamic.latte'), $pairs);
	}

	public function testFactsForMissingFileDegradesToEmptyFacts(): void
	{
		$index = $this->indexForTree();

		$facts = $index->factsFor($this->treeDir() . '/does-not-exist.latte');

		self::assertSame([], $facts->getIncludeSites());
		self::assertSame([], $facts->getBlockNames());
	}

	// Isolated temp corpus (not the shared tree) so this doesn't ripple into other tests'
	// tree-wide assertions (edgeTopology(), incomingEdges() counts, etc.).
	public function testIncludeBlockJoinsLayoutChainForReachableBlocks(): void
	{
		$tempDir = sys_get_temp_dir() . '/latte-includeblock-test-' . getmypid() . '-' . uniqid('', true);
		FileSystem::write($tempDir . '/includer.latte', "{includeblock 'included.latte'}\n");
		FileSystem::write($tempDir . '/included.latte', "{block shared}x{/block}\n");

		try {
			$universe = new LatteUniverse([$tempDir], $tempDir);
			$index = new TemplateEdgeIndex($universe, TestAdapter::accessor());

			self::assertSame(['shared'], $index->reachableBlockNames('includer.latte'));
		} finally {
			FileSystem::delete($tempDir);
		}
	}

	// Layout-slot idiom: {ifset #slot}{include #slot}{/ifset} in a layout, with `slot` declared
	// only by a template extending the layout - the ancestor-only walk can't see a descendant's
	// block, so this exercises the extender union in reachableBlockNames().
	public function testReachableBlockNamesIncludesExtenderDeclaredSlot(): void
	{
		$tempDir = $this->isolatedDir('latte-extender-slot');
		FileSystem::write($tempDir . '/layout.latte', "{ifset #slot}{include #slot}{/ifset}\n");
		FileSystem::write($tempDir . '/page.latte', "{layout 'layout.latte'}\n{block slot}x{/block}\n");

		try {
			self::assertContains('slot', $this->isolatedIndex($tempDir)->reachableBlockNames('layout.latte'));
		} finally {
			FileSystem::delete($tempDir);
		}
	}

	// Negative control for the above: no extender declares this name, so it must still be
	// reported unreachable rather than baselined away wholesale.
	public function testReachableBlockNamesStillExcludesUndeclaredSlot(): void
	{
		$tempDir = $this->isolatedDir('latte-extender-slot-negative');
		FileSystem::write($tempDir . '/layout.latte', "{ifset #slot}{include #slot}{/ifset}\n");
		FileSystem::write($tempDir . '/page.latte', "{layout 'layout.latte'}\n{block other}x{/block}\n");

		try {
			self::assertNotContains('slot', $this->isolatedIndex($tempDir)->reachableBlockNames('layout.latte'));
		} finally {
			FileSystem::delete($tempDir);
		}
	}

	// Transitive: the middle extender declares nothing, only its own extender (a grandchild of
	// the layout) declares the slot - still reachable from the layout.
	public function testReachableBlockNamesIncludesTransitiveGrandchildSlot(): void
	{
		$tempDir = $this->isolatedDir('latte-extender-slot-transitive');
		FileSystem::write($tempDir . '/layout.latte', "{ifset #slot}{include #slot}{/ifset}\n");
		FileSystem::write($tempDir . '/middle.latte', "{layout 'layout.latte'}\n");
		FileSystem::write($tempDir . '/grandchild.latte', "{layout 'middle.latte'}\n{block slot}x{/block}\n");

		try {
			self::assertContains('slot', $this->isolatedIndex($tempDir)->reachableBlockNames('layout.latte'));
		} finally {
			FileSystem::delete($tempDir);
		}
	}

	// 'content' is declared in @layout.latte, reached from child.latte via the {layout} ancestor
	// walk - the origin must name the DEFINING file, not the includer that merely reaches it.
	public function testReachableBlockOriginsResolvesAncestorDefiner(): void
	{
		$index = $this->indexForTree();

		self::assertSame(
			[$this->rel('@layout.latte')],
			$index->reachableBlockOrigins($this->rel('child.latte'))['content'] ?? [],
		);
	}

	// The name-only view must stay a pure function of the origins map (reachableBlockNames() now
	// derives from reachableBlockOrigins() instead of walking the graph a second time) - proven
	// here by cross-checking both accessors against the same fixture instead of just re-asserting
	// reachableBlockNames() in isolation.
	public function testReachableBlockNamesAndOriginsAgreeOnKnownNames(): void
	{
		$index = $this->indexForTree();

		self::assertSame(
			$index->reachableBlockNames($this->rel('importer.latte')),
			array_keys($index->reachableBlockOrigins($this->rel('importer.latte'))),
		);
	}

	public function testReachableBlockOriginsResolvesImportedDefiner(): void
	{
		$index = $this->indexForTree();

		self::assertSame(
			[$this->rel('blocks.latte')],
			$index->reachableBlockOrigins($this->rel('importer.latte'))['imported'] ?? [],
		);
	}

	public function testReachableBlockOriginsEmptyForUnknownName(): void
	{
		$index = $this->indexForTree();

		self::assertSame([], $index->reachableBlockOrigins($this->rel('importer.latte'))['ghost'] ?? []);
	}

	// Two extenders both declaring the same slot name is a genuinely ambiguous defining file -
	// the origins list must carry BOTH rather than silently picking one, so a contract-checking
	// consumer can detect the ambiguity and stay silent rather than guess.
	public function testReachableBlockOriginsListsEveryExtenderDeclaringTheSameSlot(): void
	{
		$tempDir = $this->isolatedDir('latte-extender-slot-ambiguous');
		FileSystem::write($tempDir . '/layout.latte', "{ifset #slot}{include #slot}{/ifset}\n");
		FileSystem::write($tempDir . '/page-a.latte', "{layout 'layout.latte'}\n{block slot}a{/block}\n");
		FileSystem::write($tempDir . '/page-b.latte', "{layout 'layout.latte'}\n{block slot}b{/block}\n");

		try {
			$origins = $this->isolatedIndex($tempDir)->reachableBlockOrigins('layout.latte')['slot'] ?? [];
			sort($origins);

			self::assertSame(['page-a.latte', 'page-b.latte'], $origins);
		} finally {
			FileSystem::delete($tempDir);
		}
	}

	private function isolatedDir(string $prefix): string
	{
		return sys_get_temp_dir() . '/' . $prefix . '-test-' . getmypid() . '-' . uniqid('', true);
	}

	private function isolatedIndex(string $dir): TemplateEdgeIndex
	{
		return new TemplateEdgeIndex(new LatteUniverse([$dir], $dir), TestAdapter::accessor());
	}

	private function indexForTree(?LatteAnalysisCache $cache = null): TemplateEdgeIndex
	{
		$universe = new LatteUniverse([$this->treeDir()], dirname(__DIR__, 3));

		return new TemplateEdgeIndex($universe, TestAdapter::accessor(), $cache);
	}

	private function treeDir(): string
	{
		return __DIR__ . '/Fixtures/tree';
	}

	private function rel(string $basename): string
	{
		return ProjectRelativePath::relativize(dirname(__DIR__, 3), $this->treeDir() . '/' . $basename);
	}

}
