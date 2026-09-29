<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use LogicException;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Version\DefaultCallables;
use Throwable;
use function ksort;
use function strtolower;

final class FunctionTable
{

	use CallableTargetResolution;

	/** @var array<string, array{string, string, bool}> */
	private array $table = [];

	/** @var array<string, true> */
	private array $instanceDispatch = [];

	public function __construct(DefaultCallables $defaults, ?HarvestedCustoms $harvested = null)
	{
		foreach ($defaults->getFunctions() as $name => $callable) {
			$this->register($name, $callable, $defaults);
		}

		foreach (($harvested ?? HarvestedCustoms::empty())->getFunctions() as $name => $callable) {
			$this->registerHarvested($name, $callable);
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

	// See FilterTable::resolveForTemplate()'s own doc - identical contract, mirrored here for
	// per-template FUNCTION entries.

	/**
	 * @return array{string, string, bool, bool, bool}|null
	 */
	public function resolveForTemplate(
		string $lowerName,
		?string $templateTypeClass,
		?TemplateTypeCustoms $templateTypeCustoms
	): ?array
	{
		if ($templateTypeClass !== null && $templateTypeCustoms !== null) {
			$scoped = $templateTypeCustoms->functionsFor($templateTypeClass)[$lowerName] ?? null;
			if ($scoped !== null) {
				return [$scoped[0], $scoped[1], $scoped[2], true, $scoped[3]];
			}
		}

		$base = $this->resolve($lowerName);

		return $base === null
			? null
			: [$base[0], $base[1], $base[2], false, !isset($this->instanceDispatch[$lowerName])];
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

		$target = $this->resolveDefaultTarget($callable) ?? $this->fallbackTarget($name, $defaults);
		[$class, $method, $isStatic] = $target;
		$this->table[$key] = [$class, $method, $this->isContentAware($class, $method)];
		if (!$isStatic) {
			$this->instanceDispatch[$key] = true;
		}
	}

	/**
	 * @return array{string, string, bool}
	 */
	private function fallbackTarget(string $name, DefaultCallables $defaults): array
	{
		$fallback = $defaults->functionFallback(strtolower($name));
		if ($fallback === null) {
			throw new LogicException("FunctionTable has no static-callable mapping for default function '$name'.");
		}

		return [$fallback[0], $fallback[1], true];
	}

	// Same degrade as FilterTable::registerHarvested() - an unrepresentable harvested callable
	// shape (instance-bound array, Closure, invokable object) stays unknown rather than crashing
	// or guessing; a built-in of the same lowercase name always wins.

	/**
	 * @param callable(mixed...): mixed $callable
	 */
	private function registerHarvested(string $name, callable $callable): void
	{
		$key = strtolower($name);
		if (isset($this->table[$key])) {
			return;
		}

		$target = $this->resolveStaticTarget($callable);
		if ($target === null) {
			return;
		}

		[$class, $method] = $target;

		try {
			$isContentAware = $this->isContentAware($class, $method);
		} catch (Throwable $e) {
			return;
		}

		$this->table[$key] = [$class, $method, $isContentAware];
	}

}
