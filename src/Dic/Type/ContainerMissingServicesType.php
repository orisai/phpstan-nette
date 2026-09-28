<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Type;

use Nette\DI\Container;
use PHPStan\Type\CompoundType;
use PHPStan\Type\IsSuperTypeOfResult;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use function array_diff;
use function array_unique;
use function array_values;
use function implode;
use function sort;

final class ContainerMissingServicesType extends ObjectType
{

	/** @var list<string> */
	private array $missingMethodNames;

	/**
	 * @param list<string> $missingMethodNames
	 */
	public function __construct(array $missingMethodNames)
	{
		parent::__construct(Container::class);
		$names = array_values(array_unique($missingMethodNames));
		sort($names);
		$this->missingMethodNames = $names;
	}

	/**
	 * @return list<string>
	 */
	public function getMissingMethodNames(): array
	{
		return $this->missingMethodNames;
	}

	public function describe(VerbosityLevel $level): string
	{
		return parent::describe($level) . '~service:' . implode(',', $this->missingMethodNames);
	}

	public function equals(Type $type): bool
	{
		return $type instanceof self
			&& $this->missingMethodNames === $type->missingMethodNames
			&& parent::equals($type);
	}

	public function isSuperTypeOf(Type $type): IsSuperTypeOfResult
	{
		if ($type instanceof self && array_diff($this->missingMethodNames, $type->missingMethodNames) === []) {
			return parent::isSuperTypeOf($type);
		}

		if ($type instanceof CompoundType) {
			return $type->isSubTypeOf($this);
		}

		return parent::isSuperTypeOf($type)->and(IsSuperTypeOfResult::createMaybe());
	}

}
