<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support;

use Nette\Application\UI\Control;
use Nette\Forms\Form;

final class FmCrossFileBuilder extends Control
{

	private FmCrossFileFormFactory $factory;

	public function createForm(): Form
	{
		$form = $this->factory->make();
		$form->addHidden('action');
		$form->addStars('rating');

		return $form;
	}

}
