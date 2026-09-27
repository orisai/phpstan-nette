<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Inference;

use OriPhpstan\Nette\Forms\Graph\TaggedNode;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use function array_merge;
use function array_values;
use function usort;

final class EnclosingFunctionLikeLocator
{

	/**
	 * @param array<Node> $ast
	 * @return list<FunctionLike>
	 */
	public function findFunctionLikes(array $ast): array
	{
		return array_values((new NodeFinder())->findInstanceOf($ast, FunctionLike::class));
	}

	/**
	 * @param list<FunctionLike> $functionLikes
	 */
	public function selectEnclosing(array $functionLikes, Expr $target): ?FunctionLike
	{
		$pos = $target->getStartFilePos();
		$best = null;
		$bestSpan = null;
		foreach ($functionLikes as $fn) {
			$start = $fn->getStartFilePos();
			$end = $fn->getEndFilePos();
			if ($pos < $start || $pos > $end) {
				continue;
			}

			$span = $end - $start;
			if ($bestSpan === null || $span < $bestSpan) {
				$best = $fn;
				$bestSpan = $span;
			}
		}

		return $best;
	}

	/** @return list<array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> */
	public function taggedRecords(FunctionLike $fn, Scope $scope): array
	{
		$finder = new NodeFinder();
		$taggedIn = static fn (Node $context): array => $finder->find(
			$context,
			static fn (Node $n): bool => $n->getAttribute(TaggedNode::ATTRIBUTE) instanceof TaggedNode,
		);

		$records = [];
		foreach ($finder->find(
			$fn->getStmts() ?? [],
			static fn (Node $n): bool => $n instanceof Expression || $n instanceof Unset_,
		) as $stmt) {
			foreach ($taggedIn($stmt) as $tagged) {
				$meta = $tagged->getAttribute(TaggedNode::ATTRIBUTE);
				$records[] = ['node' => $tagged, 'stmt' => $stmt, 'scope' => $scope, 'tagged' => $meta];
			}
		}

		// A component-affecting add can also sit in a compound statement's header (an if/loop
		// condition or a for's init/step), which is not part of any Expression statement, so it
		// would otherwise be dropped. Collect those from each header expression, keyed to the
		// compound statement as their context.
		foreach ($finder->find(
			$fn->getStmts() ?? [],
			static fn (Node $n): bool => $n instanceof If_ || $n instanceof ElseIf_ || $n instanceof Switch_
				|| $n instanceof While_ || $n instanceof Do_ || $n instanceof Foreach_ || $n instanceof For_,
		) as $compound) {
			foreach ($this->headerExprs($compound) as $header) {
				foreach ($taggedIn($header) as $tagged) {
					$meta = $tagged->getAttribute(TaggedNode::ATTRIBUTE);
					$records[] = ['node' => $tagged, 'stmt' => $compound, 'scope' => $scope, 'tagged' => $meta];
				}
			}
		}

		usort(
			$records,
			static fn (array $a, array $b): int => $a['node']->getStartFilePos() <=> $b['node']->getStartFilePos(),
		);

		return $records;
	}

	/**
	 * @return list<Expr>
	 */
	private function headerExprs(Node $stmt): array
	{
		if (
			$stmt instanceof If_
			|| $stmt instanceof ElseIf_
			|| $stmt instanceof While_
			|| $stmt instanceof Do_
			|| $stmt instanceof Switch_
		) {
			return [$stmt->cond];
		}

		if ($stmt instanceof Foreach_) {
			return [$stmt->expr];
		}

		if ($stmt instanceof For_) {
			return array_values(array_merge($stmt->init, $stmt->cond, $stmt->loop));
		}

		return [];
	}

}
