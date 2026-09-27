<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Forms\Controls\BaseControl;
use function PHPStan\dumpType;

/**
 * @form-read-type int|string|null
 * @form-choice single int
 * @form-choice-open string
 */
final class TaggedOpenRating extends BaseControl
{

}

/**
 * @form-read-type string|null
 * @form-choice single string
 */
final class TaggedClosedString extends BaseControl
{

}

/**
 * @form-read-type list<int|string>
 * @form-choice multi int
 * @form-choice-open string
 */
final class TaggedOpenTags extends BaseControl
{

}

final class CustomChoiceForm extends RawForm
{

	public function addOpenRating(string $name): TaggedOpenRating
	{
		$control = new TaggedOpenRating();
		$this[$name] = $control;

		return $control;
	}

	public function addClosedString(string $name): TaggedClosedString
	{
		$control = new TaggedClosedString();
		$this[$name] = $control;

		return $control;
	}

	public function addOpenTags(string $name): TaggedOpenTags
	{
		$control = new TaggedOpenTags();
		$this[$name] = $control;

		return $control;
	}

}

final class MFormCustomChoiceTagged extends BaseFormControl
{

	/** @var array<int|string, string> */
	private array $dynamic = [];

	protected function createComponentForm(): CustomChoiceForm
	{
		$form = new CustomChoiceForm();
		$form->addOpenRating('rating')->setItems([1 => 'One', 2 => 'Two']);
		$form->addClosedString('closed')->setItems(['a' => 'A', 'b' => 'B']);
		$form->addClosedString('dyn')->setItems($this->dynamic);
		$form->addOpenTags('tags')->setItems([1 => 'One', 2 => 'Two']);
		$form->addOpenRating('ratingDyn')->setItems($this->dynamic);

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['rating']->getValue()); // => 1|2|string
		dumpType($this['form']['closed']->getValue()); // => 'a'|'b'|null
		dumpType($this['form']['dyn']->getValue()); // => string|null
		dumpType($this['form']['tags']->getValue()); // => list<1|2|string>
		dumpType($this['form']['ratingDyn']->getValue()); // => int|string
	}

}
