<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\UnresolvableFactory;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

/**
 * create() returns a property fetch, which the remote-factory resolution cannot follow (only a
 * fresh `new X()` or a local variable is trusted): the rebind compensation must not clear the
 * marker on an unresolved factory, so the shape stays open despite the post-rebind local add.
 */
final class MFactoryAssignUnresolvableStaysOpen extends Control
{

	private UnresolvableFactory $factory;

	protected function createComponentForm(): ApplicationForm
	{
		$form = $this->factory->create();
		$form->addText('local');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{local: string, ...<mixed>}
	}

}
