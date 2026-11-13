<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use Nette\Utils\Strings;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Support\RerunValueExtension;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function array_keys;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function realpath;
use function rtrim;
use function sort;
use function sprintf;
use function strtr;
use const SORT_STRING;

/**
 * @implements Rule<CollectedDataNode>
 */
final class LatteSiteScopeWriterRule implements Rule
{

	public const IDENTIFIER = 'orisai.nette.latte.narrowingStoreChanged';

	private const LISTED_MAX = 10;

	private SiteScopeStore $store;

	private string $projectRoot;

	private bool $enabled;

	private bool $analysesConfiguredPaths;

	/**
	 * @param list<string> $analysedPaths
	 * @param list<string> $analysedPathsFromConfig
	 */
	public function __construct(
		ConfigurationGuard $guard,
		SiteScopeStore $store,
		string $projectRoot,
		array $analysedPaths,
		array $analysedPathsFromConfig
	)
	{
		$guard->validate();
		$this->store = $store;
		$this->projectRoot = $projectRoot;
		$this->enabled = $guard->isLatteNarrowingEnabled();
		$this->analysesConfiguredPaths = self::normalisePaths($analysedPaths)
			=== self::normalisePaths($analysedPathsFromConfig);
	}

	public function getNodeType(): string
	{
		return CollectedDataNode::class;
	}

	/**
	 * @param CollectedDataNode $node
	 * @param Scope&DependencyTracker $scope
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		// Narrowing off: no filesystem interaction at all, even with a store directory on disk.
		if (!$this->enabled) {
			return [];
		}

		$scope->trackValueDependency(RerunValueExtension::class, self::class);

		// With reuseCollectedDataRuleErrors the errors of an earlier run come back without this rule
		// running, so its writes must not be skipped: the store is tracked as this run found it, and
		// a run which wrote also tracks a written file as it left it - no store matches both, so
		// only a run which wrote nothing is ever reused.
		$scope->trackDirectoryDependency($this->store->directory(), 'LatteSlice_*.php');

		// Deliberately uncaught: AnalyserResultFinalizer::finalize() already wraps every
		// CollectedDataNode rule in try/catch (Throwable) - rethrowing under --debug, else
		// recording an InternalError and continuing - so a write failure here is never fatal to
		// the run but stays diagnosable, instead of vanishing into a silent catch.
		[$analysed, $changed, $pruned] = $this->write($node);
		if ($changed === [] && $pruned === []) {
			return [];
		}

		$scope->trackFileDependency(
			$changed !== [] ? $this->store->slicePath($changed[0]) : $this->store->directory() . '/' . $pruned[0],
		);

		// Non-ignorable: a baseline or ignoreErrors entry would hide a stale store from CI for good.
		$builder = RuleErrorBuilder::message($this->changedMessage($changed, $pruned))
			->identifier(self::IDENTIFIER)
			->line(1)
			->nonIgnorable();

		$anchor = $changed[0] ?? $analysed[0] ?? null;
		$anchorFile = $anchor !== null ? $this->store->slicePath($anchor) : $this->store->firstSlicePath();
		if ($anchorFile !== null) {
			$builder->file($anchorFile);
		}

		return [$builder->build()];
	}

	/**
	 * @return array{list<string>, list<string>, list<string>}
	 */
	private function write(CollectedDataNode $node): array
	{
		$includers = [];
		$entries = [];

		// LatteEdgeScopeCollector::get() only returns files it captured >=1 anchor for - a
		// re-analyzed includer whose anchors all vanished this run (pipeline change, not a
		// content change) would otherwise never appear in $includers, leaving its stale,
		// sha-still-valid entries uncleared. LatteAnalyzedFileMarkerCollector fires once per
		// analyzed file independent of any other collector's output, so it is the only source
		// that can mark "analyzed" separately from "captured".
		foreach ($node->get(LatteAnalyzedFileMarkerCollector::class) as $perFile) {
			foreach ($perFile as $relPath) {
				$includers[$relPath] = true;
			}
		}

		foreach ($node->get(LatteEdgeScopeCollector::class) as $perFile) {
			foreach ($perFile as $captured) {
				$includerRel = $this->includerRel($captured['key']);
				if ($includerRel === null) {
					continue;
				}

				$includers[$includerRel] = true;
				$entries[$captured['key']] = [
					'sha' => $captured['sha'],
					'vars' => $captured['vars'],
					'args' => $captured['args'],
				];
			}
		}

		// Single-writer invariant: this rule runs on CollectedDataNode, which
		// AnalyserResultFinalizer::finalize() constructs exactly once - in the parent/coordinator
		// process, only after every parallel worker's collected data has already been merged into
		// one AnalyserResult - so this write is never concurrent with another worker's write.
		$includerRels = array_keys($includers);
		$changed = $this->store->replaceForIncluders($includerRels, $entries);
		$pruned = $this->store->pruneDeletedIncluders(
			$this->projectRoot,
			$includerRels,
			$this->analysesConfiguredPaths && $includerRels !== [],
		);

		sort($includerRels, SORT_STRING);

		return [$includerRels, $changed, $pruned];
	}

	/**
	 * @param list<string> $changed
	 * @param list<string> $pruned
	 */
	private function changedMessage(array $changed, array $pruned): string
	{
		$parts = [];
		if ($changed !== []) {
			$parts[] = sprintf(
				'The Latte narrowing store changed for %d including %s: %s.',
				count($changed),
				count($changed) === 1 ? 'template' : 'templates',
				$this->capped($changed),
			);
		}

		if ($pruned !== []) {
			$parts[] = sprintf(
				'The Latte narrowing store removed %d %s of deleted templates: %s.',
				count($pruned),
				count($pruned) === 1 ? 'slice' : 'slices',
				$this->capped($pruned),
			);
		}

		$parts[] = 'Run PHPStan again until this error is gone, then commit the regenerated store files.';

		return implode(' ', $parts);
	}

	/**
	 * @param list<string> $items
	 */
	private function capped(array $items): string
	{
		$listed = implode(', ', array_slice($items, 0, self::LISTED_MAX));
		$more = count($items) - self::LISTED_MAX;

		return $more > 0 ? sprintf('%s (+%d more)', $listed, $more) : $listed;
	}

	/**
	 * @param list<string> $paths
	 * @return list<string>
	 */
	private static function normalisePaths(array $paths): array
	{
		$normalised = [];
		foreach ($paths as $path) {
			$real = realpath($path);
			$normalised[] = rtrim(strtr($real !== false ? $real : $path, '\\', '/'), '/');
		}

		$normalised = array_values(array_unique($normalised));
		sort($normalised, SORT_STRING);

		return $normalised;
	}

	private function includerRel(string $key): ?string
	{
		return Strings::before($key, '#');
	}

}
