<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support;

use Nette\Application\UI\Control;
use Nette\Forms\Container as NetteContainer;
use Nette\Forms\Controls\SubmitButton;
use Nette\Forms\Form as NetteForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

final class ClassTrackerFixture extends Control
{

	private ClassTrackerFormFactory $formFactory;

	public function newForm(): NetteForm
	{
		$form = new ApplicationForm();
		$form->addHidden('id');

		return $form;
	}

	public function factoryForm(): NetteForm
	{
		$form = $this->formFactory->create();
		$form->addHidden('id');

		return $form;
	}

	public function selfMethodForm(): NetteForm
	{
		$form = $this->newForm();
		$form->addHidden('id');

		return $form;
	}

	public function aliasForm(): NetteForm
	{
		$built = $this->formFactory->create();
		$form = $built;
		$form->addHidden('id');

		return $form;
	}

	public function paramForm(ApplicationForm $form): NetteContainer
	{
		$form->addHidden('id');

		return $form;
	}

	public function untracked(bool $flag): NetteForm
	{
		$form = new ApplicationForm();
		if ($flag) {
			$form = new ApplicationForm();
		}

		$form->addHidden('id');

		return $form;
	}

	public function closureShadow(): NetteForm
	{
		$form = $this->formFactory->create();
		$form->addSubmit('send')->onClick[] = function (SubmitButton $button): void {
			$form = $button->getForm();
			$form->addHidden('late');
		};

		return $form;
	}

}
