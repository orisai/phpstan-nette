<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use Latte\Runtime\FilterInfo;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\UnionType;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use function implode;
use function in_array;
use function is_string;
use function strtolower;

// A filter callable with no declaration a template can call (an anonymous function, a non-public
// method) is replaced by a closure of the same native signature whose body only returns its declared
// type: the arguments are checked and the result typed as the callable's own, and the callable's real
// body is never analysed again in the template.
final class FilterClosureStub
{

	private const KEYWORD_TYPES = [
		'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'string',
		'true', 'void',
	];

	private const RESULT_VARIABLE = "\u{29F}_filterResult";

	private function __construct()
	{
	}

	/**
	 * @return array{string, bool}|null
	 */
	public static function of(
		FunctionLike $function,
		ClassReflection $declaringClass,
		ReflectionProvider $reflectionProvider
	): ?array
	{
		$params = [];
		$contentAware = false;
		foreach ($function->getParams() as $index => $param) {
			if (!$param->var instanceof Variable || !is_string($param->var->name)) {
				return null;
			}

			$type = '';
			if ($param->type !== null) {
				$printed = self::printType($param->type, $declaringClass);
				if ($printed === null) {
					return null;
				}

				$type = $printed . ' ';
				if ($index === 0 && $printed === '\\' . FilterInfo::class) {
					$contentAware = true;
				}
			}

			$default = '';
			if ($param->default !== null) {
				$printed = self::printDefault($param->default, $declaringClass, $reflectionProvider);
				if ($printed === null) {
					return null;
				}

				$default = ' = ' . $printed;
			}

			$params[] = $type . ($param->byRef ? '&' : '') . ($param->variadic ? '...' : '')
				. '$' . $param->var->name . $default;
		}

		$returnType = $function->getReturnType();
		$helperCall = '\\' . Helpers::class . '::untypedFilter()';
		$result = '$' . self::RESULT_VARIABLE;
		if ($returnType === null) {
			$signature = '';
			$body = 'return ' . $helperCall . ';';
		} else {
			$printed = self::printType($returnType, $declaringClass);
			if ($printed === null) {
				return null;
			}

			$signature = ': ' . $printed;
			$lower = strtolower($printed);
			if ($lower === 'void') {
				$body = '';
			} elseif ($lower === 'never') {
				$body = 'throw new \LogicException();';
			} else {
				$body = '/** @var ' . $printed . ' ' . $result . ' */ ' . $result . ' = ' . $helperCall
					. '; return ' . $result . ';';
			}
		}

		return [
			'static function (' . implode(', ', $params) . ')' . $signature . ' { ' . $body . ' }',
			$contentAware,
		];
	}

	/**
	 * @param Identifier|Name|ComplexType $type
	 */
	private static function printType(Node $type, ClassReflection $declaringClass): ?string
	{
		if ($type instanceof NullableType) {
			$inner = self::printType($type->type, $declaringClass);

			return $inner === null ? null : '?' . $inner;
		}

		if ($type instanceof UnionType || $type instanceof IntersectionType) {
			$parts = [];
			foreach ($type->types as $member) {
				$printed = self::printType($member, $declaringClass);
				if ($printed === null) {
					return null;
				}

				$parts[] = $member instanceof IntersectionType ? '(' . $printed . ')' : $printed;
			}

			return implode($type instanceof UnionType ? '|' : '&', $parts);
		}

		if ($type instanceof Identifier || $type instanceof Name) {
			$name = $type->toString();
			$className = self::relativeClassName($name, $declaringClass);
			if ($className !== null) {
				return '\\' . $className;
			}

			if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
				return null;
			}

			// A parser for an older PHP reads the newer keyword types as class names.
			if (in_array(strtolower($name), self::KEYWORD_TYPES, true)) {
				return strtolower($name);
			}

			if ($type instanceof FullyQualified) {
				return '\\' . $name;
			}

			return $type instanceof Identifier ? $name : null;
		}

		return null;
	}

	private static function relativeClassName(string $name, ClassReflection $declaringClass): ?string
	{
		$lower = strtolower($name);
		if ($lower === 'self' || $lower === 'static') {
			return $declaringClass->getName();
		}

		if ($lower === 'parent') {
			$parent = $declaringClass->getParentClass();

			return $parent === null ? null : $parent->getName();
		}

		return null;
	}

	private static function printDefault(
		Expr $default,
		ClassReflection $declaringClass,
		ReflectionProvider $reflectionProvider
	): ?string
	{
		$qualified = self::qualifyDefault($default, $declaringClass, $reflectionProvider);

		return $qualified === null ? null : (new Standard())->prettyPrintExpr($qualified);
	}

	private static function qualifyDefault(
		Expr $expr,
		ClassReflection $declaringClass,
		ReflectionProvider $reflectionProvider
	): ?Expr
	{
		if ($expr instanceof String_ || $expr instanceof Int_ || $expr instanceof Float_) {
			return $expr;
		}

		if ($expr instanceof ConstFetch) {
			$name = strtolower($expr->name->toString());

			return in_array($name, ['null', 'true', 'false'], true) ? new ConstFetch(new Name($name)) : null;
		}

		if ($expr instanceof UnaryMinus || $expr instanceof UnaryPlus) {
			$inner = self::qualifyDefault($expr->expr, $declaringClass, $reflectionProvider);
			if ($inner === null) {
				return null;
			}

			return $expr instanceof UnaryMinus ? new UnaryMinus($inner) : new UnaryPlus($inner);
		}

		if ($expr instanceof Array_) {
			$items = [];
			foreach ($expr->items as $item) {
				if ($item->byRef || $item->unpack) {
					return null;
				}

				$value = self::qualifyDefault($item->value, $declaringClass, $reflectionProvider);
				$key = $item->key === null
					? null
					: self::qualifyDefault(
						$item->key,
						$declaringClass,
						$reflectionProvider,
					);
				if ($value === null || ($item->key !== null && $key === null)) {
					return null;
				}

				$copy = clone $item;
				$copy->value = $value;
				$copy->key = $key;
				$items[] = $copy;
			}

			return new Array_($items, ['kind' => Array_::KIND_SHORT]);
		}

		if (
			$expr instanceof ClassConstFetch
			&& $expr->class instanceof Name
			&& $expr->name instanceof Identifier
		) {
			$className = self::relativeClassName($expr->class->toString(), $declaringClass);
			if ($className === null && !$expr->class instanceof FullyQualified) {
				return null;
			}

			$className ??= $expr->class->toString();
			$constant = $expr->name->toString();
			if (!$reflectionProvider->hasClass($className)) {
				return null;
			}

			$class = $reflectionProvider->getClass($className);
			if (!$class->hasConstant($constant) || !$class->getConstant($constant)->isPublic()) {
				return null;
			}

			return new ClassConstFetch(new FullyQualified($className), $constant);
		}

		return null;
	}

}
