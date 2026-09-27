<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Nette\Forms\Container as NetteContainer;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function PHPStan\Testing\assertType;

/**
 * One name, two channels. Two branches can fill a single name with a control in one and a container
 * in the other, and nothing decides afterwards which of the two the runtime actually attached - so
 * the union of both is the honest answer and taking whichever map happened to be consulted first is
 * a coin toss that silently drops half of it.
 *
 * ContainerModel::walk()'s leaf arm - the channel every .latte and every non-building consumer goes
 * through - has always unioned these two, pinned by MUnionContainerBranchesResolves. The projector
 * folded the same shape to the slot alone, so `$form['bag']` inside the file that BUILDS the form and
 * `$this['form']['bag']` one method away answered differently for one runtime lookup. Both channels
 * now run the same four-outcome order, measured head-to-head on every collision below.
 *
 * The degrade is per-ARM and never per-union: an arm that cannot name a class contributes nothing
 * rather than contributing mixed, which would swallow the other arm whole.
 */
final class CollidingChannelsOffset
{

	private function configure(NetteContainer $c): void
	{
		$c->addText('h');
	}

	public function slotAndContainerUnion(bool $flag): void
	{
		$form = new ApplicationForm();
		if ($flag) {
			$bag = $form->addContainer('bag');
			$bag->addText('inner');
		} else {
			$form->addText('bag');
		}

		assertType(
			'Nette\Forms\Controls\TextInput|Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{inner: string}',
			$form['bag'],
		);
	}

	/**
	 * The union is minted by offset(), so every leaf that goes through it carries the same one: the
	 * nested spelling and the '-'-joined spelling are one runtime Container::getComponent() lookup and
	 * both reach it through ComponentPath::walk().
	 */
	public function unionSurvivesAPathHop(bool $flag): void
	{
		$form = new ApplicationForm();
		$outer = $form->addContainer('outer');
		if ($flag) {
			$bag = $outer->addContainer('bag');
			$bag->addText('inner');
		} else {
			$outer->addText('bag');
		}

		assertType(
			'Nette\Forms\Controls\TextInput|Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{inner: string}',
			$form['outer']['bag'],
		);
		assertType(
			'Nette\Forms\Controls\TextInput|Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{inner: string}',
			$form['outer-bag'],
		);
	}

	/**
	 * A container whose reference escapes into a callee leaves a shape isUsableInnerShape() will not
	 * wrap, so its arm degrades to the bare component class - and still joins the union as that. The
	 * arm getting less precise must not cost the OTHER arm its precision, which is exactly what
	 * unioning a mixed placeholder in here would have done.
	 */
	public function aDegradedArmJoinsTheUnionWithoutPoisoningIt(bool $flag): void
	{
		$form = new ApplicationForm();
		if ($flag) {
			$this->configure($form->addContainer('bag'));
		} else {
			$form->addText('bag');
		}

		assertType(
			'Nette\Forms\Controls\TextInput|Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer',
			$form['bag'],
		);
	}

	/**
	 * An OPAQUE slot has no carrier to contribute - opaque is a statement about the value, and the
	 * class such a slot leaves behind lives in componentTypes, not in the slot - so no union forms and
	 * the channel that CAN name a carrier answers alone. It used to shadow the container instead and
	 * hand back the control class, which is what the other channel already refused to do.
	 *
	 * The value axis is untouched: FormShapeUnknownAccessRule still reports orisaiNette.forms.partiallyUnknown
	 * for the same name.
	 */
	public function anOpaqueSlotDoesNotShadowTheContainer(bool $flag): void
	{
		$form = new ApplicationForm();
		if ($flag) {
			$bag = $form->addContainer('bag');
			$bag->addText('inner');
		} else {
			$slot = $form->addText('bag');
			$c = $form->addText('c');
			$c->addConditionOn($slot, $form::EQUAL, true)->setRequired();
		}

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{inner: string}', $form['bag']);
	}

	/**
	 * The REPLICATOR channel is not a third member of the union, on either channel. ContainerModel's
	 * leaf arm reaches its own replicator branch only after the other two have declined and only
	 * through a componentTypes entry, so unioning one in here would answer something that arm does not
	 * - re-opening the divergence in the opposite direction. Both collisions below are measured
	 * identical on the two channels.
	 */
	public function aReplicatorStaysOutOfTheUnion(bool $flag): void
	{
		$form = new ApplicationForm();
		if ($flag) {
			$form->addDynamic('bag', static function (FormContainer $c): void {
				$c->addText('x');
			});
		} else {
			$form->addText('bag');
		}

		assertType('Nette\Forms\Controls\TextInput', $form['bag']);
	}

	public function aReplicatorLosesToAContainerToo(bool $flag): void
	{
		$form = new ApplicationForm();
		if ($flag) {
			$form->addDynamic('bag', static function (FormContainer $c): void {
				$c->addText('x');
			});
		} else {
			$bag = $form->addContainer('bag');
			$bag->addText('inner');
		}

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{inner: string}', $form['bag']);
	}

	/**
	 * Presence is a separate axis and this change does not touch it: the union carries no null arm of
	 * its own, and `?? null` stays nullable off ComponentPath::hasDefiniteChild() alone, which reads
	 * the slot channel's MAYBE for this name.
	 */
	public function theUnionCarriesNoPresenceOfItsOwn(bool $flag): void
	{
		$form = new ApplicationForm();
		if ($flag) {
			$bag = $form->addContainer('bag');
			$bag->addText('inner');
		} else {
			$form->addText('bag');
		}

		assertType(
			'Nette\Forms\Controls\TextInput|Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{inner: string}|null',
			$form['bag'] ?? null,
		);
	}

}
