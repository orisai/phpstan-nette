<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Inference;

use Nette\Forms\Container as NetteContainer;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\NodeFinder;
use PHPStan\Type\ObjectType;
use function is_string;

final class IntraProceduralFormGate
{

	/** @return list<string> */
	public function trackedVariables(FunctionLike $fn): array
	{
		$assignCounts = [];
		$firstExpr = [];
		foreach ((new NodeFinder())->findInstanceOf($fn->getStmts() ?? [], Assign::class) as $assign) {
			if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
				continue;
			}

			$name = $assign->var->name;
			if (!isset($assignCounts[$name])) {
				$firstExpr[$name] = $assign->expr;
			}

			$assignCounts[$name] = ($assignCounts[$name] ?? 0) + 1;
		}

		$tracked = [];
		foreach ($assignCounts as $name => $count) {
			if ($count !== 1) {
				continue;
			}

			$expr = $firstExpr[$name];
			if (
				$expr instanceof New_
				&& $expr->class instanceof Node\Name
				&& (new ObjectType(NetteContainer::class))->isSuperTypeOf(
					new ObjectType($expr->class->toString()),
				)->yes()
			) {
				$tracked[] = $name;
			}
		}

		return $tracked;
	}

}
