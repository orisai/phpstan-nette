<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

trait MFormBuilderAsAliasTrait
{

	public function build(): MTraitFactoryAsAliasForm
	{
		$form = new MTraitFactoryAsAliasForm();
		$form->addText('fromAlias')->setRequired();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

}

final class MTraitFactoryAsAliasForm extends RawForm
{

}

final class MTraitFactoryAsAlias extends Control
{

	use MFormBuilderAsAliasTrait {
		build as createComponentForm;
	}

	public function process(MTraitFactoryAsAliasForm $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{fromAlias: string}
	}

}
