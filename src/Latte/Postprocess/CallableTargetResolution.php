<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use Closure;
use Latte\Runtime\FilterInfo;
use Latte\Runtime\Template;
use LogicException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use function function_exists;
use function get_class;
use function is_array;
use function is_object;
use function is_string;
use function method_exists;
use function strpos;
use function substr;

// Shared by FilterTable/FunctionTable: both resolve a callable to a "Class::method"/plain-function
// AST reference (PHPStan then type-checks the REAL referenced code - no manual signature modelling
// needed) and both classify content-awareness (FilterInfo-typed first param) the same way.
trait CallableTargetResolution
{

	/**
	 * @param callable(mixed...): mixed $callable
	 * @return array{string, string}|null
	 */
	private function resolveStaticTarget(callable $callable): ?array
	{
		if (is_array($callable) && is_string($callable[0])) {
			return [$callable[0], $callable[1]];
		}

		if (is_string($callable)) {
			return strpos($callable, '::') !== false
				? $this->splitStaticCallable($callable)
				: ['', $callable];
		}

		return null;
	}

	// A stock entry as the installed Latte hands it out: Latte 2 lists "Class::method" arrays, Latte
	// 3.0 [$filtersInstance, 'method'] arrays and Latte 3.1 first-class callables of both static and
	// instance methods (a harvested extension also of plain functions, trim(...)). The third element
	// says whether Class::method() is a valid dispatch.

	/**
	 * @param callable(mixed...): mixed $callable
	 * @return array{string, string, bool}|null
	 */
	private function resolveDefaultTarget(callable $callable): ?array
	{
		$static = $this->resolveStaticTarget($callable);
		if ($static !== null) {
			return [$static[0], $static[1], true];
		}

		if (is_array($callable) && is_object($callable[0])) {
			return $this->methodTarget(get_class($callable[0]), $callable[1]);
		}

		if ($callable instanceof Closure) {
			$function = new ReflectionFunction($callable);
			$scope = $function->getClosureScopeClass();
			$name = $function->getName();
			if (strpos($name, '{closure') !== false) {
				return null;
			}

			if ($scope !== null && method_exists($scope->getName(), $name)) {
				return $this->methodTarget($scope->getName(), $name);
			}

			if ($scope === null && function_exists($name)) {
				return ['', $name, true];
			}
		}

		return null;
	}

	/**
	 * @return array{string, string, bool}
	 */
	private function methodTarget(string $class, string $method): array
	{
		return [$class, $method, (new ReflectionMethod($class, $method))->isStatic()];
	}

	/**
	 * @return array{string, string}
	 */
	private function splitStaticCallable(string $callable): array
	{
		$pos = strpos($callable, '::');
		if ($pos === false) {
			throw new LogicException("Expected '::' in static callable string '$callable'.");
		}

		return [(string) substr($callable, 0, $pos), (string) substr($callable, $pos + 2)];
	}

	private function isContentAware(string $class, string $method): bool
	{
		return $this->firstParameterTypeIs($class, $method, FilterInfo::class);
	}

	// Latte 3's FunctionExecutor hands the template only to a function whose first parameter's type
	// prints exactly as Latte\Runtime\Template (so not ?Template); every other one is wrapped to
	// skip it.
	private function isTemplateAware(string $class, string $method): bool
	{
		$parameters = $this->parametersOf($class, $method);
		$type = $parameters !== [] ? $parameters[0]->getType() : null;

		return $type instanceof ReflectionNamedType
			&& !$type->allowsNull()
			&& $type->getName() === Template::class;
	}

	// A harvested Latte 3 extension hands out instance-bound callables ([$this, 'm'], $this->m(...))
	// as often as static ones; Latte 2 harvests keep the static-only resolution.

	/**
	 * @param callable(mixed...): mixed $callable
	 * @return array{string, string, bool}|null
	 */
	private function resolveHarvestedTarget(callable $callable, bool $extensionHarvest): ?array
	{
		if ($extensionHarvest) {
			return $this->resolveDefaultTarget($callable);
		}

		$static = $this->resolveStaticTarget($callable);

		return $static !== null ? [$static[0], $static[1], true] : null;
	}

	/**
	 * @return list<ReflectionParameter>
	 */
	private function parametersOf(string $class, string $method): array
	{
		return $class === ''
			? (new ReflectionFunction($method))->getParameters()
			: (new ReflectionMethod($class, $method))->getParameters();
	}

	private function firstParameterTypeIs(string $class, string $method, string $typeName): bool
	{
		$parameters = $this->parametersOf($class, $method);

		if ($parameters === []) {
			return false;
		}

		$type = $parameters[0]->getType();

		return $type instanceof ReflectionNamedType && $type->getName() === $typeName;
	}

}
