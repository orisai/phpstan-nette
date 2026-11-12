<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use function array_pop;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function spl_object_id;
use function strpos;
use function strtolower;

final class ImplicitBindingPruner
{

	public const ATTRIBUTE = 'latte.implicitBinding';

	// $iterations = 0 / $iterations++ around a Latte 2 {foreach}: kept only when the function reads the counter.
	public const KIND_COUNTER = 'counter';

	// A statement whose assignment is dropped whole when the value is never read, e.g. the hoisted $iterator.
	public const KIND_STATEMENT = 'statement';

	// An assignment whose binding is dropped and whose value is kept when the binding is never read.
	public const KIND_VALUE = 'value';

	private const TEMP_PREFIX = "\u{29F}_";

	// get_defined_vars() is no read: Latte passes it to blocks, whose reads arrive as edge manifests.
	private const POISON_FUNCTIONS = ['compact', 'extract', 'parse_str'];

	/** @var array<int, true> */
	private array $live = [];

	/** @var array<int, array{Node, string, string}> */
	private array $candidates = [];

	/** @var list<array{array<string, true>, array<string, true>}> */
	private array $loopExits = [];

	/** @var array<string, true> */
	private array $ambient = [];

	private bool $poisoned = false;

	/** @var array<int, true> */
	private array $statementLevel = [];

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>
	 */
	public function prune(array $stmts): array
	{
		$dead = [];
		foreach ((new NodeFinder())->findInstanceOf($stmts, FunctionLike::class) as $function) {
			if ($function instanceof ArrowFunction) {
				continue;
			}

			$body = $function->getStmts();
			if ($body === null) {
				continue;
			}

			$dead += $this->deadIn($body);
		}

		if ($dead === []) {
			return $stmts;
		}

		$traverser = new NodeTraverser();
		$traverser->addVisitor(new class ($dead) extends NodeVisitorAbstract {

			/** @var array<int, string> */
			private array $dead;

			/**
			 * @param array<int, string> $dead
			 */
			public function __construct(array $dead)
			{
				$this->dead = $dead;
			}

			/**
			 * @return Node|int|null
			 */
			public function leaveNode(Node $node)
			{
				if ($node instanceof Expression) {
					$kind = $this->dead[spl_object_id($node->expr)] ?? null;
					if (
						$kind === ImplicitBindingPruner::KIND_COUNTER
						|| $kind === ImplicitBindingPruner::KIND_STATEMENT
					) {
						return NodeVisitor::REMOVE_NODE;
					}

					return null;
				}

				if (
					$node instanceof Assign
					&& ($this->dead[spl_object_id(
						$node,
					)] ?? null) === ImplicitBindingPruner::KIND_VALUE
				) {
					return $node->expr;
				}

				return null;
			}

		});

		/** @var array<Stmt> $pruned */
		$pruned = $traverser->traverse($stmts);

		return $pruned;
	}

	/**
	 * @param array<Stmt> $body
	 * @return array<int, string>
	 */
	private function deadIn(array $body): array
	{
		$this->live = [];
		$this->candidates = [];
		$this->loopExits = [];
		$this->ambient = [];
		$this->poisoned = false;
		$this->statementLevel = [];
		$counterReads = false;
		$counters = [];

		$this->collect($body, $counters, $counterReads);
		if ($this->poisoned || ($this->candidates === [] && $counters === [])) {
			return [];
		}

		$dead = [];
		if (!$counterReads) {
			foreach ($counters as $id => $node) {
				$dead[$id] = self::KIND_COUNTER;
			}
		}

		if ($this->candidates === []) {
			return $dead;
		}

		$this->stmts($body, []);

		foreach ($this->candidates as $id => [$node, , $kind]) {
			if (isset($this->live[$id])) {
				continue;
			}

			// A value left as a statement of its own must still do something.
			if ($kind === self::KIND_VALUE && isset($this->statementLevel[$id]) && !$this->isCall($node)) {
				continue;
			}

			$dead[$id] = $kind;
		}

		return $dead;
	}

	/**
	 * @param array<Stmt> $body
	 * @param array<int, Node> $counters
	 */
	private function collect(array $body, array &$counters, bool &$counterReads): void
	{
		$targets = [];
		$this->walk($body, function (Node $node) use (&$counters, &$counterReads, &$targets): ?int {
			if ($node instanceof Closure) {
				foreach ($node->uses as $use) {
					if ($use->var->name === 'iterations') {
						$counterReads = true;
					}
				}

				return NodeVisitor::DONT_TRAVERSE_CHILDREN;
			}

			if ($node instanceof Function_ || $node instanceof ClassLike) {
				return NodeVisitor::DONT_TRAVERSE_CHILDREN;
			}

			if (
				$node instanceof Goto_
				|| $node instanceof Eval_
				|| $node instanceof Include_
				|| ($node instanceof Variable && !is_string($node->name))
				|| (
					$node instanceof FuncCall
					&& $node->name instanceof Name
					&& in_array(strtolower($node->name->toString()), self::POISON_FUNCTIONS, true)
				)
			) {
				$this->poisoned = true;
			}

			if ($node->getAttribute(self::ATTRIBUTE) === self::KIND_COUNTER) {
				$counters[spl_object_id($node)] = $node;

				return NodeVisitor::DONT_TRAVERSE_CHILDREN;
			}

			if (($node instanceof Assign || $node instanceof AssignRef) && $node->var instanceof Variable) {
				$targets[spl_object_id($node->var)] = true;
				$name = $this->candidateName($node);
				if ($name !== null) {
					$this->candidates[spl_object_id($node)] = [
						$node,
						$name,
						$node->getAttribute(self::ATTRIBUTE) === self::KIND_STATEMENT
							? self::KIND_STATEMENT
							: self::KIND_VALUE,
					];
				}
			}

			if (
				($node instanceof Variable && $node->name === 'iterations' && !isset($targets[spl_object_id($node)]))
				|| ($node instanceof StaticCall && in_array('iterations', $this->manifest($node), true))
			) {
				$counterReads = true;
			}

			return null;
		});
	}

	/**
	 * @param array<Node> $nodes
	 * @param callable(Node): (int|null) $enter
	 */
	private function walk(array $nodes, callable $enter): void
	{
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new class ($enter) extends NodeVisitorAbstract {

			/** @var callable(Node): (int|null) */
			private $enter;

			/**
			 * @param callable(Node): (int|null) $enter
			 */
			public function __construct(callable $enter)
			{
				$this->enter = $enter;
			}

			public function enterNode(Node $node): ?int
			{
				return ($this->enter)($node);
			}

		});
		$traverser->traverse($nodes);
	}

	private function isCall(Node $node): bool
	{
		return $node instanceof Assign
			&& (
				$node->expr instanceof FuncCall
				|| $node->expr instanceof MethodCall
				|| $node->expr instanceof NullsafeMethodCall
				|| $node->expr instanceof StaticCall
				|| $node->expr instanceof New_
			);
	}

	private function candidateName(Node $node): ?string
	{
		if (!$node instanceof Assign || !$node->var instanceof Variable || !is_string($node->var->name)) {
			return null;
		}

		$kind = $node->getAttribute(self::ATTRIBUTE);
		if (
			$kind === self::KIND_VALUE
			|| $kind === self::KIND_STATEMENT
			|| strpos($node->var->name, self::TEMP_PREFIX) === 0
		) {
			return $node->var->name;
		}

		return null;
	}

	/**
	 * @param array<Stmt> $stmts
	 * @param array<string, true> $out
	 * @return array<string, true>
	 */
	private function stmts(array $stmts, array $out): array
	{
		$live = $out;
		for ($i = count($stmts) - 1; $i >= 0; $i--) {
			$live = $this->stmt($stmts[$i], $live) + $this->ambient;
		}

		return $live;
	}

	/**
	 * @param array<string, true> $out
	 * @return array<string, true>
	 */
	private function stmt(Stmt $stmt, array $out): array
	{
		if ($stmt instanceof Expression) {
			return $this->expression($stmt->expr, $out);
		}

		if ($stmt instanceof Echo_ || $stmt instanceof Return_) {
			return $this->reads($stmt, $out);
		}

		if ($stmt instanceof If_) {
			$live = $stmt->else !== null ? $this->stmts($stmt->else->stmts, $out) : $out;
			foreach ($stmt->elseifs as $elseIf) {
				$live += $this->reads($elseIf->cond, $this->stmts($elseIf->stmts, $out));
			}

			return $this->reads($stmt->cond, $live + $this->stmts($stmt->stmts, $out));
		}

		if ($stmt instanceof Foreach_ || $stmt instanceof While_ || $stmt instanceof Do_ || $stmt instanceof For_) {
			return $this->loop($stmt, $out);
		}

		if ($stmt instanceof Switch_) {
			$this->loopExits[] = [$out, $out];
			$live = $out;
			$next = $out;
			for ($i = count($stmt->cases) - 1; $i >= 0; $i--) {
				$case = $stmt->cases[$i];
				$next = $this->stmts($case->stmts, $next);
				$live += $case->cond !== null ? $this->reads($case->cond, $next) : $next;
			}

			array_pop($this->loopExits);

			return $this->reads($stmt->cond, $live);
		}

		if ($stmt instanceof TryCatch) {
			$finally = $stmt->finally !== null ? $this->stmts($stmt->finally->stmts, $out) : $out;
			$catches = $finally;
			foreach ($stmt->catches as $catch) {
				$catches += $this->stmts($catch->stmts, $finally);
			}

			$ambient = $this->ambient;
			$this->ambient += $catches;
			$live = $this->stmts($stmt->stmts, $finally);
			$this->ambient = $ambient;

			return $live + $catches;
		}

		if ($stmt instanceof Break_ || $stmt instanceof Continue_) {
			$level = $stmt->num instanceof Int_ ? $stmt->num->value : 1;
			$target = $this->loopExits[count($this->loopExits) - $level] ?? null;
			if ($target === null) {
				$live = $out;
				foreach ($this->loopExits as [$break, $continue]) {
					$live += $break + $continue;
				}

				return $live;
			}

			return $out + $target[$stmt instanceof Break_ ? 0 : 1];
		}

		if ($stmt instanceof Block) {
			return $this->stmts($stmt->stmts, $out);
		}

		if ($stmt instanceof Function_ || $stmt instanceof ClassLike) {
			return $out;
		}

		return $this->reads($stmt, $out);
	}

	/**
	 * @param Foreach_|While_|Do_|For_ $loop
	 * @param array<string, true> $out
	 * @return array<string, true>
	 */
	private function loop(Stmt $loop, array $out): array
	{
		if ($loop instanceof Foreach_) {
			$header = [$loop->expr, $loop->keyVar, $loop->valueVar];
		} elseif ($loop instanceof For_) {
			$header = [$loop->init, $loop->cond, $loop->loop];
		} else {
			$header = $loop->cond;
		}

		$headerNames = [];
		$ignored = [];
		$this->readNames($header, $headerNames, $ignored);

		$head = [];
		do {
			$next = $out + $headerNames + $head;
			$this->loopExits[] = [$out, $next];
			$body = $this->stmts($loop->stmts, $next);
			array_pop($this->loopExits);
			$grown = $head + $body;
			$stable = count($grown) === count($head);
			$head = $grown;
		} while (!$stable);

		return $this->reads($header, $head + $out);
	}

	/**
	 * @param array<string, true> $out
	 * @return array<string, true>
	 */
	private function expression(Expr $expr, array $out): array
	{
		if ($expr instanceof Assign && $expr->var instanceof Variable && is_string($expr->var->name)) {
			$name = $expr->var->name;
			$id = spl_object_id($expr);
			$this->statementLevel[$id] = true;
			if (isset($this->candidates[$id]) && isset($out[$name])) {
				$this->live[$id] = true;
			}

			$live = $out;
			unset($live[$name]);

			return $this->reads($expr->expr, $live, $out);
		}

		return $this->reads($expr, $out);
	}

	/**
	 * @param array<Node|array<Node>|null>|Node|null $node
	 * @param array<string, true> $out
	 * @param array<string, true>|null $after
	 * @return array<string, true>
	 */
	private function reads($node, array $out, ?array $after = null): array
	{
		$names = [];
		$nested = [];
		$this->readNames($node, $names, $nested);
		$visible = ($after ?? $out) + $names;
		foreach ($nested as $id => $name) {
			if (isset($visible[$name])) {
				$this->live[$id] = true;
			}
		}

		return $out + $names;
	}

	/**
	 * @param array<Node|array<Node>|null>|Node|null $node
	 * @param array<string, true> $names
	 * @param array<int, string> $nested
	 */
	private function readNames($node, array &$names, array &$nested): void
	{
		$nodes = [];
		foreach (is_array($node) ? $node : [$node] as $item) {
			foreach (is_array($item) ? $item : [$item] as $inner) {
				if ($inner instanceof Node) {
					$nodes[] = $inner;
				}
			}
		}

		$targets = [];
		$this->walk($nodes, function (Node $node) use (&$names, &$nested, &$targets): ?int {
			if ($node instanceof Closure) {
				foreach ($node->uses as $use) {
					if (is_string($use->var->name)) {
						$names[$use->var->name] = true;
					}
				}

				return NodeVisitor::DONT_TRAVERSE_CHILDREN;
			}

			if ($node instanceof Param || $node instanceof Function_ || $node instanceof ClassLike) {
				return NodeVisitor::DONT_TRAVERSE_CHILDREN;
			}

			if ($node instanceof Variable) {
				if (is_string($node->name) && !isset($targets[spl_object_id($node)])) {
					$names[$node->name] = true;
				}

				return null;
			}

			if ($node instanceof StaticCall) {
				foreach ($this->manifest($node) as $name) {
					$names[$name] = true;
				}
			}

			if (($node instanceof Assign || $node instanceof AssignRef) && $node->var instanceof Variable) {
				$targets[spl_object_id($node->var)] = true;
				if (isset($this->candidates[spl_object_id($node)]) && is_string($node->var->name)) {
					$nested[spl_object_id($node)] = $node->var->name;
				}
			}

			return null;
		});
	}

	/**
	 * @return list<string>
	 */
	private function manifest(StaticCall $call): array
	{
		$manifest = $call->getAttribute('latte.edgeManifest');
		if (!is_array($manifest)) {
			return [];
		}

		$names = [];
		foreach ($manifest as $name) {
			if (is_string($name)) {
				$names[] = $name;
			}
		}

		return $names;
	}

}
