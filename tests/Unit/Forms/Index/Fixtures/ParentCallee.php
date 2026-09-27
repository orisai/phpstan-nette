<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class ParentCallee
{

	public function inheritedFill(Form $form): void
	{
	}

}
