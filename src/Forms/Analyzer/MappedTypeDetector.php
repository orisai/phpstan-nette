<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;
use function count;
use function spl_object_id;

final class MappedTypeDetector
{

	/**
	 * @param array<Node\Stmt> $stmts
	 */
	public static function detect(array $stmts, string $varName): ?string
	{
		foreach ((new NodeFinder())->findInstanceOf($stmts, MethodCall::class) as $call) {
			$mapped = self::mappedClassOfCall($call, $varName);
			if ($mapped !== null) {
				return $mapped;
			}
		}

		return null;
	}

	/**
	 * The form-level mapped type, scanning only the builder's own statements and never
	 * descending into nested closures/arrow functions (a setMappedType on some other
	 * container inside an onSuccess handler must not leak onto the form).
	 *
	 * @param array<Node\Stmt> $stmts
	 */
	public static function detectTopLevel(array $stmts, string $varName): ?string
	{
		$finder = new NodeFinder();

		$nested = [];
		foreach ($finder->find(
			$stmts,
			static fn (Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction,
		) as $func) {
			foreach ($finder->findInstanceOf([$func], MethodCall::class) as $inner) {
				$nested[spl_object_id($inner)] = true;
			}
		}

		foreach ($finder->findInstanceOf($stmts, MethodCall::class) as $call) {
			if (isset($nested[spl_object_id($call)])) {
				continue;
			}

			$mapped = self::mappedClassOfCall($call, $varName);
			if ($mapped !== null) {
				return $mapped;
			}
		}

		return null;
	}

	private static function mappedClassOfCall(MethodCall $call, string $varName): ?string
	{
		if (
			!$call->name instanceof Identifier
			|| $call->isFirstClassCallable()
			|| $call->name->toString() !== 'setMappedType'
			|| !$call->var instanceof Variable
			|| $call->var->name !== $varName
		) {
			return null;
		}

		$args = $call->getArgs();
		if (count($args) !== 1 || !$args[0]->value instanceof ClassConstFetch) {
			return null;
		}

		$classConst = $args[0]->value;
		if (
			$classConst->class instanceof Node\Name
			&& $classConst->name instanceof Identifier
			&& $classConst->name->toString() === 'class'
		) {
			return $classConst->class->toString();
		}

		return null;
	}

}
