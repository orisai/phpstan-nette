<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Type;

use Nette\DI\Container;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function in_array;

final class ServiceReturnTypeExtension implements DynamicMethodReturnTypeExtension
{

	private const RecursiveAliases = [
		'getService' => true,
		'getByName' => true,
		'createService' => false,
	];

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

		return in_array($methodReflection->getName(), ['getService', 'getByName', 'createService'], true);
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
			return null;
		}

		$recursiveAliases = self::RecursiveAliases[$methodReflection->getName()];

		$types = [];
		foreach ($constantStrings as $constantString) {
			$serviceType = $this->registry->getServiceType($constantString->getValue(), $recursiveAliases, $profiles);
			if ($serviceType === null) {
				return null;
			}

			$types[] = $serviceType;
		}

		return TypeCombinator::union(...$types);
	}

}
