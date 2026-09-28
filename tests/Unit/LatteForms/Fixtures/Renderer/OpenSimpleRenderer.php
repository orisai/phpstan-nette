<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// Declares the same component name as ClosedRenderer, but under a name set the analyser cannot
// enumerate - so it can never contribute negative evidence about any name.
final class OpenSimpleRenderer
{

	/** @var list<string> */
	private array $extra;

	/**
	 * @param list<string> $extra
	 */
	public function __construct(array $extra)
	{
		$this->extra = $extra;
	}

	public function createComponentSimpleForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('name');

		foreach ($this->extra as $name) {
			$form->addText($name);
		}

		return $form;
	}

}
