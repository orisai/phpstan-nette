<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

interface CrossControlFormFactory
{

	public function create(): ApplicationForm;

}

final class CrossControlChild extends Control
{

	private CrossControlFormFactory $formFactory;

	public function __construct(CrossControlFormFactory $formFactory)
	{
		$this->formFactory = $formFactory;
	}

	protected function createComponentForm(): ApplicationForm
	{
		$form = $this->formFactory->create();
		$form->addHidden('id');

		return $form;
	}

}

final class CrossControlNestedFormFieldResolves extends Control
{

	private CrossControlChild $child;

	protected function createComponentChild(): CrossControlChild
	{
		return $this->child;
	}

	public function go(): void
	{
		// Cross-control nested access: this control reaches into a *different* control's
		// createComponentForm-built form. When the child's file is result-cached, the
		// shape is recomputed on demand in this control's scope — the warm-divergence
		// shape that previously degraded to Nette\ComponentModel\IComponent.
		dumpType($this['child']['form']['id']); // => Nette\Forms\Controls\HiddenField
	}

}
