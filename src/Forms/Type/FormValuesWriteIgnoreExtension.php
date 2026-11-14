<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Type;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Error;
use PHPStan\Analyser\IgnoreErrorExtension;
use PHPStan\Analyser\Scope;
use PHPStan\Node\PropertyAssignNode;
use PHPStan\Type\TypeCombinator;
use function array_key_exists;

/**
 * A values crate is a real ArrayHash/stdClass, so writing one of its fields is legal at runtime, but
 * the object-shape property it is typed by is read-only and a writable one cannot be supplied.
 */
final class FormValuesWriteIgnoreExtension implements IgnoreErrorExtension
{

	private const IDENTIFIER = 'assign.propertyReadOnly';

	public function shouldIgnore(Error $error, Node $node, Scope $scope): bool
	{
		if ($error->getIdentifier() !== self::IDENTIFIER || !$node instanceof PropertyAssignNode) {
			return false;
		}

		$fetch = $node->getPropertyFetch();
		if (!$fetch instanceof PropertyFetch || !$fetch->name instanceof Identifier) {
			return false;
		}

		$type = TypeCombinator::removeNull($scope->getType($fetch->var));

		return $type instanceof FormValuesObjectShapeType
			&& array_key_exists($fetch->name->toString(), $type->getProperties());
	}

}
