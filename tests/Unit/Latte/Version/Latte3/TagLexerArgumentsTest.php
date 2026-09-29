<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version\Latte3;

use OriPhpstan\Nette\Latte\Includes\TagArgument;
use OriPhpstan\Nette\Latte\Version\Latte3\TagLexerArguments;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_map;

/**
 * @group latte3
 */
final class TagLexerArgumentsTest extends BaseTestCase
{

	public function testSpreadsNamesAndBareExpressions(): void
	{
		self::assertSame(
			[
				[null, '(expand) $args', true, null, null],
				[null, '...$more', true, null, null],
				['a', '$user', false, 'user', null],
				['b', '1', false, null, TagArgument::LITERAL_INT],
				['c', '1.5', false, null, TagArgument::LITERAL_FLOAT],
				['d', "'str'", false, null, TagArgument::LITERAL_STRING],
				['e', 'TRUE', false, null, TagArgument::LITERAL_BOOL],
				['f', 'null', false, null, TagArgument::LITERAL_NULL],
				['g', '$count + 1', false, null, null],
				[null, "strtoupper('hi')", false, null, null],
				[null, 'f($x, $y)', false, null, null],
				[null, '$bare', false, 'bare', null],
			],
			self::describe(TagLexerArguments::parse(
				"(expand) \$args, ...\$more, a: \$user, b => 1, c: 1.5, d: 'str', e: TRUE, f: null, "
				. "g: \$count + 1, strtoupper('hi'), f(\$x, \$y), \$bare",
			)),
		);
	}

	public function testCommentsAndWhitespaceAreNotArguments(): void
	{
		self::assertSame(
			[['a', '/* c, d */ 1', false, null, TagArgument::LITERAL_INT], [null, '$x', false, 'x', null]],
			self::describe(TagLexerArguments::parse(" a: /* c, d */ 1 ,\n\t\$x , ")),
		);
		self::assertSame([], TagLexerArguments::parse(''));
	}

	/**
	 * @param list<TagArgument> $args
	 * @return list<array{string|null, string, bool, string|null, string|null}>
	 */
	private static function describe(array $args): array
	{
		return array_map(
			static fn (TagArgument $arg): array => [
				$arg->getName(),
				$arg->getSource(),
				$arg->isSpread(),
				$arg->getVariable(),
				$arg->getLiteralType(),
			],
			$args,
		);
	}

}
