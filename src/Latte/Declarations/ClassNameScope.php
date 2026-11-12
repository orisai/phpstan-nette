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
use ReflectionClass;
use ReflectionProperty;
use function array_key_exists;
use function array_merge;
use function array_unique;
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

	/** @var list<string> */
	private array $templateNames;

	/**
	 * @param array<string, string> $uses
	 * @param list<string> $templateNames
	 */
	private function __construct(
		string $namespace,
		array $uses,
		string $className,
		?string $parentClassName,
		array $templateNames
	)
	{
		$this->namespace = $namespace;
		$this->uses = $uses;
		$this->className = $className;
		$this->parentClassName = $parentClassName;
		$this->templateNames = $templateNames;
	}

	public static function forProperty(ReflectionProperty $property): self
	{
		$class = $property->getDeclaringClass();
		$source = self::sourceOf($class, $property);
		[$namespace, $uses] = self::fileScopeOf($source);
		$parent = $class->getParentClass();

		return new self(
			$namespace,
			$uses,
			$class->getName(),
			$parent !== false ? $parent->getName() : null,
			array_values(array_unique(array_merge(self::templateNamesOf($class), self::templateNamesOf($source)))),
		);
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

		if (in_array($name, $this->templateNames, true)) {
			return 'mixed';
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

	/**
	 * @param ReflectionClass<object> $class
	 * @return list<string>
	 */
	private static function templateNamesOf(ReflectionClass $class): array
	{
		$doc = $class->getDocComment();
		if ($doc === false) {
			return [];
		}

		preg_match_all('~@template\s+(\w+)~', $doc, $m);

		return $m[1];
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
