<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use function is_string;

/**
 * Counts every binding of each variable name — plain/compound/by-ref assignment,
 * array/list destructure, foreach value/key var, catch var — the same rebind families the
 * form walk resets for, so callers can require an unambiguous single binding.
 */
final class VariableBindingCounter
{

	private function __construct()
	{
	}

	/**
	 * @param array<Node\Stmt> $stmts
	 * @return array<string, int>
	 */
	public static function count(array $stmts, bool $countClosureBindings = true): array
	{
		$visitor = new class ($countClosureBindings) extends NodeVisitorAbstract {

			/** @var array<string, int> */
			public array $counts = [];

			private bool $countClosureBindings;

			public function __construct(bool $countClosureBindings)
			{
				$this->countClosureBindings = $countClosureBindings;
			}

			public function enterNode(Node $node): ?int
			{
				if ($node instanceof Closure || $node instanceof ArrowFunction) {
					if ($this->countClosureBindings) {
						foreach ($node->params as $param) {
							$this->bump($param->var);
						}

						if ($node instanceof Closure) {
							foreach ($node->uses as $use) {
								$this->bump($use->var);
							}
						}
					}

					// Without a matching use-clause a closure/arrow-fn body is an isolated
					// scope: a reassignment inside it cannot rebind the outer name.
					return NodeVisitor::DONT_TRAVERSE_CHILDREN;
				}

				if ($node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef) {
					$this->bump($node->var);

					return null;
				}

				if ($node instanceof Foreach_) {
					$this->bump($node->valueVar);
					if ($node->keyVar !== null) {
						$this->bump($node->keyVar);
					}

					return null;
				}

				if ($node instanceof Catch_ && $node->var !== null) {
					$this->bump($node->var);
				}

				return null;
			}

			private function bump(Node $target): void
			{
				if ($target instanceof Variable && is_string($target->name)) {
					$this->counts[$target->name] = ($this->counts[$target->name] ?? 0) + 1;

					return;
				}

				if ($target instanceof List_ || $target instanceof Array_) {
					foreach ($target->items as $item) {
						if ($item === null) {
							continue;
						}

						$this->bump($item->value);
					}
				}
			}

		};

		$traverser = new NodeTraverser();
		$traverser->addVisitor($visitor);
		$traverser->traverse($stmts);

		return $visitor->counts;
	}

}
