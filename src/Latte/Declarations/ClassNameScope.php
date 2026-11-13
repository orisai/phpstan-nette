<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Declarations;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionClass;
use ReflectionProperty;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function class_exists;
use function explode;
use function file_get_contents;
use function in_array;
use function interface_exists;
use function is_file;
use function ltrim;
use function preg_match_all;
use function strpos;
use function strtolower;
use const PREG_SET_ORDER;

/**
 * @phpstan-type FileScope array{string, array<string, string>}
 */
final class ClassNameScope
{

	private const KEYWORDS = [
		'int',
		'integer',
		'string',
		'bool',
		'boolean',
		'true',
		'false',
		'null',
		'float',
		'double',
		'array',
		'iterable',
		'callable',
		'resource',
		'mixed',
		'void',
		'object',
		'never',
		'noreturn',
		'list',
		'new',
		'__stringandstringable',
		'__stringnotstringable',
	];

	private const PSEUDO_TYPES = ['scalar', 'number', 'empty'];

	/** @var array<string, array<string, FileScope>> */
	private static array $fileScopes = [];

	private string $namespace;

	/** @var array<string, string> */
	private array $uses;

	private string $className;

	private ?string $parentClassName;

	/** @var array<string, string> */
	private array $templateTypes;

	/**
	 * @param array<string, string> $uses
	 * @param array<string, string> $templateTypes
	 */
	private function __construct(
		string $namespace,
		array $uses,
		string $className,
		?string $parentClassName,
		array $templateTypes
	)
	{
		$this->namespace = $namespace;
		$this->uses = $uses;
		$this->className = $className;
		$this->parentClassName = $parentClassName;
		$this->templateTypes = $templateTypes;
	}

	/**
	 * @param ReflectionClass<object>|null $leaf
	 */
	public static function forProperty(ReflectionProperty $property, ?ReflectionClass $leaf = null): self
	{
		$class = $property->getDeclaringClass();
		$source = self::sourceOf($class, $property);
		$templateTypes = self::templateTypesOf($source) + self::templateTypesOf($class);
		if ($leaf !== null) {
			$templateTypes = self::boundTemplateTypes($leaf, $class) + $templateTypes;
		}

		return self::forClass($source, $templateTypes, $class);
	}

	/**
	 * @param ReflectionClass<object> $source
	 * @param array<string, string> $templateTypes
	 * @param ReflectionClass<object>|null $class
	 */
	private static function forClass(
		ReflectionClass $source,
		array $templateTypes,
		?ReflectionClass $class = null
	): self
	{
		$class ??= $source;
		[$namespace, $uses] = self::fileScopeOf($source);
		$parent = $class->getParentClass();

		return new self(
			$namespace,
			$uses,
			$class->getName(),
			$parent !== false ? $parent->getName() : null,
			$templateTypes,
		);
	}

	// What the `@extends` chain from $leaf binds $declaring's template parameters to, qualified; a parameter no
	// `@extends` binds is left out.

	/**
	 * @param ReflectionClass<object> $leaf
	 * @param ReflectionClass<object> $declaring
	 * @return array<string, string>
	 */
	private static function boundTemplateTypes(ReflectionClass $leaf, ReflectionClass $declaring): array
	{
		$bindings = [];
		$class = $leaf;
		while (strtolower($class->getName()) !== strtolower($declaring->getName())) {
			$parent = $class->getParentClass();
			if ($parent === false) {
				return [];
			}

			$scope = self::forClass($class, $bindings + self::templateTypesOf($class));
			$arguments = [];
			foreach (self::extendsArguments($class) as $extended => $typeArguments) {
				if (strtolower(ltrim($scope->resolveClassName($extended), '\\')) === strtolower($parent->getName())) {
					$arguments = $typeArguments;

					break;
				}
			}

			$parentBindings = [];
			foreach (array_keys(self::templateTypesOf($parent)) as $i => $name) {
				if (isset($arguments[$i])) {
					$parentBindings[$name] = PhpDocTypeQualifier::qualify($arguments[$i], $scope);
				}
			}

			$bindings = $parentBindings;
			$class = $parent;
		}

		return $bindings;
	}

	/**
	 * @param ReflectionClass<object> $class
	 * @return array<string, list<string>>
	 */
	private static function extendsArguments(ReflectionClass $class): array
	{
		$doc = $class->getDocComment();
		if ($doc === false) {
			return [];
		}

		preg_match_all('~@(?:phpstan-|psalm-)?(?:extends|template-extends)\s+(.+?)\s*(?:\*/)?$~m', $doc, $m);
		$extends = [];
		foreach ($m[1] as $type) {
			$node = PhpDocTypeQualifier::parse($type);
			if (!$node instanceof GenericTypeNode) {
				continue;
			}

			$extends[(string) $node->type] = array_values(array_map(
				static fn (TypeNode $argument): string => (string) $argument,
				$node->genericTypes,
			));
		}

		return $extends;
	}

	public function resolveTypeName(string $name): string
	{
		$lower = strtolower($name);
		if (in_array($lower, self::KEYWORDS, true) || strpos($name, '-') !== false) {
			return $name;
		}

		if (in_array($lower, self::PSEUDO_TYPES, true) && !$this->isPseudoTypeClass($name)) {
			return $name;
		}

		if (array_key_exists($name, $this->templateTypes)) {
			return $this->templateTypes[$name];
		}

		return $this->resolveClassName($name);
	}

	public function resolveClassName(string $name): string
	{
		if (strpos($name, '\\') === 0) {
			return $name;
		}

		$lower = strtolower($name);
		if ($lower === 'self' || $lower === 'static') {
			return '\\' . $this->className;
		}

		if ($lower === 'parent') {
			return $this->parentClassName !== null ? '\\' . $this->parentClassName : $name;
		}

		$parts = explode('\\', $name, 2);
		$alias = strtolower($parts[0]);
		if (array_key_exists($alias, $this->uses)) {
			return '\\' . $this->uses[$alias] . (isset($parts[1]) ? '\\' . $parts[1] : '');
		}

		return '\\' . ($this->namespace === '' ? $name : $this->namespace . '\\' . $name);
	}

	private function isPseudoTypeClass(string $name): bool
	{
		if (array_key_exists(strtolower($name), $this->uses)) {
			return true;
		}

		if ($this->namespace === '') {
			return false;
		}

		$class = $this->namespace . '\\' . $name;

		return class_exists($class) || interface_exists($class);
	}

	/**
	 * @param ReflectionClass<object> $class
	 * @return ReflectionClass<object>
	 */
	private static function sourceOf(ReflectionClass $class, ReflectionProperty $property): ReflectionClass
	{
		foreach ($class->getTraits() as $trait) {
			if (
				$trait->hasProperty($property->getName())
				&& $trait->getProperty($property->getName())->getDocComment() === $property->getDocComment()
			) {
				return self::sourceOf($trait, $property);
			}
		}

		return $class;
	}

	// The class's template parameters in declaration order, each standing for its bound: a plain class bound
	// qualified in the class's own scope, `mixed` otherwise.

	/**
	 * @param ReflectionClass<object> $class
	 * @return array<string, string>
	 */
	private static function templateTypesOf(ReflectionClass $class): array
	{
		$doc = $class->getDocComment();
		if ($doc === false) {
			return [];
		}

		preg_match_all(
			'~@(?:phpstan-|psalm-)?template(?:-covariant|-contravariant)?\s+(\w+)(?:\s+(?:of|as)\s+([\\\\\w]+)(?=\s|$))?~m',
			$doc,
			$m,
			PREG_SET_ORDER,
		);
		$types = [];
		foreach ($m as $match) {
			$types[$match[1]] = 'mixed';
		}

		[$namespace, $uses] = self::fileScopeOf($class);
		$scope = new self($namespace, $uses, $class->getName(), null, $types);
		foreach ($m as $match) {
			$bound = $match[2] ?? '';
			if ($bound !== '' && !in_array(strtolower($bound), self::KEYWORDS, true)) {
				$types[$match[1]] = $scope->resolveClassName($bound);
			}
		}

		return $types;
	}

	/**
	 * @param ReflectionClass<object> $class
	 * @return FileScope
	 */
	private static function fileScopeOf(ReflectionClass $class): array
	{
		$fallback = [$class->getNamespaceName(), []];
		$file = $class->getFileName();
		if ($file === false || !is_file($file)) {
			return $fallback;
		}

		self::$fileScopes[$file] ??= self::readFileScopes($file);

		return self::$fileScopes[$file][strtolower($class->getName())] ?? $fallback;
	}

	/**
	 * @return array<string, FileScope>
	 */
	private static function readFileScopes(string $file): array
	{
		$code = file_get_contents($file);
		if ($code === false) {
			return [];
		}

		try {
			$stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
		} catch (Error $e) {
			return [];
		}

		$scopes = [];
		self::collect($stmts ?? [], '', $scopes);

		return $scopes;
	}

	/**
	 * @param array<Node\Stmt> $stmts
	 * @param array<string, FileScope> $scopes
	 */
	private static function collect(array $stmts, string $namespace, array &$scopes): void
	{
		$uses = [];
		foreach ($stmts as $stmt) {
			if ($stmt instanceof Namespace_) {
				self::collect($stmt->stmts, $stmt->name !== null ? $stmt->name->toString() : '', $scopes);

				continue;
			}

			if ($stmt instanceof Use_) {
				if ($stmt->type === Use_::TYPE_NORMAL) {
					foreach ($stmt->uses as $use) {
						$uses[strtolower($use->getAlias()->toString())] = $use->name->toString();
					}
				}

				continue;
			}

			if ($stmt instanceof GroupUse) {
				foreach ($stmt->uses as $use) {
					$type = $use->type === Use_::TYPE_UNKNOWN ? $stmt->type : $use->type;
					if ($type === Use_::TYPE_NORMAL) {
						$alias = strtolower($use->getAlias()->toString());
						$uses[$alias] = $stmt->prefix->toString() . '\\' . $use->name->toString();
					}
				}

				continue;
			}

			foreach ((new NodeFinder())->findInstanceOf($stmt, ClassLike::class) as $class) {
				if ($class->name !== null) {
					$name = ltrim($namespace . '\\' . $class->name->toString(), '\\');
					$scopes[strtolower($name)] = [$namespace, $uses];
				}
			}
		}
	}

}
