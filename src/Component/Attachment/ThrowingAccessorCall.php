<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component\Attachment;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;

/**
 * Whether one call site of a parent accessor is the throwing form.
 *
 * Both consumers of ParentAccessors need this same answer and would otherwise each carry their own
 * copy of the argument arithmetic: the rule reports on a detached receiver only where the call
 * throws, and the return-type extension removes the declared null only where the call throws. A
 * disagreement between the two would be visible as a type that says "never null" beside a rule that
 * says the call may answer null, so they read one implementation.
 *
 * Anything unreadable answers false, which for both consumers is the quiet direction: no report, and
 * the declared nullable type left alone.
 */
final class ThrowingAccessorCall
{

	private const THROW_PARAMETER = 'throw';

	private function __construct()
	{
	}

	/**
	 * Whether $method, called here, is a parent accessor that throws rather than answering null when
	 * the receiver has no parent.
	 */
	public static function throwsWhenDetached(
		MethodReflection $method,
		MethodCall $call,
		Scope $scope
	): bool
	{
		$declaringClass = $method->getDeclaringClass()->getName();
		$name = $method->getName();
		if (!ParentAccessors::isThrowingAccessor($declaringClass, $name)) {
			return false;
		}

		$throwArgument = ParentAccessors::throwArgument($declaringClass, $name);
		if ($throwArgument === null) {
			// No $throw to read: it hands lookup() a hardcoded true.
			return true;
		}

		if ($call->isFirstClassCallable()) {
			return false;
		}

		$argument = null;
		$position = 0;
		foreach ($call->getArgs() as $arg) {
			if ($arg->unpack) {
				// A spread hides which position anything lands in, $throw included.
				return false;
			}

			if (self::isThrowArgument($arg, $position, $throwArgument)) {
				$argument = $arg;
			}

			$position++;
		}

		// Absent means the default, which is true for every entry in the table - including the one
		// whose parameter reflection does not report the argument at all.
		return $argument === null || $scope->getType($argument->value)->isTrue()->yes();
	}

	private static function isThrowArgument(Arg $arg, int $position, int $throwArgument): bool
	{
		return $arg->name === null
			? $position === $throwArgument
			: $arg->name->toString() === self::THROW_PARAMETER;
	}

}
