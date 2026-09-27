<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

/**
 * The outer factory is resolvable, but ITS form comes from an inner builder returning a
 * property fetch: the resolved factory shape itself carries the rebind marker, which must
 * propagate through the compensation instead of being stripped with the outer marker.
 */
class InnerBuilder_MTwoHopFactory
{

	private ApplicationForm $prebuilt;

	public function make(): ApplicationForm
	{
		return $this->prebuilt;
	}

}

class OuterFactory_MTwoHopFactory
{

	private InnerBuilder_MTwoHopFactory $builder;

	public function create(): ApplicationForm
	{
		$form = $this->builder->make();
		$form->addText('inner');

		return $form;
	}

}

final class MTwoHopFactoryInnerUnresolvableStaysOpen extends Control
{

	private OuterFactory_MTwoHopFactory $factory;

	protected function createComponentForm(): ApplicationForm
	{
		$form = $this->factory->create();
		$form->addText('local');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{local: string, inner: string, ...<mixed>}
	}

}
