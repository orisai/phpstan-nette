<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

final class OffsetWrittenUnknownValue
{

	public function read(): void
	{
		$form = new ApplicationForm();
		$form['raw'] = $this->make();

		$form->getValues()->raw;
	}

	/**
	 * @return mixed
	 */
	private function make()
	{
		return null;
	}

}
