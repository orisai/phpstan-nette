<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use function ltrim;
use function strncmp;
use function substr;

final class InterproceduralShapeKey
{

	public static function forClassComponent(string $fqcn, string $componentName): string
	{
		return ltrim($fqcn, '\\') . '#' . $componentName;
	}

	public static function forClassProperty(string $fqcn, string $propertyName): string
	{
		return 'prop:' . ltrim($fqcn, '\\') . '#' . $propertyName;
	}

	public static function forPlainFile(string $absolutePath, string $varName): string
	{
		return 'file:' . $absolutePath . '#' . $varName;
	}

	public static function forFactoryMethod(string $declaringClassFqcn, string $methodName): string
	{
		return 'fm:' . ltrim($declaringClassFqcn, '\\') . '::' . $methodName;
	}

	public static function forVendorMethod(string $declaringClassFqcn, string $methodName, string $contentHash): string
	{
		return 'vm:' . ltrim($declaringClassFqcn, '\\') . '::' . $methodName . '@' . $contentHash;
	}

	public static function forMethodParam(string $declaringFqcn, string $methodName, int $paramIndex): string
	{
		return 'mp:' . ltrim($declaringFqcn, '\\') . '::' . $methodName . '#' . $paramIndex;
	}

	public static function forComponentMutation(string $componentName): string
	{
		return 'mut:' . $componentName;
	}

	public static function componentMutationName(string $key): ?string
	{
		return strncmp($key, 'mut:', 4) === 0 ? substr($key, 4) : null;
	}

	public static function forConstructor(string $fqcn): string
	{
		return 'ctor:' . ltrim($fqcn, '\\');
	}

}
