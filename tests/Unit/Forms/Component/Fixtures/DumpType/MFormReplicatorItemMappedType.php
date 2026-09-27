<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MappedRowItem;
use function PHPStan\dumpType;

final class MFormReplicatorItemMappedType extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('title');
		$form->addDynamic('rows', static function (FormContainer $row): void {
			$row->setMappedType(MappedRowItem::class);
			$row->addText('label');
		});

		return $form;
	}

	public function go(): void
	{
		$values = $this['form']->getValues();
		dumpType($values); // => Nette\Utils\ArrayHash{title: string, rows: Nette\Utils\ArrayHash<Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MappedRowItem>}
	}

}
