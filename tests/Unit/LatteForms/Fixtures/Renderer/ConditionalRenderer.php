<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

use Nette\Forms\Container;

final class ConditionalRenderer
{

	private bool $flag;

	private string $dynamic;

	public function __construct(bool $flag, string $dynamic)
	{
		$this->flag = $flag;
		$this->dynamic = $dynamic;
	}

	public function createComponentConditionalForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('always');

		if ($this->flag) {
			$form->addText('sometimes');
		}

		if ($this->flag) {
			$maybe = $form->addContainer('maybe');
			$maybe->addText('inside');
		}

		return $form;
	}

	public function createComponentEscapedContainerForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('top');
		$ref = $form->addContainer('ref');
		$ref->addText('known');
		$this->fill($ref);

		return $form;
	}

	public function createComponentOpenRootForm(): PairingForm
	{
		$form = new PairingForm();
		$inner = $form->addContainer('inner');
		$inner->addText('known');
		$form->addText($this->dynamic);

		return $form;
	}

	// Attaches a control the walk cannot name, so the container it was handed keeps an unknown of
	// its own while the root stays closed. Reading this body in full and finding it attaches
	// nothing would NOT do that any more: a callee proven to add nothing lets the container close.
	private function fill(Container $container): void
	{
		$container->addText($this->dynamic);
	}

}
