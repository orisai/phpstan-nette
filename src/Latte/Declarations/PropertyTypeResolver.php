<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Declarations;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use function preg_match;

final class PropertyTypeResolver
{

	private function __construct()
	{
	}

	// Shared by every consumer that needs a {templateType} class's whole declared-parameter
	// surface (DeclaredVarsResolver's own declared-vars map, DeclarationConsistencyChecker's
	// native side) - one reflection walk, so the two can never independently drift on which
	// properties count or how each one's type resolves.

	/**
	 * @param class-string $class
	 * @return array<string, string>
	 * @throws ReflectionException
	 */
	public static function resolveAllPublic(string $class): array
	{
		$types = [];
		$reflection = new ReflectionClass($class);
		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			$types[$property->getName()] = self::resolve($property, $reflection);
		}

		return $types;
	}

	// $leaf is the class the property is read on: its `@extends` chain binds the declaring class's template parameters.

	/**
	 * @param ReflectionClass<object>|null $leaf
	 */
	public static function resolve(ReflectionProperty $property, ?ReflectionClass $leaf = null): string
	{
		$doc = $property->getDocComment();
		if ($doc !== false && preg_match('~@var\s+(.+?)(?:\s+\$\w+.*)?\s*(?:\*/)?\s*$~m', $doc, $m) === 1) {
			return PhpDocTypeQualifier::qualify($m[1], ClassNameScope::forProperty($property, $leaf));
		}

		$type = $property->getType();
		if ($type instanceof ReflectionNamedType) {
			$name = $type->getName();

			return $type->allowsNull() && $name !== 'null' ? $name . '|null' : $name;
		}

		return 'mixed';
	}

}
