<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use PhpParser\Node;
use function array_map;
use function implode;

final class NodeId
{

	private function __construct()
	{
	}

	/**
	 * @param list<array{type: string, index: int}> $path
	 */
	public static function fromPath(string $relativeFilePath, array $path): string
	{
		return $relativeFilePath . '#' . implode('/', array_map(
			static fn (array $f): string => $f['type'] . ':' . $f['index'],
			$path,
		));
	}

	public static function functionLikeKey(string $relativeFilePath, Node $functionLike): string
	{
		return $relativeFilePath . '##' . $functionLike->getType() . ':' . (string) $functionLike->getStartLine();
	}

}
