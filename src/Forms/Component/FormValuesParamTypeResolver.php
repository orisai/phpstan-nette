<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Nette\Utils\ArrayHash;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Type\FormValuesProjector;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use stdClass;
use function is_string;
use function ltrim;

final class FormValuesParamTypeResolver implements ExpressionTypeResolverExtension
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
		if (!$this->enabled) {
			return null;
		}

		if (!$expr instanceof Variable || !is_string($expr->name)) {
			return null;
		}

		$this->guard->validate();

		$shape = $this->model->resolveEventCallbackFormShape($expr, $scope);
		if ($shape === null) {
			return null;
		}

		[$param, $closure] = $this->findClosureParam($expr, $scope);
		if ($param === null) {
			return null;
		}

		$paramTypeName = $this->paramTypeName($param);
		if ($paramTypeName === null) {
			return null;
		}

		// onSuccess fires only when the form is valid, so its values are the
		// post-validation (filled) shape; onValidate/onError/onSubmit are not.
		$filled = (new ValidatedContext($this->model))->isFilledEventParam($expr, $scope);

		$paramTypeName = ltrim($paramTypeName, '\\');
		if ($paramTypeName === 'array') {
			return FormValuesProjector::projectArray($shape, $filled);
		}

		if ($paramTypeName === ArrayHash::class || $paramTypeName === stdClass::class) {
			return FormValuesProjector::projectObject($shape, $paramTypeName, $filled);
		}

		// A custom ArrayHash subclass still behaves as the crate; anything else
		// (a mapped DTO) keeps its declared type — reading it is covered by PHPStan.
		if ((new ObjectType(ArrayHash::class))->isSuperTypeOf(new ObjectType($paramTypeName))->yes()) {
			return FormValuesProjector::projectObject($shape, $paramTypeName, $filled);
		}

		return null;
	}

	/**
	 * @return array{0: Param|null, 1: Closure|null}
	 */
	private function findClosureParam(Variable $node, Scope $scope): array
	{
		if (!$scope->isInClass() || $scope->getFunctionName() === null) {
			return [null, null];
		}

		$pair = $this->model->ownerAndEnclosing($scope);
		if ($pair === null) {
			return [null, null];
		}

		[, $enclosing] = $pair;

		$varName = $node->name;
		foreach ((new NodeFinder())->findInstanceOf($enclosing->getStmts() ?? [], Closure::class) as $closure) {
			foreach ($closure->getParams() as $param) {
				if ($param->var instanceof Variable && $param->var->name === $varName) {
					foreach ((new NodeFinder())->findInstanceOf([$closure], Variable::class) as $v) {
						if ($v === $node) {
							return [$param, $closure];
						}
					}
				}
			}
		}

		return [null, null];
	}

	private function paramTypeName(Param $param): ?string
	{
		if ($param->type instanceof Name) {
			return $param->type->toString();
		}

		if ($param->type instanceof NullableType && $param->type->type instanceof Name) {
			return $param->type->type->toString();
		}

		if ($param->type instanceof Identifier && $param->type->toString() === 'array') {
			return 'array';
		}

		return null;
	}

}
