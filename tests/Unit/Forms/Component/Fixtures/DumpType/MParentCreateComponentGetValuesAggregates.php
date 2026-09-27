<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\Gap5ParentControl;
use function PHPStan\dumpType;

/**
 * The parent createComponentForm (in a separate, here-non-analysed file) adds fromParent;
 * the child adds fromChild via $form = parent::createComponentForm(). The aggregate
 * getValues() must carry BOTH fields even when the parent's stored shape is not in the
 * interprocedural store yet (resolved on demand from source).
 */
final class MParentCreateComponentGetValuesAggregates extends Gap5ParentControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = parent::createComponentForm();
		$form->addText('fromChild');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{fromChild: string, fromParent: string}
	}

}
