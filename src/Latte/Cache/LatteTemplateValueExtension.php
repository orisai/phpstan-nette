<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Cache;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Configuration\InvalidConfiguration;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\HarvestProblemReporter;
use OriPhpstan\Nette\Support\PhpstanRuntimeStubs;
use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;
use function implode;
use function ksort;
use function strlen;
use function strncmp;
use function substr;
use const SORT_STRING;

final class LatteTemplateValueExtension implements ResultCacheValueExtension
{

	public const HARVEST = 'harvest';

	public const TEMPLATE_FACTORY = 'templateFactory';

	private const NEIGHBOURHOOD_PREFIX = "neighbourhood\0";

	private const HARVEST_HOST_PREFIX = "harvestHost\0";

	private ConfigurationGuard $guard;

	private TemplateNeighbourhood $neighbourhood;

	private ?CustomsHarvester $harvester;

	private ?TemplateFactoryDefaultResolver $templateFactoryDefault;

	private ?HarvestProblemReporter $harvestProblemReporter;

	public function __construct(
		ConfigurationGuard $guard,
		TemplateNeighbourhood $neighbourhood,
		?CustomsHarvester $harvester = null,
		?TemplateFactoryDefaultResolver $templateFactoryDefault = null,
		?HarvestProblemReporter $harvestProblemReporter = null
	)
	{
		$this->harvestProblemReporter = $harvestProblemReporter;
		$this->guard = $guard;
		$this->neighbourhood = $neighbourhood;
		$this->harvester = $harvester;
		$this->templateFactoryDefault = $templateFactoryDefault;
	}

	public static function neighbourhoodKey(string $relativePath): string
	{
		return self::NEIGHBOURHOOD_PREFIX . $relativePath;
	}

	public static function harvestHostKey(string $relativePath): string
	{
		return self::HARVEST_HOST_PREFIX . $relativePath;
	}

	public function getValue(string $key): string
	{
		PhpstanRuntimeStubs::ensureLoaded();

		try {
			$this->guard->validate();
		} catch (InvalidConfiguration $e) {
			return 'invalid';
		}

		if (!$this->guard->isLatteEnabled()) {
			return 'disabled';
		}

		if ($key === self::HARVEST) {
			return ($this->harvester !== null ? $this->harvester->harvest() : HarvestedCustoms::empty())->getSaltHash();
		}

		if ($key === self::TEMPLATE_FACTORY) {
			return $this->templateFactorySalt();
		}

		if (strncmp($key, self::NEIGHBOURHOOD_PREFIX, strlen(self::NEIGHBOURHOOD_PREFIX)) === 0) {
			return $this->neighbourhood->digest(substr($key, strlen(self::NEIGHBOURHOOD_PREFIX)));
		}

		if (strncmp($key, self::HARVEST_HOST_PREFIX, strlen(self::HARVEST_HOST_PREFIX)) === 0) {
			return $this->harvestProblemReporter !== null
				? $this->harvestProblemReporter->hostValue(substr($key, strlen(self::HARVEST_HOST_PREFIX)))
				: 'none';
		}

		return 'unknown';
	}

	public function keyToResultCache(string $key): string
	{
		return $key;
	}

	public function keyFromResultCache(string $storedKey): string
	{
		return $storedKey;
	}

	// The factory-default template class and the wired dependencies FactoryProvidedVars reads from
	// the live container. "No container to ask" must not hash like a container wiring neither.
	private function templateFactorySalt(): string
	{
		$resolver = $this->templateFactoryDefault;
		if ($resolver === null) {
			return 'absent';
		}

		$wiring = $resolver->resolveWiring();
		if ($wiring === null) {
			return 'absent';
		}

		ksort($wiring, SORT_STRING);

		$parts = [(string) $resolver->resolve()];
		foreach ($wiring as $wiringKey => $className) {
			$parts[] = $wiringKey . '=' . $className;
		}

		return implode("\x1f", $parts);
	}

}
