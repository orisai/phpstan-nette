<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MUnionSubformBranchesPicksOneBranch extends Control
{

	private bool $adminMode;

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		if ($this->adminMode) {
			$form->addText('admin_only');
		} else {
			$form->addText('user_only');
		}

		$form->addText('always');

		return $form;
	}

	public function goAlways(): void
	{
		dumpType($this['form']['always']); // => Nette\Forms\Controls\TextInput
	}

}
