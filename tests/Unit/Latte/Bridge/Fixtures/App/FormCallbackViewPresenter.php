<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;

final class FormCallbackViewPresenter extends Presenter
{

	protected function createComponentEditForm(): Form
	{
		$form = new Form();
		$form->onSuccess[] = [$this, 'processEdit'];
		$form->onSuccess[] = function (): void {
			$this->setView('closureSaved');
		};

		return $form;
	}

	public function processEdit(): void
	{
		$this->setView('saved');
	}

}
