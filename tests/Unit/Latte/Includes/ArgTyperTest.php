<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use OriPhpstan\Nette\Latte\Includes\ArgTyper;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class ArgTyperTest extends BaseTestCase
{

	public function testAllNamedArgsHaveNoPositionalArgs(): void
	{
		self::assertFalse((new ArgTyper())->hasPositionalArgs($this->siteWithArgs('x: 1, y: 2')));
	}

	public function testAllArrowNamedArgsHaveNoPositionalArgs(): void
	{
		self::assertFalse((new ArgTyper())->hasPositionalArgs($this->siteWithArgs('x => 1, y => 2')));
	}

	public function testEmptyArgsHaveNoPositionalArgs(): void
	{
		self::assertFalse((new ArgTyper())->hasPositionalArgs($this->siteWithArgs('')));
	}

	public function testExpandOnlyHasNoPositionalArgs(): void
	{
		self::assertFalse((new ArgTyper())->hasPositionalArgs($this->siteWithArgs('(expand) $args')));
	}

	public function testBareVariableIsPositional(): void
	{
		self::assertTrue((new ArgTyper())->hasPositionalArgs($this->siteWithArgs('$x')));
	}

	public function testBareLiteralAfterNamedArgIsStillPositional(): void
	{
		self::assertTrue((new ArgTyper())->hasPositionalArgs($this->siteWithArgs("x: 1, 'literal'")));
	}

	public function testBareFunctionCallLikeExpressionIsPositional(): void
	{
		self::assertTrue((new ArgTyper())->hasPositionalArgs($this->siteWithArgs('someFunc($x)')));
	}

	private function siteWithArgs(string $argsSource): IncludeTarget
	{
		return new IncludeTarget('include', IncludeTarget::KIND_STATIC_BLOCK, 'b', null, $argsSource, 1);
	}

}
