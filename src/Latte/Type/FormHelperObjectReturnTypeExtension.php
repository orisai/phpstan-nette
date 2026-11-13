<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Type;

use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function count;
use function in_array;

// A field or container a template references by an object ({input $control}, {formContainer $container}) is that
// object: the helper's declared return only stands for a name the analysis resolves through the form.
final class FormHelperObjectReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{

	private const METHODS = ['formField', 'formFieldPart', 'formContainer'];

	public function getClass(): string
	{
		return Helpers::class;
	}

	public function isStaticMethodSupported(MethodReflection $methodReflection): bool
	{
		return in_array($methodReflection->getName(), self::METHODS, true);
	}

	public function getTypeFromStaticMethodCall(
		MethodReflection $methodReflection,
		StaticCall $methodCall,
		Scope $scope
	): ?Type
	{
		$args = $methodCall->getArgs();
		if (count($args) !== 1) {
			return null;
		}

		$argType = $scope->getType($args[0]->value);
		if (!$argType->isObject()->yes()) {
			return null;
		}

		$declared = ParametersAcceptorSelector::selectFromArgs($scope, $args, $methodReflection->getVariants())
			->getReturnType();
		$type = TypeCombinator::intersect($argType, $declared);

		return $type instanceof NeverType ? null : $type;
	}

}
