<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

trait SameFileFormTrait
{

	protected function createComponentForm(): Form
	{
		$form = parent::createComponentForm();
		$form->addText('childField');
		$form->onSuccess[] = [$this, 'sameFileSucceeded'];

		return $form;
	}

	public function sameFileSucceeded(Form $form): void
	{
	}

}

final class TraitSameFileForm extends InheritedParentBase
{

	use SameFileFormTrait;

}
