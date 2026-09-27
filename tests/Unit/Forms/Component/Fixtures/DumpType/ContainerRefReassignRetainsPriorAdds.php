<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class ContainerRefReassignRetainsPriorAdds extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$c = $form->addContainer('area');
		$c->addCheckbox('flag');
		$c = $c->addText('reused');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['area']['flag']); // => Nette\Forms\Controls\Checkbox
		dumpType($this['form']['area']['reused']); // => Nette\Forms\Controls\TextInput
	}

}

final class ContainerRefNoReassignStaysSingle extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$c = $form->addContainer('area');
		$c->addCheckbox('flag');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['area']['flag']); // => Nette\Forms\Controls\Checkbox
	}

}
