<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use Nette\Forms\Controls\TextInput;
use function PHPStan\dumpType;

/**
 * A factory method folds components onto the form in a `foreach` over a literal name array
 * (`$form->addComponent(new X(), $name)`). A loop-variable component name is not unrolled to a
 * concrete slot scope-free, so the on-demand factory twin resolves the folded components through
 * the form's open tail (IComponent), never a closed miss. This pins the addComponent-fold family
 * sound (open, never narrower) — the twin needs no widening for it, the open tail already covers
 * the fold.
 */
class AddComponentFoldFactory_MFactoryAddComponentFold
{

	public function create(): Form
	{
		$form = new Form();

		foreach (['alpha', 'beta'] as $name) {
			$form->addComponent(new TextInput(), $name);
		}

		return $form;
	}

}

final class MFactoryAddComponentFoldResolves extends Control
{

	private AddComponentFoldFactory_MFactoryAddComponentFold $factory;

	public function go(): void
	{
		$form = $this->factory->create();
		dumpType($form['alpha']); // => Nette\ComponentModel\IComponent
		dumpType($form['beta']); // => Nette\ComponentModel\IComponent
	}

}
