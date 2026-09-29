<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;

// The version-agnostic services every adapter's compiler is built from.
final class AdapterCollaborators
{

	private ?LatteAnalysisCache $cache;

	private ?CustomsHarvester $harvester;

	private ?DiscoveryStore $discoveryStore;

	private bool $discoveryStoreEnabled;

	public function __construct(
		?LatteAnalysisCache $cache = null,
		?CustomsHarvester $harvester = null,
		?DiscoveryStore $discoveryStore = null,
		bool $discoveryStoreEnabled = false
	)
	{
		$this->cache = $cache;
		$this->harvester = $harvester;
		$this->discoveryStore = $discoveryStore;
		$this->discoveryStoreEnabled = $discoveryStoreEnabled;
	}

	public function getCache(): ?LatteAnalysisCache
	{
		return $this->cache;
	}

	public function getHarvester(): ?CustomsHarvester
	{
		return $this->harvester;
	}

	public function getDiscoveryStore(): ?DiscoveryStore
	{
		return $this->discoveryStore;
	}

	public function isDiscoveryStoreEnabled(): bool
	{
		return $this->discoveryStoreEnabled;
	}

}
