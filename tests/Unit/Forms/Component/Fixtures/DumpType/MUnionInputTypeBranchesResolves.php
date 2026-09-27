<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MUnionInputTypeBranchesResolves extends Control
{

	private bool $useDateTime;

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		if ($this->useDateTime) {
			$form->addDate('field');
		} else {
			$form->addText('field');
		}

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['field']); // => Nette\Forms\Controls\DateTimeControl|Nette\Forms\Controls\TextInput
	}

}
