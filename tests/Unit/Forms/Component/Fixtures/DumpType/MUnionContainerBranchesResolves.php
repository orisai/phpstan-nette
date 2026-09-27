<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MUnionContainerBranchesResolves extends Control
{

	private bool $useNestedShape;

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		if ($this->useNestedShape) {
			$bag = $form->addContainer('bag');
			$bag->addText('inner');
		} else {
			$form->addText('bag');
		}

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['bag']); // => Nette\Forms\Controls\TextInput|Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{inner: string}
	}

}
