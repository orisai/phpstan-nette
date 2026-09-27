<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use Nette\Application\UI\Form;

class FactoryBuiltForm extends Form
{

	public function __construct()
	{
		parent::__construct();
		$this->addText('fromFactory');
	}

}
