<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Nette\Forms\Controls\BaseControl;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\Testing\assertType;

/**
 * A control handed to another control's addConditionOn() escapes the analysis, so its slot keeps
 * its NAME and its class but loses its value type - the slot goes opaque. Opaque is a statement
 * about the VALUE alone: the component is still the TextInput/SelectBox that was added, and that
 * class is recorded in the shape's componentTypes.
 *
 * FormShapeProjector::offset() used to answer mixed for every such name. The slots channel already
 * held it, so ComponentPath::hasShapedChild() was true and childType()'s componentTypes arm could
 * never be reached - the class was shadowed and thrown away. ContainerModel's leaf arm, the channel
 * every .latte goes through, has always declined an opaque slot and fallen to componentTypes, so
 * the same expression answered with the real control class in a template and with mixed in the
 * builder.
 *
 * The value axis is unchanged and still unknown: FormShapeUnknownAccessRule reports
 * orisai.nette.forms.partiallyUnknown for these names (pinned by
 * InferenceUnknownRuleTest::testOpaqueSlotStillReportsAnUnknownValue()), and getValues() still
 * carries no readable type for them.
 */
final class OpaqueSlotOffset
{

	public function opaqueSlotResolvesItsControlClass(): void
	{
		$form = new ApplicationForm();
		$a = $form->addText('a');
		$c = $form->addText('c');
		$c->addConditionOn($a, $form::EQUAL, true)->setRequired();

		assertType('Nette\Forms\Controls\TextInput', $form['a']);
		$form['a']->setRequired();
	}

	public function opaqueSlotInsideAContainerResolvesToo(): void
	{
		$form = new ApplicationForm();
		$inner = $form->addContainer('outer');
		$a = $inner->addSelect('a');
		$c = $inner->addText('c');
		$c->addConditionOn($a, $form::EQUAL, true)->setRequired();

		// The same answer through the '-'-joined path spelling and the nested one, because both are
		// one runtime Container::getComponent() lookup and both land on offset()'s slot arm.
		assertType('Nette\Forms\Controls\SelectBox', $form['outer-a']);
		assertType('Nette\Forms\Controls\SelectBox', $form['outer']['a']);
	}

	public function opaqueSlotAssignedFromOutsideResolvesToo(BaseControl $external): void
	{
		$form = new ApplicationForm();
		$form['a'] = $external;
		$c = $form->addText('c');
		$c->addConditionOn($form['a'], $form::EQUAL, true)->setRequired();

		// A control the builder never added itself reaches componentTypes by the same route, so the
		// recovered class is whatever the assignment could prove - here the declared parameter type,
		// which is all that is knowable and is still strictly better than mixed.
		assertType('Nette\Forms\Controls\BaseControl', $form['a']);
	}

	public function aNameNoChannelHoldsIsUnaffected(string $name): void
	{
		$form = new ApplicationForm();
		$a = $form->addText($name);
		$c = $form->addText('c');
		$c->addConditionOn($a, $form::EQUAL, true)->setRequired();

		// componentTypes is READ for an opaque slot, never consulted for a name no channel holds:
		// that stays unknownLeaf()'s answer, mixed on this open shape.
		assertType('mixed', $form['nope']);
	}

}
