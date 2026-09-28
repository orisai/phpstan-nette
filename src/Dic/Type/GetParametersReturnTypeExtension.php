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
use function in_array;

final class GetParametersReturnTypeExtension implements DynamicMethodReturnTypeExtension
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

		return in_array($methodReflection->getName(), ['getParameters'], true);
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

		return $this->registry->getMergedParametersType($resolution->getProfiles());
	}

}
