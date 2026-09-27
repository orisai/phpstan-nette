<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Form;

use Nette\Forms\Form;

class RawForm extends Form
{

	use FormSharedMethods;

	public function __construct(?string $name = null)
	{
		parent::__construct($name);
		$this->configureRender();
	}

	private function configureRender(): void
	{
		$renderer = $this->getRenderer();
		$renderer->wrappers['label']['suffix'] = ':';
	}

}
