<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;

final class WriteRatingForm extends RawForm
{

	public function addRating(string $name): WriteRating
	{
		$control = new WriteRating();
		$this[$name] = $control;

		return $control;
	}

}
