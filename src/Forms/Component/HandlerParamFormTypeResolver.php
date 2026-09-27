<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use function is_string;

/**
 * Types a bare handler-param form variable ($form inside an onSuccess/onClick handler) as the class
 * the form is actually registered with, rather than the declared base Nette\…\Form the signature
 * spells. The registered class is the constructed class proven scope-free at the registration site;
 * narrowing the variable to it makes the offset walk that reflects the builder's add* returns reflect
 * them on the constructed class, so every nested container/submit/replicator label — at the handler
 * vantage AND the same-file class-component reads the shared summaries would otherwise coarsen —
 * resolves to its concrete class. Only ever narrows to a strict subtype of the declared type
 * (ContainerModel::paramClassForVariable enforces it), so the type is never widened or made unrelated.
 */
final class HandlerParamFormTypeResolver implements ExpressionTypeResolverExtension
{

	private ConfigurationGuard $guard;

	private bool $enabled;

	private ContainerModel $model;

	public function __construct(ConfigurationGuard $guard, bool $enabled, ContainerModel $model)
	{
		// PHPStan builds type extensions with the container, before the reflection provider knows the
		// analysed paths (and in the stub validator's container too), so validate on first use.
		$this->guard = $guard;
		$this->enabled = $enabled;
		$this->model = $model;
	}

	public function getType(Expr $expr, Scope $scope): ?Type
	{
		$this->guard->validate();

		if (!$this->enabled) {
			return null;
		}

		if (!$expr instanceof Variable || !is_string($expr->name) || $expr->name === 'this') {
			return null;
		}

		$class = $this->model->paramClassForVariable($expr, $scope);

		return $class === null ? null : new ObjectType($class);
	}

}
