<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Cache;

use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use function array_keys;
use function array_pop;
use function implode;
use function serialize;
use function sha1;
use function sort;
use const SORT_STRING;

// A template's analysis reads its includers' contexts, its targets' declarations and block tables,
// its layouts and the discovery records of all of them, transitively along every edge: the
// connected component of the template graph is the smallest set no edge leaves.
final class TemplateNeighbourhood
{

	private TemplateEdgeIndex $edgeIndex;

	private LatteUniverse $universe;

	private ?DiscoveryStore $discoveryStore;

	/** @var array<string, string>|null template => component id */
	private ?array $componentOf = null;

	/** @var array<string, list<string>> component id => members */
	private array $members = [];

	/** @var array<string, string> component id => digest */
	private array $digests = [];

	public function __construct(
		TemplateEdgeIndex $edgeIndex,
		LatteUniverse $universe,
		?DiscoveryStore $discoveryStore = null,
		bool $discoveryStoreEnabled = false
	)
	{
		$this->edgeIndex = $edgeIndex;
		$this->universe = $universe;
		$this->discoveryStore = $discoveryStoreEnabled ? $discoveryStore : null;
	}

	/**
	 * @return list<string>
	 */
	public function members(string $relativePath): array
	{
		$component = $this->componentOf()[$relativePath] ?? null;

		return $component === null ? [$relativePath] : $this->members[$component];
	}

	// The renderers of every template the component holds: their resolved template class and control
	// axis feed the factory-provided variables of each member, and through the contexts its includes.

	/**
	 * @return list<string>
	 */
	public function rendererClasses(string $relativePath): array
	{
		if ($this->discoveryStore === null) {
			return [];
		}

		$classes = [];
		foreach ($this->members($relativePath) as $member) {
			foreach ($this->discoveryStore->recordsForTemplate($member) as $record) {
				$classes[$record['class']] = true;
			}
		}

		$classes = array_keys($classes);
		sort($classes, SORT_STRING);

		return $classes;
	}

	// Every template whose include sites can feed a context of this one: its includers, transitively,
	// along the edges ContextResolver walks.

	/**
	 * @return list<string>
	 */
	public function ancestors(string $relativePath): array
	{
		$ancestors = [];
		$queue = [$relativePath];
		while ($queue !== []) {
			$current = array_pop($queue);
			foreach ($this->edgeIndex->incomingEdges($current) as $edge) {
				$includer = $edge['includer'];
				if ($edge['site']->getKind() === IncludeTarget::KIND_DISCOVERY || isset($ancestors[$includer])) {
					continue;
				}

				$ancestors[$includer] = true;
				$queue[] = $includer;
			}
		}

		$ancestors = array_keys($ancestors);
		sort($ancestors, SORT_STRING);

		return $ancestors;
	}

	public function digest(string $relativePath): string
	{
		$component = $this->componentOf()[$relativePath] ?? null;
		if ($component === null) {
			return 'outside';
		}

		if (isset($this->digests[$component])) {
			return $this->digests[$component];
		}

		$lines = [];
		foreach ($this->members[$component] as $member) {
			foreach ($this->edgeIndex->incomingEdges($member) as $edge) {
				$site = $edge['site'];
				$lines[] = implode(
					"\x1f",
					['in', $member, $edge['includer'], $site->getTag(), $site->getKind(), $site->getRawTarget()],
				);
			}

			foreach ($this->edgeIndex->autoLayoutAncestors($member) as $layout) {
				$lines[] = implode("\x1f", ['layout', $member, $layout]);
			}

			if ($this->discoveryStore !== null) {
				$lines[] = implode(
					"\x1f",
					['records', $member, sha1(serialize($this->discoveryStore->recordsForTemplate($member)))],
				);
			}
		}

		sort($lines, SORT_STRING);

		return $this->digests[$component] = sha1(implode("\n", $lines));
	}

	/**
	 * @return array<string, string>
	 */
	private function componentOf(): array
	{
		if ($this->componentOf !== null) {
			return $this->componentOf;
		}

		$templates = [];
		foreach ($this->universe->files() as $file) {
			$templates[$this->universe->relativePath($file)] = true;
		}

		/** @var array<string, array<string, true>> $adjacent */
		$adjacent = [];
		foreach (array_keys($templates) as $template) {
			$adjacent[$template] ??= [];
			$neighbours = $this->edgeIndex->autoLayoutAncestors($template);
			foreach ($this->edgeIndex->incomingEdges($template) as $edge) {
				if ($edge['site']->getKind() !== IncludeTarget::KIND_DISCOVERY) {
					$neighbours[] = $edge['includer'];
				}
			}

			foreach ($this->edgeIndex->outgoingSites($template) as $site) {
				$resolvedPath = $site->getResolvedPath();
				if ($resolvedPath !== null && $this->edgeIndex->targetExists($site)) {
					$neighbours[] = $resolvedPath;
				}
			}

			foreach ($neighbours as $neighbour) {
				if (!isset($templates[$neighbour])) {
					continue;
				}

				$adjacent[$template][$neighbour] = true;
				$adjacent[$neighbour][$template] = true;
			}
		}

		$componentOf = [];
		$sorted = array_keys($templates);
		sort($sorted, SORT_STRING);
		foreach ($sorted as $start) {
			if (isset($componentOf[$start])) {
				continue;
			}

			$members = [];
			$queue = [$start];
			$componentOf[$start] = $start;
			while ($queue !== []) {
				$current = array_pop($queue);
				$members[] = $current;
				foreach (array_keys($adjacent[$current] ?? []) as $neighbour) {
					if (!isset($componentOf[$neighbour])) {
						$componentOf[$neighbour] = $start;
						$queue[] = $neighbour;
					}
				}
			}

			sort($members, SORT_STRING);
			$this->members[$start] = $members;
		}

		return $this->componentOf = $componentOf;
	}

}
