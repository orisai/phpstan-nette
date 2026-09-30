<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use Latte\Engine;
use OriPhpstan\Nette\Latte\Compile\VendorErrorContainment;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapterFactory;
use Throwable;

final class CustomsHarvester
{

	private EngineSource $engineSource;

	private LatteVersionAdapterFactory $adapterFactory;

	private ExtensionSourceSalt $extensionSourceSalt;

	private ?LatteUniverse $universe;

	private ?HarvestedCustoms $harvested = null;

	public function __construct(
		EngineSource $engineSource,
		LatteVersionAdapterFactory $adapterFactory,
		ExtensionSourceSalt $extensionSourceSalt,
		?LatteUniverse $universe = null
	)
	{
		$this->engineSource = $engineSource;
		$this->adapterFactory = $adapterFactory;
		$this->extensionSourceSalt = $extensionSourceSalt;
		$this->universe = $universe;
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
		$engine = $this->contained(fn (): ?Engine => $this->engineSource->resolve());

		if ($engine === null) {
			return HarvestedCustoms::empty();
		}

		// Outside the containment on purpose: a reader the factory cannot provide for the installed
		// Latte is a defect of this library, and swallowing it would pass off an empty harvest as the
		// project's real customs.
		$reader = $this->adapterFactory->createEngineReader();

		$sourceSalt = $this->extensionSourceSalt;

		$harvested = $this->contained(
			static fn (): HarvestedCustoms => $reader->read($engine)->withExtensionSources($sourceSalt),
		) ?? HarvestedCustoms::empty();

		return $this->withLoaderFilters($harvested);
	}

	// The loaders' answers for every filter name the analysed templates may use join the salt, so a
	// loader that starts or stops answering one of them invalidates both caches. The same memoised
	// probe answers FilterTable later, so the salt and the analysis agree within a process.
	private function withLoaderFilters(HarvestedCustoms $harvested): HarvestedCustoms
	{
		$universe = $this->universe;
		if ($harvested->getFilterLoaders() === null || $universe === null) {
			return $harvested;
		}

		// Without the salt lines a loader answer could change unseen by both caches, so a failed scan
		// drops the loaders: their filters report unknown rather than serve a stale analysis.
		return $this->contained(
			static fn (): HarvestedCustoms => $harvested->withLoaderFilters(
				FilterNameScan::scan($universe->files()),
			),
		) ?? $harvested->withoutFilterLoaders(
			'filter loaders are not asked - the analysed templates could not be scanned for filter names',
		);
	}

	// No per-template line exists here - harvest runs once per analysis, not once per compiled
	// template - so every severity is contained and dropped, same vendor-internal-noise policy
	// LatteCompiler applies to its own non-deprecation captures; a throwing body degrades to null.
	// The window also covers our own resolve()/reader code, not just vendor calls - an accepted
	// trade consistent with harvest's silent-degradation contract.

	/**
	 * @template T
	 * @param callable(): (T|null) $body
	 * @return T|null
	 */
	private function contained(callable $body)
	{
		try {
			return VendorErrorContainment::run(
				$body,
				static function (int $severity, string $message): void {
				},
			);
		} catch (Throwable $e) {
			return null;
		}
	}

}
