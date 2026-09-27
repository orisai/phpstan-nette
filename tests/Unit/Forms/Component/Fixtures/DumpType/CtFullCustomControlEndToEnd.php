<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use Nette\Forms\Controls\BaseControl;
use function PHPStan\dumpType;

/**
 * @form-read-type 'a'|'b'|'c'
 * @form-write-type 'a'|'b'|'c'
 */
final class EndToEndRating extends BaseControl
{

	/** @form-modifier nullable */
	public function asNullable(): self
	{
		return $this;
	}

}

final class EndToEndForm extends RawForm
{

	public function addRating(string $name): EndToEndRating
	{
		$control = new EndToEndRating();
		$this[$name] = $control;

		return $control;
	}

}

final class CtFullCustomControlEndToEnd extends Control
{

	protected function createComponentForm(): EndToEndForm
	{
		$form = new EndToEndForm();
		$form->addRating('viaMethod')->setRequired();
		$form['viaOffset'] = new EndToEndRating();
		$form->addRating('viaModifier')->asNullable();

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['viaMethod']); // => Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\EndToEndRating
		dumpType($this['form']['viaOffset']); // => Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\EndToEndRating
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{viaMethod: 'a'|'b'|'c', viaOffset: 'a'|'b'|'c', viaModifier: 'a'|'b'|'c'|null}
	}

}
