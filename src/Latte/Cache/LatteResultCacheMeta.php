<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Cache;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Configuration\InvalidConfiguration;
use OriPhpstan\Nette\Latte\Bridge\Discovery\PreAnalysisIndexBuilder;
use OriPhpstan\Nette\Support\PhpstanRuntimeStubs;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;

// Not a salt: the discovery store must be built before the first template is parsed, and
// ResultCacheManager::restore() calls every meta extension exactly once, in the coordinator, before
// any file is parsed or handed to a worker - PHPStan 2.3 offers no other pre-analysis seam. The
// returned hash never moves, so the build never discards the result cache; what the analysis reads
// from the store is tracked per template (LatteTemplateValueExtension).
// @phpstan-ignore class.implementsDeprecatedInterface (the only pre-analysis seam, see above)
final class LatteResultCacheMeta implements ResultCacheMetaExtension
{

	public const KEY = 'orisai.nette.latte.discoveryBuild';

	public const HASH = 'pre-analysis build';

	private ConfigurationGuard $guard;

	private ?PreAnalysisIndexBuilder $indexBuilder;

	private bool $indexBuilt = false;

	public function __construct(ConfigurationGuard $guard, ?PreAnalysisIndexBuilder $indexBuilder = null)
	{
		$this->guard = $guard;
		$this->indexBuilder = $indexBuilder;
	}

	public function getKey(): string
	{
		return self::KEY;
	}

	public function getHash(): string
	{
		PhpstanRuntimeStubs::ensureLoaded();

		try {
			$this->guard->validate();
		} catch (InvalidConfiguration $e) {
			// An exception here is a raw console crash; the rules report the message properly.
			return self::HASH;
		}

		$indexBuilder = $this->indexBuilder;
		if ($indexBuilder !== null && $this->guard->isLatteDiscoveryEnabled() && !$this->indexBuilt) {
			$this->indexBuilt = true;
			$indexBuilder->build();
		}

		return self::HASH;
	}

}
