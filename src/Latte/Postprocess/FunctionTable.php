<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use Latte\Runtime\Defaults;
use LogicException;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use Throwable;
use function ksort;
use function strtolower;

final class FunctionTable
{

	use CallableTargetResolution;

	/** @var array<string, array{string, string, bool}> */
	private array $table = [];

	public function __construct(?HarvestedCustoms $harvested = null)
	{
		$defaults = new Defaults();

		foreach ($defaults->getFunctions() as $name => $callable) {
			$this->register($name, $callable);
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

		return $base === null ? null : [$base[0], $base[1], $base[2], false, false];
	}

	// Defaults::getFunctions() (unlike getFilters()) never wraps an entry in an extension-guard
	// closure - every value is a plain "Class::method" static array, so no CLOSURE_FALLBACKS-style
	// hardcoded map is needed here.

	/**
	 * @param callable(mixed...): mixed $callable
	 */
	private function register(string $name, callable $callable): void
	{
		$key = strtolower($name);
		if (isset($this->table[$key])) {
			return;
		}

		$target = $this->resolveStaticTarget($callable);
		if ($target === null) {
			throw new LogicException("FunctionTable has no static-callable mapping for default function '$name'.");
		}

		[$class, $method] = $target;
		$this->table[$key] = [$class, $method, $this->isContentAware($class, $method)];
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
