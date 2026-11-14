<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

abstract class AbstractHookBaseControl extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('baseField');
		$this->buildTypeFields($form);
		$this->buildExtraFields($form);

		return $form;
	}

	abstract protected function buildTypeFields(ApplicationForm $form): void;

	protected function buildExtraFields(ApplicationForm $form): void
	{
		$this->buildFooter($form);
	}

	protected function buildFooter(ApplicationForm $form): void
	{
		$form->addText('footer');
	}

	public function readBase(): void
	{
		$this['form']['baseField']->setRequired();
	}

}
