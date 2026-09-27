<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MEventCallbackFormParamResolvesEmitter extends BaseFormControl
{

	/** @var array<callable> */
	public array $onAfterSuccess = [];

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('emitterField');

		return $form;
	}

}

final class MEventCallbackFormParamResolves extends Control
{

	private MEventCallbackFormParamResolvesEmitter $emitter;

	protected function createComponentChild(): MEventCallbackFormParamResolvesEmitter
	{
		$cmp = $this->emitter;
		$cmp->onAfterSuccess[] = function (ApplicationForm $form): void {
			dumpType($form['emitterField']); // => Nette\Forms\Controls\TextInput
		};

		return $cmp;
	}

}
