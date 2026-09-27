<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

/**
 * The form BUILDER for the form-level shape fixtures, in its own file for the reason
 * FmClosedAndOpenContainerControl states: a consumer holding any form-building code of its own turns
 * FormFileIndex::hasAnyTrackedForm() true for its file, and FormAccessExpressionTypeResolver then
 * answers before ContainerModel ever runs - leaving the channel every .latte actually uses
 * unexercised.
 *
 * Two forms, because closedness is the axis. The first is fully read and therefore CLOSED, so a name
 * it does not hold is proven absent; the second is opened by a name no analysis can read, so nothing
 * about it can be proven and every unknown name there must degrade.
 */
final class FmFormLevelShapeControl extends Control
{

	public string $dyn = 'x';

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$sub = $form->addContainer('sub');
		$sub->addText('inner');

		return $form;
	}

	protected function createComponentOpenForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('base');
		$form->addText($this->dyn);

		return $form;
	}

}
