<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use function array_key_exists;
use function array_keys;
use function sha1_file;

// The one piece of logic every capture CONSUMER (ContextResolver, IncludeContractChecker,
// DeclarationInjector) must call through here rather than recompute - see EdgeScope's own
// drift-warning comment for why two independently-written copies of a store lookup are a hazard.

final class CapturedOverlay
{

	private SiteScopeStore $store;

	private bool $enabled;

	/** @var array<string, string> */
	private array $includerShaCache = [];

	public function __construct(SiteScopeStore $store, bool $enabled)
	{
		$this->store = $store;
		$this->enabled = $enabled;
	}

	// Narrowing overlay: replaces edge-provided type strings with the ones a prior
	// analysis run actually observed, never adds a name the edge doesn't already provide - an
	// old-code-version store entry that captured a since-removed variable must stay inert. Callers
	// apply their own declared-target overlay AFTER this one and let it win per variable, so a
	// captured type can never override a declaration, by construction alone.

	/**
	 * @param array<string, string> $vars
	 * @param array<string, string> $namedKeys
	 * @return array{vars: array<string, string>, namedKeys: array<string, string>, overlaidNames: array<string, true>}
	 */
	public function overlay(
		array $vars,
		array $namedKeys,
		string $includerRel,
		string $includerAbsolute,
		int $latteLine,
		string $rawTarget,
		string $contextHash
	): array
	{
		$slice = $this->getSlice($includerRel, $includerAbsolute, $latteLine, $rawTarget, $contextHash);
		if ($slice === null) {
			return ['vars' => $vars, 'namedKeys' => $namedKeys, 'overlaidNames' => []];
		}

		// overlaidNames (consumed by dumpLatteVarOrigin): exactly the names this call actually
		// replaced, for the debug rule's `captured:` provenance label - never a name the store
		// mentions but this edge/context doesn't already provide (same containment the vars/args
		// loops below already enforce).
		$overlaidNames = [];

		foreach ($slice['vars'] as $name => $type) {
			if (array_key_exists($name, $vars)) {
				$vars[$name] = $type;
				$overlaidNames[$name] = true;
			}
		}

		foreach ($slice['args'] as $name => $type) {
			if (array_key_exists($name, $namedKeys)) {
				$vars[$name] = $type;
				$namedKeys[$name] = $type;
				$overlaidNames[$name] = true;
			}
		}

		return ['vars' => $vars, 'namedKeys' => $namedKeys, 'overlaidNames' => $overlaidNames];
	}

	// Opt-in gate: narrowing disabled means the store is never even asked for an entry - this is
	// the single choke point every consumer (overlay() below, DeclarationInjector's direct call)
	// goes through, so "no store reads when off" holds regardless of what a stray store directory
	// on disk contains.
	//
	// $declaredNames (empty by default, so overlay()'s own call here is untouched): names a
	// caller already knows are DECLARED at the target (e.g. DeclaredVarsResolver::forBlock for a
	// block-dispatch site) are stripped from the returned slice before the caller ever sees them -
	// defensive against an old-store-version entry captured before the target declared that name
	// (block-body {varType} is new syntax; a pre-existing slice cannot yet know about it).
	// DeclarationInjector's own-untyped-param materialization (capturedBlockArgTypes) is the one
	// caller that opts in: a declared body {varType} must win over a captured arg type there, same
	// per-variable priority a typed own param already gets unconditionally.

	/**
	 * @param array<string, string> $declaredNames
	 * @return array{sha: string, vars: array<string, string>, args: array<string, string>}|null
	 */
	public function getSlice(
		string $includerRel,
		string $includerAbsolute,
		int $latteLine,
		string $rawTarget,
		string $contextHash,
		array $declaredNames = []
	): ?array
	{
		if (!$this->enabled) {
			return null;
		}

		$slice = $this->store->get(
			SiteScopeStore::key($includerRel, $latteLine, $rawTarget, $contextHash),
			$this->includerSha($includerAbsolute),
		);

		if ($slice === null || $declaredNames === []) {
			return $slice;
		}

		foreach (array_keys($declaredNames) as $name) {
			unset($slice['vars'][$name], $slice['args'][$name]);
		}

		return $slice;
	}

	// A store entry's sha never matches a false fallback (sha1_file() only fails if the includer
	// vanished mid-run, an already-tolerated race per LatteUniverse::contentHashes()'s own
	// fallback) - callers degrade to no overlay rather than throwing.
	public function includerSha(string $includerAbsolute): string
	{
		if (isset($this->includerShaCache[$includerAbsolute])) {
			return $this->includerShaCache[$includerAbsolute];
		}

		$sha = sha1_file($includerAbsolute);

		return $this->includerShaCache[$includerAbsolute] = $sha === false ? '' : $sha;
	}

}
