<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Forms\Container;

/**
 * The form BUILDER for the walk-channel replicator fixtures, deliberately in its own file: the
 * consumer must contain no form-building code at all, or FormFileIndex::hasAnyTrackedForm() turns
 * true for it and FormAccessExpressionTypeResolver answers the offset first (an
 * ExpressionTypeResolverExtension runs before any dynamic return-type extension), leaving
 * ContainerModel - the channel every .latte actually uses - unexercised.
 */
final class FmReplicatorOwnChildControl extends Control
{

	protected function createComponentForm(): Form
	{
		$form = new Form();
		$outer = $form->addContainer('outer');
		$rep = $outer->addDynamic('rep', static function (Container $row): void {
			$row->addText('x');
		});
		$rep->addSubmit('addNode', 'Add');

		return $form;
	}

}
