<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Version\Latte2\DeclarationScanner;

// The shared services an adapter is built from: the compiler's inputs and the one DeclarationScanner
// instance whose memo every declaration consumer shares.
final class AdapterCollaborators
{

	private DeclarationScanner $declarationScanner;

	private ?LatteAnalysisCache $cache;

	private ?CustomsHarvester $harvester;

	private ?DiscoveryStore $discoveryStore;

	private bool $discoveryStoreEnabled;

	public function __construct(
		DeclarationScanner $declarationScanner,
		?LatteAnalysisCache $cache = null,
		?CustomsHarvester $harvester = null,
		?DiscoveryStore $discoveryStore = null,
		bool $discoveryStoreEnabled = false
	)
	{
		$this->declarationScanner = $declarationScanner;
		$this->cache = $cache;
		$this->harvester = $harvester;
		$this->discoveryStore = $discoveryStore;
		$this->discoveryStoreEnabled = $discoveryStoreEnabled;
	}

	public function getDeclarationScanner(): DeclarationScanner
	{
		return $this->declarationScanner;
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
