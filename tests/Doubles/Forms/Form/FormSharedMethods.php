<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Form;

trait FormSharedMethods
{

	use ContainerSharedMethods;

	public function setAjax(bool $ajax = true): void
	{
		if ($ajax) {
			$this->elementPrototype->addAttributes([
				'class' => 'ajax',
			]);
		}
	}

}
