<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use function count;

final class DevTagEliminator extends EliminatorVisitor
{

	// {dump}/{trace} calls dropped with their arguments kept analyzed.
	public const ROLE_DROPPED_CALL = 'droppedCall';

	// {templatePrint}'s class printer, dropped together with the exit that follows it.
	public const ROLE_PRINT_CLASS = 'printClass';

	private const HELPERS_CLASS = Helpers::class;

	public function describePattern(): string
	{
		return 'drop {dump}/{trace} calls, routing their argument expressions (including the auto-generated '
			. 'title string) through a single Helpers::analyzed(...) call so {dump $x} still analyzes $x without '
			. 'leaving bare, unused-expression statements behind; drop {templatePrint}\'s printClass(...); exit; '
			. 'pair so the template body stays reachable: ' . $this->patterns()->describe();
	}

	/**
	 * @return array<Expression>|null
	 */
	public function leaveNode(Node $node)
	{
		if ($node instanceof ClassMethod && $node->stmts !== null) {
			$node->stmts = $this->dropPrintClass($node->stmts);
		}

		if (
			$node instanceof Expression
			&& $node->expr instanceof StaticCall
			&& $this->isRoleCall(self::ROLE_DROPPED_CALL, $node->expr)
		) {
			return $this->keptArgStatements($node->expr);
		}

		return null;
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>
	 */
	private function dropPrintClass(array $stmts): array
	{
		$result = [];
		$count = count($stmts);
		$i = 0;

		while ($i < $count) {
			$stmt = $stmts[$i];
			$next = $stmts[$i + 1] ?? null;

			if (
				$stmt instanceof Expression
				&& $stmt->expr instanceof StaticCall
				&& $this->isRoleCall(self::ROLE_PRINT_CLASS, $stmt->expr)
				&& $next instanceof Expression
				&& $next->expr instanceof Exit_
			) {
				$i += 2;

				continue;
			}

			$result[] = $stmt;
			$i++;
		}

		return $result;
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

	private function isRoleCall(string $role, StaticCall $node): bool
	{
		if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
			return false;
		}

		return $this->patterns()->isStaticCall($role, $node->class->toString(), $node->name->toString());
	}

}
