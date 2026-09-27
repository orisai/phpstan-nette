<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\UniverseFold;

use Nette\Application\UI\Form;

/**
 * Never CLI-listed by UniverseFoldTest — its registration is visible to the index only when the
 * universe is folded from the config's declared paths, not the CLI-narrowed analysedPaths.
 */
trait RegistersOrderForm
{

	public function createComponentOrder(): Form
	{
		$form = new Form();
		$form->addText('note');
		$form->onSuccess[] = [$this, 'orderSucceeded'];

		return $form;
	}

}
