<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Type;

use Nette\DI\Container;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function in_array;

final class FindByTypeReturnTypeExtension implements DynamicMethodReturnTypeExtension
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

		return in_array($methodReflection->getName(), ['findByType'], true);
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

		$types = [];
		foreach ($constantStrings as $constantString) {
			$types[] = $this->buildForClassName($constantString->getValue(), $profiles);
		}

		return TypeCombinator::union(...$types);
	}

	/**
	 * @param list<string> $profiles
	 */
	private function buildForClassName(string $className, array $profiles): Type
	{
		$namesPerProfile = [];

		foreach ($this->registry->getTypeLookup($className, $profiles) as $result) {
			$namesPerProfile[] = $result->getAllNames();
		}

		$first = $namesPerProfile[0] ?? [];

		foreach ($namesPerProfile as $names) {
			if ($names !== $first) {
				return TypeCombinator::intersect(
					new ArrayType(new IntegerType(), new StringType()),
					new AccessoryArrayListType(),
				);
			}
		}

		$builder = ConstantArrayTypeBuilder::createEmpty();

		foreach ($first as $name) {
			$builder->setOffsetValueType(null, new ConstantStringType($name));
		}

		return $builder->getArray();
	}

}
