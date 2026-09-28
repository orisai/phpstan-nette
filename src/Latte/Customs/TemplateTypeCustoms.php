<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use Latte\Attributes\TemplateFilter;
use Latte\Attributes\TemplateFunction;
use OriPhpstan\Nette\Latte\Postprocess\CallableTargetResolution;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use function class_exists;
use function strpos;
use function strtolower;

// Models Latte\Engine::processParams() (vendor Engine.php:531-555) for a {templateType C}
// declaration: C's own qualifying public methods become per-template filter/function entries.
// Qualification mirrors the vendor condition exactly - `getMethods(ReflectionMethod::IS_PUBLIC)`
// filters on the public bit only (a public STATIC method qualifies too, and inherited public
// methods count via native reflection's own hierarchy walk, both matching vendor's bare
// `(new \ReflectionClass($params))->getMethods(\ReflectionMethod::IS_PUBLIC)`), and a method
// counts if its docblock contains '@filter'/'@function' (any PHP version) OR - only when
// PHPStan's configured PhpVersion is 8.0+ - it carries a #[TemplateFilter]/#[TemplateFunction]
// attribute. The attribute check cannot use native ReflectionMethod::getAttributes() (PHP 8-only
// API, absent on this project's own PHP 7.4 analysis runtime regardless of the configured
// PhpVersion): it re-parses the declaring method's own source via the injected Parser instead,
// the same AST-attribute-reading approach PresenterForbidInjectRule/ComponentDirectInjectionRule
// already use for #[Inject].
// Latte 3 forward note (v3.1.4-verified): processParams() is REMOVED entirely, not tightened -
// Latte 3's Engine.php has no docblock-tag or attribute scanning left at all, replaced by
// Extension::getFilters()/getFunctions(). A future Latte 3 migration must retire this class
// outright, not adjust its matching rules.
final class TemplateTypeCustoms
{

	use CallableTargetResolution;

	private const FILTER_ATTRIBUTE_CLASS = TemplateFilter::class;

	private const FUNCTION_ATTRIBUTE_CLASS = TemplateFunction::class;

	private PhpVersion $phpVersion;

	private Parser $phpParser;

	/** @var array<string, array<string, ClassMethod>> */
	private array $methodNodesByFile = [];

	/** @var array<string, array{filters: array<string, array{string, string, bool, bool}>, functions: array<string, array{string, string, bool, bool}>}> */
	private array $cache = [];

	public function __construct(PhpVersion $phpVersion, Parser $phpParser)
	{
		$this->phpVersion = $phpVersion;
		$this->phpParser = $phpParser;
	}

	// The 4th tuple element (isStatic) tells FilterRewriter HOW to dispatch a per-template hit:
	// getMethods(IS_PUBLIC) qualifies a public STATIC method too (see this class's own top-level
	// doc), and calling it through Helpers::templateTypeInstance($class)->method() - the shape every
	// OTHER (instance) per-template entry needs, since there is no real params instance at analysis
	// time - trips PHPStan's own staticMethod.dynamicCall at strict level 8 for a genuinely static
	// method. A static entry must dispatch as a plain "Class::method()" StaticCall instead, exactly
	// like a base-table entry.

	/**
	 * @return array<string, array{string, string, bool, bool}>
	 */
	public function filtersFor(?string $templateTypeClass): array
	{
		return $templateTypeClass === null ? [] : $this->resolve($templateTypeClass)['filters'];
	}

	/**
	 * @return array<string, array{string, string, bool, bool}>
	 */
	public function functionsFor(?string $templateTypeClass): array
	{
		return $templateTypeClass === null ? [] : $this->resolve($templateTypeClass)['functions'];
	}

	/**
	 * @return array{filters: array<string, array{string, string, bool, bool}>, functions: array<string, array{string, string, bool, bool}>}
	 */
	private function resolve(string $templateTypeClass): array
	{
		if (isset($this->cache[$templateTypeClass])) {
			return $this->cache[$templateTypeClass];
		}

		$empty = ['filters' => [], 'functions' => []];

		if (!class_exists($templateTypeClass)) {
			return $this->cache[$templateTypeClass] = $empty;
		}

		// class_exists() above already guarantees $templateTypeClass is a real, loaded class -
		// ReflectionClass::__construct() cannot throw for it.
		$reflection = new ReflectionClass($templateTypeClass);

		$filters = [];
		$functions = [];

		foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			$doc = (string) $method->getDocComment();
			$isFilterDoc = strpos($doc, '@filter') !== false;
			$isFunctionDoc = strpos($doc, '@function') !== false;

			$isFilterAttr = false;
			$isFunctionAttr = false;
			if ($this->phpVersion->getVersionId() >= 80000) {
				[$isFilterAttr, $isFunctionAttr] = $this->attributeFlags($method);
			}

			if (!$isFilterDoc && !$isFilterAttr && !$isFunctionDoc && !$isFunctionAttr) {
				continue;
			}

			$name = $method->getName();
			$declaringClass = $method->getDeclaringClass()->getName();
			$isContentAware = $this->isContentAware($declaringClass, $name);
			$entry = [$declaringClass, $name, $isContentAware, $method->isStatic()];
			$key = strtolower($name);

			if ($isFilterDoc || $isFilterAttr) {
				$filters[$key] = $entry;
			}

			if ($isFunctionDoc || $isFunctionAttr) {
				$functions[$key] = $entry;
			}
		}

		return $this->cache[$templateTypeClass] = ['filters' => $filters, 'functions' => $functions];
	}

	/**
	 * @return array{bool, bool}
	 */
	private function attributeFlags(ReflectionMethod $method): array
	{
		$node = $this->findMethodNode($method);
		if ($node === null) {
			return [false, false];
		}

		$isFilter = false;
		$isFunction = false;

		foreach ($node->attrGroups as $attrGroup) {
			foreach ($attrGroup->attrs as $attr) {
				$attrName = $attr->name->toString();

				if ($attrName === self::FILTER_ATTRIBUTE_CLASS) {
					$isFilter = true;
				}

				if ($attrName === self::FUNCTION_ATTRIBUTE_CLASS) {
					$isFunction = true;
				}
			}
		}

		return [$isFilter, $isFunction];
	}

	private function findMethodNode(ReflectionMethod $method): ?ClassMethod
	{
		$file = $method->getDeclaringClass()->getFileName();
		if ($file === false) {
			return null;
		}

		if (!isset($this->methodNodesByFile[$file])) {
			$this->methodNodesByFile[$file] = $this->parseMethodNodes($file, $method->getDeclaringClass()->getName());
		}

		return $this->methodNodesByFile[$file][$method->getName()] ?? null;
	}

	/**
	 * @return array<string, ClassMethod>
	 */
	private function parseMethodNodes(string $file, string $className): array
	{
		try {
			$stmts = $this->phpParser->parseFile($file);
		} catch (Throwable $e) {
			return [];
		}

		$class = $this->findClassNode($stmts, $className);
		if ($class === null) {
			return [];
		}

		$methods = [];
		foreach ($class->stmts as $stmt) {
			if ($stmt instanceof ClassMethod) {
				$methods[$stmt->name->toString()] = $stmt;
			}
		}

		return $methods;
	}

	/**
	 * @param array<Stmt> $stmts
	 */
	private function findClassNode(array $stmts, string $className): ?Class_
	{
		foreach ($stmts as $stmt) {
			if (
				$stmt instanceof Class_
				&& $stmt->namespacedName !== null
				&& $stmt->namespacedName->toString() === $className
			) {
				return $stmt;
			}

			if ($stmt instanceof Namespace_) {
				$found = $this->findClassNode($stmt->stmts, $className);
				if ($found !== null) {
					return $found;
				}
			}
		}

		return null;
	}

}
