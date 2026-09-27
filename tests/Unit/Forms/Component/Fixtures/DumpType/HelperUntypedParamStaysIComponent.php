<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class HelperUntypedParamStaysIComponent extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('x');

		return $form;
	}

	public function trigger(): void
	{
		$this->renderForm($this['form']);
	}

	/**
	 * @param mixed $form
	 */
	private function renderForm($form): void
	{
		dumpType($form['x']); // => mixed
	}

}
