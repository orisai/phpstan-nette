<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use Nette\Application\UI\Form as UiForm;
use Nette\Forms\Form as FormsForm;
use Ublaboo\DataGrid\DataGrid;

final class CreateComponentUiForm extends Control
{

	// OK: ApplicationForm extends Nette\Application\UI\Form.
	protected function createComponentApplicationForm(): ApplicationForm
	{
		return new ApplicationForm();
	}

	// OK: declared directly as Nette\Application\UI\Form.
	protected function createComponentPlainUiForm(): UiForm
	{
		return new UiForm();
	}

	// ERROR: RawForm extends Nette\Forms\Form (not UI\Form).
	protected function createComponentContentForm(): RawForm
	{
		return new RawForm();
	}

	// ERROR: bare Nette\Forms\Form.
	protected function createComponentBareForm(): FormsForm
	{
		return new FormsForm();
	}

	// OK: not a form at all.
	protected function createComponentGrid(): DataGrid
	{
		return new DataGrid();
	}

	// OK: not a createComponent method, even though it returns a Forms\Form.
	protected function buildHelperForm(): RawForm
	{
		return new RawForm();
	}

}
