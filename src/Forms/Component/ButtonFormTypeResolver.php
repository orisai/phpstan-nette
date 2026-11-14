<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\Type;

/**
 * Types `$button->getForm()` inside a button-event handler as the form the button was added to, the
 * counterpart of the form parameter an onSuccess handler is typed by.
 */
final class ButtonFormTypeResolver implements ExpressionTypeResolverExtension
{

	private ConfigurationGuard $guard;

	private bool $enabled;

	private ContainerModel $model;

	public function __construct(ConfigurationGuard $guard, bool $enabled, ContainerModel $model)
	{
		$this->guard = $guard;
		$this->enabled = $enabled;
		$this->model = $model;
	}

	public function getType(Expr $expr, Scope $scope): ?Type
	{
		if (
			!$this->enabled
			|| !$expr instanceof MethodCall
			|| !$expr->name instanceof Identifier
			|| $expr->name->toString() !== 'getForm'
		) {
			return null;
		}

		return $this->model->trackDependencies($scope, function () use ($expr, $scope): ?Type {
			$this->guard->validate();

			$shape = $this->model->resolveButtonFormShape($expr, $scope);
			$className = $shape === null ? null : $shape->getClassName();
			if ($shape === null || ReturnShapeSupport::isClassNameUnresolved($className)) {
				return null;
			}

			return new FormShapeType($className, $shape);
		});
	}

}
