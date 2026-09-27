<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use Nette\Forms\Controls\BaseControl;
use Nette\Utils\ArrayHash;
use function PHPStan\dumpType;

/**
 * @form-read-type string
 * @form-write-type string
 */
final class ModeControl extends BaseControl
{

	public const ModeInt = 'int';

	public const ModeJson = 'json';

	/** @form-read-by-arg Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\ModeControl::ModeInt=int; Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\ModeControl::ModeJson=array<string, mixed>; *=string */
	public function withMode(string $mode): self
	{
		return $this;
	}

}

final class ModeForm extends RawForm
{

	public function addMode(string $name): ModeControl
	{
		$control = new ModeControl();
		$this[$name] = $control;

		return $control;
	}

}

final class CtReadByArgCustomControl extends Control
{

	private string $dynamicMode = 'x';

	protected function createComponentForm(): ModeForm
	{
		$form = new ModeForm();
		$form->addMode('asInt')->withMode(ModeControl::ModeInt);
		$form->addMode('asJson')->withMode(ModeControl::ModeJson);
		$form->addMode('asFallback')->withMode('whatever');
		$form->addMode('asIntReq')->withMode(ModeControl::ModeInt)->setRequired();
		$form->addMode('dyn')->withMode($this->dynamicMode);

		$form->onSuccess[] = function (ModeForm $form, ArrayHash $values): void {
			dumpType($values); // => Nette\Utils\ArrayHash{asInt: int|null, asJson: array<string, mixed>|null, asFallback: string|null, asIntReq: int, dyn: mixed}
		};

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{asInt: int|null, asJson: array<string, mixed>|null, asFallback: string|null, asIntReq: int|null, dyn: mixed}
	}

}
