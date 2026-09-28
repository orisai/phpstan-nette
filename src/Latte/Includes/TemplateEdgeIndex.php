<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use Nette\IOException;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function implode;
use function in_array;
use function ksort;
use function sha1;
use function sha1_file;
use function sort;
use function strlen;
use function substr;
use function usort;

final class TemplateEdgeIndex
{

	private const LAYOUT_CHAIN_TAGS = ['import', 'layout', 'extends', 'includeblock'];

	private LatteUniverse $universe;

	private TemplateFactExtractor $extractor;

	private ?LatteAnalysisCache $cache;

	private ?DiscoveryStore $discoveryStore;

	private bool $discoveryStoreEnabled;

	private bool $built = false;

	/** @var array<string, list<array{includer: string, site: IncludeTarget}>> */
	private array $incoming = [];

	/** @var array<string, list<IncludeTarget>> */
	private array $outgoing = [];

	/** @var array<string, list<array{includer: string, site: IncludeTarget}>> */
	private array $discoveryIncoming = [];

	/** @var array<string, list<string>> */
	private array $autoLayoutAncestors = [];

	private bool $pathPrefixResolved = false;

	private ?string $pathPrefix = null;

	public function __construct(
		LatteUniverse $universe,
		TemplateFactExtractor $extractor,
		?LatteAnalysisCache $cache = null,
		?DiscoveryStore $discoveryStore = null,
		bool $discoveryStoreEnabled = false
	)
	{
		$this->universe = $universe;
		$this->extractor = $extractor;
		$this->cache = $cache;
		$this->discoveryStore = $discoveryStore;
		$this->discoveryStoreEnabled = $discoveryStoreEnabled;
	}

	public function factsFor(string $absoluteFile): TemplateFacts
	{
		if ($this->cache === null) {
			return $this->readAndExtract($absoluteFile);
		}

		$data = $this->cache->rememberContentAddressed(
			$this->contentHashFor($absoluteFile),
			'latte-facts',
			fn (): array => $this->readAndExtract($absoluteFile)->toArray(),
		);

		/** @var array{includeSites: list<array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}>, blockNames: list<string>, defineNames: list<string>, topLevelVars: array<string, string>, topLevelDefaults: list<string>, blockDeclaredVars: array<string, array<string, string>>, blockDeclaredVarLines: array<string, array<string, int>>, lineMacros: array<int, list<array{name: string, column: int}>>, layoutMode: TemplateFacts::LAYOUT_MODE_*|null} $data */
		return TemplateFacts::fromArray($data);
	}

	/**
	 * @return list<array{includer: string, site: IncludeTarget}>
	 */
	public function incomingEdges(string $projectRelativePath): array
	{
		$this->build();

		return array_merge(
			$this->incoming[$projectRelativePath] ?? [],
			$this->discoveryIncoming[$projectRelativePath] ?? [],
		);
	}

	// The layout files vendor's presenter-side auto-layout walk puts ABOVE this template - no .latte
	// tag in it names them (that walk is exactly what phase 1 could not model). Kept OUT of
	// outgoingSites(): those sites are checked one by one for their own contracts and hashed into
	// EdgeFingerprint, and a synthetic line-0 site has neither a reportable location nor a place in
	// a fingerprint derived from the file's own text. Reachability is the one thing it does feed.

	/**
	 * @return list<string>
	 */
	public function autoLayoutAncestors(string $projectRelativePath): array
	{
		$this->build();

		return $this->autoLayoutAncestors[$projectRelativePath] ?? [];
	}

	/**
	 * @return list<IncludeTarget>
	 */
	public function outgoingSites(string $projectRelativePath): array
	{
		$this->build();

		return $this->outgoing[$projectRelativePath] ?? [];
	}

	// Rel-path-only includer->target pairs across the whole universe - no args/content - for
	// LatteResultCacheMeta's topology salt: only existing (resolved + targetExists) edges, same as
	// incomingEdges()'s own population rule in fold(). Discovery-derived edges are deliberately
	// absent: they are a pure function of store RECORD CONTENT, which LatteResultCacheMeta keeps out
	// of its whole-cache salt on purpose (record changes propagate granularly through each store
	// file's own bytes - and both ends of an auto-layout edge are re-derived from the same renderer
	// class, so a record change rewrites both store files and reanalyzes both templates).

	/**
	 * @return list<array{includer: string, target: string}>
	 */
	public function edgeTopology(): array
	{
		$this->build();

		$edges = [];
		foreach ($this->incoming as $target => $list) {
			foreach ($list as $edge) {
				$edges[] = ['includer' => $edge['includer'], 'target' => $target];
			}
		}

		return $edges;
	}

	/**
	 * @return list<string>
	 */
	public function reachableBlockNames(string $projectRelativePath): array
	{
		$names = array_keys($this->reachableBlockOrigins($projectRelativePath));
		sort($names);

		return $names;
	}

	// Single source of truth for "which name is reachable" (reachableBlockNames() above) AND
	// "which file(s) actually declare it" (IncludeContractChecker's block-contract resolution) -
	// deriving the former from this rather than keeping a second, independently-walked name-only
	// traversal is what prevents the two from ever disagreeing about reachability (same hazard
	// EdgeScope's own drift-warning comment documents for provided-scope computation).

	/**
	 * @return array<string, list<string>>
	 */
	public function reachableBlockOrigins(string $projectRelativePath): array
	{
		$this->build();

		$visited = [];
		$origins = $this->collectReachableBlockOrigins($projectRelativePath, $visited);

		// Layout-slot idiom: {ifset #slot}{include #slot}{/ifset} in a layout, where `slot` is
		// declared only by templates extending the layout. The ancestor walk above never sees it
		// (descendants are invisible going up), so extenders are unioned in here too, transitively
		// through grandchild extenders. OPEN: over-approximates across every extender, not just
		// whichever one actually renders.
		$extenderVisited = [];
		$origins = $this->mergeOrigins(
			$origins,
			$this->collectExtenderBlockOrigins($projectRelativePath, $extenderVisited),
		);

		foreach ($origins as $name => $files) {
			$unique = array_values(array_unique($files));
			sort($unique);
			$origins[$name] = $unique;
		}

		return $origins;
	}

	public function targetExists(IncludeTarget $site): bool
	{
		if ($site->getKind() !== IncludeTarget::KIND_STATIC_FILE) {
			return false;
		}

		$resolvedPath = $site->getResolvedPath();
		if ($resolvedPath === null) {
			return false;
		}

		$absolute = $this->absoluteFor($resolvedPath);

		return $absolute !== null && $this->universe->contains($absolute);
	}

	public function manifest(): string
	{
		$lines = [];
		foreach ($this->universe->contentHashes() as $file => $hash) {
			$lines[] = $this->universe->relativePath($file) . "\x1f" . $hash;
		}

		sort($lines);

		return sha1(implode("\n", $lines));
	}

	private function build(): void
	{
		if ($this->built) {
			return;
		}

		$this->built = true;

		$maps = $this->cache === null
			? $this->fold()
			: $this->cache->rememberByManifest('latte-edgeidx', $this->manifest(), fn (): array => $this->fold());

		/** @var array{incoming: array<string, list<array{includer: string, site: array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}}>>, outgoing: array<string, list<array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}>>} $maps */
		$this->incoming = $this->hydrateIncoming($maps['incoming']);
		$this->outgoing = $this->hydrateOutgoing($maps['outgoing']);

		// Never folded into the cached blob above: that blob is keyed by manifest() (the .latte
		// universe's own content hashes), which no store record participates in, so caching
		// discovery edges under it would serve stale edges after a record-only change.
		$this->ingestDiscovery();
	}

	/**
	 * @return array{incoming: array<string, list<array{includer: string, site: array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}}>>, outgoing: array<string, list<array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}>>}
	 */
	private function fold(): array
	{
		/** @var array<string, list<array{includer: string, site: IncludeTarget}>> $incoming */
		$incoming = [];
		/** @var array<string, list<IncludeTarget>> $outgoing */
		$outgoing = [];

		foreach ($this->universe->files() as $absoluteFile) {
			$rel = $this->universe->relativePath($absoluteFile);
			$sites = $this->factsFor($absoluteFile)->getIncludeSites();
			$outgoing[$rel] = $sites;

			foreach ($sites as $site) {
				$resolvedPath = $site->getResolvedPath();
				if ($resolvedPath === null || !$this->targetExists($site)) {
					continue;
				}

				$incoming[$resolvedPath][] = ['includer' => $rel, 'site' => $site];
			}
		}

		foreach ($incoming as &$edges) {
			usort($edges, static function (array $a, array $b): int {
				$byIncluder = $a['includer'] <=> $b['includer'];

				return $byIncluder !== 0 ? $byIncluder : $a['site']->getLatteLine() <=> $b['site']->getLatteLine();
			});
		}

		unset($edges);

		return [
			'incoming' => $this->lowerIncoming($incoming),
			'outgoing' => $this->lowerOutgoing($outgoing),
		];
	}

	// CLASS->FILE marker edges (one per distinct renderer class + record marker) plus the auto-layout
	// edges those records imply. A marker carries no variable payload at all - it exists so that a
	// template reached only through invisible PHP-side wiring stops looking unreached, which is what
	// the checkers' own zero-incoming-edge valves key off.
	private function ingestDiscovery(): void
	{
		if ($this->discoveryStore === null || !$this->discoveryStoreEnabled) {
			return;
		}

		/** @var array<string, array<string, array<string, true>>> $markers */
		$markers = [];
		/** @var array<string, array<string, true>> $renderedByClass */
		$renderedByClass = [];
		/** @var array<string, array<string, true>> $layoutsByClass */
		$layoutsByClass = [];

		foreach ($this->discoveryStore->allLinkedTemplates() as $rel) {
			foreach ($this->discoveryStore->recordsForTemplate($rel) as $record) {
				// Certainty is deliberately not part of the marker: two records differing only in
				// how sure the walk was state the same wiring fact, and folding certainty in would
				// duplicate the edge for no consumer's benefit.
				$marker = $record['view'] === null ? $record['kind'] : $record['kind'] . ':' . $record['view'];
				$markers[$rel][$record['class']][$marker] = true;

				if ($record['kind'] === CandidatePath::KIND_LAYOUT) {
					$layoutsByClass[$record['class']][$rel] = true;
				} else {
					$renderedByClass[$record['class']][$rel] = true;
				}
			}
		}

		/** @var array<string, list<array{includer: string, site: IncludeTarget}>> $incoming */
		$incoming = [];
		foreach ($markers as $rel => $byClass) {
			foreach ($byClass as $className => $classMarkers) {
				foreach (array_keys($classMarkers) as $marker) {
					$incoming[$rel][] = [
						'includer' => $className,
						'site' => new IncludeTarget(
							IncludeTarget::TAG_DISCOVERY,
							IncludeTarget::KIND_DISCOVERY,
							$marker,
							null,
							'',
							0,
						),
					];
				}
			}
		}

		/** @var array<string, array<string, true>> $ancestors */
		$ancestors = [];
		foreach ($renderedByClass as $className => $renderedRels) {
			foreach (array_keys($layoutsByClass[$className] ?? []) as $layoutRel) {
				foreach (array_keys($renderedRels) as $renderedRel) {
					if ($renderedRel === $layoutRel || !$this->autoLayoutApplies($renderedRel, $layoutRel)) {
						continue;
					}

					$ancestors[$renderedRel][$layoutRel] = true;
					$incoming[$layoutRel][] = [
						'includer' => $renderedRel,
						'site' => new IncludeTarget(
							'layout',
							IncludeTarget::KIND_STATIC_FILE,
							$layoutRel,
							$layoutRel,
							'',
							0,
						),
					];
				}
			}
		}

		$this->discoveryIncoming = $this->canonicalDiscoveryEdges($incoming);

		$sortedAncestors = [];
		foreach ($ancestors as $rel => $layoutRels) {
			$layoutRels = array_keys($layoutRels);
			sort($layoutRels);
			$sortedAncestors[$rel] = $layoutRels;
		}

		ksort($sortedAncestors);

		$this->autoLayoutAncestors = $sortedAncestors;
	}

	// Store records arrive canonically ordered, but the derived edge set is assembled across two
	// passes and two keying dimensions - re-canonicalizing here is what keeps incomingEdges()
	// byte-identical run to run whatever order the store's own iteration produced.

	/**
	 * @param array<string, list<array{includer: string, site: IncludeTarget}>> $incoming
	 * @return array<string, list<array{includer: string, site: IncludeTarget}>>
	 */
	private function canonicalDiscoveryEdges(array $incoming): array
	{
		$canonical = [];
		foreach ($incoming as $rel => $edges) {
			usort(
				$edges,
				static fn (array $a, array $b): int => [$a['includer'], $a['site']->getKind(), $a['site']->getRawTarget()]
					<=> [$b['includer'], $b['site']->getKind(), $b['site']->getRawTarget()],
			);

			$deduplicated = [];
			$previous = null;
			foreach ($edges as $edge) {
				$identity = $edge['includer'] . "\x1f" . $edge['site']->getKind() . "\x1f" . $edge['site']->getRawTarget();
				if ($identity === $previous) {
					continue;
				}

				$deduplicated[] = $edge;
				$previous = $identity;
			}

			$canonical[$rel] = $deduplicated;
		}

		ksort($canonical);

		return $canonical;
	}

	// The owner-ratified suppression rule, runtime-proven by LayoutSuppressionParityTest against
	// nette/application's UIRuntime::initialize()/UIMacros::macroExtends(): a template that declares
	// ANY {layout}/{extends} of its own - including {layout none} and a dynamic {layout $var} - takes
	// the auto-layout walk out of play for itself, and a template declaring no {block}/{define} at
	// all never triggers it either (initialize() bails on an empty block set). {layout auto} is the
	// one declaration that REQUESTS the walk, unconditionally.
	private function autoLayoutApplies(string $renderedRel, string $layoutRel): bool
	{
		$renderedAbsolute = $this->absoluteFor($renderedRel);
		$layoutAbsolute = $this->absoluteFor($layoutRel);
		if (
			$renderedAbsolute === null
			|| $layoutAbsolute === null
			|| !$this->universe->contains($renderedAbsolute)
			|| !$this->universe->contains($layoutAbsolute)
		) {
			return false;
		}

		$facts = $this->factsFor($renderedAbsolute);
		$mode = $facts->getLayoutMode();

		if ($mode === TemplateFacts::LAYOUT_MODE_AUTO) {
			return true;
		}

		if ($mode !== null) {
			return false;
		}

		return $facts->getBlockNames() !== [] || $facts->getDefineNames() !== [];
	}

	/**
	 * @param array<string, true> $visited
	 * @return array<string, list<string>>
	 */
	private function collectReachableBlockOrigins(string $rel, array &$visited): array
	{
		if (isset($visited[$rel])) {
			return [];
		}

		$visited[$rel] = true;

		$absolute = $this->absoluteFor($rel);
		if ($absolute === null || !$this->universe->contains($absolute)) {
			return [];
		}

		$facts = $this->factsFor($absolute);
		$origins = [];
		foreach (array_merge($facts->getBlockNames(), $facts->getDefineNames()) as $name) {
			$origins[$name][] = $rel;
		}

		foreach ($facts->getIncludeSites() as $site) {
			if (
				$site->getKind() !== IncludeTarget::KIND_STATIC_FILE
				|| !in_array($site->getTag(), self::LAYOUT_CHAIN_TAGS, true)
			) {
				continue;
			}

			$resolvedPath = $site->getResolvedPath();
			if ($resolvedPath === null) {
				continue;
			}

			$origins = $this->mergeOrigins($origins, $this->collectReachableBlockOrigins($resolvedPath, $visited));
		}

		foreach ($this->autoLayoutAncestors[$rel] ?? [] as $layoutRel) {
			$origins = $this->mergeOrigins($origins, $this->collectReachableBlockOrigins($layoutRel, $visited));
		}

		return $origins;
	}

	/**
	 * @param array<string, true> $visited
	 * @return array<string, list<string>>
	 */
	private function collectExtenderBlockOrigins(string $rel, array &$visited): array
	{
		if (isset($visited[$rel])) {
			return [];
		}

		$visited[$rel] = true;

		$origins = [];
		foreach ($this->incomingEdges($rel) as $edge) {
			if (!in_array($edge['site']->getTag(), self::LAYOUT_CHAIN_TAGS, true)) {
				continue;
			}

			$childRel = $edge['includer'];
			$absolute = $this->absoluteFor($childRel);
			if ($absolute === null || !$this->universe->contains($absolute)) {
				continue;
			}

			$facts = $this->factsFor($absolute);
			foreach (array_merge($facts->getBlockNames(), $facts->getDefineNames()) as $name) {
				$origins[$name][] = $childRel;
			}

			$origins = $this->mergeOrigins($origins, $this->collectExtenderBlockOrigins($childRel, $visited));
		}

		return $origins;
	}

	/**
	 * @param array<string, list<string>> $a
	 * @param array<string, list<string>> $b
	 * @return array<string, list<string>>
	 */
	private function mergeOrigins(array $a, array $b): array
	{
		foreach ($b as $name => $files) {
			foreach ($files as $file) {
				$a[$name][] = $file;
			}
		}

		return $a;
	}

	private function readAndExtract(string $absoluteFile): TemplateFacts
	{
		try {
			$source = FileSystem::read($absoluteFile);
		} catch (IOException $e) {
			return new TemplateFacts([], [], [], [], [], [], [], []);
		}

		return $this->extractor->extract($source, $this->universe->relativePath($absoluteFile));
	}

	private function contentHashFor(string $absoluteFile): string
	{
		$hashes = $this->universe->contentHashes();
		if (isset($hashes[$absoluteFile])) {
			return $hashes[$absoluteFile];
		}

		$hash = sha1_file($absoluteFile);

		return $hash === false ? sha1($absoluteFile) : $hash;
	}

	// LatteUniverse exposes only relativePath() (absolute -> relative), never the reverse;
	// derived once from its first enumerated file (every file shares the same projectRoot
	// prefix) so resolvedPath values (already in that coordinate system - see
	// TemplateFactExtractor::normalizePath) can be turned back into the absolute path
	// contains() requires.
	private function absoluteFor(string $relativePath): ?string
	{
		$prefix = $this->pathPrefix();

		return $prefix === null ? null : $prefix . $relativePath;
	}

	private function pathPrefix(): ?string
	{
		if (!$this->pathPrefixResolved) {
			$this->pathPrefixResolved = true;

			$files = $this->universe->files();
			if ($files !== []) {
				$first = $files[0];
				$relative = $this->universe->relativePath($first);
				$this->pathPrefix = (string) substr($first, 0, strlen($first) - strlen($relative));
			}
		}

		return $this->pathPrefix;
	}

	/**
	 * @param array<string, list<array{includer: string, site: IncludeTarget}>> $incoming
	 * @return array<string, list<array{includer: string, site: array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}}>>
	 */
	private function lowerIncoming(array $incoming): array
	{
		$lowered = [];
		foreach ($incoming as $target => $edges) {
			$lowered[$target] = array_map(
				static fn (array $edge): array => ['includer' => $edge['includer'], 'site' => $edge['site']->toArray()],
				$edges,
			);
		}

		return $lowered;
	}

	/**
	 * @param array<string, list<IncludeTarget>> $outgoing
	 * @return array<string, list<array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}>>
	 */
	private function lowerOutgoing(array $outgoing): array
	{
		$lowered = [];
		foreach ($outgoing as $rel => $sites) {
			$lowered[$rel] = array_map(static fn (IncludeTarget $site): array => $site->toArray(), $sites);
		}

		return $lowered;
	}

	/**
	 * @param array<string, list<array{includer: string, site: array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}}>> $lowered
	 * @return array<string, list<array{includer: string, site: IncludeTarget}>>
	 */
	private function hydrateIncoming(array $lowered): array
	{
		$hydrated = [];
		foreach ($lowered as $target => $edges) {
			$hydrated[$target] = array_map(fn (array $edge): array => $this->hydrateEdge($edge), $edges);
		}

		return $hydrated;
	}

	/**
	 * @param array{includer: string, site: array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}} $edge
	 * @return array{includer: string, site: IncludeTarget}
	 */
	private function hydrateEdge(array $edge): array
	{
		return ['includer' => $edge['includer'], 'site' => IncludeTarget::fromArray($edge['site'])];
	}

	/**
	 * @param array<string, list<array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}>> $lowered
	 * @return array<string, list<IncludeTarget>>
	 */
	private function hydrateOutgoing(array $lowered): array
	{
		$hydrated = [];
		foreach ($lowered as $rel => $sites) {
			$hydrated[$rel] = array_map(
				static fn (array $site): IncludeTarget => IncludeTarget::fromArray($site),
				$sites,
			);
		}

		return $hydrated;
	}

}
