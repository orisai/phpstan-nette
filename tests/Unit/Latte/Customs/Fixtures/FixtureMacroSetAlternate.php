<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

use Latte\Compiler;
use Latte\Macros\MacroSet;

final class FixtureMacroSetAlternate extends MacroSet
{

	public static function install(Compiler $compiler): self
	{
		$me = new self($compiler);
		$me->addMacro('fixtureMacro', '/* fixtureMacroAlternate */', '/* /fixtureMacroAlternate */');

		return $me;
	}

}
