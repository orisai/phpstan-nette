<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

use Nette\Forms\Controls\SubmitButton;
use Nette\Utils\Html;

// A button that takes its label back. Vendor's Button::getLabel() is the `return null` this check
// looks for, and ancestry alone would condemn every subclass of it - so the check asks which class
// DECLARES the method the macro calls, and this fixture is the reason that distinction exists.
final class LabelledButton extends SubmitButton
{

	/**
	 * @param string|object|null $caption
	 * @return Html
	 */
	public function getLabel($caption = null)
	{
		return Html::el('label');
	}

}
