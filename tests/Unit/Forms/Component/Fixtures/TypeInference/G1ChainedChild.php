<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\TypeInference;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Forms\Container;
use function PHPStan\Testing\assertType;

final class G1ChainedChild extends Control
{

	protected function createComponentSignInForm(): Form
	{
		$form = new Form();
		$form->addText('username');

		return $form;
	}

	public function render(Container $external): void
	{
		assertType('Nette\Application\UI\Form', $this['signInForm']);
		assertType('Nette\Forms\Controls\TextInput', $this['signInForm']['username']);
		assertType('Nette\ComponentModel\IComponent', $external['whatever']);
	}

}
