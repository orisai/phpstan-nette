<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\AbstractHookBaseControl;
use function PHPStan\dumpType;

class MAbstractHookOverrideFollowed extends AbstractHookBaseControl
{

	protected function buildTypeFields(ApplicationForm $form): void
	{
		$form->addInteger('typeField');
	}

	public function go(): void
	{
		dumpType($this['form']['typeField']); // => Nette\Forms\Controls\TextInput
		dumpType($this['form']->getValues('array')); // => array{baseField: string, typeField: int|null, footer: string}
		$this['form']['otherField']->setRequired(); // !! Form component 'otherField' does not exist.
	}

}

final class MAbstractHookOverrideFollowedDeeper extends MAbstractHookOverrideFollowed
{

	protected function buildFooter(ApplicationForm $form): void
	{
		$form->addCheckbox('agree');
	}

	public function go(): void
	{
		dumpType($this['form']->getValues('array')); // => array{baseField: string, typeField: int|null, agree: bool}
		$this['form']['footer']->setRequired(); // !! Form component 'footer' does not exist.
	}

}

final class MAbstractHookOverrideFollowedSibling extends AbstractHookBaseControl
{

	protected function buildTypeFields(ApplicationForm $form): void
	{
		$form->addTextArea('note');
	}

	public function go(): void
	{
		dumpType($this['form']->getValues('array')); // => array{baseField: string, note: string, footer: string}
		$this['form']['typeField']->setRequired(); // !! Form component 'typeField' does not exist.
	}

}
