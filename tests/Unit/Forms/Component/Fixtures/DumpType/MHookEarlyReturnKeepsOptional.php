<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

abstract class MHookEarlyReturnKeepsOptionalBase extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$this->buildTypeFields($form);
		$form->addSubmit('send');

		return $form;
	}

	abstract protected function buildTypeFields(ApplicationForm $form): void;

}

final class MHookEarlyReturnKeepsOptional extends MHookEarlyReturnKeepsOptionalBase
{

	public bool $review = false;

	protected function buildTypeFields(ApplicationForm $form): void
	{
		if ($this->review) {
			$this->addReviewFields($form);

			return;
		}

		$form->addText('confirm');
	}

	private function addReviewFields(ApplicationForm $form): void
	{
		$form->addTextArea('reason');
		$form->addSubmit('sendReject');
	}

	public function go(): void
	{
		$form = $this['form'];
		dumpType(isset($form['sendReject'])); // => bool
		dumpType($form->getValues('array')); // => array{base: string, reason?: string, confirm?: string}
		if (isset($form['sendReject'])) {
			$form['sendReject']->setDisabled();
		}

		$form['nope']->setDisabled(); // !! Form component 'nope' does not exist.
	}

}
