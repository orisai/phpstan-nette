<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Declarations;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use function in_array;
use function preg_match;
use function preg_match_all;

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
		foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			$types[$property->getName()] = self::resolve($property);
		}

		return $types;
	}

	public static function resolve(ReflectionProperty $property): string
	{
		$doc = $property->getDocComment();
		if ($doc !== false && preg_match('~@var\s+(.+?)(?:\s+\$\w+.*)?\s*(?:\*/)?\s*$~m', $doc, $m) === 1) {
			$type = $m[1];

			return in_array($type, self::classTemplateParamNames($property), true) ? 'mixed' : $type;
		}

		$type = $property->getType();
		if ($type instanceof ReflectionNamedType) {
			$name = $type->getName();

			return $type->allowsNull() && $name !== 'null' ? $name . '|null' : $name;
		}

		return 'mixed';
	}

	// @extends binding a templateType class's @template parameter (e.g. UITemplate<ChatWindowControl>
	// binding C) to a concrete class is phase-2 depth this pipeline doesn't have; until then, a
	// property typed as the bare template parameter name (e.g. `@var C`) must not leak that letter out
	// as if it were a real class name.

	/**
	 * @return list<string>
	 */
	private static function classTemplateParamNames(ReflectionProperty $property): array
	{
		$doc = $property->getDeclaringClass()->getDocComment();
		if ($doc === false) {
			return [];
		}

		preg_match_all('~@template\s+(\w+)~', $doc, $m);

		return $m[1];
	}

}
