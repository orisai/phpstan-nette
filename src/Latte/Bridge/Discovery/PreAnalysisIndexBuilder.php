<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Discovery;

use Nette\Utils\Finder;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use PHPStan\File\FileExcluder;
use PHPStan\Reflection\ReflectionProvider;
use function array_keys;
use function is_dir;
use function is_file;
use function ksort;
use function substr_compare;
use const SORT_STRING;

// The store's SOLE writer, and it runs ahead of the first parse: every consumer of this store is
// parse-time, so the aggregate-stage writer this replaced was always one run behind and made the
// run's results depend on the previous run's artifact.
//
// Two properties make that placement possible, and both are load-bearing:
// - The universe is the CONFIGURED paths MINUS excludePaths, never the CLI-narrowed analysed set, so
//   a path-narrowing spawn derives the same index as a full run instead of emptying the records it
//   has no authority over. The exclusions are half of that identity, not a refinement of it: the
//   writer this replaces derived its universe from the analysed set, where an excluded fixture class
//   never appeared, and a build that indexed one would claim a link no analysis of this
//   configuration can see. It is also never seeded from the store's own linkedClasses(): reading the
//   previous run's index back in is precisely the cross-run dependency this class exists to remove.
// - Nothing here consumes an analysis result. DiscoveryRecordSource reaches PhpRenderWalk, which
//   holds a ReflectionProvider and its own parser and takes no Scope, no collected data and no
//   reflection of anything but source files.
final class PreAnalysisIndexBuilder
{

	/** @var list<string> */
	private array $paths;

	private DiscoveryStore $store;

	private DiscoveryRecordSource $recordSource;

	private ReflectionProvider $reflectionProvider;

	private LatteUniverse $universe;

	private ?FileExcluder $fileExcluder;

	private FileClassScanner $scanner;

	/**
	 * @param list<string> $paths
	 */
	public function __construct(
		array $paths,
		DiscoveryStore $store,
		DiscoveryRecordSource $recordSource,
		ReflectionProvider $reflectionProvider,
		LatteUniverse $universe,
		?FileExcluder $fileExcluder = null
	)
	{
		$this->paths = $paths;
		$this->store = $store;
		$this->recordSource = $recordSource;
		$this->reflectionProvider = $reflectionProvider;
		$this->universe = $universe;
		$this->fileExcluder = $fileExcluder;
		$this->scanner = new FileClassScanner();
	}

	public function build(): void
	{
		$recordsByTemplate = [];
		$linkedClasses = [];

		foreach ($this->universe() as $className) {
			// Discovery describes render-side CLASSES, and a trait's or an interface's body only ever
			// runs as part of the class using it. A universe admitting them would index names no
			// analysis of this configuration can attribute a render to.
			if (!$this->isRenderSideClass($className)) {
				continue;
			}

			$facts = $this->recordSource->factsFor($className);
			// No discovery at all: not a renderer, outside the first-party boundary, or a class
			// reflection cannot find. None of the three may enter the index - the writer's own skip.
			if ($facts->getDiscovery() === null) {
				continue;
			}

			$linkedClasses[] = $className;
			foreach (DiscoveryRecords::forClass($className, $facts) as $relPath => $records) {
				foreach ($records as $record) {
					$recordsByTemplate[$relPath][] = $record;
				}
			}
		}

		// Single-writer invariant: this runs once, coordinator-side, before any worker exists.
		$this->store->replaceWith($recordsByTemplate, $linkedClasses, $this->templateRels());
	}

	// The materialization set the aggregate writer took from LatteAnalyzedFileMarkerCollector - one
	// (possibly empty) store file per template, so DiscoveryRefResolver's file-exists gate lets that
	// template reference its own discovery class from its very first parse. Derived from the same
	// configured universe as the class half, not from the analysed set: a path-narrowing spawn would
	// otherwise materialize only the templates it was pointed at.
	//
	// The excluder is applied HERE rather than inside LatteUniverse because that service answers a
	// different question for TemplateEdgeIndex and ContextResolver - which .latte files EXIST as
	// include targets - and an analysed template including an excluded one must keep resolving it.
	// Only the store's own membership is `%paths%` minus `%excludePaths%`.

	/**
	 * @return list<string>
	 */
	private function templateRels(): array
	{
		$rels = [];
		foreach ($this->universe->files() as $file) {
			if ($this->isExcluded($file)) {
				continue;
			}

			$rels[] = $this->universe->relativePath($file);
		}

		return $rels;
	}

	/**
	 * @return list<string>
	 */
	private function universe(): array
	{
		$classNames = [];
		foreach ($this->phpFiles() as $file) {
			// EVERY class-like, not just the file's first: a class moved into a file that already
			// declares one is a class this configuration analyses like any other, and a first-only
			// universe would drop its records the moment the move happened. An unreadable or
			// untokenizable file names none and contributes nothing.
			foreach ($this->scanner->allClassLikesIn($file) as $className) {
				$classNames[$className] = true;
			}
		}

		ksort($classNames, SORT_STRING);

		return array_keys($classNames);
	}

	/**
	 * @return list<string>
	 */
	private function phpFiles(): array
	{
		$files = [];
		foreach ($this->paths as $path) {
			if (is_file($path)) {
				if (substr_compare($path, '.php', -4) === 0 && !$this->isExcluded($path)) {
					$files[] = $path;
				}

				continue;
			}

			// A configured path is a file, a directory, or neither (LatteUniverse's own tolerance).
			if (!is_dir($path)) {
				continue;
			}

			foreach (Finder::findFiles('*.php')->from($path) as $pathname => $fileInfo) {
				if ($this->isExcluded($pathname)) {
					continue;
				}

				$files[] = $pathname;
			}
		}

		return $files;
	}

	private function isRenderSideClass(string $className): bool
	{
		// A file naming something reflection cannot resolve at all - the walk's own tolerance, and
		// the reason an unloadable fixture beside a real renderer is skipped rather than fatal.
		if (!$this->reflectionProvider->hasClass($className)) {
			return false;
		}

		return $this->reflectionProvider->getClass($className)->isClass();
	}

	private function isExcluded(string $file): bool
	{
		$fileExcluder = $this->fileExcluder;

		return $fileExcluder !== null && $fileExcluder->isExcludedFromAnalysing($file);
	}

}
