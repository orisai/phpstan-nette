<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;

final class TrueConstantFinder
{

	public static function find(Parser $parser, string $file): ConstFetch
	{
		$found = (new NodeFinder())->findFirst(
			$parser->parseFile($file),
			static fn (Node $node): bool => $node instanceof ConstFetch && $node->name->toLowerString() === 'true',
		);

		if (!$found instanceof ConstFetch) {
			throw new LogicException("No true constant in $file.");
		}

		return $found;
	}

}
