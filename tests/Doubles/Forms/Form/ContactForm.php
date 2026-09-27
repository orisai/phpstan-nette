<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Form;

use Nette\Forms\Form;

class ContactForm extends Form
{

	public function __construct()
	{
		parent::__construct();

		$this->addText('name')
			->setRequired();
		$this->addText('email')
			->setRequired()
			->addRule(Form::EMAIL);
		$this->addTextArea('message')
			->setRequired();
		$this->addSubmit('send', 'Send message');
	}

}
