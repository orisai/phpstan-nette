<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use OriPhpstan\Nette\Latte\Includes\ArgTyper;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use function array_map;
use function str_replace;

// Through the installed adapter, so the same argument grammar is pinned on every Latte line.
final class ArgTyperTest extends BaseTestCase
{

	public function testAllNamedArgsHaveNoPositionalArgs(): void
	{
		self::assertFalse(self::typer()->hasPositionalArgs($this->siteWithArgs('x: 1, y: 2')));
	}

	public function testAllArrowNamedArgsHaveNoPositionalArgs(): void
	{
		self::assertFalse(self::typer()->hasPositionalArgs($this->siteWithArgs('x => 1, y => 2')));
	}

	public function testEmptyArgsHaveNoPositionalArgs(): void
	{
		self::assertFalse(self::typer()->hasPositionalArgs($this->siteWithArgs('')));
	}

	public function testExpandOnlyHasNoPositionalArgs(): void
	{
		self::assertFalse(self::typer()->hasPositionalArgs($this->siteWithArgs('(expand) $args')));
	}

	public function testBareVariableIsPositional(): void
	{
		self::assertTrue(self::typer()->hasPositionalArgs($this->siteWithArgs('$x')));
	}

	public function testBareLiteralAfterNamedArgIsStillPositional(): void
	{
		self::assertTrue(self::typer()->hasPositionalArgs($this->siteWithArgs("x: 1, 'literal'")));
	}

	public function testBareFunctionCallLikeExpressionIsPositional(): void
	{
		self::assertTrue(self::typer()->hasPositionalArgs($this->siteWithArgs('someFunc($x)')));
	}

	public function testNamedArgsAreTypedFromLiteralsAndContextVariables(): void
	{
		$context = TemplateContext::root(['user' => 'App\User', 'count' => 'int']);
		$site = $this->siteWithArgs(
			"a: \$user, b => 1, c: 1.5, d: 'str', e: true, f: FALSE, g: null, h: \$count + 1, i: \$missing, j: [1, 2]",
		);

		self::assertSame(
			[
				'vars' => [
					'a' => 'App\User',
					'b' => 'int',
					'c' => 'float',
					'd' => 'string',
					'e' => 'bool',
					'f' => 'bool',
					'g' => 'null',
					'h' => 'mixed',
					'i' => 'mixed',
					'j' => 'mixed',
				],
				'open' => false,
			],
			self::typer()->typeArgs($site, $context),
		);
	}

	public function testSpreadOpensTheArgListAndIsNeverASource(): void
	{
		$site = $this->siteWithArgs('x: 1, (expand) $args, $y');

		self::assertSame(
			['vars' => ['x' => 'int'], 'open' => true],
			self::typer()->typeArgs($site, TemplateContext::root([])),
		);
		self::assertSame(['1', '$y'], self::typer()->argSources($site));
		self::assertSame([['x', '1']], self::typer()->namedArgSources($site));
	}

	public function testArgSourcesKeepCallOrderAndStripNamePrefixes(): void
	{
		$site = $this->siteWithArgs("strtoupper('hi'), name: \$order->getItem(), 'A' . 'B', key => [1, 2]");

		self::assertSame(
			['strtoupper (\'hi\')', '$order->getItem()', '\'A\' . \'B\'', '[1, 2]'],
			array_map(
				static fn (string $source): string => str_replace('strtoupper(', 'strtoupper (', $source),
				self::typer()->argSources($site),
			),
		);
		self::assertSame(
			[['name', '$order->getItem()'], ['key', '[1, 2]']],
			self::typer()->namedArgSources($site),
		);
	}

	public function testCommaInsideBracketsDoesNotSplitAnArgument(): void
	{
		$site = $this->siteWithArgs('a: f($x, $y), b: [1, 2], c: "s, t"');

		self::assertSame(
			[['a', 'f($x, $y)'], ['b', '[1, 2]'], ['c', '"s, t"']],
			self::typer()->namedArgSources($site),
		);
	}

	private static function typer(): ArgTyper
	{
		return new ArgTyper(TestAdapter::accessor());
	}

	private function siteWithArgs(string $argsSource): IncludeTarget
	{
		return new IncludeTarget('include', IncludeTarget::KIND_STATIC_BLOCK, 'b', null, $argsSource, 1);
	}

}
