<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class CycleForm
{

	public function aHandler(Form $form): void
	{
		$this->bRelay($form);
	}

	public function bRelay(Form $form): void
	{
		$this->aHandler($form);
	}

}
