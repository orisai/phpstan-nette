<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Nette\Forms\Container as NetteContainer;
use Nette\Utils\ArrayHash;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use OriPhpstan\Nette\Forms\Type\FormValuesProjector;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use function count;
use function ltrim;

final class FormGetValuesDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{

	private const ARRAY = 'array';

	private ConfigurationGuard $guard;

	private bool $enabled;

	private ContainerModel $model;

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
		return NetteContainer::class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		$this->guard->validate();

		$name = $methodReflection->getName();

		// getValues() delegates to getUntrustedValues(); getUnsafeValues() is a
		// deprecated alias. All three share one data shape, so we type them equally.
		return $name === 'getValues'
		|| $name === 'getUntrustedValues'
		|| $name === 'getUnsafeValues';
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

		$returnKind = $this->returnKind($methodCall, $scope);
		$receiverType = $scope->getType($methodCall->var);
		$filled = (new ValidatedContext($this->model))->isFilledGetValues(
			$receiverType instanceof FormShapeType ? $receiverType : null,
			$methodCall,
			$scope,
		);

		if ($returnKind === self::ARRAY) {
			$shape = $this->model->resolveFormShapeFromExpression($methodCall->var, $scope);

			return $shape === null
			? new ArrayType(new StringType(), new MixedType())
			: FormValuesProjector::projectArray($shape, $filled);
		}

		if (!FormValuesProjector::isUniversalCrate($returnKind)) {
			// A custom mapped type (DTO): PHPStan types it natively from the class's
			// declared properties (reading is covered); FormValuesMappedTypeRule checks
			// the write. phpstan-nette's getValues() extension is disabled, so we must
			// supply the class type ourselves instead of letting it fall back to
			// object|array.
			return new ObjectType($returnKind);
		}

		$shape = $this->model->resolveFormShapeFromExpression($methodCall->var, $scope);

		// Only no-arg getValues() applies the form's own setMappedType(): Nette resolves
		// `$returnType ?? $this->mappedType ?? ArrayHash` there, whereas getUntrustedValues()
		// / getUnsafeValues() default $returnType to ArrayHash and never consult mappedType.
		if (
			$shape !== null
			&& $methodReflection->getName() === 'getValues'
			&& count($methodCall->getArgs()) === 0
			&& $shape->getMappedType() !== null
		) {
			return new ObjectType($shape->getMappedType());
		}

		// phpstan-nette's getValues() extension is disabled, so for forms we cannot
		// shape we replicate its plain crate result rather than losing the typing.
		return $shape === null
		? new ObjectType($returnKind)
		: FormValuesProjector::projectObject($shape, $returnKind, $filled);
	}

	/**
	 * The container requested: self::ARRAY for the array form, otherwise the class
	 * name of the object form (ArrayHash / stdClass crate, or a custom mapped DTO).
	 */
	private function returnKind(MethodCall $methodCall, Scope $scope): string
	{
		$args = $methodCall->getArgs();
		if (count($args) === 0) {
			return ArrayHash::class;
		}

		$argType = $scope->getType($args[0]->value);
		if ($argType->isTrue()->yes()) {
			return self::ARRAY;
		}

		foreach ($argType->getConstantStrings() as $constantString) {
			if ($constantString->getValue() === self::ARRAY) {
				return self::ARRAY;
			}
		}

		foreach ($argType->getClassStringObjectType()->getObjectClassNames() as $className) {
			return ltrim($className, '\\');
		}

		return ArrayHash::class;
	}

}
