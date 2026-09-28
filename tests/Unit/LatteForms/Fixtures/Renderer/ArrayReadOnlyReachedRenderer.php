<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The negative control of the array unwrap: the same literal-array spelling handed to a callee that
// only READS its elements. This is the corpus's own addProvider('formsStack', [$this['form']]) shape,
// and the whole reason the unwrap stays conditional on the callee instead of being unconditional.
final class ArrayReadOnlyReachedRenderer extends PairingControl
{

	public function createComponentArrayReadOnlyForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('early');

		return $form;
	}

}
