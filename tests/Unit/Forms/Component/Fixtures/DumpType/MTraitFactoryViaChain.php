<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MTraitFactoryViaChainForm extends RawForm
{

}

trait MFactoryBuildTrait
{

	public function build(): MTraitFactoryViaChainForm
	{
		$form = new MTraitFactoryViaChainForm();
		$form->addText('fromFactoryTrait')->setRequired();

		return $form;
	}

}

final class MTraitFactoryViaChainBuilder
{

	use MFactoryBuildTrait;

}

final class MTraitFactoryViaChain extends Control
{

	private MTraitFactoryViaChainBuilder $builder;

	public function __construct(MTraitFactoryViaChainBuilder $builder)
	{
		parent::__construct();
		$this->builder = $builder;
	}

	protected function createComponentForm(): MTraitFactoryViaChainForm
	{
		$form = $this->builder->build();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(MTraitFactoryViaChainForm $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{fromFactoryTrait: non-empty-string}
	}

}
