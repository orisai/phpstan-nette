<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version\Latte3;

use OriPhpstan\Nette\Latte\Compile\CompileResult;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Version\Latte3\Latte3Compiler;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use function array_map;
use function array_values;

/**
 * @group latte3
 */
final class Latte3CompileTest extends BaseTestCase
{

	public function testCoreTagsCompileToTheNamedTemplateClass(): void
	{
		$result = $this->compile(
			"{templateType Foo}\n{parameters string \$name}\n{varType bool \$show}\n{if \$show}<b>{\$name}</b>{else}{foreach [1] as \$i}{\$i}{/foreach}{/if}\n{block main}{include 'x.latte'}{/block}\n",
		);

		self::assertSame([], $result->getDiagnostics());
		self::assertSame('LatteTpl_test', $result->getClassName());
		$code = $result->getPhpSource();
		self::assertNotNull($code);
		self::assertStringContainsString('final class LatteTpl_test extends Latte\Runtime\Template', $code);
		self::assertStringContainsString('public function main(array $ʟ_args): void', $code);
		self::assertStringContainsString('/** source: fixtures/test.latte */', $code);
		self::assertStringContainsString('if ($show)', $code);
		self::assertStringNotContainsString('Template_', $code);
	}

	public function testUnknownTagAndAttributeArePassedThroughWithOneDiagnosticPerName(): void
	{
		$result = $this->compile(
			"a\n{foo \$x, 1}\n<p n:foo=\"\$y\" n:inner-bar=\"2\">{\$inner}</p>\n{baz \$q}{\$paired}{/baz}\n{qux /}\n",
		);

		$code = $result->getPhpSource();
		self::assertNotNull($code);
		self::assertSame(
			[
				['orisaiNette.latte.unknownMacro', "Unknown Latte macro or attribute 'foo'.", 2],
				['orisaiNette.latte.unknownMacro', "Unknown Latte macro or attribute 'bar'.", 3],
				['orisaiNette.latte.unknownMacro', "Unknown Latte macro or attribute 'baz'.", 4],
				['orisaiNette.latte.unknownMacro', "Unknown Latte macro or attribute 'qux'.", 5],
			],
			self::describe($result->getDiagnostics()),
		);
		self::assertStringContainsString('($inner)', $code);
		self::assertStringContainsString('($paired)', $code);
		self::assertStringContainsString('<p>', $code);
		self::assertStringNotContainsString('foo', $code);
	}

	public function testUnknownPairedTagWithMixedNestingFallsBackToParseError(): void
	{
		// {foo} is claimed paired because a {/foo} exists, so the unpaired occurrence inside {if}
		// swallows {/if}: Latte's own message is reported rather than a silent acceptance.
		$result = $this->compile("{if \$a}{foo}{/if}\n{foo}x{/foo}\n");

		self::assertNull($result->getPhpSource());
		self::assertSame(
			[['orisaiNette.latte.parseError', 'Unexpected {/if}, expecting {/foo} (on line 1 at column 13)', 1]],
			self::describe($result->getDiagnostics()),
		);
	}

	public function testLatte2OnlyTagIsReportedAsParseError(): void
	{
		$result = $this->compile("{var \$a = 1}\n{includeblock 'x.latte'}\n");

		self::assertNull($result->getPhpSource());
		self::assertSame('LatteTpl_test', $result->getClassName());
		self::assertSame(
			[['orisaiNette.latte.parseError', 'Unexpected tag {includeblock} (on line 2 at column 1)', 2]],
			self::describe($result->getDiagnostics()),
		);
	}

	public function testMalformedExpressionIsAParseErrorAtItsLine(): void
	{
		$result = $this->compile("<p>\n{if \$a ==}x{/if}\n");

		self::assertNull($result->getPhpSource());
		self::assertSame(
			[['orisaiNette.latte.parseError', 'Unexpected end (on line 2 at column 10)', 2]],
			self::describe($result->getDiagnostics()),
		);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function provideMisplacedKnownNames(): iterable
	{
		yield 'intermediate tag outside its pair' => ["{else}\n", 'Unexpected tag {else} (on line 1 at column 1)'];
		yield 'intermediate tag after its last place' => [
			"{if 1}{else}{elseif \$a}{/if}\n",
			'Unexpected tag {elseif} (on line 1 at column 13)',
		];

		yield 'case outside switch' => ["{case}\n", 'Unexpected tag {case} (on line 1 at column 1)'];
		yield 'attribute-only name as a tag' => ["<html>{ifcontent}\n", 'Unexpected tag {ifcontent} (on line 1 at column 7)'];
		yield 'known attribute with a prefix it does not support' => [
			"<form n:inner-name></form>\n",
			'Unexpected attribute n:inner-name, did you mean n:inner-label? (on line 1 at column 7)',
		];

		yield 'brace in a script' => [
			"<script>if (true) {return}</script>\n",
			'Unexpected tag {return} (in JavaScript or CSS, try to put a space after bracket or use n:syntax=off) (on line 1 at column 19)',
		];
	}

	/**
	 * @dataProvider provideMisplacedKnownNames
	 */
	public function testMisplacedKnownNameIsAParseErrorNotAPassthrough(string $source, string $message): void
	{
		$result = $this->compile($source);

		self::assertNull($result->getPhpSource());
		self::assertSame(
			[['orisaiNette.latte.parseError', $message, 1]],
			self::describe($result->getDiagnostics()),
		);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function provideIntermediateTagsInsideUnknownPairs(): iterable
	{
		yield 'else' => ["{ifAllowed \$x}\na\n{else}\nb\n{/ifAllowed}\n"];

		yield 'elseif and else' => ["{ifAllowed \$x}a{elseif \$y}b{else}c{/ifAllowed}\n"];

		yield 'nested pair of the same name' => ["{ifAllowed \$x}{ifAllowed \$y}a{/ifAllowed}{else}b{/ifAllowed}\n"];

		yield 'if owns its else inside the pair' => ["{ifAllowed \$x}{if \$a}a{else}b{/if}{/ifAllowed}\n"];

		yield 'generic closer' => ["{ifAllowed \$x}a{else}b{/}\n"];

		yield 'if with its own else after the pair' => ["{ifAllowed \$x}a{else}b{/ifAllowed}{if \$c}x{else}y{/if}\n"];
	}

	/**
	 * @dataProvider provideIntermediateTagsInsideUnknownPairs
	 */
	public function testIntermediateTagInsideAnUnknownPairIsPassedThrough(string $source): void
	{
		$result = $this->compile($source);

		self::assertNotNull($result->getPhpSource());
		self::assertMatchesRegularExpression("~echo '[^']*b~", $result->getPhpSource());
		self::assertSame(
			[['orisaiNette.latte.unknownMacro', "Unknown Latte macro or attribute 'ifAllowed'.", 1]],
			self::describe($result->getDiagnostics()),
		);
	}

	/**
	 * @return iterable<string, array{string, string, int}>
	 */
	public static function provideMisplacedIntermediateTags(): iterable
	{
		yield 'between unknown pairs' => [
			"{ifAllowed \$x}a{/ifAllowed}\n{else}\n{ifAllowed \$y}b{/ifAllowed}\n",
			'Unexpected tag {else} (on line 2 at column 1)',
			2,
		];

		yield 'after an unknown pair that had one' => [
			"{ifAllowed \$x}a{else}b{/ifAllowed}\n{else}\n",
			'Unexpected tag {else} (on line 2 at column 1)',
			2,
		];

		yield 'inside a known tag inside an unknown pair' => [
			"{ifAllowed \$x}{block foo}a{else}b{/block}{/ifAllowed}\n",
			'Unexpected tag {else} (on line 1 at column 27)',
			1,
		];
	}

	/**
	 * @dataProvider provideMisplacedIntermediateTags
	 */
	public function testMisplacedIntermediateTagIsAParseError(string $source, string $message, int $line): void
	{
		$result = $this->compile($source);

		self::assertNull($result->getPhpSource());
		self::assertSame(
			[['orisaiNette.latte.parseError', $message, $line]],
			self::describe($result->getDiagnostics()),
		);
	}

	public function testUnknownNameUsedAsTagAndAttributeIsPassedThroughBoth(): void
	{
		$result = $this->compile("<p n:foo=\"\$a\">x</p>\n{foo \$b}y{/foo}\n");

		self::assertNotNull($result->getPhpSource());
		self::assertSame(
			[['orisaiNette.latte.unknownMacro', "Unknown Latte macro or attribute 'foo'.", 1]],
			self::describe($result->getDiagnostics()),
		);
	}

	public function testUnknownTagClosedByTheGenericClosingTagIsPaired(): void
	{
		$result = $this->compile("{foo \$a}\n{\$inner}\n{/}\n");

		self::assertNotNull($result->getPhpSource());
		self::assertStringContainsString('($inner)', $result->getPhpSource());
		self::assertSame(
			[['orisaiNette.latte.unknownMacro', "Unknown Latte macro or attribute 'foo'.", 1]],
			self::describe($result->getDiagnostics()),
		);
	}

	public function testThrowableFromAVendorTagParserIsAParseErrorAtItsTag(): void
	{
		$result = $this->compile("a\n\n{snippet UnknownClass::Name}x{/snippet}\n");

		self::assertNull($result->getPhpSource());
		self::assertSame(
			[['orisaiNette.latte.parseError', "Thrown exception 'Class \"UnknownClass\" not found'", 3]],
			self::describe($result->getDiagnostics()),
		);
	}

	public function testClosingTagWithoutOpenerIsAParseError(): void
	{
		$result = $this->compile("x\n{/foo}\n");

		self::assertNull($result->getPhpSource());
		self::assertSame(
			[['orisaiNette.latte.parseError', "Unexpected '{' (on line 2 at column 1)", 2]],
			self::describe($result->getDiagnostics()),
		);
	}

	public function testTypeTagsAreCapturedWithTheirSourceSpelling(): void
	{
		$parsed = (new Latte3Compiler())->parse(
			"{templateType \\App\\Foo\\Bar}\n{parameters  array<int, string> \$a = ['x' => 1, 'y' => [2, 3]], ?int \$b, \$c = null, \$d}\n{varType  array<int, string>  \$x}\n{var \$q = 1}\n{varType ?int \$late}\nhello\n{varType string|null \$mid}\n",
		);

		self::assertNull($parsed->getFailure());
		self::assertSame(
			[
				['templateType', 'App\Foo\Bar', null, null, 1],
				['parameter', 'array<int, string>', 'a', "['x' => 1, 'y' => [2, 3]]", 2],
				['parameter', '?int', 'b', null, 2],
				['parameter', null, 'c', 'null', 2],
				['parameter', null, 'd', null, 2],
				['varType', 'array<int, string>', 'x', null, 3],
				['varType', '?int', 'late', null, 5],
				['varType', 'string|null', 'mid', null, 7],
			],
			array_map(
				static fn ($d): array => [$d->getKind(), $d->getType(), $d->getVariable(), $d->getDefault(), $d->getLine()],
				$parsed->getDeclarations(),
			),
		);
	}

	public function testTypeTagsGenerateTheSameCodeAsLatteItself(): void
	{
		$result = $this->compile(
			"{templateType Foo}\n{parameters string \$name, int \$age = 18}\n{varType bool \$show}\nHello {\$name}\n",
		);

		$code = $result->getPhpSource();
		self::assertNotNull($code);
		self::assertSame([], $result->getDiagnostics());
		self::assertStringContainsString("\$name = \$this->params[0] ?? \$this->params['name'] ?? null;", $code);
		self::assertStringContainsString("\$age = \$this->params[1] ?? \$this->params['age'] ?? 18;", $code);
		self::assertStringNotContainsString('templateType', $code);
		self::assertStringNotContainsString('varType', $code);
	}

	public function testMisplacedTemplateTypeKeepsLattesOwnError(): void
	{
		$result = $this->compile("x\n{templateType Foo}\n");

		self::assertNull($result->getPhpSource());
		self::assertSame(
			[['orisaiNette.latte.parseError', '{templateType} is allowed only in template header (on line 2 at column 1)', 2]],
			self::describe($result->getDiagnostics()),
		);
	}

	public function testVendorDeprecationBecomesADiagnosticAtItsLine(): void
	{
		InstalledVersionsGuard::requireNetteLine('nette/forms', '^3.3');

		$result = $this->compile("\n{form f class => 'ajax'}{input x}{/form}\n");

		self::assertNotNull($result->getPhpSource());
		self::assertSame(
			[['orisaiNette.latte.deprecated', 'Missing comma before arguments in {form} tag on line 2 at column 9.', 2]],
			self::describe($result->getDiagnostics()),
		);
	}

	public function testBridgeTagsCompile(): void
	{
		$result = $this->compile(
			"{snippet s}{control c}{link this}{/snippet}\n{form f}{input x}{label x /}{/form}\n{cache \$k, expire => '1 hour'}c{/cache}\n",
		);

		self::assertNotNull($result->getPhpSource());
		self::assertSame([], $result->getDiagnostics());
	}

	public function testLinkBaseRewritesDestinationsAtCompileTime(): void
	{
		$php = (string) $this->compile(
			"{linkBase Admin:}\n<a n:href=\"Foo:bar 1\">x</a>\n{link Foo:bar}\n{plink this}\n",
		)->getPhpSource();

		self::assertStringContainsString("\$this->global->uiControl->link(':Admin::Foo:bar', [1])", $php);
		self::assertStringContainsString("\$this->global->uiControl->link(':Admin::Foo:bar')", $php);
		self::assertStringContainsString("\$this->global->uiPresenter->link('this')", $php);
		self::assertStringNotContainsString('linkBase', $php);
	}

	public function testCacheTagKeyIsDeterministic(): void
	{
		$source = "{varType string \$k}\n{cache \$k, expire => '1 hour'}c{\$k}{/cache}\n{cache 'x'}d{/cache}\n";

		$first = (string) $this->compile($source)->getPhpSource();
		$second = (string) $this->compile($source)->getPhpSource();

		self::assertSame($first, $second);
		self::assertStringContainsString(
			"\$this->global->cache->createCache('latte-analysis-cache-2:1', [\$k, 'expire' => '1 hour'])",
			$first,
		);
		self::assertStringContainsString("createCache('latte-analysis-cache-3:1', ['x'])", $first);
		self::assertStringContainsString('$this->global->cache->end()', $first);
		self::assertStringContainsString('$this->global->cache->rollback();', $first);
		self::assertStringContainsString('$this->global->cache->initialize($this);', $first);
	}

	private function compile(string $source): CompileResult
	{
		$compiler = new Latte3Compiler();

		return $compiler->generate($compiler->parse($source), 'LatteTpl_test', 'fixtures/test.latte');
	}

	/**
	 * @param array<Diagnostic> $diagnostics
	 * @return list<array{string, string, int}>
	 */
	private static function describe(array $diagnostics): array
	{
		return array_values(array_map(
			static fn (Diagnostic $d): array => [$d->getIdentifier(), $d->getMessage(), $d->getLatteLine()],
			$diagnostics,
		));
	}

}
