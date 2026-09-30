<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use Nette\Utils\Strings;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Converge\StoreChangeSignal;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use PhpParser\Node;
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
use function getenv;
use function implode;
use function is_dir;
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

	public const PRUNE_REFUSED_IDENTIFIER = 'orisai.nette.latte.narrowingPruneRefused';

	private const LISTED_MAX = 10;

	private string $storeDirPath;

	private SiteScopeStore $store;

	private bool $enabled;

	private bool $analysesConfiguredPaths;

	/**
	 * @param list<string> $analysedPaths
	 * @param list<string> $analysedPathsFromConfig
	 */
	public function __construct(
		ConfigurationGuard $guard,
		string $storeDirPath,
		SiteScopeStore $store,
		array $analysedPaths,
		array $analysedPathsFromConfig
	)
	{
		$guard->validate();
		$this->storeDirPath = $storeDirPath;
		$this->store = $store;
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
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		// Opt-in gate: narrowing disabled means this rule never even stats the store directory,
		// let alone writes to it - a downstream consumer with a stray store dir on disk must see
		// zero filesystem interaction from this rule.
		if (!$this->enabled) {
			return [];
		}

		// This rule must stay inert on every gate run until the store directory exists, so it
		// never writes into one that does not already exist: the consumer creates the directory at
		// orisai.nette.latte.narrowing.storePath.
		if (!is_dir($this->storeDirPath)) {
			return [];
		}

		// Deliberately uncaught: AnalyserResultFinalizer::finalize() already wraps every
		// CollectedDataNode rule in try/catch (Throwable) - rethrowing under --debug, else
		// recording an InternalError and continuing - so a write failure here is never fatal to
		// the run but stays diagnosable, instead of vanishing into a silent catch.
		[$analysed, $changed, $pruned, $pruneRefusal] = $this->write($node);
		if ($changed === [] && $pruned === []) {
			if ($pruneRefusal === null) {
				return [];
			}

			$builder = RuleErrorBuilder::message(sprintf(
				'The Latte narrowing store was not pruned: %s. Prune with the paths your configuration analyses.',
				$pruneRefusal,
			))
				->identifier(self::PRUNE_REFUSED_IDENTIFIER)
				->line(1)
				->nonIgnorable();
		} else {
			// Non-ignorable: a baseline or ignoreErrors entry would hide a stale store from CI for good.
			$builder = RuleErrorBuilder::message($this->changedMessage($changed, $pruned, $pruneRefusal))
				->identifier(StoreChangeSignal::IDENTIFIER)
				->line(1)
				->nonIgnorable();
		}

		$anchor = $changed[0] ?? $analysed[0] ?? null;
		if ($anchor !== null) {
			$builder->file($this->store->slicePath($anchor));
		}

		return [$builder->build()];
	}

	/**
	 * @return array{list<string>, list<string>, list<string>, string|null}
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
		$pruned = [];
		$pruneRefusal = null;
		if (getenv(StoreChangeSignal::PRUNE_ENVIRONMENT_VARIABLE) === '1') {
			if (!$this->analysesConfiguredPaths) {
				$pruneRefusal = 'the run analysed other paths than the configured ones';
			} elseif ($includerRels === []) {
				$pruneRefusal = 'the run analysed no templates';
			} else {
				$pruned = $this->store->pruneExcept($includerRels);
			}
		}

		sort($includerRels, SORT_STRING);

		return [$includerRels, $changed, $pruned, $pruneRefusal];
	}

	/**
	 * @param list<string> $changed
	 * @param list<string> $pruned
	 */
	private function changedMessage(array $changed, array $pruned, ?string $pruneRefusal): string
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
				'The Latte narrowing store pruned %d orphaned %s: %s.',
				count($pruned),
				count($pruned) === 1 ? 'slice' : 'slices',
				$this->capped($pruned),
			);
		}

		if ($pruneRefusal !== null) {
			$parts[] = sprintf('Pruning was skipped: %s.', $pruneRefusal);
		}

		$parts[] = 'Run the analysis again until this error disappears, then commit the store.';

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
