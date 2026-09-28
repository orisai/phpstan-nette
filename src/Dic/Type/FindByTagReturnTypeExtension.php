<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Type;

use Nette\DI\Container;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function in_array;

final class FindByTagReturnTypeExtension implements DynamicMethodReturnTypeExtension
{

	private ConfigurationGuard $guard;

	private MultiContainerRegistry $registry;

	public function __construct(ConfigurationGuard $guard, MultiContainerRegistry $registry)
	{
		$this->guard = $guard;
		$this->registry = $registry;
	}

	public function getClass(): string
	{
		return Container::class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		$this->guard->validate();

		return in_array($methodReflection->getName(), ['findByTag'], true);
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope
	): ?Type
	{
		if (!$this->registry->isActive()) {
			return null;
		}

		$resolution = $this->registry->resolveProfiles($scope->getType($methodCall->var));
		if ($resolution === null) {
			return null;
		}

		$profiles = $resolution->getProfiles();

		$args = $methodCall->getArgs();
		if ($args === []) {
			return null;
		}

		$constantStrings = $scope->getType($args[0]->value)->getConstantStrings();
		if ($constantStrings === []) {
			return new ArrayType(new StringType(), new MixedType());
		}

		$types = [];

		foreach ($constantStrings as $constantString) {
			$tagValueType = $this->registry->getTagValueType($constantString->getValue(), $profiles);
			$types[] = $tagValueType === null
				? ConstantArrayTypeBuilder::createEmpty()->getArray()
				: new ArrayType(new StringType(), $tagValueType);
		}

		return TypeCombinator::union(...$types);
	}

}
