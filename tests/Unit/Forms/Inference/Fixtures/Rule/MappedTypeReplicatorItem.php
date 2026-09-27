<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;

final class MappedTypeReplicatorItem extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addDynamic('rowsGood', static function (FormContainer $row): void {
			$row->setMappedType(RowItemGood::class);
			$row->addText('label');
		});
		$form->addDynamic('rowsBad', static function (FormContainer $row): void {
			$row->setMappedType(RowItemBad::class);
			$row->addText('label');
		});

		return $form;
	}

	public function mapItems(): void
	{
		$this['form']->getValues(OuterWithRows::class);
	}

}
