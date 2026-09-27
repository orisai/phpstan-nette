<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Nette\Application\UI\Form;

final class OpaqueStepForm extends Form
{

	public function addMystery(string $name): UntaggedLookalike
	{
		$control = new UntaggedLookalike();

		return $this[$name] = $control;
	}

}
