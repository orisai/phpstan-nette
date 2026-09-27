<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpTypePhp84;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class FirstClassCallableImmediateInvoke extends Control
{

	private function addFieldTo(ApplicationForm $f, string $name): void
	{
		$f->addText($name);
	}

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$adder = $this->addFieldTo(...);
		$adder($form, 'firstClass');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['firstClass']); // => Nette\ComponentModel\IComponent
	}

}
