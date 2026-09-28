<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use function implode;
use function ksort;
use function sha1;
use function sort;
use function strcmp;
use function usort;
use const SORT_STRING;

final class EdgeFingerprint
{

	private function __construct()
	{
	}

	// $outgoingSites must be this file's OWN outgoing include-family sites only - never incoming
	// edges - so a neighbor's edit never changes this fingerprint, only an edit to this file's own
	// include/import/layout/extends sites, or to its own topLevelVars/topLevelDefaults, does.
	// $topLevelVars/$topLevelDefaults close the cross-file-consumed-facts staleness window: a
	// {layout}/{extends} target reads the includer's topLevelVars (EdgeScope), and any includer
	// reads a target's topLevelDefaults (IncludeContractChecker::defaultNamesFor) - neither is an
	// outgoing site of THIS file, so without them here a body-only edit to a top-level {var}/
	// {default} would leave this class's exported signature (and therefore PHPStan's own
	// exportedNodesChanged() diffing) unchanged, and dependents would keep a stale cached result.
	// $siteScopeSliceHash folds this file's own SiteScopeStore slice - the captures written FOR
	// it, keyed by its own current content sha - into this exported constant's VALUE. This covers
	// only the includer-CONTENT-changed path: PHPStan's restore() gate reparses a file (and diffs
	// its exported nodes, this constant included) only once that file's own raw content hash has
	// already changed for another reason (an edit, or a cold/cleared cache) - a slice write alone
	// never touches this file's bytes, so it can never trigger that reparse by itself. The
	// store-only propagation path runs through
	// LatteRoutingParser::emitSliceRefs() instead: a changed SLICE FILE's own bytes/CAPTURES_HASH
	// are what PHPStan's restore() gate observes directly, re-analysing the classes that reference
	// it. Keeping the fold-in here too closes a narrower, complementary staleness window: it
	// prevents a stale (pre-edit) slice hash from surviving inside an already-scheduled reparse.
	// $templateTypeVars closes the TRANSITIVE-includer window:
	// emitTemplateTypeRef() ties THIS file's own reanalysis to its {templateType} class C, but an
	// includer of this file reads C-derived vars only through THIS file's exported nodes/
	// fingerprint - never C directly - and TemplateFactExtractor's topLevelVars come from
	// tokenizing THIS file's own .latte source text, never from reflecting C. Without folding C's
	// resolved property shape in here too, a C-only edit (this file's own bytes untouched) leaves
	// this fingerprint's VALUE unchanged, so an includer several hops away never learns its
	// declared-var assumptions about this file are now stale.

	/**
	 * @param list<IncludeTarget> $outgoingSites
	 * @param array<string, string> $topLevelVars
	 * @param list<string> $topLevelDefaults
	 * @param array<string, string> $templateTypeVars
	 */
	public static function compute(
		array $outgoingSites,
		array $topLevelVars,
		array $topLevelDefaults,
		string $siteScopeSliceHash,
		array $templateTypeVars = []
	): string
	{
		usort($outgoingSites, static function (IncludeTarget $a, IncludeTarget $b): int {
			$byLine = $a->getLatteLine() <=> $b->getLatteLine();
			if ($byLine !== 0) {
				return $byLine;
			}

			$byTag = strcmp($a->getTag(), $b->getTag());
			if ($byTag !== 0) {
				return $byTag;
			}

			$byRawTarget = strcmp($a->getRawTarget(), $b->getRawTarget());
			if ($byRawTarget !== 0) {
				return $byRawTarget;
			}

			$byArgsSource = strcmp($a->getArgsSource(), $b->getArgsSource());
			if ($byArgsSource !== 0) {
				return $byArgsSource;
			}

			$byKind = strcmp($a->getKind(), $b->getKind());
			if ($byKind !== 0) {
				return $byKind;
			}

			return strcmp($a->getResolvedPath() ?? "\x00", $b->getResolvedPath() ?? "\x00");
		});

		$lines = [];
		foreach ($outgoingSites as $site) {
			$lines[] = implode("\x1f", [
				$site->getTag(),
				$site->getKind(),
				$site->getRawTarget(),
				$site->getResolvedPath() ?? "\x00",
				$site->getArgsSource(),
			]);
		}

		ksort($topLevelVars, SORT_STRING);
		$varLines = [];
		foreach ($topLevelVars as $name => $type) {
			$varLines[] = $name . "\x1f" . $type;
		}

		sort($topLevelDefaults, SORT_STRING);

		ksort($templateTypeVars, SORT_STRING);
		$templateTypeVarLines = [];
		foreach ($templateTypeVars as $name => $type) {
			$templateTypeVarLines[] = $name . "\x1f" . $type;
		}

		$payload = implode("\n", $lines)
			. "\x1e" . implode("\n", $varLines)
			. "\x1e" . implode("\n", $topLevelDefaults)
			. "\x1e" . $siteScopeSliceHash
			. "\x1e" . implode("\n", $templateTypeVarLines);

		return sha1($payload);
	}

}
