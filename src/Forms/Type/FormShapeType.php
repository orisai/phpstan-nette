<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Type;

use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use function count;

final class FormShapeType extends ObjectType
{

	private FormShape $shape;

	private bool $validated;

	public function __construct(string $className, FormShape $shape, bool $validated = false)
	{
		$this->shape = $shape;
		$this->validated = $validated;
		parent::__construct($className);
	}

	public function getFormShape(): FormShape
	{
		return $this->shape;
	}

	/**
	 * True once the form has passed validation (inside an `if ($form->isValid())`
	 * branch), so getValues() projects the post-validation (filled) shape.
	 */
	public function isValidated(): bool
	{
		return $this->validated;
	}

	/**
	 * Yes for a name the shape can prove DEFINITELY exists - a slot or nested container/replicator
	 * whose presence is Certainty::HAPPENS on every path - with separator descent for a path name
	 * (`'a-b'`), through the shared ComponentPath walk that owns Nette's own
	 * Container::getComponent() semantics. This is the one consumer that walks with
	 * $requireDefinitePresence on: core's issetCheck() takes a Yes literally, so a hop through a
	 * container that might not be attached must not produce one. Everything else delegates to
	 * parent::, which is Maybe for a string offset (the wrapped Container's ArrayAccess<string,
	 * IComponent> stub) - NEVER No: FormShapeUnknownAccessRule stays the sole authority on reporting
	 * absence, and a No here would double-report against it.
	 *
	 * A componentTypes-only entry (a value-less control like a submit button) contributes Yes on the
	 * same terms as the other three and on no easier ones: only when its own presence axis says
	 * HAPPENS. That axis is what CompositionState's componentTypes fold and FormShape::joinBranch()
	 * MEET; before it existed the map was accumulated with a bare union, so a name any one branch
	 * added looked exactly like a name every branch added, and answering Yes off the mere existence
	 * of an entry mis-resolved `if ($c) { $form->addSubmit('save', ...); } $form['save'] ?? null;` to
	 * non-nullable - core's MutatingScope::issetCheck() takes this method's Yes branch literally.
	 * That case still answers Maybe; what changed is that the unconditional one no longer has to.
	 *
	 * classify() (FormShapeUnknownAccessRule) still treats the mere EXISTENCE of a componentTypes
	 * entry as sufficient for NOT reporting an absence, and deliberately does not consult the presence
	 * axis: a false negative there only means stay silent, the safe direction, while a false Yes here
	 * misinforms core about nullability with nothing behind it since 744bf824e removed the |null
	 * redundancy.
	 */
	public function hasOffsetValueType(Type $offsetType): TrinaryLogic
	{
		$strings = $offsetType->getConstantStrings();
		if (
			count($strings) === 1
			&& ComponentPath::hasDefinitePath($this->shape, ComponentPath::split($strings[0]->getValue()))
		) {
			return TrinaryLogic::createYes();
		}

		return parent::hasOffsetValueType($offsetType);
	}

	public function equals(Type $type): bool
	{
		if (!$type instanceof self) {
			return false;
		}

		return $this->validated === $type->validated
			&& parent::equals($type)
			&& $this->describe(VerbosityLevel::precise()) === $type->describe(VerbosityLevel::precise());
	}

	public function describe(VerbosityLevel $level): string
	{
		if ($level->isPrecise()) {
			return $this->getClassName() . $this->shape->describe($level);
		}

		return $this->getClassName();
	}

}
