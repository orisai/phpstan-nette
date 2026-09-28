<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Type;

use Nette\DI\Container;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

final class ParametersPropertyTypeExtension implements ExpressionTypeResolverExtension
{

	private ConfigurationGuard $guard;

	private MultiContainerRegistry $registry;

	public function __construct(ConfigurationGuard $guard, MultiContainerRegistry $registry)
	{
		$this->guard = $guard;
		$this->registry = $registry;
	}

	public function getType(Expr $expr, Scope $scope): ?Type
	{
		$this->guard->validate();

		if (!$expr instanceof PropertyFetch || !$this->registry->isActive()) {
			return null;
		}

		if (!$expr->name instanceof Identifier || $expr->name->toString() !== 'parameters') {
			return null;
		}

		$varType = $scope->getType($expr->var);
		if (!(new ObjectType(Container::class))->isSuperTypeOf($varType)->yes()) {
			return null;
		}

		$resolution = $this->registry->resolveProfiles($varType);
		if ($resolution === null) {
			return null;
		}

		return $this->registry->getMergedParametersType($resolution->getProfiles());
	}

}
