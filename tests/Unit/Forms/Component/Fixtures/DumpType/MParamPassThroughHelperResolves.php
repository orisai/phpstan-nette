<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MParamPassThroughHelperResolves extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('passField');

		return $form;
	}

	public function trigger(): void
	{
		$this->outerHelper($this['form']);
	}

	private function outerHelper(ApplicationForm $form): void
	{
		$this->innerHelper($form);
	}

	private function innerHelper(ApplicationForm $form): void
	{
		dumpType($form['passField']); // => Nette\Forms\Controls\TextInput
	}

}
