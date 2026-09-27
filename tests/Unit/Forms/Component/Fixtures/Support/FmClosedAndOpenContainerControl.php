<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;

/**
 * The form BUILDER for the walk-channel proven-absence fixture, deliberately in its own file: the
 * consumer must contain no form-building code at all, or FormFileIndex::hasAnyTrackedForm() turns
 * true for it and FormAccessExpressionTypeResolver answers the offset first (an
 * ExpressionTypeResolverExtension runs before any dynamic return-type extension), leaving
 * ContainerModel - the channel every .latte actually uses - unexercised.
 *
 * Two containers, because closedness is the axis under test. Both carry a named child so both are
 * wrapped in a FormShapeType; only the second one is opened, by a name no analysis can read.
 */
final class FmClosedAndOpenContainerControl extends Control
{

	public string $dyn = 'x';

	protected function createComponentForm(): Form
	{
		$form = new Form();
		$closed = $form->addContainer('closed');
		$closed->addText('a');

		$open = $form->addContainer('open');
		$open->addText('b');
		$open->addText($this->dyn);

		return $form;
	}

}
