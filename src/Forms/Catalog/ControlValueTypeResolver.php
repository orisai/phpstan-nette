<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;

interface ControlValueTypeResolver
{

	public function resolve(
		string $methodName,
		Type $receiverType,
		Expr $call,
		Scope $scope,
		bool $trustReceiverReflection = true
	): ControlValueResolution;

	public function resolveControlType(Type $controlType): ControlValueResolution;

	/**
	 * @return list<FormAddsSpec>
	 */
	public function addsSpecs(string $methodName, Type $receiverType, Scope $scope): array;

	public function bodyRefutesNameConvention(string $methodName, Type $receiverType, Scope $scope): bool;

	public function declaresAdds(string $containerClass, string $methodName): bool;

	public function choiceModel(string $controlClass): ?ChoiceModel;

	public function modifierEffect(string $controlClass, string $methodName): ?string;

	public function isFormDisabler(string $containerClass, string $methodName): bool;

	public function isReadByArgModifier(string $controlClass, string $methodName): bool;

	public function readTypeByArg(string $controlClass, string $methodName, ?string $argLiteral): ?Type;

	public function ruleCastType(string $ruleIdentifier): ?RuleCastType;

	public function getReflectionProvider(): ReflectionProvider;

}
