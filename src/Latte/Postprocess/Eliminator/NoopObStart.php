<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use function count;

// The output-discarding ob_start() every capturing shell opens with: Latte 2 passes an empty
// closure, Latte 3 an arrow function returning ''.
final class NoopObStart
{

	public const ROLE = 'noopObStart';

	public const SHAPE_EMPTY_CLOSURE = 'emptyClosure';

	public const SHAPE_EMPTY_STRING_ARROW = 'emptyStringArrow';

	private function __construct()
	{
	}

	public static function matches(Stmt $stmt, PatternSet $patterns): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof FuncCall) {
			return false;
		}

		$call = $stmt->expr;
		if (!$call->name instanceof Name || $call->name->toString() !== 'ob_start' || count($call->args) !== 1) {
			return false;
		}

		$arg = $call->args[0];
		if (!$arg instanceof Arg) {
			return false;
		}

		$handler = $arg->value;
		if ($handler instanceof Closure) {
			return $patterns->hasName(self::ROLE, self::SHAPE_EMPTY_CLOSURE)
				&& $handler->params === []
				&& $handler->uses === []
				&& $handler->stmts === [];
		}

		if ($handler instanceof ArrowFunction) {
			return $patterns->hasName(self::ROLE, self::SHAPE_EMPTY_STRING_ARROW)
				&& $handler->params === []
				&& $handler->expr instanceof String_
				&& $handler->expr->value === '';
		}

		return false;
	}

}
