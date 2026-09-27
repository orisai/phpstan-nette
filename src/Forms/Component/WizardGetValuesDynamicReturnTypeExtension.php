<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Nette\Application\UI\Component as UiComponent;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * getValues() on a @form-wizard-tagged component aggregates the steps indexed by step
 * number. The extension is bound to the broad UI component base; ContainerModel gates
 * on the @form-wizard tag, so only tagged wizards are shaped and every other component
 * keeps its native return type.
 */
final class WizardGetValuesDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
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

	public function getClass(): string
	{
		return UiComponent::class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		$this->guard->validate();

		return $methodReflection->getName() === 'getValues';
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope
	): ?Type
	{
		if (!$this->enabled) {
			return null;
		}

		return $this->model->resolveWizardValuesType($methodCall->var, $scope);
	}

}
