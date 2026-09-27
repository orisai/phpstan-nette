<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class SelfFactoryForm extends Form
{

	public function __construct()
	{
		parent::__construct();
		$this->addText('title');
	}

	public function build(): self
	{
		$form = new self();
		$form->onSuccess[] = [$this, 'selfSucceeded'];

		return $form;
	}

	public function selfSucceeded(Form $form): void
	{
	}

}
