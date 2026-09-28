<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use Nette\Utils\Strings;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use function array_keys;
use function is_dir;

/**
 * @implements Rule<CollectedDataNode>
 */
final class LatteSiteScopeWriterRule implements Rule
{

	private string $storeDirPath;

	private SiteScopeStore $store;

	private bool $enabled;

	public function __construct(ConfigurationGuard $guard, string $storeDirPath, SiteScopeStore $store)
	{
		$guard->validate();
		$this->storeDirPath = $storeDirPath;
		$this->store = $store;
		$this->enabled = $guard->isLatteNarrowingEnabled();
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
		// never writes into one that does not already exist. Bootstrap: `make phpstan-narrowing-init`
		// creates the initial (slice-per-includer) directory this gate checks for.
		if (!is_dir($this->storeDirPath)) {
			return [];
		}

		// Deliberately uncaught: AnalyserResultFinalizer::finalize() already wraps every
		// CollectedDataNode rule in try/catch (Throwable) - rethrowing under --debug, else
		// recording an InternalError and continuing - so a write failure here is never fatal to
		// the run but stays diagnosable, instead of vanishing into a silent catch.
		$this->write($node);

		return [];
	}

	private function write(CollectedDataNode $node): void
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
		$this->store->replaceForIncluders(array_keys($includers), $entries);
	}

	private function includerRel(string $key): ?string
	{
		return Strings::before($key, '#');
	}

}
