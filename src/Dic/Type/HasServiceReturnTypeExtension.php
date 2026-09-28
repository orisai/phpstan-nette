<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Type;

use Nette\DI\Container;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;
use function in_array;

final class HasServiceReturnTypeExtension implements DynamicMethodReturnTypeExtension
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

		return $methodReflection->getName() === 'hasService';
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
		if ($resolution === null || $resolution->getProfiles() === []) {
			return null;
		}

		$args = $methodCall->getArgs();
		if ($args === []) {
			return null;
		}

		$constantStrings = $scope->getType($args[0]->value)->getConstantStrings();
		if ($constantStrings === []) {
			return null;
		}

		// Asymmetric fold: presence of a compiled createService* method is monotone under
		// runtime addService()/removeService(), absence is not.
		foreach ($constantStrings as $constantString) {
			$existence = $this->registry->getServiceExistence(
				$constantString->getValue(),
				false,
				$resolution->getProfiles(),
			);

			if (in_array(false, $existence, true)) {
				return null;
			}
		}

		return new ConstantBooleanType(true);
	}

}
