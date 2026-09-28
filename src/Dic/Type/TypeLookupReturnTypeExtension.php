<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Type;

use Nette\DI\Container;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function count;
use function in_array;

final class TypeLookupReturnTypeExtension implements DynamicMethodReturnTypeExtension
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

		return in_array($methodReflection->getName(), ['getByType', 'createInstance'], true);
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope
	): ?Type
	{
		$args = $methodCall->getArgs();
		if ($args === []) {
			return null;
		}

		$isGetByType = $methodReflection->getName() === 'getByType';
		$argType = $scope->getType($args[0]->value);
		$constantStrings = $argType->getConstantStrings();

		if ($constantStrings === []) {
			if (!$isGetByType) {
				return null;
			}

			return $this->addNullUnlessThrows($argType->getClassStringObjectType(), $args, $scope, false);
		}

		$types = [];
		foreach ($constantStrings as $constantString) {
			$className = $constantString->getValue();
			$type = new ObjectType($className);
			if ($isGetByType) {
				$type = $this->addNullUnlessThrows(
					$type,
					$args,
					$scope,
					$this->isUniquelyAutowiredEverywhere($className, $methodCall, $scope),
				);
			}

			$types[] = $type;
		}

		return TypeCombinator::union(...$types);
	}

	/**
	 * @param array<Arg> $args
	 */
	private function addNullUnlessThrows(Type $type, array $args, Scope $scope, bool $alwaysResolvable): Type
	{
		if ($alwaysResolvable) {
			return $type;
		}

		if (count($args) >= 2 && !$scope->getType($args[1]->value)->isTrue()->yes()) {
			return TypeCombinator::addNull($type);
		}

		return $type;
	}

	private function isUniquelyAutowiredEverywhere(string $className, MethodCall $methodCall, Scope $scope): bool
	{
		if (!$this->registry->isActive()) {
			return false;
		}

		$resolution = $this->registry->resolveProfiles($scope->getType($methodCall->var));

		if ($resolution === null || $resolution->getProfiles() === []) {
			return false;
		}

		foreach ($this->registry->getTypeLookup($className, $resolution->getProfiles()) as $result) {
			if (!$result->isKnown() || count($result->getAutowiredNames()) !== 1) {
				return false;
			}
		}

		return true;
	}

}
