<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Cache;

use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Support\PhpstanRuntimeStubs;
use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;
use Throwable;
use function get_class;
use function is_array;
use function serialize;
use function sha1;
use function strlen;
use function strncmp;
use function substr;

// Keyed by a render-side class, valued by its render facts: they are read off the class's ancestors'
// method bodies, probed template paths, directory listings and the live container, none of which a
// change of the class's own file reflects.
final class LatteRenderFactsValueExtension implements ResultCacheValueExtension
{

	private const SITELESS_PREFIX = "siteless\0";

	private const SITE_KEYS = ['line' => true, 'site' => true, 'sites' => true];

	private PhpRenderWalk $renderWalk;

	private PhpFactsCache $renderFactsCache;

	public function __construct(PhpRenderWalk $renderWalk, PhpFactsCache $renderFactsCache)
	{
		$this->renderWalk = $renderWalk;
		$this->renderFactsCache = $renderFactsCache;
	}

	// For a reader that reports nothing at the renderer's lines - a template - so that a comment or
	// a blank line in the renderer re-analyses only the renderer.
	public static function sitelessKey(string $className): string
	{
		return self::SITELESS_PREFIX . $className;
	}

	public function getValue(string $key): string
	{
		PhpstanRuntimeStubs::ensureLoaded();

		$siteless = strncmp($key, self::SITELESS_PREFIX, strlen(self::SITELESS_PREFIX)) === 0;
		$className = $siteless ? substr($key, strlen(self::SITELESS_PREFIX)) : $key;

		$walk = $this->renderWalk;
		try {
			$facts = $this->renderFactsCache->remember(
				$className,
				static fn (): PhpRenderFacts => $walk->factsFor($className),
			);
		} catch (Throwable $e) {
			return 'unavailable:' . get_class($e);
		}

		return $siteless ? sha1(serialize(self::withoutSites($facts->toArray()))) : $facts->getCanonicalHash();
	}

	public function keyToResultCache(string $key): string
	{
		return $key;
	}

	public function keyFromResultCache(string $storedKey): string
	{
		return $storedKey;
	}

	/**
	 * @param array<mixed> $facts
	 * @return array<mixed>
	 */
	private static function withoutSites(array $facts): array
	{
		$stripped = [];
		foreach ($facts as $key => $value) {
			if (isset(self::SITE_KEYS[$key])) {
				continue;
			}

			$stripped[$key] = is_array($value) ? self::withoutSites($value) : $value;
		}

		return $stripped;
	}

}
