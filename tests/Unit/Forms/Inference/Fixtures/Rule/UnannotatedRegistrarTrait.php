<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\Forms\Controls\TextInput;

/**
 * A generic adder written once and composed into several containers, which is how this repo's own
 * shared container methods are written. PHPStan analyses a trait body once per using class, so the
 * report names the TRAIT — the declaration a tag would be written on — rather than the class the body
 * happens to be analysed in the context of.
 */
trait UnannotatedRegistrarTrait
{

	public function addShared(string $name): TextInput
	{
		return $this->addText($name);
	}

}
