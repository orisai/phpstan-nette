<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use OriPhpstan\Nette\Forms\Cache\DependencyRecorder;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use function count;
use function is_string;

final class LiteralNameResolver
{

	/**
	 * @param array<string, string> $locals
	 */
	public static function resolve(
		?Node $expr,
		array $locals,
		?ClassReflection $classReflection,
		?ReflectionProvider $reflectionProvider,
		?DependencyRecorder $recorder = null
	): ?string
	{
		if ($expr === null) {
			return null;
		}

		if ($expr instanceof String_) {
			return $expr->value;
		}

		if ($expr instanceof Variable && is_string($expr->name)) {
			return $locals[$expr->name] ?? null;
		}

		if (
			$classReflection !== null
			&& $reflectionProvider !== null
			&& $expr instanceof ClassConstFetch
			&& $expr->name instanceof Identifier
			&& $expr->class instanceof Name
		) {
			$className = $expr->class->toString();
			if ($className === 'self' || $className === 'static') {
				$target = $classReflection;
			} elseif ($reflectionProvider->hasClass($className)) {
				$target = $reflectionProvider->getClass($className);
			} else {
				return null;
			}

			if (!$target->hasConstant($expr->name->toString())) {
				return null;
			}

			$constant = $target->getConstant($expr->name->toString());
			$constantFile = $constant->getFileName();
			if ($recorder !== null && $constantFile !== null) {
				$recorder->record($constantFile);
			}

			$strings = $constant->getValueType()->getConstantStrings();
			if (count($strings) !== 1) {
				return null;
			}

			return $strings[0]->getValue();
		}

		if ($expr instanceof Concat) {
			$left = self::resolve($expr->left, $locals, $classReflection, $reflectionProvider, $recorder);
			$right = self::resolve($expr->right, $locals, $classReflection, $reflectionProvider, $recorder);
			if ($left === null || $right === null) {
				return null;
			}

			return $left . $right;
		}

		return null;
	}

}
