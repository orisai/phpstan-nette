<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use function in_array;

final class MainMethodFinder
{

	private function __construct()
	{
	}

	/**
	 * @param array<Stmt> $stmts
	 */
	public static function find(array $stmts): ?ClassMethod
	{
		$class = self::findClass($stmts);
		if ($class === null) {
			return null;
		}

		// Contexts non-empty: DeclarationInjector removes latteMain in favour of N
		// latteMain_ctx{i} clones. Both diagnostics and dependency edges only need to land once, so
		// the first clone (ctx0, canonicalHash-ordered) stands in for the removed,
		// un-contextualized main.
		foreach ($class->stmts as $stmt) {
			if (
				$stmt instanceof ClassMethod
				&& in_array($stmt->name->toString(), ['latteMain', 'latteMain_ctx0'], true)
			) {
				return $stmt;
			}
		}

		return null;
	}

	/**
	 * @param array<Stmt> $stmts
	 */
	public static function findClass(array $stmts): ?Class_
	{
		return (new NodeFinder())->findFirstInstanceOf($stmts, Class_::class);
	}

}
