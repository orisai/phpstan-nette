<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\UniverseFold;

use Nette\Application\UI\Form;

class Consumer
{

	use RegistersOrderForm;

	public function orderSucceeded(Form $form): void
	{
	}

}
