<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;

final class MethodChainSpine
{

	/**
	 * @return list<MethodCall|NullsafeMethodCall>
	 */
	public static function calls(Expr $tip, ?Expr $stop = null): array
	{
		$calls = [];
		$cursor = $tip;
		while ($cursor instanceof MethodCall || $cursor instanceof NullsafeMethodCall) {
			if ($cursor === $stop) {
				break;
			}

			$calls[] = $cursor;
			$cursor = $cursor->var;
		}

		return $calls;
	}

	public static function rootReceiver(Expr $tip): Expr
	{
		$cursor = $tip;
		while ($cursor instanceof MethodCall || $cursor instanceof NullsafeMethodCall) {
			$cursor = $cursor->var;
		}

		return $cursor;
	}

	public static function conditionTransition(string $method): int
	{
		if ($method === 'addCondition' || $method === 'addConditionOn') {
			return 1;
		}

		if ($method === 'endCondition') {
			return -1;
		}

		return 0;
	}

}
