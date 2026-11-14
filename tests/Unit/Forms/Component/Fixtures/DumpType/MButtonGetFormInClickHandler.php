<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Controls\SubmitButton;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

final class MButtonGetFormInClickHandler extends Control
{

	protected function createComponentClickForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addSubmit('save')->onClick[] = [$this, 'saveClicked'];
		$form['save']->onClick[] = static function (SubmitButton $button): void {
			dumpType($button->getForm()->getValues('array')); // => array{name: string, box: array{inner: string}}
		};
		$box = $form->addContainer('box');
		$box->addText('inner');
		$delete = $box->addSubmit('delete');
		$delete->onClick[] = [$this, 'deleteClicked'];

		return $form;
	}

	public function saveClicked(SubmitButton $button): void
	{
		dumpType($button->getForm()); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{name: string, box: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{inner: string}}
		dumpType($button->getForm()->getValues('array')); // => array{name: string, box: array{inner: string}}
		$form = $button->getForm();
		dumpType($form['name']); // => Nette\Forms\Controls\TextInput
		$form['nope']->setRequired(); // !! Form component 'nope' does not exist.
	}

	public function deleteClicked(SubmitButton $button): void
	{
		dumpType($button->getForm()->getValues('array')); // => array{name: string, box: array{inner: string}}
	}

	public function unrelated(SubmitButton $button): void
	{
		dumpType($button->getForm()); // => Nette\Forms\Form
	}

}
