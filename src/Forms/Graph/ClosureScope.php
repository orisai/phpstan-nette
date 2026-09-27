<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\NodeFinder;
use function spl_object_id;

final class ClosureScope
{

	private function __construct()
	{
	}

	/**
	 * @param array<Node>|Node $nodes
	 * @return array<int, true>
	 */
	public static function innerNodeIds($nodes): array
	{
		$finder = new NodeFinder();
		$ids = [];
		foreach ($finder->find(
			$nodes,
			static fn (Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction,
		) as $fnNode) {
			foreach ($finder->findInstanceOf([$fnNode], Node::class) as $inner) {
				if ($inner !== $fnNode) {
					$ids[spl_object_id($inner)] = true;
				}
			}
		}

		return $ids;
	}

}
