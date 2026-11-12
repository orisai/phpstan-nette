<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Nette\Application\UI\Control;
use Nette\Forms\Controls\Checkbox;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\Testing\assertType;

/**
 * isset() on a component offset narrows through Type::getOffsetValueType() on PHPStan 2.3, which never asks
 * the offsetGet() dynamic return type extensions. The offset keeps the type its plain read has.
 */
final class IssetOffsetControl extends Control
{

	public bool $withDelete = true;

	public function onSuccess(ApplicationForm $form): void
	{
		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['delete']);
		if (isset($form['delete'])) {
			assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['delete']);
		}

		if (isset($form['delete'], $form['name']) && $form['delete']->isSubmittedBy()) {
			assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton', $form['delete']);
			assertType('Nette\Forms\Controls\TextInput', $form['name']);
		}

		if ($form['name'] instanceof Checkbox) {
			assertType('Nette\Forms\Controls\Checkbox', $form['name']);
		}

		if (isset($form['unknown'])) {
			assertType('Nette\ComponentModel\IComponent', $form['unknown']);
		}
	}

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		if ($this->withDelete) {
			$form->addSubmit('delete');
		}

		$form->onSuccess[] = [$this, 'onSuccess'];

		return $form;
	}

}
