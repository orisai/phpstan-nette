<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpTypePhp84;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class PropertyHookGetSetForm extends Control
{

	public string $defaultLabel {
		get => 'Default ' . $this->name;
		set (string $value) => $this->defaultLabel = strtoupper($value);
	}

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('hookedField', $this->defaultLabel);

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['hookedField']); // => Nette\Forms\Controls\TextInput
	}

}
