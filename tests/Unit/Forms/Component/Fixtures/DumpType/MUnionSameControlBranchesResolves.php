<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MUnionSameControlBranchesResolves extends Control
{

	private bool $flag;

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		if ($this->flag) {
			$form->addText('field');
		} else {
			$form->addText('field');
		}

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['field']); // => Nette\Forms\Controls\TextInput
	}

}
