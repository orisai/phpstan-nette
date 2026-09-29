<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\CapturedOverlay;
use OriPhpstan\Nette\Latte\Includes\ContextResolver;
use OriPhpstan\Nette\Latte\Includes\IncludeContractChecker;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function array_keys;
use function array_map;
use function array_merge;
use function array_reverse;
use function getmypid;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use const SORT_STRING;

// The discovery store is the first PHP-side source of template edges: a template Nette reaches only
// through a presenter/control formula has no .latte tag naming it, so before ingestion it looked
// unreached and every reachability check stayed OPEN. These pins cover the ingested edge shape, the
// owner-ratified auto-layout suppression, and the checker valves the edges flip.
final class DiscoveryEdgeIngestionTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const RENDERER = 'Fixture\\ProbePresenter';

	// Deliberately sorts after every template rel path in the order-independence corpus.
	private const LATE_RENDERER = 'zz\\LateProbePresenter';

	public function testRecordBecomesMarkerEdgeCarryingNoVariablePayload(): void
	{
		$dir = $this->corpus(['page.latte' => "body\n"]);

		try {
			$edges = $this->index($dir, [
				'page.latte' => [$this->record(CandidatePath::KIND_FORMULA, 'detail')],
			])->incomingEdges('page.latte');

			self::assertCount(1, $edges);
			self::assertSame(self::RENDERER, $edges[0]['includer']);
			self::assertSame(IncludeTarget::KIND_DISCOVERY, $edges[0]['site']->getKind());
			self::assertSame(IncludeTarget::TAG_DISCOVERY, $edges[0]['site']->getTag());
			self::assertSame('formula:detail', $edges[0]['site']->getRawTarget());
			self::assertSame('', $edges[0]['site']->getArgsSource());
			self::assertNull($edges[0]['site']->getResolvedPath());
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testViewlessRecordMarkerCarriesTheKindAlone(): void
	{
		$dir = $this->corpus(['page.latte' => "body\n"]);

		try {
			$edges = $this->index($dir, [
				'page.latte' => [$this->record(CandidatePath::KIND_SET_FILE, null)],
			])->incomingEdges('page.latte');

			self::assertSame('setFile', $edges[0]['site']->getRawTarget());
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Certainty is not part of the marker identity: two records of the same class/view/kind that only
	// disagree about how sure the walk was state one wiring fact, not two edges.
	public function testRecordsDifferingOnlyInCertaintyCollapseToOneEdge(): void
	{
		$dir = $this->corpus(['page.latte' => "body\n"]);

		try {
			$edges = $this->index($dir, [
				'page.latte' => [
					$this->record(CandidatePath::KIND_FORMULA, 'detail', Certainty::HAPPENS),
					$this->record(CandidatePath::KIND_FORMULA, 'detail', Certainty::MAYBE),
				],
			])->incomingEdges('page.latte');

			self::assertCount(1, $edges);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testUnlinkedTemplateKeepsZeroIncomingEdges(): void
	{
		$dir = $this->corpus(['page.latte' => "body\n", 'other.latte' => "body\n"]);

		try {
			$index = $this->index($dir, ['page.latte' => [$this->record(CandidatePath::KIND_FORMULA, 'detail')]]);

			self::assertSame([], $index->incomingEdges('other.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDisabledFlagIngestsNothing(): void
	{
		$dir = $this->corpus(['page.latte' => "body\n"]);

		try {
			$index = $this->index(
				$dir,
				['page.latte' => [$this->record(CandidatePath::KIND_FORMULA, 'detail')]],
				false,
			);

			self::assertSame([], $index->incomingEdges('page.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testAutoLayoutEdgeConnectsRenderedTemplateToItsLayout(): void
	{
		$dir = $this->layoutCorpus("{block content}x{/block}\n");

		try {
			$index = $this->index($dir, $this->layoutRecords());

			self::assertSame(['@layout.latte'], $index->autoLayoutAncestors('page.latte'));

			$layoutEdges = $this->fileEdges($index->incomingEdges('@layout.latte'));
			self::assertCount(1, $layoutEdges);
			self::assertSame('page.latte', $layoutEdges[0]['includer']);
			self::assertSame('layout', $layoutEdges[0]['site']->getTag());
			self::assertSame(IncludeTarget::KIND_STATIC_FILE, $layoutEdges[0]['site']->getKind());
			self::assertSame('@layout.latte', $layoutEdges[0]['site']->getResolvedPath());
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @dataProvider suppressingTemplates
	 */
	public function testOwnLayoutDeclarationSuppressesTheAutoLayoutEdge(string $source): void
	{
		$dir = $this->layoutCorpus($source);

		try {
			$index = $this->index($dir, $this->layoutRecords());

			self::assertSame([], $index->autoLayoutAncestors('page.latte'));
			self::assertSame([], $this->fileEdges($index->incomingEdges('@layout.latte')));
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function suppressingTemplates(): array
	{
		return [
			'explicit layout' => ["{layout 'other.latte'}\n{block content}x{/block}\n"],
			'layout none' => ["{layout none}\n{block content}x{/block}\n"],
			'extends none' => ["{extends none}\n{block content}x{/block}\n"],
			'dynamic layout' => ["{layout \$chosen}\n{block content}x{/block}\n"],
			// vendor's UIRuntime::initialize() bails before findLayoutTemplateFile() when the
			// template contributes no non-snippet block at all - proven by LayoutSuppressionParityTest.
			'no blocks at all' => ["plain body\n"],
		];
	}

	// {layout auto} is the one declaration that REQUESTS the walk: UIMacros::macroExtends() emits the
	// findLayoutTemplateFile() call into the prolog unconditionally, ahead of the block-set check.
	public function testLayoutAutoAttachesTheEdgeEvenWithoutBlocks(): void
	{
		$dir = $this->layoutCorpus("{layout auto}\nplain body\n");

		try {
			self::assertSame(
				['@layout.latte'],
				$this->index($dir, $this->layoutRecords())->autoLayoutAncestors('page.latte'),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testAutoLayoutEdgeMakesBlocksReachableBothWays(): void
	{
		$dir = $this->layoutCorpus("{block content}x{/block}\n");
		FileSystem::write($dir . '/templates/@layout.latte', "{block shell}{include content}{/block}\n");

		try {
			$index = $this->index($dir, $this->layoutRecords());

			self::assertContains('shell', $index->reachableBlockNames('page.latte'));
			self::assertContains('content', $index->reachableBlockNames('@layout.latte'));
			self::assertSame(['@layout.latte'], $index->reachableBlockOrigins('page.latte')['shell']);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// THE VALVE FLIP: checkStaticBlock() stays silent on a template with zero incoming edges because
	// convention wiring could still reach it. A store record IS that wiring, made visible.
	public function testUnknownBlockValveFlipsOnAStoreLinkedTemplate(): void
	{
		$dir = $this->corpus(['page.latte' => "{include #ghost}\n{block real}x{/block}\n"]);

		try {
			self::assertNotContains('orisaiNette.latte.unknownBlock', $this->identifiers($dir, [], 'page.latte'));
			self::assertContains(
				'orisaiNette.latte.unknownBlock',
				$this->identifiers(
					$dir,
					['page.latte' => [$this->record(CandidatePath::KIND_CONVENTION, 'detail')]],
					'page.latte',
				),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testUnknownBlockStaysOpenOnATemplateNoRecordLinks(): void
	{
		$dir = $this->corpus([
			'page.latte' => "{include #ghost}\n{block real}x{/block}\n",
			'linked.latte' => "body\n",
		]);

		try {
			self::assertNotContains(
				'orisaiNette.latte.unknownBlock',
				$this->identifiers(
					$dir,
					['linked.latte' => [$this->record(CandidatePath::KIND_FORMULA, 'detail')]],
					'page.latte',
				),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The `@`-prefixed clause of the same valve is NOT made redundant by ingestion. A layout gathers
	// its block declarations from whichever templates extend it, and discovery only ever proves the
	// extenders it could resolve - a presenter behind a dynamic action, an unanalysable formula or a
	// vendor-side render leaves the set incomplete, so a name declared only by a missing extender
	// would report unreachable. Removing the clause is a false-positive machine, not a precision win.
	public function testAtPrefixedLayoutKeepsItsValveDespiteIncomingEdges(): void
	{
		$dir = $this->layoutCorpus("{block content}x{/block}\n");
		FileSystem::write($dir . '/templates/@layout.latte', "{include #undiscovered}\n");

		try {
			$index = $this->index($dir, $this->layoutRecords());
			self::assertNotSame([], $index->incomingEdges('@layout.latte'));

			self::assertNotContains(
				'orisaiNette.latte.unknownBlock',
				$this->identifiersFromIndex($dir, $index, '@layout.latte'),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// A marker names a CLASS, so there is nothing to resolve an includer context from - the target
	// must keep exactly the root context it had before ingestion.
	public function testMarkerEdgeContributesNoContext(): void
	{
		$dir = $this->corpus(['page.latte' => "{varType string \$name}\nbody\n"]);

		try {
			$linked = $this->index($dir, ['page.latte' => [$this->record(CandidatePath::KIND_FORMULA, 'detail')]]);

			self::assertEquals(
				$this->resolver($dir, $this->index($dir, []))->contextsFor('page.latte'),
				$this->resolver($dir, $linked)->contextsFor('page.latte'),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// LatteResultCacheMeta's whole-cache salt is built off edgeTopology(); discovery edges are a pure
	// function of store record CONTENT, which that salt deliberately excludes (record changes
	// propagate granularly through each store file's own bytes instead).
	public function testEdgeTopologyExcludesDiscoveryEdges(): void
	{
		$dir = $this->layoutCorpus("{block content}x{/block}\n");

		try {
			self::assertSame(
				$this->index($dir, [])->edgeTopology(),
				$this->index($dir, $this->layoutRecords())->edgeTopology(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The store canonicalizes records on write AND on read, so a permuted input reaches
	// ingestDiscovery() already ordered - which is why the emitted edge list is asserted to be
	// totally ordered too: that is the half canonicalDiscoveryEdges() owns, and without it the
	// two-pass assembly (markers, then auto-layout edges) leaks its pass order to every consumer.
	public function testIngestionIsOrderIndependent(): void
	{
		$dir = $this->corpus([
			'page.latte' => "{block content}x{/block}\n",
			'sibling.latte' => "{block content}y{/block}\n",
			'@layout.latte' => "{block content}fallback{/block}\n",
		]);

		// Both dimensions of the pass order are made non-canonical on purpose: `setFile` carries no
		// view and `formula` does, so the store's [class, view, kind] record order emits the markers
		// in non-alphabetical order, and LATE_RENDERER sorts after `page.latte`, so the marker pass
		// and the auto-layout pass have to interleave rather than concatenate.
		$records = [
			'page.latte' => [
				$this->record(CandidatePath::KIND_SET_FILE, null),
				$this->record(CandidatePath::KIND_FORMULA, 'detail'),
			],
			'sibling.latte' => [
				$this->record(CandidatePath::KIND_CONVENTION, 'default', Certainty::UNKNOWN, self::LATE_RENDERER),
			],
			'@layout.latte' => [
				$this->record(CandidatePath::KIND_LAYOUT, null),
				$this->record(CandidatePath::KIND_LAYOUT, null, Certainty::UNKNOWN, self::LATE_RENDERER),
			],
		];

		try {
			$forward = $this->index($dir, $records);
			$reversed = $this->index($dir, $this->reorder($records, true));
			$interleaved = $this->index($dir, $this->reorder($records, false));

			foreach (['page.latte', 'sibling.latte', '@layout.latte'] as $rel) {
				$edges = $forward->incomingEdges($rel);

				self::assertEquals($edges, $reversed->incomingEdges($rel));
				self::assertEquals($edges, $interleaved->incomingEdges($rel));

				$keys = $this->edgeKeys($edges);
				$sorted = $keys;
				sort($sorted, SORT_STRING);
				self::assertSame($sorted, $keys);

				$ancestors = $forward->autoLayoutAncestors($rel);
				self::assertSame($ancestors, $reversed->autoLayoutAncestors($rel));
				self::assertSame($ancestors, $interleaved->autoLayoutAncestors($rel));
			}
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @return array{class: string, view: string|null, kind: string, certainty: string}
	 */
	private function record(
		string $kind,
		?string $view,
		string $certainty = Certainty::UNKNOWN,
		string $class = self::RENDERER
	): array
	{
		return ['class' => $class, 'view' => $view, 'kind' => $kind, 'certainty' => $certainty];
	}

	/**
	 * @param array<string, list<array{class: string, view: string|null, kind: string, certainty: string}>> $records
	 * @return array<string, list<array{class: string, view: string|null, kind: string, certainty: string}>>
	 */
	private function reorder(array $records, bool $reverse): array
	{
		$reordered = [];
		foreach ($this->permute(array_keys($records), $reverse) as $rel) {
			$reordered[$rel] = $this->permute($records[$rel], $reverse);
		}

		return $reordered;
	}

	/**
	 * @template T
	 * @param list<T> $items
	 * @return list<T>
	 */
	private function permute(array $items, bool $reverse): array
	{
		if ($reverse) {
			return array_reverse($items);
		}

		$odd = [];
		$even = [];
		foreach ($items as $position => $item) {
			if ($position % 2 === 0) {
				$even[] = $item;
			} else {
				$odd[] = $item;
			}
		}

		return array_merge($odd, $even);
	}

	/**
	 * @param list<array{includer: string, site: IncludeTarget}> $edges
	 * @return list<string>
	 */
	private function edgeKeys(array $edges): array
	{
		$keys = [];
		foreach ($edges as $edge) {
			$keys[] = $edge['includer'] . "\x1f" . $edge['site']->getKind() . "\x1f" . $edge['site']->getRawTarget();
		}

		return $keys;
	}

	/**
	 * @return array<string, list<array{class: string, view: string|null, kind: string, certainty: string}>>
	 */
	private function layoutRecords(): array
	{
		return [
			'page.latte' => [$this->record(CandidatePath::KIND_FORMULA, 'detail')],
			'@layout.latte' => [$this->record(CandidatePath::KIND_LAYOUT, null)],
		];
	}

	private function layoutCorpus(string $pageSource): string
	{
		return $this->corpus([
			'page.latte' => $pageSource,
			'@layout.latte' => "{block content}fallback{/block}\n",
			'other.latte' => "other\n",
		]);
	}

	/**
	 * @param array<string, string> $templates
	 */
	private function corpus(array $templates): string
	{
		$dir = sys_get_temp_dir() . '/latte-discovery-edges-' . getmypid() . '-' . uniqid('', true);
		foreach ($templates as $basename => $source) {
			FileSystem::write($dir . '/templates/' . $basename, $source);
		}

		FileSystem::createDir($dir . '/store');

		return $dir;
	}

	/**
	 * @param array<string, list<array{class: string, view: string|null, kind: string, certainty: string}>> $records
	 */
	private function index(string $dir, array $records, bool $enabled = true): TemplateEdgeIndex
	{
		$store = new DiscoveryStore($dir . '/store/' . uniqid('', true));
		$store->replaceWith($records, [self::RENDERER], []);

		return new TemplateEdgeIndex(
			$this->universe($dir),
			new TemplateFactExtractor(),
			null,
			$store,
			$enabled,
		);
	}

	/**
	 * @param array<string, list<array{class: string, view: string|null, kind: string, certainty: string}>> $records
	 * @return list<string>
	 */
	private function identifiers(string $dir, array $records, string $rel): array
	{
		return $this->identifiersFromIndex($dir, $this->index($dir, $records), $rel);
	}

	/**
	 * @return list<string>
	 */
	private function identifiersFromIndex(string $dir, TemplateEdgeIndex $index, string $rel): array
	{
		$resolver = $this->resolver($dir, $index);
		$checker = new IncludeContractChecker(
			$index,
			new DeclarationScanner(),
			$this->universe($dir),
			$resolver,
			self::getContainer()->getByType(TypeStringResolver::class),
			$this->capturedOverlay($dir),
		);

		return array_map(
			static fn (Diagnostic $diagnostic): string => $diagnostic->getIdentifier(),
			$checker->check($rel, $resolver->contextsFor($rel)),
		);
	}

	private function resolver(string $dir, TemplateEdgeIndex $index): ContextResolver
	{
		return new ContextResolver(
			$index,
			new DeclarationScanner(),
			$this->universe($dir),
			$this->capturedOverlay($dir),
		);
	}

	private function capturedOverlay(string $dir): CapturedOverlay
	{
		return new CapturedOverlay(new SiteScopeStore($dir . '/__absent_sitescope_store__'), true);
	}

	private function universe(string $dir): LatteUniverse
	{
		return new LatteUniverse([$dir . '/templates'], $dir . '/templates');
	}

	/**
	 * @param list<array{includer: string, site: IncludeTarget}> $edges
	 * @return list<array{includer: string, site: IncludeTarget}>
	 */
	private function fileEdges(array $edges): array
	{
		$fileEdges = [];
		foreach ($edges as $edge) {
			if ($edge['site']->getKind() !== IncludeTarget::KIND_DISCOVERY) {
				$fileEdges[] = $edge;
			}
		}

		return $fileEdges;
	}

}
