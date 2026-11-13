<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Reflection\ClassConstantReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use function strtolower;

final class ExpressionDependencies
{

	private ReflectionProvider $reflectionProvider;

	private DependencyRecorder $recorder;

	public function __construct(ReflectionProvider $reflectionProvider, DependencyRecorder $recorder)
	{
		$this->reflectionProvider = $reflectionProvider;
		$this->recorder = $recorder;
	}

	/**
	 * @param Node|array<Node> $nodes
	 */
	public function record($nodes, ?ClassReflection $lexicalClass): void
	{
		if (!$this->recorder->hasActiveFrame()) {
			return;
		}

		$visited = [];
		$this->walk($nodes, $lexicalClass, $visited);
	}

	public function recordConstant(ClassConstantReflection $constant): void
	{
		if (!$this->recorder->hasActiveFrame()) {
			return;
		}

		$visited = [];
		$this->constant($constant, $visited);
	}

	public function recordClass(string $className): ?ClassReflection
	{
		if (!$this->reflectionProvider->hasClass($className)) {
			$this->recorder->recordAbsentClass($className);

			return null;
		}

		$class = $this->reflectionProvider->getClass($className);
		$this->recordHierarchy($class);

		return $class;
	}

	public function recordHierarchy(ClassReflection $class): void
	{
		$lineage = [$class];
		foreach ($class->getParents() as $parent) {
			$lineage[] = $parent;
		}

		foreach ($class->getInterfaces() as $interface) {
			$lineage[] = $interface;
		}

		foreach ($lineage as $reflection) {
			$this->recordFile($reflection);
			foreach ($reflection->getTraits(true) as $trait) {
				$this->recordFile($trait);
			}
		}
	}

	/**
	 * @param Node|array<Node> $nodes
	 * @param array<string, true> $visited
	 */
	private function walk($nodes, ?ClassReflection $lexicalClass, array &$visited): void
	{
		$found = (new NodeFinder())->find(
			$nodes,
			static fn (Node $node): bool => $node instanceof ClassConstFetch
				|| $node instanceof StaticCall
				|| $node instanceof StaticPropertyFetch
				|| $node instanceof New_
				|| $node instanceof Instanceof_
				|| $node instanceof FuncCall,
		);

		foreach ($found as $node) {
			if ($node instanceof FuncCall) {
				if ($node->name instanceof Name) {
					$this->function($node->name);
				}

				continue;
			}

			/** @var ClassConstFetch|StaticCall|StaticPropertyFetch|New_|Instanceof_ $node */
			if (!$node->class instanceof Name) {
				continue;
			}

			$class = $this->resolveClass($node->class, $lexicalClass);
			if ($class === null || !$node instanceof ClassConstFetch || !$node->name instanceof Identifier) {
				continue;
			}

			$name = $node->name->toString();
			if (strtolower($name) !== 'class' && $class->hasConstant($name)) {
				$this->constant($class->getConstant($name), $visited);
			}
		}
	}

	private function resolveClass(Name $name, ?ClassReflection $lexicalClass): ?ClassReflection
	{
		$lowered = $name->toLowerString();
		if ($lowered === 'self' || $lowered === 'static' || $lowered === 'parent') {
			if ($lexicalClass === null) {
				return null;
			}

			$this->recordHierarchy($lexicalClass);

			return $lowered === 'parent' ? $lexicalClass->getParentClass() : $lexicalClass;
		}

		return $this->recordClass($name->toString());
	}

	/**
	 * @param array<string, true> $visited
	 */
	private function constant(ClassConstantReflection $constant, array &$visited): void
	{
		$declaringClass = $constant->getDeclaringClass();
		$key = strtolower($declaringClass->getName()) . '::' . $constant->getName();
		if (isset($visited[$key])) {
			return;
		}

		$visited[$key] = true;
		$this->recordFile($declaringClass);

		$this->walk($constant->getValueExpr(), $declaringClass, $visited);
	}

	private function function(Name $name): void
	{
		if (!$this->reflectionProvider->hasFunction($name, null)) {
			return;
		}

		$file = $this->reflectionProvider->getFunction($name, null)->getFileName();
		if ($file !== null) {
			$this->recorder->record($file);
		}
	}

	private function recordFile(ClassReflection $class): void
	{
		$file = $class->getFileName();
		if ($file !== null) {
			$this->recorder->record($file);
		}
	}

}
