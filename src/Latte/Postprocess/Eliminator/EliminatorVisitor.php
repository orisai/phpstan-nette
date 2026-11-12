<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Finally_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;

abstract class EliminatorVisitor extends NodeVisitorAbstract
{

	private ShapeFamily $family;

	private ?PatternSet $patterns = null;

	public function __construct(ShapeFamily $family)
	{
		$this->family = $family;
	}

	abstract public function describePattern(): string;

	final protected function patterns(): PatternSet
	{
		return $this->patterns ??= FamilyPatterns::for($this->family, static::class);
	}

	/**
	 * @param callable(array<Stmt>): array<Stmt> $normalize
	 */
	final protected static function normalizeStatementList(Node $node, callable $normalize): void
	{
		if ($node instanceof ClassMethod || $node instanceof Declare_) {
			if ($node->stmts !== null) {
				$node->stmts = $normalize($node->stmts);
			}

			return;
		}

		if (
			$node instanceof Function_
			|| $node instanceof Closure
			|| $node instanceof If_
			|| $node instanceof ElseIf_
			|| $node instanceof Else_
			|| $node instanceof Foreach_
			|| $node instanceof For_
			|| $node instanceof While_
			|| $node instanceof Do_
			|| $node instanceof Case_
			|| $node instanceof TryCatch
			|| $node instanceof Catch_
			|| $node instanceof Finally_
			|| $node instanceof Block
		) {
			$node->stmts = $normalize($node->stmts);
		}
	}

}
