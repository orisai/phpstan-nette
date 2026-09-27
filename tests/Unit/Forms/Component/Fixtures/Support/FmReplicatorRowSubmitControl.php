<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Forms\Container;

/**
 * The form BUILDER for the row-submit walk-channel fixture, in its own file for the same reason
 * FmReplicatorOwnChildControl is: a consumer that builds a form of its own turns
 * FormFileIndex::hasAnyTrackedForm() true for itself, and FormAccessExpressionTypeResolver then
 * answers the offset before ContainerModel - the channel every .latte actually uses - ever sees it.
 *
 * The remove button lives INSIDE the item factory, so it is a child of each ROW rather than of the
 * replicator itself; it carries no value, so the row shape records it in componentTypes alone.
 */
final class FmReplicatorRowSubmitControl extends Control
{

	protected function createComponentForm(): Form
	{
		$form = new Form();
		$outer = $form->addContainer('outer');
		$rep = $outer->addDynamic('rep', static function (Container $row): void {
			$row->addText('x');
			$row->addSubmit('removeNode', 'Remove');
		});
		$rep->addSubmit('addNode', 'Add');

		return $form;
	}

}
