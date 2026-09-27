<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Type;

use OriPhpstan\Nette\Forms\Shape\FormShape;
use PHPStan\Reflection\ClassMemberAccessAnswerer;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Reflection\Type\UnresolvedPropertyPrototypeReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectShapeType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use function array_filter;
use function array_values;
use function count;
use function in_array;
use function ltrim;
use function strlen;
use function substr;

/**
 * The value object returned by Nette's $form->getValues(): a universal object
 * crate (Nette\Utils\ArrayHash by default, or stdClass) carrying typed fields.
 *
 * It behaves equally for property and offset access — $values->field and
 * $values['field'] both resolve to the exact field type — and still reports the
 * crate class identity, so it stays assignable where an ArrayHash/stdClass is
 * expected. (An IntersectionType of ArrayHash with a plain ObjectShapeType cannot
 * achieve this: ArrayHash is ArrayAccess<array-key, mixed>, which widens offset
 * access back to mixed.)
 */
final class FormValuesObjectShapeType extends ObjectShapeType
{

	private string $crateClass;

	/** @var array<string, Type> */
	private array $valueProperties;

	/** @var list<string> */
	private array $optionalKeys;

	private FormShape $shape;

	/**
	 * @param array<string, Type> $properties
	 * @param list<string> $optionalProperties
	 */
	public function __construct(string $crateClass, array $properties, array $optionalProperties, FormShape $shape)
	{
		parent::__construct($properties, $optionalProperties);
		$this->crateClass = $crateClass;
		$this->valueProperties = $properties;
		$this->optionalKeys = $optionalProperties;
		$this->shape = $shape;
	}

	public function getFormShape(): FormShape
	{
		return $this->shape;
	}

	/** @return list<non-empty-string> */
	public function getObjectClassNames(): array
	{
		return $this->crateClass === '' ? [] : [$this->crateClass];
	}

	public function describe(VerbosityLevel $level): string
	{
		// ObjectShapeType renders "object{…}"; prefix the crate class so the dump
		// reads "Nette\Utils\ArrayHash{name: string, …}".
		$shape = parent::describe($level);
		$body = ltrim($this->crateClass, '\\') . substr($shape, strlen('object'));

		if (!$this->shape->getUnknown()->hasUnknown()) {
			return $body;
		}

		// The shape is open — there may be further, undetermined fields, so the crate is
		// unsealed: an unlisted property/offset reads as mixed (see getInstanceProperty / getOffsetValueType).
		$separator = $this->valueProperties === [] ? '' : ', ';

		return (string) substr($body, 0, -1) . $separator . '...<mixed>}';
	}

	public function isOffsetAccessible(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	/**
	 * Never No, the same discipline FormShapeType/FormReplicatorType hold for a string offset:
	 * FormShapeUnknownAccessRule stays the sole authority on reporting absence, and a No here would
	 * double-report against it (core's own offsetAccess.notFound check fires on hasOffsetValueType()
	 * ->no(), independent of what our rule already said). A missing key — closed shape or not —
	 * delegates to parent::, which (ObjectShapeType, via MaybeOffsetAccessibleTypeTrait) always
	 * answers Maybe.
	 */
	public function hasOffsetValueType(Type $offsetType): TrinaryLogic
	{
		$key = $this->constantKey($offsetType);
		if ($key === null || !isset($this->valueProperties[$key])) {
			return parent::hasOffsetValueType($offsetType);
		}

		return in_array($key, $this->optionalKeys, true)
			? TrinaryLogic::createMaybe()
			: TrinaryLogic::createYes();
	}

	public function getOffsetValueType(Type $offsetType): Type
	{
		$key = $this->constantKey($offsetType);
		if ($key !== null && isset($this->valueProperties[$key])) {
			return $this->valueProperties[$key];
		}

		if ($this->shape->getUnknown()->hasUnknown()) {
			return new MixedType();
		}

		return parent::getOffsetValueType($offsetType);
	}

	public function hasInstanceProperty(string $propertyName): TrinaryLogic
	{
		if (isset($this->valueProperties[$propertyName]) || !$this->shape->getUnknown()->hasUnknown()) {
			return parent::hasInstanceProperty($propertyName);
		}

		// open crate: $values->unlistedField is one of the fields we could not enumerate, and the
		// real crate (ArrayHash/stdClass) carries dynamic properties, so it reads as mixed.
		return $this->crate()->hasInstanceProperty($propertyName);
	}

	public function getInstanceProperty(
		string $propertyName,
		ClassMemberAccessAnswerer $scope
	): ExtendedPropertyReflection
	{
		if (isset($this->valueProperties[$propertyName]) || !$this->shape->getUnknown()->hasUnknown()) {
			return parent::getInstanceProperty($propertyName, $scope);
		}

		return $this->crate()->getInstanceProperty($propertyName, $scope);
	}

	public function getUnresolvedInstancePropertyPrototype(
		string $propertyName,
		ClassMemberAccessAnswerer $scope
	): UnresolvedPropertyPrototypeReflection
	{
		if (isset($this->valueProperties[$propertyName]) || !$this->shape->getUnknown()->hasUnknown()) {
			return parent::getUnresolvedInstancePropertyPrototype($propertyName, $scope);
		}

		return $this->crate()->getUnresolvedInstancePropertyPrototype($propertyName, $scope);
	}

	/**
	 * The crate (Nette\Utils\ArrayHash) is Traversable, so getValues() is iterable —
	 * foreach over it yields its members. ObjectShapeType is not iterable on its own,
	 * so delegate iteration to the crate (key array-key, value mixed), matching how a
	 * plain ArrayHash behaves.
	 */
	public function isIterableAtLeastOnce(): TrinaryLogic
	{
		return $this->crate()->isIterableAtLeastOnce();
	}

	public function isIterable(): TrinaryLogic
	{
		return $this->crate()->isIterable();
	}

	public function getIterableKeyType(): Type
	{
		return $this->crate()->getIterableKeyType();
	}

	public function getIterableValueType(): Type
	{
		return $this->crate()->getIterableValueType();
	}

	/**
	 * unset($values[key]) drops the field while preserving the crate identity and the
	 * open/unknown status — ObjectShapeType::unsetOffset would return a bare object
	 * shape, losing both (and with them iterability).
	 */
	public function unsetOffset(Type $offsetType): Type
	{
		$key = $this->constantKey($offsetType);
		if ($key === null || !isset($this->valueProperties[$key])) {
			return $this;
		}

		$properties = $this->valueProperties;
		unset($properties[$key]);

		return new self(
			$this->crateClass,
			$properties,
			array_values(array_filter($this->optionalKeys, static fn (string $k): bool => $k !== $key)),
			$this->shape,
		);
	}

	/**
	 * The crate is a real ArrayHash/stdClass, so its methods (offsetGet, count, …) are
	 * callable on getValues(); ObjectShapeType alone exposes none, so delegate to the
	 * crate class.
	 */
	public function hasMethod(string $methodName): TrinaryLogic
	{
		return $this->crate()->hasMethod($methodName);
	}

	public function getMethod(string $methodName, ClassMemberAccessAnswerer $scope): ExtendedMethodReflection
	{
		return $this->crate()->getMethod($methodName, $scope);
	}

	private function crate(): Type
	{
		return new ObjectType($this->crateClass);
	}

	private function constantKey(Type $offsetType): ?string
	{
		$strings = $offsetType->getConstantStrings();

		return count($strings) === 1 ? $strings[0]->getValue() : null;
	}

}
