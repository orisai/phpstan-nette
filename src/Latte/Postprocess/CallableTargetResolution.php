<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use Latte\Runtime\FilterInfo;
use LogicException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use function is_array;
use function is_string;
use function strpos;
use function substr;

// Shared by FilterTable/FunctionTable: both resolve a callable to a static "Class::method"/
// plain-function AST reference (PHPStan then type-checks the REAL referenced code - no manual
// signature modelling needed) and both classify content-awareness (FilterInfo-typed first
// param) the same way.
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
		$parameters = $class === ''
			? (new ReflectionFunction($method))->getParameters()
			: (new ReflectionMethod($class, $method))->getParameters();

		if ($parameters === []) {
			return false;
		}

		$type = $parameters[0]->getType();

		return $type instanceof ReflectionNamedType && $type->getName() === FilterInfo::class;
	}

}
