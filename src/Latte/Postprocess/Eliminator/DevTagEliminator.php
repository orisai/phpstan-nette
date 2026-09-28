<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Expression;

final class DevTagEliminator extends EliminatorVisitor
{

	private const DROPPED_CALLS = [
		'Tracy\Debugger' => 'barDump',
		'Latte\Runtime\Tracer' => 'throw',
	];

	private const HELPERS_CLASS = Helpers::class;

	public function describePattern(): string
	{
		return 'drop Tracy\Debugger::barDump(...) ({dump}) and Latte\Runtime\Tracer::throw() ({trace}) calls, '
			. 'routing their argument expressions (including the auto-generated title string) through a '
			. 'single Helpers::analyzed(...) call so {dump $x} still analyzes $x without leaving bare, '
			. 'unused-expression statements behind';
	}

	/**
	 * @return array<Expression>|null
	 */
	public function leaveNode(Node $node)
	{
		if ($node instanceof Expression && $node->expr instanceof StaticCall && $this->isDroppedCall($node->expr)) {
			return $this->keptArgStatements($node->expr);
		}

		return null;
	}

	/**
	 * @return array<Expression>
	 */
	private function keptArgStatements(StaticCall $node): array
	{
		if ($node->args === []) {
			return [];
		}

		return [
			new Expression(
				new StaticCall(new FullyQualified(self::HELPERS_CLASS), new Identifier('analyzed'), $node->args),
			),
		];
	}

	private function isDroppedCall(StaticCall $node): bool
	{
		if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
			return false;
		}

		$className = $node->class->toString();
		$methodName = $node->name->toString();

		return isset(self::DROPPED_CALLS[$className]) && self::DROPPED_CALLS[$className] === $methodName;
	}

}
