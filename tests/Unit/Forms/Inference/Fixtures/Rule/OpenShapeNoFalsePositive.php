<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\ComponentModel\IComponent;

final class OpenShapeNoFalsePositive
{

	private IComponent $widget;

	public function firstClassCallableAddOpensShape(): void
	{
		$form = new ApplicationForm();
		$ref = $form->addText(...);
		$form->addText('plain');

		$form->getValues()->anythingGoes;
	}

	public function dynamicMethodOpensShape(string $method): void
	{
		$form = new ApplicationForm();
		$form->addText('plain');
		$form->$method('dyn');

		$form->getValues()->anythingGoes;
	}

	public function removeVariableComponentOpensShape(): void
	{
		$form = new ApplicationForm();
		$form->addText('plain');
		$form->removeComponent($this->widget);

		$form->getValues()->anythingGoes;
	}

	public function unsetDynamicKeyOpensShape(string $key): void
	{
		$form = new ApplicationForm();
		$form->addText('plain');
		unset($form[$key]);

		$form->getValues()->anythingGoes;
	}

}
