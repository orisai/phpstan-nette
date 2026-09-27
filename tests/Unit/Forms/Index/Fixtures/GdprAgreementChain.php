<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

/**
 * A cross-file pass-through chain (the GdprAgreement production shape): the createComponent registers
 * an array-callable handler, the handler hands its form to a helper method declared in a trait living
 * in a SEPARATE file (GdprAgreementPersistHelper). The store→read seam could not resolve the helper
 * param cold (the registration and the pass-through are collected in an order the reader could race);
 * the index folds both up front, so the helper param resolves closed end-to-end regardless of file
 * order.
 */
class GdprAgreementChain
{

	use GdprAgreementPersistHelper;

	public function createComponentGdpr(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addCheckbox('agreement');
		$form->addText('note');
		$form->onSuccess[] = [$this, 'gdprSucceeded'];

		return $form;
	}

	public function gdprSucceeded(ApplicationForm $form): void
	{
		$this->persistAgreement($form);
	}

}
