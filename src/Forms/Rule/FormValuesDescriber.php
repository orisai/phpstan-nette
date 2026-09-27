<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use Nette\Utils\ArrayHash;
use OriPhpstan\Nette\Forms\Component\ContainerModel;
use OriPhpstan\Nette\Forms\Component\ValidatedContext;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use OriPhpstan\Nette\Forms\Type\FormValuesProjector;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Type\VerbosityLevel;
use function ltrim;

/**
 * Projects the read-values shape behind an expression the same way getValues() does (the form's
 * ArrayHash crate, nested containers as nested ArrayHash, a setMappedType() DTO as its class) and
 * renders it via the native type describer, shared by dumpFormValues and assertFormValues.
 * Required fields narrow to their post-validation type in a validated / onSuccess context.
 */
final class FormValuesDescriber
{

	private ContainerModel $model;

	private ValidatedContext $validatedContext;

	public function __construct(ContainerModel $model)
	{
		$this->model = $model;
		$this->validatedContext = new ValidatedContext($model);
	}

	public function describe(Node\Expr $expr, Node\Expr $call, Scope $scope): string
	{
		$shape = $this->model->resolveFormShapeFromExpression($expr, $scope);
		if ($shape === null) {
			return $scope->getType($expr)->describe(VerbosityLevel::precise());
		}

		if ($shape->getMappedType() !== null) {
			return ltrim($shape->getMappedType(), '\\');
		}

		$type = $scope->getType($expr);
		$filled = $this->validatedContext->isFilledGetValues(
			$type instanceof FormShapeType ? $type : null,
			$call,
			$scope,
		);

		return FormValuesProjector::projectObject($shape, ArrayHash::class, $filled)
			->describe(VerbosityLevel::precise());
	}

}
