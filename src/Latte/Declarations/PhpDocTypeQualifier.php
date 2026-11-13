<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Declarations;

use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use function assert;
use function is_int;

final class PhpDocTypeQualifier
{

	private static ?Lexer $lexer = null;

	private static ?TypeParser $typeParser = null;

	private function __construct()
	{
	}

	public static function parse(string $type): ?TypeNode
	{
		return self::parseTokens(self::lexer()->tokenize($type));
	}

	public static function qualify(string $type, ClassNameScope $scope): string
	{
		$tokens = self::lexer()->tokenize($type);
		$node = self::parseTokens($tokens);
		if ($node === null) {
			return $type;
		}

		$end = $node->getAttribute(Attribute::END_INDEX);
		if (!is_int($end)) {
			return $type;
		}

		$visitor = new QualifyingTypeVisitor($scope);
		(new NodeTraverser([$visitor]))->traverse([$node]);
		$replacements = $visitor->getReplacements();

		$qualified = '';
		for ($i = 0; $i <= $end; $i++) {
			$qualified .= $replacements[$i] ?? $tokens[$i][Lexer::VALUE_OFFSET];
		}

		return $qualified;
	}

	private static function lexer(): Lexer
	{
		if (self::$lexer === null || self::$typeParser === null) {
			$config = new ParserConfig(['indexes' => true]);
			self::$lexer = new Lexer($config);
			self::$typeParser = new TypeParser($config, new ConstExprParser($config));
		}

		return self::$lexer;
	}

	/**
	 * @param list<array{string, int, int}> $tokens
	 */
	private static function parseTokens(array $tokens): ?TypeNode
	{
		self::lexer();
		assert(self::$typeParser !== null);
		try {
			return self::$typeParser->parse(new TokenIterator($tokens));
		} catch (ParserException $e) {
			return null;
		}
	}

}
