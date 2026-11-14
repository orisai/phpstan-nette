<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

final class MExternalMutationUnreadableOpensControl extends Control
{

	protected function createComponentAliasedForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('own');
		$form->onSuccess[] = [$this, 'succeeded'];

		return $form;
	}

	public function succeeded(ApplicationForm $form): void
	{
		dumpType($form->getValues('array')); // => array{own: string, ...<string, mixed>}
		dumpType($this['aliasedForm']['own']); // => Nette\Forms\Controls\TextInput
	}

}

final class MExternalMutationUnreadableOpensPresenter extends Presenter
{

	protected function createComponentAliasingCtrl(): MExternalMutationUnreadableOpensControl
	{
		$control = new MExternalMutationUnreadableOpensControl();
		$form = $control['aliasedForm'];
		$form->addText('late');

		return $control;
	}

}
