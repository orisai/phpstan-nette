<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class OpenRenderer
{

	/** @var list<string> */
	private array $extra;

	/** @param list<string> $extra */
	public function __construct(array $extra)
	{
		$this->extra = $extra;
	}

	public function createComponentOpenForm(): PairingForm
	{
		$form = new PairingForm();
		$form->addText('known');

		foreach ($this->extra as $name) {
			$form->addText($name);
		}

		return $form;
	}

}
