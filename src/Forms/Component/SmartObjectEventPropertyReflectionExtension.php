<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Nette\SmartObject;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionEnum;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\CallableType;
use PHPStan\Type\IntegerType;
use function array_merge;
use function array_unique;
use function array_values;
use function in_array;
use function strlen;
use function substr;

final class SmartObjectEventPropertyReflectionExtension implements PropertiesClassReflectionExtension
{

	private ConfigurationGuard $guard;

	public function __construct(ConfigurationGuard $guard)
	{
		// PHPStan builds reflection extensions with the container, before the reflection provider knows
		// the analysed paths (and in the stub validator's container too), so validate on first use.
		$this->guard = $guard;
	}

	public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
	{
		$this->guard->validate();

		if (!$this->isEventPropertyName($propertyName)) {
			return false;
		}

		if ($classReflection->hasNativeProperty($propertyName)) {
			return false;
		}

		return $this->usesSmartObject($classReflection->getNativeReflection());
	}

	public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
	{
		return new SmartObjectEventPropertyReflection(
			$classReflection,
			new ArrayType(new IntegerType(), new CallableType()),
		);
	}

	private function isEventPropertyName(string $name): bool
	{
		if (substr($name, 0, 2) !== 'on' || strlen($name) <= 2) {
			return false;
		}

		$third = $name[2];

		return $third >= 'A' && $third <= 'Z';
	}

	/**
	 * @param ReflectionClass|ReflectionEnum $class
	 */
	private function usesSmartObject($class): bool
	{
		return in_array(SmartObject::class, $this->traitNames($class), true);
	}

	/**
	 * @param ReflectionClass|ReflectionEnum $class
	 * @return list<string>
	 */
	private function traitNames($class): array
	{
		$names = $class->getTraitNames();
		while ($class->getParentClass() !== false) {
			$class = $class->getParentClass();
			$names = array_values(array_unique(array_merge($names, $class->getTraitNames())));
		}

		return $names;
	}

}
