<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

final class CustomControlWrite
{

	// no error: 'b' is within the declared write type
	public function accepted(): void
	{
		$form = new WriteRatingForm();
		$form->addRating('x');
		$form['x']->setValue('b');
	}

	// Form field 'x' (...WriteRating) accepts 'a'|'b'|'c', 'z' given. [orisai.nette.forms.writeType]
	public function rejected(): void
	{
		$form = new WriteRatingForm();
		$form->addRating('x');
		$form['x']->setValue('z');
	}

	// no error: the absent field does not exist, so the write rule sees no FormControlType
	public function absent(): void
	{
		$form = new WriteRatingForm();
		$form->addRating('x');
		$form['missing']->setValue('z');
	}

}
