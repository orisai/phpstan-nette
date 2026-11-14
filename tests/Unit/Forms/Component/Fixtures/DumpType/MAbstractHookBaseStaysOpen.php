<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

abstract class MAbstractHookBaseStaysOpen extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('baseField');
		$this->buildTypeFields($form);

		return $form;
	}

	abstract protected function buildTypeFields(ApplicationForm $form): void;

	public function go(): void
	{
		dumpType($this['form']->getValues('array')); // => array{baseField: string, ...<string, mixed>}
	}

}

final class MAbstractHookBaseStaysOpenChild extends MAbstractHookBaseStaysOpen
{

	protected function buildTypeFields(ApplicationForm $form): void
	{
		$form->addText('childField');
	}

	public function read(): void
	{
		dumpType($this['form']->getValues('array')); // => array{baseField: string, childField: string}
	}

}
