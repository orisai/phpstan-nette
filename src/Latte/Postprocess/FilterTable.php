<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use Latte\Runtime\Defaults;
use Latte\Runtime\Filters;
use LogicException;
use Nette\Utils\Strings;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use Throwable;
use function ksort;
use function strtolower;

final class FilterTable
{

	use CallableTargetResolution;

	// Defaults::getFilters()/getFunctions() wrap a handful of entries in closures when an optional
	// dependency (mbstring, nette/utils) is missing; both are hard dependencies of this project so
	// those branches never execute here, but they are mapped by hand so resolution never depends on
	// which branch PHP happened to take. testResolvesEveryDefaultFilter/Function guards drift.
	private const CLOSURE_FALLBACKS = [
		'capitalize' => [Filters::class, 'capitalize'],
		'firstupper' => [Filters::class, 'firstUpper'],
		'lower' => [Filters::class, 'lower'],
		'upper' => [Filters::class, 'upper'],
		'webalize' => [Strings::class, 'webalize'],
	];

	/** @var array<string, array{string, string, bool}> */
	private array $table = [];

	public function __construct(?HarvestedCustoms $harvested = null)
	{
		$defaults = new Defaults();

		foreach ($defaults->getFilters() as $name => $callable) {
			$this->register($name, $callable);
		}

		$this->table['translate'] = [Helpers::class, 'translate', false];
		$this->table['modifydate'] = [Helpers::class, 'modifyDate', false];
		$this->table['slice'] = [Helpers::class, 'slice', false];

		foreach (($harvested ?? HarvestedCustoms::empty())->getFilters() as $name => $callable) {
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

	// The 4th element (isPerTemplate) tells FilterRewriter HOW to dispatch: a per-template entry
	// names a {templateType} class's own qualifying method - INSTANCE-dispatched via
	// Helpers::templateTypeInstance($class)->method(...) (there is no real params instance at
	// analysis time, see the helper's own docblock) UNLESS the 5th element (isStatic) says
	// otherwise, in which case it's a plain "Class::method()" StaticCall exactly like a base entry -
	// unlike every entry resolve() alone ever returns (always static/plain-function, always false
	// here). Strictly scoped: only consulted for the declaring template's own {templateType} class
	// (a deliberate divergence from the runtime's shared-engine leak).

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
			$scoped = $templateTypeCustoms->filtersFor($templateTypeClass)[$lowerName] ?? null;
			if ($scoped !== null) {
				return [$scoped[0], $scoped[1], $scoped[2], true, $scoped[3]];
			}
		}

		$base = $this->resolve($lowerName);

		return $base === null ? null : [$base[0], $base[1], $base[2], false, false];
	}

	/**
	 * @param callable(mixed...): mixed $callable
	 */
	private function register(string $name, callable $callable): void
	{
		$key = strtolower($name);
		if (isset($this->table[$key])) {
			return;
		}

		[$class, $method] = $this->resolveTarget($name, $callable);
		$this->table[$key] = [$class, $method, $this->isContentAware($class, $method)];
	}

	// Harvested callables (real, running-engine values) can take shapes resolveTarget()/
	// isContentAware() never throw for on the hardcoded Defaults/CLOSURE_FALLBACKS domain but
	// aren't representable as a static "Class::method"/plain-function AST reference at all
	// (an instance-bound [$object, 'method'] array, a Closure, an invokable object) - those
	// degrade to "stays unknown" here rather than crashing or guessing; a built-in of the same
	// lowercase name always wins (register() above runs first).

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

	/**
	 * @param callable(mixed...): mixed $callable
	 * @return array{string, string}
	 */
	private function resolveTarget(string $name, callable $callable): array
	{
		$target = $this->resolveStaticTarget($callable);
		if ($target !== null) {
			return $target;
		}

		$fallback = self::CLOSURE_FALLBACKS[strtolower($name)] ?? null;
		if ($fallback === null) {
			throw new LogicException("FilterTable has no hardcoded mapping for closure-backed filter '$name'.");
		}

		return $fallback;
	}

}
