<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeVisitorAbstract;
use function array_pop;
use function count;
use function get_class;
use function strncmp;

final class ComponentAffectingNodeVisitor extends NodeVisitorAbstract
{

	private const FUNCTION_LIKE = [
		Function_::class => true,
		ClassMethod::class => true,
		Closure::class => true,
		ArrowFunction::class => true,
	];

	private const BRANCH_TYPES = [
		'Stmt_If' => true,
		'Stmt_ElseIf' => true,
		'Stmt_Else' => true,
		'Stmt_Switch' => true,
		'Stmt_Case' => true,
		'Expr_Match' => true,
		'MatchArm' => true,
		'Expr_Ternary' => true,
	];

	private const LOOP_TYPES = [
		'Stmt_For' => true,
		'Stmt_Foreach' => true,
		'Stmt_While' => true,
		'Stmt_Do' => true,
	];

	private const TRY_TYPES = [
		'Stmt_TryCatch' => true,
		'Stmt_Catch' => true,
		'Stmt_Finally' => true,
	];

	/** @var list<array{node: Node, type: string, ordinal: int, childOrdinals: array<string, int>}> */
	private array $stack = [];

	/** @var array<string, int> */
	private array $rootOrdinals = [];

	/** @param array<Node> $nodes */
	public function beforeTraverse(array $nodes): ?array
	{
		$this->stack = [];
		$this->rootOrdinals = [];

		return null;
	}

	public function enterNode(Node $node): ?Node
	{
		$type = $node->getType();

		if ($this->stack === []) {
			$ordinal = $this->rootOrdinals[$type] ?? 0;
			$this->rootOrdinals[$type] = $ordinal + 1;
		} else {
			$parentIndex = count($this->stack) - 1;
			$ordinal = $this->stack[$parentIndex]['childOrdinals'][$type] ?? 0;
			$this->stack[$parentIndex]['childOrdinals'][$type] = $ordinal + 1;
		}

		$this->stack[] = [
			'node' => $node,
			'type' => $type,
			'ordinal' => $ordinal,
			'childOrdinals' => [],
		];

		$operationKind = $this->componentAffectingKind($node);
		if ($operationKind !== null) {
			$start = $this->enclosingFunctionLikeDepth();
			$structuralPath = [];
			$structuralFrames = [];
			$frameCount = count($this->stack);
			for ($i = $start; $i < $frameCount; $i++) {
				$frame = $this->stack[$i];
				$structuralPath[] = [
					'type' => $frame['type'],
					'index' => $frame['ordinal'],
				];
				$kind = $this->controlKind($frame['type']);
				if ($kind !== null) {
					$structuralFrames[] = [
						'kind' => $kind,
						'key' => $frame['type'] . ':' . $frame['ordinal'],
					];
				}
			}

			$node->setAttribute(
				TaggedNode::ATTRIBUTE,
				new TaggedNode(
					NodeId::fromPath('', $structuralPath),
					$operationKind,
					new StructuralContext($structuralFrames),
				),
			);
		}

		return null;
	}

	public function leaveNode(Node $node): ?Node
	{
		array_pop($this->stack);

		return null;
	}

	private function enclosingFunctionLikeDepth(): int
	{
		for ($i = count($this->stack) - 1; $i >= 0; $i--) {
			if (isset(self::FUNCTION_LIKE[get_class($this->stack[$i]['node'])])) {
				return $i + 1;
			}
		}

		return 0;
	}

	private function controlKind(string $type): ?string
	{
		if (isset(self::BRANCH_TYPES[$type])) {
			return StructuralContext::KIND_BRANCH;
		}

		if (isset(self::LOOP_TYPES[$type])) {
			return StructuralContext::KIND_LOOP;
		}

		if (isset(self::TRY_TYPES[$type])) {
			return StructuralContext::KIND_TRY;
		}

		return null;
	}

	/**
	 * Whether a method call spelled $name is one this pass marks as component-affecting.
	 *
	 * The pass runs on the AST alone — no scope, no reflection, no types — because the node ids it
	 * mints are the walk's cache key and an id that moved with a type would let one consumer poison
	 * another's entry. So the gate is a NAME, and a declaration that registers under any other name is
	 * invisible to it however it is annotated. Callers that can answer the question properly ask this
	 * first, to tell what the pass has already accounted for from what it never saw.
	 */
	public static function tagsMethodName(string $name): bool
	{
		return strncmp($name, 'add', 3) === 0 || $name === 'removeComponent';
	}

	private function componentAffectingKind(Node $node): ?string
	{
		if (
			($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
			&& $node->name instanceof Identifier
		) {
			$name = $node->name->toString();

			return self::tagsMethodName($name) ? $name : null;
		}

		if (
			$node instanceof Assign
			&& $node->var instanceof ArrayDimFetch
			&& $node->var->var instanceof Variable
		) {
			return '$offsetSet';
		}

		if ($node instanceof Unset_) {
			foreach ($node->vars as $var) {
				if ($var instanceof ArrayDimFetch && $var->var instanceof Variable) {
					return '$offsetUnset';
				}
			}
		}

		return null;
	}

}
