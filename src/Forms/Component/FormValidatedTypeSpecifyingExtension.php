<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Nette\Forms\Form as NetteForm;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\MethodTypeSpecifyingExtension;
use function in_array;

/**
 * Inside `if ($form->isValid())` / `if ($form->isSuccess())` the form has passed
 * validation, so the truthy branch narrows the form to a validated FormShapeType —
 * a getValues() there then projects the filled (post-validation) shape.
 */
final class FormValidatedTypeSpecifyingExtension implements MethodTypeSpecifyingExtension, TypeSpecifierAwareExtension
{

	private ConfigurationGuard $guard;

	private bool $enabled;

	private ContainerModel $model;

	private TypeSpecifier $typeSpecifier;

	public function __construct(ConfigurationGuard $guard, bool $enabled, ContainerModel $model)
	{
		// PHPStan builds type-specifying extensions with the container, before the reflection provider
		// knows the analysed paths (and in the stub validator's container too), so validate on first use.
		$this->guard = $guard;
		$this->enabled = $enabled;
		$this->model = $model;
	}

	public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
	{
		$this->typeSpecifier = $typeSpecifier;
	}

	public function getClass(): string
	{
		return NetteForm::class;
	}

	public function isMethodSupported(
		MethodReflection $methodReflection,
		MethodCall $node,
		TypeSpecifierContext $context
	): bool
	{
		$this->guard->validate();

		return $this->enabled
			&& $context->truthy()
			&& in_array($methodReflection->getName(), ['isValid', 'isSuccess'], true);
	}

	public function specifyTypes(
		MethodReflection $methodReflection,
		MethodCall $node,
		Scope $scope,
		TypeSpecifierContext $context
	): SpecifiedTypes
	{
		$shape = $this->model->resolveFormShapeFromExpression($node->var, $scope);
		if ($shape === null) {
			return new SpecifiedTypes();
		}

		$className = $shape->getClassName();
		if ($className === null) {
			return new SpecifiedTypes();
		}

		$validated = new FormShapeType($className, $shape, true);

		return $this->typeSpecifier->create($node->var, $validated, $context, $scope);
	}

}
