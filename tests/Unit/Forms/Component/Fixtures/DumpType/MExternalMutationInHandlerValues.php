<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Utils\ArrayHash;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

final class MExternalMutationInHandlerValuesControl extends Control
{

	protected function createComponentExtForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('own');
		$form->onSuccess[] = [$this, 'succeeded'];
		$form->onSuccess[] = static function (ApplicationForm $form, ArrayHash $values): void {
			dumpType($values); // => Nette\Utils\ArrayHash{own: string, flag: bool|null, extra: int|null}
		};

		return $form;
	}

	public function succeeded(ApplicationForm $form): void
	{
		dumpType($form->getValues('array')); // => array{own: string, flag?: bool, extra?: int|null}
		dumpType($form['extra']); // => Nette\Forms\Controls\TextInput
		$this['extForm']['other']->setRequired(); // !! Form component 'other' does not exist.
	}

	public function handleFlag(): void
	{
		$this['extForm']->addCheckbox('flag');
	}

}

final class MExternalMutationInHandlerValuesPresenter extends Presenter
{

	protected function createComponentExtCtrl(): MExternalMutationInHandlerValuesControl
	{
		$control = new MExternalMutationInHandlerValuesControl();
		$control['extForm']->addInteger('extra');

		return $control;
	}

}
