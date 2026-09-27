<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\MergeOrder;

trait CreatesSharedForm
{

	public function createComponentAlpha(): SharedForm
	{
		$form = new SharedForm();
		$form->addText('x');
		$form->onSuccess[] = [$this, 'sharedSucceeded'];

		return $form;
	}

}
