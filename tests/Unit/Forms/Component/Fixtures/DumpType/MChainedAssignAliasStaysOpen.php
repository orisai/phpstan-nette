<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MChainedAssignAliasStaysOpen extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = $alias = new ApplicationForm();
		$form->addText('viaForm');
		$alias->addText('viaAlias');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{viaForm: string, ...<mixed>}
	}

}
