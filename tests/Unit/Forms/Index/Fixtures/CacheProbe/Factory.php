<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\CacheProbe;

use Nette\Application\UI\Form;

class ProbeForm extends Form
{

}

class ProbeFactory
{

	public function build(): ProbeForm
	{
		$form = new ProbeForm();
		$form->addText('a');

		return $form;
	}

}
