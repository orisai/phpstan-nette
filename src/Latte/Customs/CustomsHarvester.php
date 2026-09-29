<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use OriPhpstan\Nette\Latte\Compile\VendorErrorContainment;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapterFactory;
use Throwable;

final class CustomsHarvester
{

	private EngineSource $engineSource;

	private LatteVersionAdapterFactory $adapterFactory;

	private ?HarvestedCustoms $harvested = null;

	public function __construct(EngineSource $engineSource, LatteVersionAdapterFactory $adapterFactory)
	{
		$this->engineSource = $engineSource;
		$this->adapterFactory = $adapterFactory;
	}

	public function harvest(): HarvestedCustoms
	{
		if ($this->harvested === null) {
			$this->harvested = $this->doHarvest();
		}

		return $this->harvested;
	}

	public function hasConfiguredSource(): bool
	{
		return $this->engineSource->isConfigured();
	}

	private function doHarvest(): HarvestedCustoms
	{
		try {
			return VendorErrorContainment::run(
				function (): HarvestedCustoms {
					$engine = $this->engineSource->resolve();

					if ($engine === null) {
						return HarvestedCustoms::empty();
					}

					return $this->adapterFactory->createEngineReader()->read($engine);
				},
				// No per-template line exists here - harvest runs once per analysis, not once per
				// compiled template - so every severity is contained and dropped, same
				// vendor-internal-noise policy LatteCompiler applies to its own non-deprecation
				// captures. Unlike LatteCompiler's wrap, this window also covers our own resolve()/
				// reader code, not just vendor calls - an accepted trade (spec explicitly scopes
				// the wrap to "engine creation, enumeration, onCompile"), consistent with harvest's
				// existing silent-degradation contract: an own-code notice in here is contained the
				// same as a vendor one, never surfaced.
				static function (int $severity, string $message): void {
				},
			);
		} catch (Throwable $e) {
			return HarvestedCustoms::empty();
		}
	}

}
