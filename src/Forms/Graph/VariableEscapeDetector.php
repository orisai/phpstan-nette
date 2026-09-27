<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeFinder;

final class VariableEscapeDetector
{

	/**
	 * @param callable(CallLike): bool|null $excludeCall
	 */
	public static function passedAsDirectArgument(Node $scope, string $varName, ?callable $excludeCall = null): bool
	{
		foreach (self::callsIn($scope) as $call) {
			if ($call->isFirstClassCallable()) {
				continue;
			}

			if ($excludeCall !== null && $excludeCall($call)) {
				continue;
			}

			foreach ($call->getArgs() as $arg) {
				if ($arg->value instanceof Variable && $arg->value->name === $varName) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Every call that takes the variable as a positional argument, with that argument's index —
	 * the follow-up question passedAsDirectArgument only answers yes/no to. A named or unpacked
	 * argument has no reliable position, so the call is reported with a null index and the
	 * caller must treat it as unfollowable rather than guessing.
	 *
	 * @param callable(CallLike): bool|null $excludeCall
	 * @return list<array{call: CallLike, index: int|null}>
	 */
	public static function directArgumentCalls(Node $scope, string $varName, ?callable $excludeCall = null): array
	{
		$found = [];
		foreach (self::callsIn($scope) as $call) {
			if ($call->isFirstClassCallable()) {
				continue;
			}

			if ($excludeCall !== null && $excludeCall($call)) {
				continue;
			}

			$position = 0;
			$positional = true;
			foreach ($call->getArgs() as $arg) {
				if ($arg->name !== null || $arg->unpack) {
					$positional = false;
				}

				if ($arg->value instanceof Variable && $arg->value->name === $varName) {
					$found[] = ['call' => $call, 'index' => $positional ? $position : null];
				}

				$position++;
			}
		}

		return $found;
	}

	/**
	 * @return list<CallLike>
	 */
	private static function callsIn(Node $scope): array
	{
		/** @var list<CallLike> */
		return (new NodeFinder())->find(
			$scope,
			static fn (Node $n): bool => $n instanceof MethodCall
				|| $n instanceof StaticCall
				|| $n instanceof FuncCall
				|| $n instanceof New_,
		);
	}

}
