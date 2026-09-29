<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version\Latte3;

use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Version\ExtractedFacts;
use OriPhpstan\Nette\Latte\Version\Latte3\Latte3Adapter;
use OriPhpstan\Nette\Latte\Version\Latte3\Latte3Compiler;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;

// Where the Latte 3 facts deliberately differ from the Latte 2 token scanner's: each case follows
// what Latte 3 itself parses, so no shared parity fixture can hold it.
/**
 * @group latte3
 */
final class Latte3FactsDivergenceTest extends BaseTestCase
{

	// Latte 2 classifies the raw word (quotes mean a file); Latte 3's EmbedNode classifies the
	// dequoted value, and a word-like one really embeds a block at runtime.
	public function testQuotedWordLikeEmbedTargetIsABlock(): void
	{
		[$site] = $this->facts("{embed 'sub'}{/embed}\n")->getTemplateFacts()->getIncludeSites();

		self::assertSame('embed', $site->getTag());
		self::assertSame(IncludeTarget::KIND_STATIC_BLOCK, $site->getKind());
		self::assertSame('sub', $site->getRawTarget());
		self::assertNull($site->getResolvedPath());
	}

	// `{embed #x}` and `{include Foo::BAR}` were suspected to diverge; both come out as the same
	// static file target Latte 2 records.
	public function testHashPrefixedEmbedAndClassConstantIncludeStayStaticFiles(): void
	{
		[$embed] = $this->facts("{embed #x}{/embed}\n")->getTemplateFacts()->getIncludeSites();
		[$include] = $this->facts("{include Foo::BAR}\n")->getTemplateFacts()->getIncludeSites();

		self::assertSame([IncludeTarget::KIND_STATIC_FILE, '#x', 'version/#x'], $this->target($embed));
		self::assertSame([IncludeTarget::KIND_STATIC_FILE, 'Foo::BAR', 'version/Foo::BAR'], $this->target($include));
	}

	// Latte 2 sees two tokens and gives up (`mixed`); the parentheses are gone from Latte 3's AST.
	public function testParenthesizedLiteralVarIsTypedFromTheLiteral(): void
	{
		self::assertSame(
			['x' => 'int'],
			$this->facts("{var \$x = (1)}\n{\$x}\n")->getTemplateFacts()->getTopLevelVars(),
		);
	}

	// Latte 2's type-prefix scan cannot read `<` and degrades the whole scan to empty Declarations.
	public function testGenericTypesInDefineParamsAndVarsAreRecorded(): void
	{
		$declarations = $this->facts(
			"{define d, array<int, string> \$items}{\$items}{/define}\n{var array<int, string> \$list = []}\n",
		)->getDeclarations();

		self::assertSame(['d' => [['array<int,string>', 'items']]], $declarations->getDefineParams());
		self::assertSame([['list', 'array<int,string>', 2]], $declarations->getTypedVars());
	}

	private function facts(string $source): ExtractedFacts
	{
		return (new Latte3Adapter(new Latte3Compiler(), TestAdapter::factory()->family()))
			->extractFacts($source, 'version/divergence.latte');
	}

	/**
	 * @return array{string, string, string|null}
	 */
	private function target(IncludeTarget $site): array
	{
		return [$site->getKind(), $site->getRawTarget(), $site->getResolvedPath()];
	}

}
