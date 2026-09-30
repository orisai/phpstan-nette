<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte2;

use Closure;
use Latte\Engine;
use Latte\Macro;
use Latte\Runtime\FilterExecutor;
use OriPhpstan\Nette\Latte\Customs\FilterLoaderProbe;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\OriginalNameCollisionMap;
use OriPhpstan\Nette\Latte\Version\LatteEngineReader;
use ReflectionFunction;
use ReflectionProperty;
use stdClass;
use function array_keys;
use function assert;
use function end;
use function get_class;
use function get_object_vars;
use function is_callable;
use function spl_object_id;
use function strtolower;

final class Latte2EngineReader implements LatteEngineReader
{

	public function read(Engine $engine): HarvestedCustoms
	{
		[$filters, $filterOriginalNames] = $this->readFilters($engine);
		[$functions, $functionOriginalNames] = $this->readFunctions($engine);

		foreach ($engine->onCompile as $callback) {
			$callback($engine);
		}

		$macrosByName = $engine->getCompiler()->getMacros();

		return (new HarvestedCustoms(
			$filters,
			$functions,
			$this->macroSets($macrosByName),
			$this->macroClassesByName($macrosByName),
			$filterOriginalNames,
			$functionOriginalNames,
		))->withFilterLoaders($this->filterLoaders($engine));
	}

	// Engine::addFilterLoader() wraps its loader in a dynamic filter that adds the loader's answer as
	// a static filter, which FilterExecutor then calls; the runtime asks those wrappers in _dynamic
	// order and the first answer wins. A bare addFilter(null, ...) dynamic filter computes the
	// filtered value itself instead of naming a callable, so it is no loader and is skipped.
	private function filterLoaders(Engine $engine): ?FilterLoaderProbe
	{
		$property = new ReflectionProperty(Engine::class, 'filters');
		$property->setAccessible(true);

		$executor = $property->getValue($engine);
		assert($executor instanceof FilterExecutor);

		$dynamicProperty = new ReflectionProperty($executor, '_dynamic');
		$dynamicProperty->setAccessible(true);

		/** @var list<callable(mixed...): mixed> $dynamic */
		$dynamic = $dynamicProperty->getValue($executor);

		$loaders = [];
		foreach ($dynamic as $filter) {
			$loader = self::wrappedLoader($filter);
			if ($loader !== null) {
				$loaders[] = $loader;
			}
		}

		if ($loaders === []) {
			return null;
		}

		return new FilterLoaderProbe(
			static function (string $name) use ($loaders) {
				foreach ($loaders as $loader) {
					$filter = $loader($name);
					if ((bool) $filter) {
						return $filter;
					}
				}

				return null;
			},
			false,
		);
	}

	/**
	 * @param callable(mixed...): mixed $filter
	 * @return (callable(string): mixed)|null
	 */
	private static function wrappedLoader(callable $filter): ?callable
	{
		if (!$filter instanceof Closure) {
			return null;
		}

		$function = new ReflectionFunction($filter);
		$scope = $function->getClosureScopeClass();
		$loader = $function->getStaticVariables()['callback'] ?? null;

		return $scope !== null && $scope->getName() === Engine::class && is_callable($loader) ? $loader : null;
	}

	// Latte's own Compiler::expandMacro() tries $this->macros[$name] in array_reverse() order and
	// dispatches to the first one whose nodeOpened() accepts - the LAST-registered entry, matching
	// LatteCompiler::installHarvestedMacroSets()'s own "last-registered-wins" precedent.

	/**
	 * @param array<string, array<Macro>> $macrosByName
	 * @return array<string, class-string>
	 */
	private function macroClassesByName(array $macrosByName): array
	{
		$classesByName = [];
		foreach ($macrosByName as $name => $macros) {
			$winner = end($macros);
			if ($winner === false) {
				continue;
			}

			$classesByName[$name] = get_class($winner);
		}

		return $classesByName;
	}

	/**
	 * @return array{array<string, callable(mixed...): mixed>, array<string, string>}
	 */
	private function readFilters(Engine $engine): array
	{
		// Engine::getFilters() (@return string[]) returns lowercase-name => same-name pairs, not
		// callables - FilterExecutor::__get() (public, magic) is the only way to resolve a real,
		// invokable callback per name, exactly what Engine::invokeFilter() itself does at runtime.
		$names = $engine->getFilters();

		$property = new ReflectionProperty(Engine::class, 'filters');
		$property->setAccessible(true);

		$executor = $property->getValue($engine);
		assert($executor instanceof FilterExecutor);

		$filters = [];
		foreach (array_keys($names) as $name) {
			$filters[$name] = $executor->$name;
		}

		// $_origNames is [registeredSpelling => lowercase], one entry per distinct spelling ever
		// passed to FilterExecutor::add() - see OriginalNameCollisionMap's own doc for why this is
		// collision-resolved rather than a naive single flip.
		return [$filters, OriginalNameCollisionMap::build($executor->_origNames)];
	}

	/**
	 * @return array{array<string, callable(mixed...): mixed>, array<string, string>}
	 */
	private function readFunctions(Engine $engine): array
	{
		$property = new ReflectionProperty(Engine::class, 'functions');
		$property->setAccessible(true);

		$functions = $property->getValue($engine);
		assert($functions instanceof stdClass);

		/** @var array<string, callable(mixed...): mixed> $vars */
		$vars = get_object_vars($functions);

		// Engine::addFunction() keeps the exact spelling passed in as the stdClass property name
		// (no separate lowercase tracking like FilterExecutor) - the property names ARE the
		// registered spellings.
		$origToLower = [];
		foreach (array_keys($vars) as $name) {
			$origToLower[$name] = strtolower($name);
		}

		return [$vars, OriginalNameCollisionMap::build($origToLower)];
	}

	/**
	 * @param array<array<Macro>> $macrosByName
	 * @return list<object>
	 */
	private function macroSets(array $macrosByName): array
	{
		$seen = [];
		$sets = [];

		foreach ($macrosByName as $macros) {
			foreach ($macros as $macro) {
				$id = spl_object_id($macro);

				if (isset($seen[$id])) {
					continue;
				}

				$seen[$id] = true;
				$sets[] = $macro;
			}
		}

		return $sets;
	}

}
