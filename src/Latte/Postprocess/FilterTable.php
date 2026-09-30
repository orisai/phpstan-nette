<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use LogicException;
use OriPhpstan\Nette\Latte\Customs\FilterLoaderProbe;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use OriPhpstan\Nette\Latte\Version\DefaultCallables;
use Throwable;
use function array_key_exists;
use function ksort;
use function strtolower;

final class FilterTable
{

	use CallableTargetResolution;

	/** @var array<string, array{string, string, bool}> */
	private array $table = [];

	/** @var array<string, true> */
	private array $instanceDispatch = [];

	private ?FilterLoaderProbe $filterLoaders;

	/** @var array<string, array{string, string, bool, bool}|null> */
	private array $loaderEntries = [];

	// The bridge entries come first: the runtime bridges (nette/application's |modifyDate, the
	// translator's |translate) register them as closures the tables cannot reference, and |slice is
	// redirected to a typed helper whose return follows the input. testResolvesEveryDefaultFilter
	// guards drift between the installed defaults and the fallbacks.
	public function __construct(DefaultCallables $defaults, ?HarvestedCustoms $harvested = null)
	{
		$this->table['translate'] = [Helpers::class, 'translate', false];
		$this->table['modifydate'] = [Helpers::class, 'modifyDate', false];
		$this->table['slice'] = [Helpers::class, 'slice', false];

		foreach ($defaults->getFilters() as $name => $callable) {
			$this->register($name, $callable, $defaults);
		}

		$harvested ??= HarvestedCustoms::empty();
		$this->filterLoaders = $harvested->getFilterLoaders();
		foreach ($harvested->getFilters() as $name => $callable) {
			$this->registerHarvested($name, $callable, $harvested->isExtensionHarvest());
		}

		ksort($this->table);
	}

	/**
	 * @return array{string, string, bool}|null
	 */
	public function resolve(string $lowerName): ?array
	{
		return $this->table[$lowerName] ?? null;
	}

	// The 4th element (isPerTemplate) says the entry names a {templateType} class's own qualifying
	// method - strictly scoped to the declaring template's own class (a deliberate divergence from
	// the runtime's shared-engine leak). The 5th (isStatic) tells FilterRewriter HOW to dispatch: a
	// plain "Class::method()" StaticCall, or - for an instance method, per-template or a stock one
	// like Latte 3's |number - through Helpers::templateTypeInstance($class)->method(...) (there is
	// no real instance at analysis time, see the helper's own docblock).

	// A name neither table knows goes to the harvested engine's filter loaders, asked with the name as
	// written ($writtenName; FilterLoaderProbe applies the Latte line's case rule).

	/**
	 * @return array{string, string, bool, bool, bool}|null
	 */
	public function resolveForTemplate(
		string $lowerName,
		?string $templateTypeClass,
		?TemplateTypeCustoms $templateTypeCustoms,
		?string $writtenName = null
	): ?array
	{
		if ($templateTypeClass !== null && $templateTypeCustoms !== null) {
			$scoped = $templateTypeCustoms->filtersFor($templateTypeClass)[$lowerName] ?? null;
			if ($scoped !== null) {
				return [$scoped[0], $scoped[1], $scoped[2], true, $scoped[3]];
			}
		}

		$base = $this->resolve($lowerName);
		if ($base !== null) {
			return [$base[0], $base[1], $base[2], false, !isset($this->instanceDispatch[$lowerName])];
		}

		$loaded = $this->resolveFromLoaders($writtenName ?? $lowerName);

		return $loaded === null ? null : [$loaded[0], $loaded[1], $loaded[2], false, $loaded[3]];
	}

	/**
	 * @return array{string, string, bool, bool}|null
	 */
	private function resolveFromLoaders(string $writtenName): ?array
	{
		$filterLoaders = $this->filterLoaders;
		if ($filterLoaders === null) {
			return null;
		}

		$key = $filterLoaders->queryName($writtenName);
		if (!array_key_exists($key, $this->loaderEntries)) {
			$callable = $filterLoaders->resolve($writtenName);
			$this->loaderEntries[$key] = $callable !== null ? $this->loaderEntry($callable) : null;
		}

		return $this->loaderEntries[$key];
	}

	/**
	 * @param callable(mixed...): mixed $callable
	 * @return array{string, string, bool, bool}|null
	 */
	private function loaderEntry(callable $callable): ?array
	{
		try {
			$target = $this->resolveDefaultTarget($callable);
			if ($target === null) {
				return null;
			}

			[$class, $method, $isStatic] = $target;

			return [$class, $method, $this->isContentAware($class, $method), $isStatic];
		} catch (Throwable $e) {
			return null;
		}
	}

	/**
	 * @param callable(mixed...): mixed $callable
	 */
	private function register(string $name, callable $callable, DefaultCallables $defaults): void
	{
		$key = strtolower($name);
		if (isset($this->table[$key])) {
			return;
		}

		[$class, $method, $isStatic] = $this->resolveTarget($name, $callable, $defaults);
		$this->table[$key] = [$class, $method, $this->isContentAware($class, $method)];
		if (!$isStatic) {
			$this->instanceDispatch[$key] = true;
		}
	}

	// Harvested callables (real, running-engine values) can take shapes resolveTarget()/
	// isContentAware() never throw for on the stock domain but aren't representable as a static
	// "Class::method"/plain-function AST reference at all - a Closure, an invokable object, and on a
	// Latte 2 harvest an instance-bound [$object, 'method'] array (a Latte 3 extension harvest
	// resolves those and named-method closures to an instance dispatch) - those degrade to "stays
	// unknown" here rather than crashing or guessing; a built-in of the same lowercase name always
	// wins (register() above runs first).

	/**
	 * @param callable(mixed...): mixed $callable
	 */
	private function registerHarvested(string $name, callable $callable, bool $extensionHarvest): void
	{
		$key = strtolower($name);
		if (isset($this->table[$key])) {
			return;
		}

		try {
			$target = $this->resolveHarvestedTarget($callable, $extensionHarvest);
			if ($target === null) {
				return;
			}

			[$class, $method, $isStatic] = $target;
			$isContentAware = $this->isContentAware($class, $method);
		} catch (Throwable $e) {
			return;
		}

		$this->table[$key] = [$class, $method, $isContentAware];
		if (!$isStatic) {
			$this->instanceDispatch[$key] = true;
		}
	}

	/**
	 * @param callable(mixed...): mixed $callable
	 * @return array{string, string, bool}
	 */
	private function resolveTarget(string $name, callable $callable, DefaultCallables $defaults): array
	{
		$target = $this->resolveDefaultTarget($callable);
		if ($target !== null) {
			return $target;
		}

		$fallback = $defaults->filterFallback(strtolower($name));
		if ($fallback === null) {
			throw new LogicException("FilterTable has no hardcoded mapping for closure-backed filter '$name'.");
		}

		return [$fallback[0], $fallback[1], true];
	}

}
