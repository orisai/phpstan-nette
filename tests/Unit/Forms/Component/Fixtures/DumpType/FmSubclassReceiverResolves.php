<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support\FmCrossFileBuilder;
use function PHPStan\dumpType;

/**
 * A form built by a not-analysed control whose createForm() declares the base Form return
 * type but actually builds a Form subclass with a custom add* method. The shared variable
 * name $form makes the on-demand analyser's receiver lookup resolve to the base Form in the
 * caller scope; the precise control still resolves via the scope-free tracked class.
 */
final class FmSubclassReceiverResolves extends Control
{

	public function go(FmCrossFileBuilder $inner, Form $form): void
	{
		$shaped = $inner->createForm();
		dumpType($shaped['action']); // => Nette\Forms\Controls\HiddenField
		dumpType($shaped['rating']); // => Nette\Forms\Controls\TextInput
	}

}
