<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Declarations;

use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use function is_int;

final class PhpDocTypeQualifier
{

	private static ?Lexer $lexer = null;

	private static ?TypeParser $typeParser = null;

	private function __construct()
	{
	}

	public static function qualify(string $type, ClassNameScope $scope): string
	{
		if (self::$lexer === null || self::$typeParser === null) {
			$config = new ParserConfig(['indexes' => true]);
			self::$lexer = new Lexer($config);
			self::$typeParser = new TypeParser($config, new ConstExprParser($config));
		}

		$tokens = self::$lexer->tokenize($type);
		try {
			$node = self::$typeParser->parse(new TokenIterator($tokens));
		} catch (ParserException $e) {
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

}
