<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\Type;
use function spl_object_id;

/**
 * $form['field']->getValue() resolves to the same value type as the matching
 * member of $form->getValues() — both are `$control->getValue()` at runtime.
 *
 * Implemented as an ExpressionTypeResolverExtension (not a
 * DynamicMethodReturnTypeExtension) on purpose: getValue() is declared
 * `@return mixed` on Nette\Forms\Controls\BaseControl, and for controls that do
 * not override it (e.g. Checkbox) PHPStan unions a dynamic-extension result with
 * that native mixed, widening it back to mixed. An ExpressionTypeResolverExtension
 * runs first and short-circuits, so the precise type wins for every control.
 */
final class FormControlGetValueTypeResolver implements ExpressionTypeResolverExtension
{

	private ConfigurationGuard $guard;

	private bool $enabled;

	private ContainerModel $model;

	/** @var array<string, true> */
	private array $resolving = [];

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
		if (!$this->enabled) {
			return null;
		}

		if (
			!$expr instanceof MethodCall
			|| !$expr->name instanceof Identifier
			|| $expr->name->toString() !== 'getValue'
			|| $expr->isFirstClassCallable()
			|| $expr->getArgs() !== []
		) {
			return null;
		}

		$this->guard->validate();

		$guardKey = $scope->getFile() . '|' . spl_object_id($expr);
		if (isset($this->resolving[$guardKey])) {
			return null;
		}

		$this->resolving[$guardKey] = true;
		try {
			return $this->model->resolveControlValueType($expr->var, $scope);
		} finally {
			unset($this->resolving[$guardKey]);
		}
	}

}
