<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use OriPhpstan\Nette\Forms\Catalog\ControlAcceptedTypeResolver;
use OriPhpstan\Nette\Forms\Component\ContainerModel;
use OriPhpstan\Nette\Forms\Component\ValidatedContext;
use OriPhpstan\Nette\Forms\Shape\ComponentShapeRenderer;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use function sort;

/**
 * Resolves the component shape behind an expression (a wizard, a form/container, or an opaque
 * component) and renders it to the compact PHPStan-shape block shared by dumpComponent (which
 * prints it) and assertComponent (which compares it to an expected literal). Read value types
 * narrow to their post-validation form in a validated / onSuccess context, like getValues().
 */
final class ComponentShapeDescriber
{

	private ContainerModel $model;

	private ComponentShapeRenderer $renderer;

	private ValidatedContext $validatedContext;

	public function __construct(ContainerModel $model, TypeStringResolver $typeStringResolver)
	{
		$this->model = $model;
		$this->renderer = new ComponentShapeRenderer(new ControlAcceptedTypeResolver($typeStringResolver));
		$this->validatedContext = new ValidatedContext($model);
	}

	public function describe(Node\Expr $expr, Node\Expr $call, Scope $scope, ?int $maxDepth, bool $formValues): string
	{
		$type = $scope->getType($expr);
		$filled = $this->validatedContext->isFilledGetValues(
			$type instanceof FormShapeType ? $type : null,
			$call,
			$scope,
		);

		$wizardSteps = $this->model->resolveWizardStepShapes($expr, $scope);
		if ($wizardSteps !== null) {
			$classNames = self::sortedClassNames($type);

			return $this->renderer->renderWizard(
				$classNames === [] ? $type->describe(VerbosityLevel::precise()) : $classNames[0],
				$wizardSteps,
				$maxDepth,
				$formValues,
				$filled,
			);
		}

		$shape = $this->model->resolveComponentShape($expr, $scope);
		if ($shape !== null) {
			return $this->renderer->render($shape, $maxDepth, $formValues, $filled);
		}

		$classNames = self::sortedClassNames($type);

		return $this->renderer->renderOpaque(
			$classNames === [] ? $type->describe(VerbosityLevel::precise()) : $classNames[0],
			['unresolved_origin'],
		);
	}

	/**
	 * @return list<string>
	 */
	private static function sortedClassNames(Type $type): array
	{
		// PHPStan's union member order is an implementation detail that changed between releases.
		$classNames = $type->getObjectClassNames();
		sort($classNames);

		return $classNames;
	}

	public static function intArg(?Arg $arg, Scope $scope): ?int
	{
		if ($arg === null) {
			return null;
		}

		$type = $scope->getType($arg->value);

		return $type instanceof ConstantIntegerType ? $type->getValue() : null;
	}

	public static function boolArg(?Arg $arg, Scope $scope): bool
	{
		if ($arg === null) {
			return true;
		}

		return !$scope->getType($arg->value)->isFalse()->yes();
	}

}
