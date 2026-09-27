<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

/**
 * A form held in a PUBLIC property: the shape lists 'known' and carries
 * UnknownReason::PROPERTY_WRITE_UNMODELLED, because a write from outside this class is exactly what
 * the per-class fold cannot enumerate. 'addedFromOutside' is such a write — legal, real code — so
 * the open shape is a statement that fields were LOST, not that the accessed name is wrong.
 * Reporting it would be a false "may not exist" on correct code.
 */
final class PropertyWriteUnmodelledAccess
{

	public ApplicationForm $form;

	public function __construct()
	{
		$this->form = new ApplicationForm();
		$this->form->addText('known');
	}

	// G13-01: no error — the accessed name is most likely one of the fields an unmodelled external
	// write added, so the open shape must not be read as "this name does not exist".
	public function read(): void
	{
		$this->form->getValues()->addedFromOutside;
	}

	// G13-02: no error — the listed field still resolves through the same shape.
	public function readKnown(): void
	{
		$this->form->getValues()->known;
	}

}
