<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

use Latte\Engine;
use LogicException;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Version\Latte2\FormSiteScanner;
use OriPhpstan\Nette\Latte\Version\Latte2\Latte2Adapter;
use OriPhpstan\Nette\Latte\Version\Latte2\Latte2EngineReader;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use ReflectionMethod;

// The one version switch. It holds nothing but the installed versions and is only ever asked at
// first use (LatteVersionAdapterAccessor::get(), CustomsHarvester::harvest()), never at container
// build, so an unsupported install is the ConfigurationGuard's message rather than a crash.
final class LatteVersionAdapterFactory
{

	// Resolved at runtime only: the Latte 3 classes are analysed under their own profile and stay
	// out of the default one's type-checked graph.
	private const LATTE3_ADAPTER_CLASS = 'OriPhpstan\Nette\Latte\Version\Latte3\Latte3Adapter';

	private ProjectInstalledVersions $installedVersions;

	private ?ShapeFamily $family = null;

	public function __construct(ProjectInstalledVersions $installedVersions)
	{
		$this->installedVersions = $installedVersions;
	}

	public function family(): ShapeFamily
	{
		return $this->family ??= ShapeFamily::detect(
			$this->installedVersions->getVersion('latte/latte') ?? Engine::VERSION,
			$this->installedVersions->getVersion('nette/forms'),
		);
	}

	public function create(AdapterCollaborators $collaborators): LatteVersionAdapter
	{
		$family = $this->family();

		if ($family->latteLine === ShapeFamily::LATTE_2) {
			return new Latte2Adapter(
				new LatteCompiler(
					$collaborators->getCache(),
					$collaborators->getHarvester(),
					$collaborators->getDiscoveryStore(),
					$collaborators->isDiscoveryStoreEnabled(),
				),
				$collaborators->getDeclarationScanner(),
				new TemplateFactExtractor(),
				new FormSiteScanner(),
			);
		}

		$adapter = (new ReflectionMethod(self::LATTE3_ADAPTER_CLASS, 'create'))->invoke(null, $family, $collaborators);
		if (!$adapter instanceof LatteVersionAdapter) {
			throw new LogicException('Latte 3 adapter factory must return a LatteVersionAdapter.');
		}

		return $adapter;
	}

	public function createEngineReader(): LatteEngineReader
	{
		if ($this->family()->latteLine === ShapeFamily::LATTE_2) {
			return new Latte2EngineReader();
		}

		throw new LogicException('Latte 3 engine reader not available yet');
	}

}
