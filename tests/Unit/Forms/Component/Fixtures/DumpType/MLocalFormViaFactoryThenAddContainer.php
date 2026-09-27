<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\FormFactory;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MLocalFormViaFactoryThenAddContainer extends Control
{

	private FormFactory $factory;

	protected function createComponentForm(): ApplicationForm
	{
		$form = $this->factory->create();
		$bank = $form->addContainer('bank');
		$bank->addText('account_number');

		dumpType($form['bank']['account_number']); // => Nette\Forms\Controls\TextInput

		return $form;
	}

}
