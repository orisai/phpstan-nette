<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\ManifestIdentity;

use Nette\Application\UI\Form;

class Php8Registrant
{

	public readonly int $marker;

	public function __construct()
	{
		$this->marker = 1;
	}

	public function createComponentModern(): Form
	{
		$form = new Form();
		$form->addText('modernField');
		$form->onSuccess[] = [$this, 'modernSucceeded'];

		return $form;
	}

	public function modernSucceeded(Form $form): void
	{
	}

}
