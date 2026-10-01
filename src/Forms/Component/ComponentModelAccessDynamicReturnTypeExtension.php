<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Iterator;
use Nette\Application\UI\Component as UiComponent;
use Nette\ComponentModel\Container as ComponentModelContainer;
use Nette\ComponentModel\IComponent;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IterableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use ReflectionNamedType;
use function array_key_exists;
use function count;
use function ltrim;
use function sprintf;
use function ucfirst;

final class ComponentModelAccessDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{

	private ConfigurationGuard $guard;

	private bool $enabled;

	private ContainerModel $model;

	/** @var array<string, string|null> */
	private array $declaredReturnTypeNames = [];

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
		return ComponentModelContainer::class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		$this->guard->validate();

		$name = $methodReflection->getName();

		return $name === 'offsetGet'
			|| $name === 'getComponent'
			|| $name === 'getComponents'
			|| $name === 'getControls';
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope
	): ?Type
	{
		$name = $methodReflection->getName();
		if ($name === 'getControls') {
			$controls = $this->enabled ? $this->model->resolveLeafControls($methodCall->var, $scope) : null;

			return $controls === null ? null : $this->declaredIterable($methodReflection, $controls);
		}

		if ($name === 'getComponents') {
			// Only the no-argument form maps to "all immediate children"; getComponents($deep,
			// $filter) changes the set, so leave those to the native return type.
			if ($methodCall->getArgs() !== []) {
				return null;
			}

			$children = $this->enabled ? $this->model->resolveImmediateChildren($methodCall->var, $scope) : null;
			if ($children !== null) {
				return $this->declaredIterable($methodReflection, $children);
			}

			// nette/component-model 4 declares getComponents(): array, where phpstan-nette's
			// Iterator stub no longer applies and the children would type as mixed.
			return $this->declaredReturnTypeName($methodReflection) === 'array'
				? $this->declaredIterable($methodReflection, new ObjectType(IComponent::class))
				: null;
		}

		if (!$this->enabled) {
			return null;
		}

		$receiverType = $scope->getType($methodCall->var);
		if ($receiverType instanceof FormShapeType) {
			$primary = $this->model->resolveFromFormShapeReceiver($receiverType, $methodCall, $scope);
			if ($primary !== null) {
				return $primary;
			}
		}

		$resolved = $this->model->resolveFromStore($methodCall, $scope);
		if ($resolved !== null) {
			return $resolved;
		}

		return $this->createComponentFallback($methodReflection, $methodCall, $scope);
	}

	private function declaredIterable(MethodReflection $methodReflection, Type $value): ?Type
	{
		$key = TypeCombinator::union(new IntegerType(), new StringType());

		switch ($this->declaredReturnTypeName($methodReflection)) {
			case 'array':
				return new ArrayType($key, $value);
			case 'iterable':
				return new IterableType($key, $value);
			case Iterator::class:
				return new GenericObjectType(Iterator::class, [$key, $value]);
			default:
				return null;
		}
	}

	private function declaredReturnTypeName(MethodReflection $methodReflection): ?string
	{
		$class = $methodReflection->getDeclaringClass();
		$key = $class->getName() . '::' . $methodReflection->getName();
		if (!array_key_exists($key, $this->declaredReturnTypeNames)) {
			$returnType = $class->getNativeReflection()->getMethod($methodReflection->getName())->getReturnType();
			$this->declaredReturnTypeNames[$key] = $returnType instanceof ReflectionNamedType
				? ltrim($returnType->getName(), '\\')
				: null;
		}

		return $this->declaredReturnTypeNames[$key];
	}

	private function createComponentFallback(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope
	): ?Type
	{
		$name = $methodReflection->getName();
		$calledOnType = $scope->getType($methodCall->var);

		$isUiComponent = (new ObjectType(UiComponent::class))
			->isSuperTypeOf($calledOnType)->yes();

		if ($name === 'offsetGet' && !$isUiComponent) {
			return null;
		}

		$defaultType = $calledOnType->getMethod('createComponent', $scope)->getVariants()[0]->getReturnType();

		if ($name === 'offsetGet') {
			$defaultType = TypeCombinator::remove($defaultType, new NullType());
			if ($defaultType->isSuperTypeOf(new ObjectType(IComponent::class))->yes()) {
				$defaultType = new MixedType(false, new NullType());
			}
		} else {
			// Mirror array access: an absent/unknown getComponent is IComponent (not mixed),
			// so no-throw getComponent('x', false) → IComponent|null (like $form['x'] ?? null)
			// and the throwing default getComponent('x') → IComponent (like $form['x']).
			if ($defaultType->isSuperTypeOf(new ObjectType(IComponent::class))->yes()) {
				$defaultType = TypeCombinator::union(new ObjectType(IComponent::class), new NullType());
			}

			$throw = true;
			$throwArgs = $methodCall->getArgs();
			if (isset($throwArgs[1])) {
				$throwType = $scope->getType($throwArgs[1]->value);
				if (!$throwType->isTrue()->yes()) {
					$throw = false;
				}
			}

			if ($throw) {
				$defaultType = TypeCombinator::remove($defaultType, new NullType());
			}
		}

		$args = $methodCall->getArgs();
		if (count($args) < 1) {
			return $defaultType;
		}

		$argType = $scope->getType($args[0]->value);
		if (count($argType->getConstantStrings()) === 0) {
			return $defaultType;
		}

		$types = [];
		foreach ($argType->getConstantStrings() as $constantString) {
			$componentName = $constantString->getValue();
			$createName = sprintf('createComponent%s', ucfirst($componentName));
			if (!$calledOnType->hasMethod($createName)->yes()) {
				return $defaultType;
			}

			$method = $calledOnType->getMethod($createName, $scope);
			$types[] = ParametersAcceptorSelector::selectFromArgs(
				$scope,
				[new Arg(new String_($componentName))],
				$method->getVariants(),
			)->getReturnType();
		}

		return TypeCombinator::union(...$types);
	}

}
