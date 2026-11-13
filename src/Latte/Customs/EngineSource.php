<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use Latte\Engine;
use Nette\Bridges\ApplicationLatte\ILatteFactory;
use Nette\DI\Container;
use OriPhpstan\Nette\Latte\Parser\BootstrapFilesLoader;
use Throwable;
use function is_array;
use function is_file;

final class EngineSource
{

	private ?string $containerLoaderFile;

	private ?string $latteEngineLoaderFile;

	private ?BootstrapFilesLoader $bootstrapFilesLoader;

	public function __construct(
		?string $containerLoader,
		?string $latteEngineLoaderFile,
		?BootstrapFilesLoader $bootstrapFilesLoader = null
	)
	{
		$this->containerLoaderFile = $containerLoader;
		$this->latteEngineLoaderFile = $latteEngineLoaderFile;
		$this->bootstrapFilesLoader = $bootstrapFilesLoader;
	}

	public function resolve(): ?Engine
	{
		$engine = $this->resolveFromContainerLoader();

		if ($engine !== null) {
			return $engine;
		}

		return $this->resolveFromEngineLoaderFile();
	}

	// True whenever either knob is SET, independent of whether resolve() eventually succeeds - a
	// containerLoaderFile pointing at a real file with no ILatteFactory (or a orisai.nette.latte.engineLoader
	// throwing) still counts as configured, degrading to an EMPTY harvest rather than to the
	// distinct "nothing configured at all" state dumpLatteCustoms() reports.
	public function isConfigured(): bool
	{
		return $this->containerLoaderFile !== null || $this->latteEngineLoaderFile !== null;
	}

	private function resolveFromContainerLoader(): ?Engine
	{
		$containerLoaderFile = $this->containerLoaderFile;

		if ($containerLoaderFile === null || !is_file($containerLoaderFile)) {
			return null;
		}

		$this->loadBootstrapFiles();

		try {
			$containers = require $containerLoaderFile;
		} catch (Throwable $e) {
			return null;
		}

		if ($containers instanceof Container) {
			$containers = [$containers];
		}

		if (!is_array($containers)) {
			return null;
		}

		foreach ($containers as $container) {
			if (!$container instanceof Container) {
				continue;
			}

			$engine = $this->resolveFromContainer($container);

			if ($engine !== null) {
				return $engine;
			}
		}

		return null;
	}

	private function resolveFromContainer(Container $container): ?Engine
	{
		try {
			$factory = $container->getByType(ILatteFactory::class, false);
		} catch (Throwable $e) {
			return null;
		}

		if ($factory === null) {
			return null;
		}

		try {
			return $factory->create();
		} catch (Throwable $e) {
			return null;
		}
	}

	private function resolveFromEngineLoaderFile(): ?Engine
	{
		if ($this->latteEngineLoaderFile === null || !is_file($this->latteEngineLoaderFile)) {
			return null;
		}

		$this->loadBootstrapFiles();

		try {
			$engine = require $this->latteEngineLoaderFile;
		} catch (Throwable $e) {
			return null;
		}

		return $engine instanceof Engine ? $engine : null;
	}

	private function loadBootstrapFiles(): void
	{
		if ($this->bootstrapFilesLoader !== null) {
			$this->bootstrapFilesLoader->load();
		}
	}

}
