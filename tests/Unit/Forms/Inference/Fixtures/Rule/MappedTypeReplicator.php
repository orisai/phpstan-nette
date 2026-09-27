<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;

final class MappedTypeReplicator extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addDynamic('items', static function (FormContainer $item): void {
			$item->addText('label');
		});

		return $form;
	}

	public function replicatorToArray(): void
	{
		$this['form']->getValues(NestedReplicatorArrayOk::class);
	}

	public function replicatorToScalar(): void
	{
		$this['form']->getValues(NestedReplicatorScalar::class);
	}

}
