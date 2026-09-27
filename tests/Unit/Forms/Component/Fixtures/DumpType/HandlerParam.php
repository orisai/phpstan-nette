<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

final class HandlerParam extends Control
{

	protected function createComponentOrderForm(): Form
	{
		$form = new Form();
		$delivery = $form->addContainer('delivery');
		$delivery->addText('note');
		$form->onSuccess[] = [$this, 'orderSucceeded'];

		return $form;
	}

	public function orderSucceeded(Form $form): void
	{
		dumpType($form['delivery']['note']); // => Nette\Forms\Controls\TextInput
	}

}
