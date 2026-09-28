<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Cache;

use PHPStan\Cache\CacheStorage;
use function strpos;

// PHPStan's own FileTypeMapper (`ftm-<file>` keys) and BetterReflection OptimizedSingleFileSourceLocator
// (`osfsl-<file>-...` keys) persist their PhpDoc/reflection results to %tmpDir%/cache/PHPStan across
// separate CLI invocations, invalidated only by hash_file() of the referenced path. That is correct
// for a real PHP file, but our defaultAnalysisParser! (LatteRoutingParser) AST for a .latte path is
// not a pure function of that file's own bytes: DeclarationInjector's per-edge clones depend on
// ContextResolver's contexts, which depend on the site-scope store's captured data - data that can
// change (as narrowing convergence writes new captures) without the .latte file's own content
// changing at all. A cross-invocation cache entry built before a capture propagated then silently
// keeps serving a stale PhpDoc/reflection for every latteMain_ctx<N> clone but the one that happened
// to populate the entry, degrading the rest to implicit mixed - confirmed by a direct cold-vs-warm
// %tmpDir%/cache/PHPStan A/B repro on _twoStateRadioList.latte's 9 per-edge clones. Slice files
// (LatteSlice_*.php, real files SiteScopeStore writes) are unaffected and keep the phar's normal,
// correctly-invalidated caching - only paths containing ".latte" are ever bypassed here.
final class LatteReflectionCacheBypass implements CacheStorage
{

	private CacheStorage $inner;

	public function __construct(CacheStorage $inner)
	{
		$this->inner = $inner;
	}

	/**
	 * @return mixed
	 */
	public function load(string $key, string $variableKey)
	{
		if ($this->isLatteDerived($key)) {
			return null;
		}

		return $this->inner->load($key, $variableKey);
	}

	/**
	 * @param mixed $data
	 */
	public function save(string $key, string $variableKey, $data): void
	{
		if ($this->isLatteDerived($key)) {
			return;
		}

		$this->inner->save($key, $variableKey, $data);
	}

	private function isLatteDerived(string $key): bool
	{
		return strpos($key, '.latte') !== false;
	}

}
