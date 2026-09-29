<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Iterator;
use Kdyby\Replicator\Container as KdybyReplicatorContainer;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;
use ReflectionNamedType;

final class ReplicatorMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{

	private ConfigurationGuard $guard;

	private bool $enabled;

	private ContainerModel $model;

	private ?bool $rowsAreArray = null;

	public function __construct(ConfigurationGuard $guard, bool $enabled, ContainerModel $model)
	{
		// PHPStan builds type extensions with the container, before the reflection provider knows the
		// analysed paths (and in the stub validator's container too), so validate on first use.
		$this->guard = $guard;
		$this->enabled = $enabled;
		$this->model = $model;
	}

	public function getClass(): string
	{
		return KdybyReplicatorContainer::class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		$this->guard->validate();

		$name = $methodReflection->getName();

		return $name === 'createOne' || $name === 'getContainers';
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope
	): ?Type
	{
		if (!$this->enabled) {
			return null;
		}

		$innerShape = $this->model->resolveReplicatorInnerShape($methodCall->var, $scope);
		if ($innerShape === null) {
			return null;
		}

		$innerClass = $innerShape->getClassName();
		if ($innerClass === null) {
			return null;
		}

		$innerType = new FormShapeType($innerClass, $innerShape);

		if ($methodReflection->getName() === 'createOne') {
			return $innerType;
		}

		if ($this->rowsAreArray($methodReflection->getDeclaringClass())) {
			return new ArrayType(new IntegerType(), $innerType);
		}

		return new GenericObjectType(Iterator::class, [new IntegerType(), $innerType]);
	}

	/**
	 * kdyby/forms-replicator 2 declares getContainers(): Iterator, 3 declares getContainers(): array.
	 */
	private function rowsAreArray(ClassReflection $declaringClass): bool
	{
		if ($this->rowsAreArray !== null) {
			return $this->rowsAreArray;
		}

		$replicator = $declaringClass->getAncestorWithClassName(KdybyReplicatorContainer::class);
		$returnType = $replicator !== null
			? $replicator->getNativeReflection()->getMethod('getContainers')->getReturnType()
			: null;

		return $this->rowsAreArray = $returnType instanceof ReflectionNamedType && $returnType->getName() === 'array';
	}

}
