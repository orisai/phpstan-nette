<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;

final class MappedTypePhpdoc extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->addInteger('age');

		return $form;
	}

	public function phpdocNarrow(): void
	{
		$this['form']->getValues(MappedDtoPhpdocNarrow::class);
	}

}
