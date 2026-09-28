<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Metadata;

use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function array_keys;
use function count;
use function get_class;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function range;

final class ParameterTypeWidener
{

	/**
	 * @param mixed $value
	 */
	public function widen($value): Type
	{
		if (is_string($value)) {
			return new StringType();
		}

		if (is_int($value)) {
			return new IntegerType();
		}

		if (is_float($value)) {
			return new FloatType();
		}

		if (is_bool($value)) {
			return new BooleanType();
		}

		if ($value === null) {
			return new NullType();
		}

		if (is_array($value)) {
			return $this->widenArray($value);
		}

		if (is_object($value)) {
			return new ObjectType(get_class($value));
		}

		return new MixedType();
	}

	/**
	 * @param array<mixed> $value
	 */
	private function widenArray(array $value): Type
	{
		if ($value === []) {
			return new ArrayType(new MixedType(), new MixedType());
		}

		if ($this->isList($value)) {
			$members = [];

			foreach ($value as $member) {
				$members[] = $this->widen($member);
			}

			return TypeCombinator::intersect(
				new ArrayType(new IntegerType(), TypeCombinator::union(...$members)),
				new AccessoryArrayListType(),
			);
		}

		$builder = ConstantArrayTypeBuilder::createEmpty();

		foreach ($value as $key => $member) {
			$builder->setOffsetValueType(
				is_int($key) ? new ConstantIntegerType($key) : new ConstantStringType($key),
				$this->widen($member),
			);
		}

		return $builder->getArray();
	}

	/**
	 * @param array<mixed> $value
	 */
	private function isList(array $value): bool
	{
		return $value === [] || array_keys($value) === range(0, count($value) - 1);
	}

}
