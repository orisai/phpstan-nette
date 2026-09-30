<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Declarations;

use OriPhpstan\Nette\Latte\Version\Latte2\DeclarationScanner;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

/**
 * @group latte2
 */
final class DeclarationScannerTest extends BaseTestCase
{

	public function testScansHeaderDeclarations(): void
	{
		$source = "{templateType Foo\\BarTemplate}\n{varType int|null \$count}\n{varType array<string> \$tags}\nbody {\$count}\n";
		$declarations = (new DeclarationScanner())->scan($source);

		self::assertSame('Foo\\BarTemplate', $declarations->getTemplateTypeClass());
		self::assertSame(['count' => 'int|null', 'tags' => 'array<string>'], $declarations->getHeaderVarTypes());
	}

	public function testMidFileVarTypeIsPositional(): void
	{
		$source = "{\$a}\n{varType string \$a}\n{\$a}\n";
		$declarations = (new DeclarationScanner())->scan($source);

		self::assertSame([], $declarations->getHeaderVarTypes());
		self::assertSame([['a', 'string', 2]], $declarations->getMidFileVarTypes());
	}

	public function testScansTypedVarAndDefault(): void
	{
		$source = "{var int \$x = 1, \$y = 2}\n{default bool \$flag = false}\n";
		$declarations = (new DeclarationScanner())->scan($source);

		self::assertSame([['x', 'int', 1]], $declarations->getTypedVars());
		self::assertSame([['flag', 'bool', 2]], $declarations->getTypedDefaults());
	}

	public function testScansParameters(): void
	{
		$source = "{parameters int \$a, string \$b = 'x', \$c}\n";
		$parameters = (new DeclarationScanner())->scan($source)->getParameters();

		self::assertSame([
			['int', 'a', null, 1],
			['string', 'b', "'x'", 1],
			[null, 'c', null, 1],
		], $parameters);
	}

	public function testScansDefineParams(): void
	{
		$source = "{define row, string \$label, \$value}x{/define}\n";
		self::assertSame(
			['row' => [['string', 'label'], [null, 'value']]],
			(new DeclarationScanner())->scan($source)->getDefineParams(),
		);
	}

	public function testScansDefineParamDefaults(): void
	{
		$source = "{define row, string \$label, \$value = 'x', int \$count}x{/define}\n";
		$declarations = (new DeclarationScanner())->scan($source);

		self::assertSame(
			['row' => [['string', 'label'], [null, 'value'], ['int', 'count']]],
			$declarations->getDefineParams(),
		);
		self::assertSame(['row' => ['value' => true]], $declarations->getDefineParamDefaults());
	}

	public function testDefineWithNoDefaultsYieldsEmptyDefaultsSet(): void
	{
		$source = "{define row, string \$label, \$value}x{/define}\n";
		$declarations = (new DeclarationScanner())->scan($source);

		self::assertSame([], $declarations->getDefineParamDefaults()['row'] ?? []);
	}

	public function testHeaderEndsAtFirstNonHeadContent(): void
	{
		$source = "text\n{varType string \$late}\n";
		$declarations = (new DeclarationScanner())->scan($source);

		self::assertSame([], $declarations->getHeaderVarTypes());
		self::assertSame([['late', 'string', 2]], $declarations->getMidFileVarTypes());
	}

	public function testUnknownSyntaxTagDoesNotThrow(): void
	{
		$source = "{syntax bogus}\n{\$x}\n";
		$declarations = (new DeclarationScanner())->scan($source);

		self::assertNull($declarations->getTemplateTypeClass());
		self::assertNull($declarations->getTemplateTypeLine());
		self::assertSame([], $declarations->getHeaderVarTypes());
		self::assertSame([], $declarations->getMidFileVarTypes());
		self::assertSame([], $declarations->getTypedVars());
		self::assertSame([], $declarations->getTypedDefaults());
		self::assertNull($declarations->getParameters());
		self::assertSame([], $declarations->getDefineParams());
	}

	public function testScansLocalDefineParams(): void
	{
		$source = "{define local row, string \$label}x{/define}\n";
		self::assertSame(
			['row' => [['string', 'label']]],
			(new DeclarationScanner())->scan($source)->getDefineParams(),
		);
	}

	public function testMalformedParametersDegradesToEmptyInsteadOfThrowing(): void
	{
		$source = "{parameters int}\n";
		$declarations = (new DeclarationScanner())->scan($source);

		self::assertNull($declarations->getTemplateTypeClass());
		self::assertNull($declarations->getParameters());
		self::assertSame([], $declarations->getDefineParams());
	}

	public function testMalformedDefineDegradesToEmptyInsteadOfThrowing(): void
	{
		$source = "{define foo int}x{/define}\n";
		$declarations = (new DeclarationScanner())->scan($source);

		self::assertSame([], $declarations->getDefineParams());
	}

	public function testScansPlainBlockNameWithEmptyParamListLikeAParamlessDefine(): void
	{
		// {block} never carries params (BlockMacros rejects them at compile time), so this must
		// always land as an empty own-param list - the point is that the NAME itself now joins
		// getDefineParams() too, needed so DeclarationInjector's blockNameMap/defineMethodMap
		// (and therefore capturedBlockArgTypes()/extraBlockArgTypes()) treat a plain {block} target
		// the same way they already treat a {define} one.
		$source = "{block row}x{/block}\n";

		self::assertSame(
			['row' => []],
			(new DeclarationScanner())->scan($source)->getDefineParams(),
		);
	}

	public function testScanMemoizesRepeatCallsForSameSource(): void
	{
		$scanner = new DeclarationScanner();
		$source = "{varType string \$x}\n";

		$first = $scanner->scan($source);
		$second = $scanner->scan($source);

		self::assertSame($first, $second);
	}

	public function testScanMemoDoesNotLeakAcrossDifferentSources(): void
	{
		$scanner = new DeclarationScanner();

		$a = $scanner->scan("{varType string \$x}\n");
		$b = $scanner->scan("{varType int \$y}\n");

		self::assertNotSame($a, $b);
		self::assertSame(['x' => 'string'], $a->getHeaderVarTypes());
		self::assertSame(['y' => 'int'], $b->getHeaderVarTypes());
	}

}
