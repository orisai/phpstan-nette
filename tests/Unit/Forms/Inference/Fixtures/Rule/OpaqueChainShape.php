<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\Support\IOpaqueContentControlFactory;
use function OriPhpstan\Nette\Forms\Testing\dumpComponent;

/**
 * An unreadable builder chain bound to a LOCAL first, so the shape is assembled by IndexShapeResolver
 * rather than by ContainerModel's own builder-chain arm. Every resolution channel declines - the
 * chain terminal cannot be followed, and neither a parent, a factory nor a constructor supplies a
 * class - so the declared return class is substituted, and that substitution is the tell that the
 * origin was never read. Nothing may be claimed about the component's children on the strength of a
 * class name alone.
 */
final class OpaqueChainReturnTypeControl extends Control
{

	private IOpaqueContentControlFactory $factory;

	public function __construct(IOpaqueContentControlFactory $factory)
	{
		$this->factory = $factory;
	}

	protected function createComponentForm(): ApplicationForm
	{
		$form = $this->factory
			->create()
			->setLabel('x')
			->setPresenter($this->getPresenter())
			->create();

		return $form;
	}

	public function dump(): void
	{
		dumpComponent($this['form']);
	}

}

final class OpaqueChainInnerControl extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('inner');

		return $form;
	}

}

/**
 * The same unreadable builder chain returned DIRECTLY, with no local in between, from a factory
 * method declaring a UI component. That arm substitutes the method's declared return class for a
 * chain terminal it could not follow at all, so nothing here was ever read off the object the
 * chain produces - OpaqueChainInnerControl really does own a 'form' child, and a closed shape is a
 * false proof that the declared component owns nothing.
 */
final class OpaqueChainDirectReturnControl extends Control
{

	private IOpaqueContentControlFactory $factory;

	public function __construct(IOpaqueContentControlFactory $factory)
	{
		$this->factory = $factory;
	}

	protected function createComponentInner(): OpaqueChainInnerControl
	{
		return $this->factory
			->create()
			->setLabel('x')
			->setPresenter($this->getPresenter())
			->create();
	}

	public function dump(): void
	{
		dumpComponent($this['inner']);
	}

}
