<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

use OriPhpstan\Nette\Forms\Cache\DependencyRecorder;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function count;
use function get_class;
use function is_int;

/**
 * Resolves a choice control's items argument to the union of its item KEYS, scope-free:
 * a literal array node is read directly from the AST and a self::CONST / Class::CONST
 * array via reflection on the constant's value type. Returns null whenever the item set
 * is not provably a closed set of int/string literal keys (a variable, a call, an
 * optgroup nested array, a mixed explicit/implicit key list, or an empty set).
 */
final class ChoiceItemKeyResolver
{

	private ?ReflectionProvider $reflectionProvider;

	private ?DependencyRecorder $recorder;

	public function __construct(?ReflectionProvider $reflectionProvider, ?DependencyRecorder $recorder = null)
	{
		$this->reflectionProvider = $reflectionProvider;
		$this->recorder = $recorder;
	}

	public function resolveKeyUnion(Expr $items, bool $useKeys, ?ClassReflection $classReflection): ?Type
	{
		$keys = $this->resolveKeys($items, $useKeys, $classReflection);
		if ($keys === null || $keys === []) {
			return null;
		}

		return TypeCombinator::union(...$keys);
	}

	/** @return list<ConstantIntegerType|ConstantStringType>|null */
	private function resolveKeys(Expr $items, bool $useKeys, ?ClassReflection $classReflection): ?array
	{
		if ($items instanceof Array_) {
			return $this->keysFromArrayNode($items, $useKeys);
		}

		if ($items instanceof ClassConstFetch) {
			return $this->keysFromConst($items, $useKeys, $classReflection);
		}

		return null;
	}

	/** @return list<ConstantIntegerType|ConstantStringType>|null */
	private function keysFromArrayNode(Array_ $node, bool $useKeys): ?array
	{
		/** @var list<ConstantIntegerType|ConstantStringType> $keys */
		$keys = [];
		$allExplicitKeys = true;
		$allImplicitKeys = true;

		foreach ($node->items as $item) {
			if ($item->unpack || $item->byRef) {
				return null;
			}

			if ($item->value instanceof Array_) {
				return null;
			}

			if (!$useKeys) {
				$valueLiteral = $this->scalarKeyFromNode($item->value, true);
				if ($valueLiteral === null) {
					return null;
				}

				$keys[] = $valueLiteral;

				continue;
			}

			if ($item->key === null) {
				$allExplicitKeys = false;

				continue;
			}

			$allImplicitKeys = false;
			$keyLiteral = $this->scalarKeyFromNode($item->key, false);
			if ($keyLiteral === null) {
				return null;
			}

			$keys[] = $keyLiteral;
		}

		if (!$useKeys) {
			return $keys;
		}

		if ($allImplicitKeys) {
			return $this->sequentialKeys(count($node->items));
		}

		if (!$allExplicitKeys) {
			return null;
		}

		return $keys;
	}

	/** @return list<ConstantIntegerType> */
	private function sequentialKeys(int $count): array
	{
		$keys = [];
		for ($i = 0; $i < $count; $i++) {
			$keys[] = new ConstantIntegerType($i);
		}

		return $keys;
	}

	/**
	 * @return ConstantIntegerType|ConstantStringType|null
	 */
	private function scalarKeyFromNode(Expr $node, bool $castIntToString)
	{
		if ($node instanceof String_) {
			return new ConstantStringType($node->value);
		}

		// php-parser >=5 names the integer-literal node Int_ (LNumber is a classmap-only
		// alias declared under if(false), never instantiated at runtime); a class-name
		// compare matches the real node without an instanceof PHPStan resolves against its
		// own bundled parser typing.
		if (get_class($node) === Int_::class) {
			/** @var Int_ $node */
			return $castIntToString
				? new ConstantStringType((string) $node->value)
				: new ConstantIntegerType($node->value);
		}

		return null;
	}

	/** @return list<ConstantIntegerType|ConstantStringType>|null */
	private function keysFromConst(ClassConstFetch $fetch, bool $useKeys, ?ClassReflection $classReflection): ?array
	{
		if (
			$this->reflectionProvider === null
			|| !$fetch->name instanceof Identifier
			|| !$fetch->class instanceof Name
		) {
			return null;
		}

		$className = $fetch->class->toString();
		if ($className === 'self' || $className === 'static') {
			$target = $classReflection;
		} elseif ($this->reflectionProvider->hasClass($className)) {
			$target = $this->reflectionProvider->getClass($className);
		} else {
			$target = null;
		}

		if ($target === null || !$target->hasConstant($fetch->name->toString())) {
			return null;
		}

		$constant = $target->getConstant($fetch->name->toString());
		$constantFile = $constant->getFileName();
		if ($this->recorder !== null && $constantFile !== null) {
			$this->recorder->record($constantFile);
		}

		$valueType = $constant->getValueType();
		$constantArrays = $valueType->getConstantArrays();
		if (count($constantArrays) !== 1) {
			return null;
		}

		$source = $useKeys
			? $constantArrays[0]->getKeyTypes()
			: $constantArrays[0]->getValueTypes();

		/** @var list<ConstantIntegerType|ConstantStringType> $keys */
		$keys = [];
		foreach ($source as $member) {
			$literal = $this->literalFromType($member, !$useKeys);
			if ($literal === null) {
				return null;
			}

			$keys[] = $literal;
		}

		return $keys;
	}

	/**
	 * @return ConstantIntegerType|ConstantStringType|null
	 */
	private function literalFromType(Type $type, bool $castIntToString)
	{
		$strings = $type->getConstantStrings();
		if (count($strings) === 1) {
			return $strings[0];
		}

		$scalars = $type->getConstantScalarValues();
		if (count($scalars) === 1 && is_int($scalars[0])) {
			return $castIntToString
				? new ConstantStringType((string) $scalars[0])
				: new ConstantIntegerType($scalars[0]);
		}

		return null;
	}

}
