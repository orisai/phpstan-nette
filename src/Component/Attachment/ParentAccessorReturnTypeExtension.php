<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component\Attachment;

use Nette\ComponentModel\Component;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * The declared `?Presenter` on a throwing accessor is a lie on the default path.
 *
 * `Component::lookup()` answers null only when it is told not to throw; with `$throw` true - which
 * every one of these leaves as its default - the not-found case raises instead of returning. So
 * `getPresenter()`, `getForm()`, `lookup($type)` and `lookupPath()` never hand back null, and the
 * nullable return type they declare describes their OTHER call site, the one that passes false.
 *
 * The narrowing is therefore per call site rather than per method, off the same
 * ThrowingAccessorCall the unattached-access rule asks, and it degrades to the declared type
 * wherever the `$throw` argument cannot be read. `getPresenterIfExists()` is untouched: it is the
 * honestly nullable member of the family and the spelling this rule's tips point people at.
 *
 * The receiver class is the root of the whole component model, so one extension covers every
 * declaring class in the table; which of them is being called is decided by the table, not by the
 * registration.
 */
final class ParentAccessorReturnTypeExtension implements DynamicMethodReturnTypeExtension
{

	private ConfigurationGuard $guard;

	private bool $enabled;

	public function __construct(ConfigurationGuard $guard, bool $enabled)
	{
		// PHPStan builds type extensions with the container, before the reflection provider knows the
		// analysed paths (and in the stub validator's container too), so validate on first use.
		$this->guard = $guard;
		$this->enabled = $enabled;
	}

	public function getClass(): string
	{
		return Component::class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		$this->guard->validate();

		if (!$this->enabled) {
			return false;
		}

		$name = $methodReflection->getName();

		// lookup() is already claimed by phpstan-nette's own
		// ComponentLookupDynamicReturnTypeExtension, which is registered for the same class and
		// answers a Type unconditionally, so whichever of the two the container happens to order
		// first would win. Standing aside is what makes the outcome deterministic rather than a
		// registration-order accident; the cost is that its narrowing stays the partial one it has
		// always been - null removed only where $throw is spelled out as true, not where it is left
		// at the default that also throws.
		if ($name === ParentAccessors::LOOKUP) {
			return false;
		}

		return ParentAccessors::isThrowingAccessor($methodReflection->getDeclaringClass()->getName(), $name);
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope
	): ?Type
	{
		if (!ThrowingAccessorCall::throwsWhenDetached($methodReflection, $methodCall, $scope)) {
			return null;
		}

		$declared = ParametersAcceptorSelector::selectFromArgs(
			$scope,
			$methodCall->getArgs(),
			$methodReflection->getVariants(),
		)->getReturnType();

		return TypeCombinator::removeNull($declared);
	}

}
