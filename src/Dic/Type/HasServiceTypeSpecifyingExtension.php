<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Type;

use Nette\DI\Container;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Accessory\HasMethodType;
use PHPStan\Type\MethodTypeSpecifyingExtension;
use PHPStan\Type\TypeCombinator;
use function array_merge;
use function strtolower;

final class HasServiceTypeSpecifyingExtension implements MethodTypeSpecifyingExtension, TypeSpecifierAwareExtension
{

	private ConfigurationGuard $guard;

	private MultiContainerRegistry $registry;

	private TypeSpecifier $typeSpecifier;

	public function __construct(ConfigurationGuard $guard, MultiContainerRegistry $registry)
	{
		$this->guard = $guard;
		$this->registry = $registry;
	}

	public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
	{
		$this->typeSpecifier = $typeSpecifier;
	}

	public function getClass(): string
	{
		return Container::class;
	}

	public function isMethodSupported(
		MethodReflection $methodReflection,
		MethodCall $node,
		TypeSpecifierContext $context
	): bool
	{
		$this->guard->validate();

		return $this->registry->isActive()
			&& ($context->true() || $context->false())
			&& strtolower($methodReflection->getName()) === 'hasservice'
			&& !$node->isFirstClassCallable()
			&& $node->getArgs() !== [];
	}

	public function specifyTypes(
		MethodReflection $methodReflection,
		MethodCall $node,
		Scope $scope,
		TypeSpecifierContext $context
	): SpecifiedTypes
	{
		$receiverType = $scope->getType($node->var);

		// Narrowing is only meaningful for base-class receivers; a known-class receiver
		// answers existence exactly and an unknown container has an unknown service universe.
		$resolution = $this->registry->resolveProfiles($receiverType);

		if ($resolution === null || !$resolution->isBase()) {
			return new SpecifiedTypes([], []);
		}

		$profiles = $resolution->getProfiles();

		$constantStrings = $scope->getType($node->getArgs()[0]->value)->getConstantStrings();

		if ($constantStrings === []) {
			return new SpecifiedTypes([], []);
		}

		$methodName = null;

		foreach ($constantStrings as $constantString) {
			$resolved = $this->registry->getResolvedServiceMethodName($constantString->getValue(), false, $profiles);

			if ($resolved === null) {
				return new SpecifiedTypes([], []);
			}

			if ($methodName === null) {
				$methodName = $resolved;
			} elseif ($methodName !== $resolved) {
				return new SpecifiedTypes([], []);
			}
		}

		if ($context->true()) {
			return $this->typeSpecifier->create(
				$node->var,
				TypeCombinator::intersect($receiverType, new HasMethodType($methodName)),
				$context,
				$scope,
			);
		}

		$missingMethodNames = [$methodName];

		if ($receiverType instanceof ContainerMissingServicesType) {
			$missingMethodNames = array_merge($receiverType->getMissingMethodNames(), $missingMethodNames);
		}

		return $this->typeSpecifier->create(
			$node->var,
			new ContainerMissingServicesType($missingMethodNames),
			TypeSpecifierContext::createTruthy(),
			$scope,
		);
	}

}
