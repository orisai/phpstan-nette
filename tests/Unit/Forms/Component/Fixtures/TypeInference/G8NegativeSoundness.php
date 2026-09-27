<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\TypeInference;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Forms\Container;
use function PHPStan\Testing\assertType;

final class G8NegativeSoundness extends Control
{

	protected function createComponentKnownForm(): Form
	{
		$form = new Form();
		$form->addText('a');

		return $form;
	}

	public function dynamicOffset(string $name): void
	{
		assertType('mixed~null', $this[$name]);
	}

	public function unknownChildOfKnownForm(): void
	{
		assertType('Nette\ComponentModel\IComponent', $this['knownForm']['noSuchField']);
	}

	public function viaContainerParam(Container $external): void
	{
		assertType('Nette\ComponentModel\IComponent', $external['anything']);
	}

}
