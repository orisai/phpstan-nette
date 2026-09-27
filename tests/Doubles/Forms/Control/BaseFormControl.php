<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Control;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

abstract class BaseFormControl extends Control
{

	private FormFactory $formFactory;

	public function __construct(FormFactory $formFactory)
	{
		$this->formFactory = $formFactory;
	}

	protected function getFormFactory(): FormFactory
	{
		return $this->formFactory;
	}

	abstract protected function createComponentForm(): ApplicationForm;

}
