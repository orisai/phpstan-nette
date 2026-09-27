<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use OriPhpstan\Nette\Forms\Shape\ComponentSlot;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use PHPStan\Type\Accessory\AccessoryType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\CompoundType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use function array_map;
use function get_class;
use function method_exists;

final class TypeCanonicalizer
{

	private function __construct()
	{
	}

	public static function canonicalizeShape(FormShape $shape): FormShape
	{
		$slots = [];
		foreach ($shape->getSlots() as $name => $slot) {
			$rebuilt = new ComponentSlot(
				$slot->getName(),
				self::canonicalize($slot->getValueType()),
				$slot->getPresence(),
				$slot->getContributingNodeIds(),
				null,
				$slot->isNullable(),
				$slot->getAcceptedSetSpec(),
				$slot->isRequired(),
				$slot->isTypeOpaque(),
				$slot->getOmission(),
			);
			$slots[$name] = $rebuilt->withControlClasses($slot->getControlClasses());
		}

		$containers = [];
		foreach ($shape->getContainers() as $name => $child) {
			$containers[$name] = self::canonicalizeShape($child);
		}

		$replicators = [];
		foreach ($shape->getReplicators() as $name => $rep) {
			$replicators[$name] = new ReplicatorShape(
				self::canonicalizeShape($rep->getInner()),
				self::canonicalizeShape($rep->getOwn()),
			);
		}

		return new FormShape(
			$shape->getClassName(),
			$slots,
			$containers,
			$replicators,
			$shape->getUnknown(),
			$shape->getOriginNodeIds(),
			$shape->getContainerPresence(),
			$shape->getReplicatorPresence(),
			$shape->getComponentTypes(),
			$shape->getComponentTypePresence(),
			$shape->getMappedType(),
		);
	}

	public static function canonicalize(Type $type): Type
	{
		if ($type instanceof UnionType) {
			$types = array_map([self::class, 'canonicalize'], $type->getTypes());

			// A benevolent union must rebuild as itself, not downgrade to a strict union (which
			// would make cached types spuriously stricter). BenevolentUnionType is internal to the
			// PHPStan runtime and absent from its analyzable API surface, so it is referenced by
			// name — via the concrete class the instance already is — rather than a use-import that
			// make phpstan cannot resolve.
			$class = get_class($type);
			if ($class === 'PHPStan\\Type\\Generic\\BenevolentUnionType') {
				return new $class($types);
			}

			return new UnionType($types);
		}

		if (get_class($type) === IntersectionType::class) {
			return new IntersectionType(array_map([self::class, 'canonicalize'], $type->getTypes()));
		}

		if (get_class($type) === ArrayType::class) {
			return new ArrayType(
				self::canonicalize($type->getKeyType()),
				self::canonicalize($type->getItemType()),
			);
		}

		if (get_class($type) === ObjectType::class) {
			return new ObjectType($type->getClassName());
		}

		// An unhandled composite (a CompoundType exposing member types we do not rebuild
		// field-by-field) cannot be canonicalized deterministically, so degrade to mixed rather
		// than crash the analysis — OPEN, don't throw.
		if ($type instanceof CompoundType && !($type instanceof AccessoryType) && method_exists($type, 'getTypes')) {
			return new MixedType();
		}

		return $type;
	}

}
