<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Type;

use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ErrorType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use function array_slice;
use function count;

final class FormReplicatorType extends ObjectType
{

	private FormShapeType $innerRow;

	private FormShape $innerRowShape;

	private FormShape $own;

	/**
	 * Both classes are taken as arguments rather than read off the shapes, because a shape carries no
	 * class when the walk could not resolve one and this type has nowhere to put that: it IS an
	 * ObjectType over the replicator's class wrapping a FormShapeType over the row's, and neither has
	 * a "no class" spelling. FormShapeProjector::replicatorType() is the one gate that decides both
	 * are known and declines to build this type at all when either is not - which is why there is no
	 * placeholder class name here any more.
	 */
	public function __construct(
		string $replicatorClass,
		string $innerRowClass,
		FormShape $innerRowShape,
		FormShape $ownShape
	)
	{
		$this->innerRow = new FormShapeType($innerRowClass, $innerRowShape);
		$this->innerRowShape = $innerRowShape;
		$this->own = $ownShape;
		parent::__construct($replicatorClass);
	}

	/**
	 * The children added straight onto the replicator container itself (e.g.
	 * `$reservationsParameters->addSubmit('addNode', …)` on the addDynamic() return value), as
	 * opposed to the per-ROW shape from the item-factory closure (wrapped in $innerRow above).
	 */
	public function getOwnFormShape(): FormShape
	{
		return $this->own;
	}

	public function isOffsetAccessible(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function isOffsetAccessLegal(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function getOffsetValueType(Type $offsetType): Type
	{
		// A constant string matching a child added straight onto the replicator holder (e.g. an
		// "addNode" submit button) resolves to THAT child's own type - answering the row here
		// would be confidently wrong (see docs: the false positive this task removes).
		$strings = $offsetType->getConstantStrings();
		if (count($strings) === 1) {
			$ownType = $this->pathType(ComponentPath::split($strings[0]->getValue()));
			if ($ownType !== null) {
				return $ownType;
			}
		}

		// An offset with no constant value to judge names a row, created on demand and keyed by index,
		// when it is an integer OR a string PHPStan proves is a decimal integer - `$rep[(string) $i]`,
		// or a variable inside a ctype_digit() guard, both of which reach that proof with no
		// annotation. The no-constant gate keeps every offset that HAS a constant with the
		// split()/isRowKey() arm above; see ComponentPath::isRowKeyType() for why the segment-string
		// and offset-type predicates must not be merged into one.
		if ($strings === [] && ComponentPath::isRowKeyType($offsetType)->yes()) {
			return $this->innerRow;
		}

		// Unresolvable: degrade to whatever the wrapped class's own ArrayAccess<string, IComponent>
		// stub would answer (IComponent) - never the row shape, never ErrorType.
		return parent::getOffsetValueType($offsetType);
	}

	public function hasOffsetValueType(Type $offsetType): TrinaryLogic
	{
		// offsetAccess.notFound is decided by this method, not by isOffsetAccessible()/
		// getOffsetValueType() above - without this override ObjectType answers from the wrapped
		// class, and phpstan-nette's Container.stub declares the offset key as `string`, narrower
		// than Nette's own ComponentModel\ArrayAccess, which documents `string|int` on offsetGet(),
		// offsetSet() and offsetExists() and casts an int to string in every body. That stub is not
		// ours to correct; this override is, and it carries knowledge the stub could not have. A
		// replicator's rows are created on demand and keyed by index, so any integer offset is
		// legitimate - hence the unconditional Yes for isInteger(). A constant-string PATH the shared
		// walk proves definite is ALSO Yes: a decimal name is the same on-demand row an int offset is,
		// and any other name is a child added straight onto the replicator holder. Anything else is
		// delegated to the parent (NOT hard-coded to Yes or No): ObjectType::hasOffsetValueType()
		// reads the wrapped class's declared offsetSet() parameter type (`string`), sees the offset
		// fits it, and answers Maybe - which is exactly the pre-existing, silent behaviour (the core
		// offsetAccess.notFound check does not report a Maybe on a non-array ArrayAccess receiver).
		// Never No: FormShapeUnknownAccessRule stays the sole authority on reporting absence.
		//
		// "Definite" is strict on purpose, and it is a question about PRESENCE, never about a name
		// merely being recorded: a MAYBE-present slot, container, replicator or componentTypes entry
		// all answer Maybe here, because answering Yes for any of them would misinform core's
		// issetCheck() about nullability (confirmed live: a conditionally-added control resolved as
		// non-nullable through `?? null` with an earlier draft that skipped this check). An
		// addSubmit() straight onto the replicator holder - the "addNode" button every Kdyby
		// replicator template references - is a componentTypes-only own child, and it reaches Yes
		// through the same ComponentPath::hasDefiniteChild() arm every other channel does, gated on the
		// certainty CompositionState met for it. pathType() below resolves such a name for the
		// POSITIVE case regardless of the answer here, since core only takes the "always exists"
		// shortcut when this method itself says Yes.
		$strings = $offsetType->getConstantStrings();
		$isChild = count($strings) === 1
			&& ComponentPath::replicatorHasDefinitePath(
				$this->innerRowShape,
				$this->own,
				ComponentPath::split($strings[0]->getValue()),
			);

		// isRowKeyType() is deliberately NOT applied here as well, although a non-constant
		// decimal-integer-string offset is the same on-demand row an integer one is. Measured: with an
		// ArrayAccess OBJECT receiver, core reads this answer only to decide offsetAccess.notFound
		// (which needs a No, and this method never returns one), so upgrading Maybe to Yes changes
		// nothing observable - `$rep[$i] ?? null` and `isset($rep[$i])` come out identically typed
		// whether this says Yes (an int) or Maybe (a plain string). getOffsetValueType() resolving a
		// name this method leaves at Maybe is the established asymmetry, not a new one: the same
		// already holds for a constant own-child name that pathType() resolves but
		// replicatorHasDefinitePath() will not call definite.
		return $offsetType->isInteger()
			->or(TrinaryLogic::createFromBoolean($isChild))
			->or(parent::hasOffsetValueType($offsetType));
	}

	/**
	 * The type behind a component path that has already reached this replicator, whether written as
	 * an offset on it (`$rep['0-field']`) or reached mid-walk by FormShapeProjector::offsetPath()
	 * (`$form['rows-0-field']`) - the same lookup either way.
	 *
	 * Nette casts an int offset to a string before looking it up and a replicator names each row by
	 * its integer index, so a purely-decimal FIRST segment is a ROW and never an own child: `$rep[0]`,
	 * `$rep['0']` and the '0' inside `$rep['0-field']` are one component. Anything else is a path
	 * into the OWN shape - a control added straight onto addDynamic()'s return value.
	 *
	 * FormShapeProjector owns both the walk and the leaf projection, including the bare
	 * componentTypes entry (e.g. a KIND_OMITTED addSubmit()) this method once resolved out of its
	 * own second spelling of the rule - FormShapeProjector::childType() is that rule now, and both
	 * leaves ask it. Null means unresolvable, and an ErrorType from the projector (a name a CLOSED
	 * shape proves absent) is unresolvable too as far as a TYPE is concerned - both degrade to the
	 * caller's parent:: answer, never to a confidently-wrong row.
	 *
	 * @param non-empty-list<string> $segments
	 */
	public function pathType(array $segments): ?Type
	{
		if (ComponentPath::isRowKey($segments[0])) {
			$belowRow = array_slice($segments, 1);
			if ($belowRow === []) {
				return $this->innerRow;
			}

			return self::usable(FormShapeProjector::offsetPath($this->innerRowShape, $belowRow));
		}

		$walk = ComponentPath::walk($this->own, $segments);
		if ($walk->getReplicatorHop() !== null) {
			return self::usable(FormShapeProjector::offsetPath($this->own, $segments));
		}

		if (!$walk->isLeaf()) {
			return null;
		}

		return FormShapeProjector::childType($walk->getShape(), $walk->getSegment());
	}

	private static function usable(?Type $type): ?Type
	{
		return $type === null || $type instanceof ErrorType ? null : $type;
	}

	public function equals(Type $type): bool
	{
		return $type instanceof self
			&& parent::equals($type)
			&& $this->innerRow->describe(VerbosityLevel::precise()) === $type->innerRow->describe(
				VerbosityLevel::precise(),
			)
			&& $this->own->describe(VerbosityLevel::precise()) === $type->own->describe(VerbosityLevel::precise());
	}

	public function describe(VerbosityLevel $level): string
	{
		return 'array<int, ' . $this->innerRow->describe($level) . '>';
	}

}
