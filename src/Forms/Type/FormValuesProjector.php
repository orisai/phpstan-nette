<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Type;

use Nette\Utils\ArrayHash;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use stdClass;

/**
 * The single projection of a FormShape into the PHPStan type of its values:
 * getValues() (object crate or array), the on* callback $values param, and a single
 * field (member). $filled narrows required fields to their post-validation type
 * inside a validated context (onSuccess / after isValid()).
 */
final class FormValuesProjector
{

	/**
	 * Whether a class named as getValues()'s $returnType is a universal object CRATE - a bag Nette
	 * fills with whatever fields the form has - rather than a mapped DTO, which is the whole of the
	 * decision between projecting a shape into it and handing back the bare class.
	 *
	 * A DTO declares its own properties and PHPStan types them natively off the class, so wrapping it
	 * in a projected shape would claim fields the class does not have and FormValuesMappedTypeRule
	 * already checks the write side. A crate declares none: ArrayHash is an ArrayAccess/IteratorAggregate
	 * over dynamic properties and stdClass has no members at all, so the bare class answers `mixed` for
	 * every field read and the projection is the only thing that can carry a type at all.
	 *
	 * ONE definition, because both getValues() channels ask it of the same call:
	 * FormGetValuesDynamicReturnTypeExtension for a form the walk resolved, and
	 * FormAccessExpressionTypeResolver for a tracked local inside a builder file. The second one did not
	 * have it - it answered every class-string argument with the bare class - so
	 * `$form->getValues(stdClass::class)` lost its fields inside the file that BUILDS the form while
	 * `$this['form']->getValues(stdClass::class)` one method away kept them.
	 */
	public static function isUniversalCrate(string $className): bool
	{
		return $className === ArrayHash::class || $className === stdClass::class;
	}

	/**
	 * Object view of getValues(): a universal object crate ($objectClass, an
	 * ArrayHash or stdClass) carrying typed fields, precise for both property and
	 * offset access.
	 */
	public static function projectObject(
		FormShape $shape,
		string $objectClass = ArrayHash::class,
		bool $filled = false
	): Type
	{
		$properties = [];
		foreach ($shape->getSlots() as $name => $slot) {
			if ($slot->isOmitted()) {
				continue;
			}

			$value = $filled ? $slot->getFilledValueType() : $slot->getValueType();
			$properties[$name] = self::maybeNullable($value, $slot->valueMaybeAbsent());
		}

		foreach ($shape->getContainers() as $name => $child) {
			$properties[$name] = self::maybeNullable(
				self::projectObject($child, ArrayHash::class, $filled),
				($shape->getContainerPresence()[$name] ?? Certainty::HAPPENS) === Certainty::MAYBE,
			);
		}

		foreach ($shape->getReplicators() as $name => $replicator) {
			$properties[$name] = self::maybeNullable(
				self::replicatorObjectCollection($replicator, $filled),
				($shape->getReplicatorPresence()[$name] ?? Certainty::HAPPENS) === Certainty::MAYBE,
			);
		}

		return new FormValuesObjectShapeType($objectClass, $properties, [], $shape);
	}

	/**
	 * A MAYBE field (conditionally added) is absent from the values when not added;
	 * reading a missing key on the ArrayHash/array crate yields null, so the value is
	 * widened to nullable rather than marked optional (optional access would resolve
	 * to ERROR on a direct ->field read).
	 */
	private static function maybeNullable(Type $value, bool $maybe): Type
	{
		return $maybe ? TypeCombinator::union($value, new NullType()) : $value;
	}

	public static function projectArray(FormShape $shape, bool $filled = false): Type
	{
		$builder = ConstantArrayTypeBuilder::createEmpty();
		foreach ($shape->getSlots() as $name => $slot) {
			if ($slot->isOmitted()) {
				continue;
			}

			$builder->setOffsetValueType(
				new ConstantStringType($name),
				$filled ? $slot->getFilledValueType() : $slot->getValueType(),
				$slot->valueMaybeAbsent(),
			);
		}

		foreach ($shape->getContainers() as $name => $child) {
			$builder->setOffsetValueType(
				new ConstantStringType($name),
				self::projectArray($child, $filled),
				($shape->getContainerPresence()[$name] ?? Certainty::HAPPENS) === Certainty::MAYBE,
			);
		}

		foreach ($shape->getReplicators() as $name => $replicator) {
			$builder->setOffsetValueType(
				new ConstantStringType($name),
				new ArrayType(new IntegerType(), self::replicatorItemType($replicator, false, $filled)),
				($shape->getReplicatorPresence()[$name] ?? Certainty::HAPPENS) === Certainty::MAYBE,
			);
		}

		// An open shape couldn't enumerate every field — there may be more keys we never saw,
		// so the values array is unsealed (sound) rather than claiming exactly the known keys.
		if ($shape->getUnknown()->hasUnknown()) {
			$builder->makeUnsealed(new StringType(), new MixedType());
		}

		return $builder->getArray();
	}

	/**
	 * A single field of the values object: the same per-field projection projectObject
	 * uses, with MAYBE presence widened to nullable for direct access.
	 */
	public static function member(FormShape $shape, string $name, bool $filled = false): Type
	{
		$slots = $shape->getSlots();
		if (isset($slots[$name]) && !$slots[$name]->isOmitted()) {
			$slot = $slots[$name];
			if ($slot->isTypeOpaque()) {
				return new MixedType();
			}

			$value = $filled ? $slot->getFilledValueType() : $slot->getValueType();

			return $slot->valueMaybeAbsent()
				? TypeCombinator::union($value, new NullType())
				: $value;
		}

		$containers = $shape->getContainers();
		if (isset($containers[$name])) {
			$child = self::projectObject($containers[$name], ArrayHash::class, $filled);

			return ($shape->getContainerPresence()[$name] ?? Certainty::HAPPENS) === Certainty::MAYBE
				? TypeCombinator::union($child, new NullType())
				: $child;
		}

		$replicators = $shape->getReplicators();
		if (isset($replicators[$name])) {
			return self::replicatorObjectCollection($replicators[$name], $filled);
		}

		return $shape->getUnknown()->hasUnknown() ? new MixedType() : new ErrorType();
	}

	// getValues() returns a replicator's replica collection as a Nette\Utils\ArrayHash (a nested
	// container mapped through getUntrustedValues), so it is Countable and offset-accessible — not
	// the plain array<int, …> the array view (projectArray, getValues(Form::Array)) keeps.
	private static function replicatorObjectCollection(ReplicatorShape $replicator, bool $filled): Type
	{
		return new GenericObjectType(ArrayHash::class, [self::replicatorItemType($replicator, true, $filled)]);
	}

	/**
	 * A replica's value type. When the item container maps itself to a DTO
	 * ($item->setMappedType()), each replica is that object; otherwise it is the
	 * inner crate/array shape.
	 */
	private static function replicatorItemType(ReplicatorShape $replicator, bool $asObject, bool $filled): Type
	{
		$inner = $replicator->getInner();
		$mapped = $inner->getMappedType();
		if ($mapped !== null) {
			return new ObjectType($mapped);
		}

		return $asObject
			? self::projectObject($inner, ArrayHash::class, $filled)
			: self::projectArray($inner, $filled);
	}

}
