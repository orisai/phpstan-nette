<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\NameScope;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\NameScopeAlreadyBeingCreatedException;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ArrayType;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\IsSuperTypeOfResult;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\VerbosityLevel;
use function array_map;
use function sprintf;

// A mid-file {varType}'s declared type against the type of the expression its anchor actually
// assigns - a port of PHPStan\Rules\PhpDoc\VarTagTypeRuleHelper, including both of its knobs, whose
// Latte-side names and (deliberately non-core) defaults live in wiring.neon.
//
// Not merged into DeclarationConsistencyChecker: that axis compares two DECLARED type strings and
// needs no Scope at all, while this one needs the analysis engine's own inferred type for a real
// expression, which only a registered Rule ever receives.
//
// Upstream's third identifier, phpstanApi.varTagAssumption, is deliberately not ported: it fires
// only when a PHPStan Type object is the inferred type of the assigned expression, which is a
// PHPStan-extension-authoring situation, never a template one.
final class VarTypeExpressionChecker
{

	public const NATIVE_TYPE_IDENTIFIER = 'orisai.nette.latte.varTypeNativeType';

	public const TYPE_IDENTIFIER = 'orisai.nette.latte.varTypeType';

	private TypeNodeResolver $typeNodeResolver;

	private FileTypeMapper $fileTypeMapper;

	private bool $checkTypeAgainstPhpDocType;

	private bool $strictWideningCheck;

	public function __construct(
		TypeNodeResolver $typeNodeResolver,
		FileTypeMapper $fileTypeMapper,
		bool $checkTypeAgainstPhpDocType,
		bool $strictWideningCheck
	)
	{
		$this->typeNodeResolver = $typeNodeResolver;
		$this->fileTypeMapper = $fileTypeMapper;
		$this->checkTypeAgainstPhpDocType = $checkTypeAgainstPhpDocType;
		$this->strictWideningCheck = $strictWideningCheck;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function check(Scope $scope, string $variableName, Expr $expr, Type $varTypeType, int $line): array
	{
		$exprNativeType = $scope->getScopeNativeType($expr);
		$isValidSuperTypeOfExpr = $this->isValidSuperTypeOfExpr($scope, $expr, $exprNativeType, $varTypeType);

		if (!$isValidSuperTypeOfExpr->yes()) {
			$verbosity = VerbosityLevel::getRecommendedLevelByType($exprNativeType, $varTypeType);

			return [
				RuleErrorBuilder::message(sprintf(
					'{varType} for $%s with type %s is not subtype of native type %s.',
					$variableName,
					$varTypeType->describe($verbosity),
					$exprNativeType->describe($verbosity),
				))
					->acceptsReasonsTip($isValidSuperTypeOfExpr->reasons)
					->identifier(self::NATIVE_TYPE_IDENTIFIER)
					->line($line)
					->build(),
			];
		}

		if (!$this->checkTypeAgainstPhpDocType) {
			return [];
		}

		$exprType = $scope->getScopeType($expr);
		$isValidSuperTypeOfExpr = $this->isValidSuperTypeOfExpr($scope, $expr, $exprType, $varTypeType);
		if ($isValidSuperTypeOfExpr->yes()) {
			return [];
		}

		$verbosity = VerbosityLevel::getRecommendedLevelByType($exprType, $varTypeType);

		return [
			RuleErrorBuilder::message(sprintf(
				'{varType} for $%s with type %s is not subtype of type %s.',
				$variableName,
				$varTypeType->describe($verbosity),
				$exprType->describe($verbosity),
			))
				->acceptsReasonsTip($isValidSuperTypeOfExpr->reasons)
				->identifier(self::TYPE_IDENTIFIER)
				->line($line)
				->build(),
		];
	}

	private function isValidSuperTypeOfExpr(
		Scope $scope,
		Expr $expr,
		Type $type,
		Type $varTypeType
	): IsSuperTypeOfResult
	{
		if ($expr instanceof Expr\Array_) {
			if ($expr->items === []) {
				$type = new ArrayType(new MixedType(), new MixedType());
			}

			return $this->isAtLeastMaybeSuperTypeOfVarType($scope, $type, $varTypeType);
		}

		if ($expr instanceof Expr\ConstFetch || $expr instanceof Node\Scalar) {
			return $this->isAtLeastMaybeSuperTypeOfVarType($scope, $type, $varTypeType);
		}

		if ($expr instanceof Expr\New_ && $type instanceof GenericObjectType) {
			$type = new ObjectType($type->getClassName());
		}

		return $this->isValidSuperType($scope, $type, $varTypeType);
	}

	private function isValidSuperType(Scope $scope, Type $type, Type $varTypeType, int $depth = 0): IsSuperTypeOfResult
	{
		if ($this->strictWideningCheck) {
			return $this->isSuperTypeOfVarType($scope, $type, $varTypeType);
		}

		$type = TypeTraverser::map($type, static function (Type $type, callable $traverse): Type {
			if ($type instanceof GenericObjectType) {
				$type = $type->changeVariances(array_map(
					static fn (TemplateTypeVariance $variance): TemplateTypeVariance => $variance->invariant()
						? TemplateTypeVariance::createCovariant()
						: $variance,
					$type->getVariances(),
				));
			}

			return $traverse($type);
		});

		if ($type->isConstantArray()->yes() && $type->isIterableAtLeastOnce()->no()) {
			return $this->isAtLeastMaybeSuperTypeOfVarType(
				$scope,
				new ArrayType(new MixedType(), new MixedType()),
				$varTypeType,
			);
		}

		if ($type->isIterable()->yes() && $varTypeType->isIterable()->yes()) {
			$isAtLeastMaybeSuperTypeOf = $this->isAtLeastMaybeSuperTypeOfVarType($scope, $type, $varTypeType);
			if ($isAtLeastMaybeSuperTypeOf->no()) {
				return $isAtLeastMaybeSuperTypeOf;
			}

			$innerType = $type->getIterableValueType();
			$innerVarTypeType = $varTypeType->getIterableValueType();
			if ($type->equals($innerType) || $varTypeType->equals($innerVarTypeType)) {
				return $this->isSuperTypeOfVarType($scope, $innerType, $innerVarTypeType);
			}

			return $this->isValidSuperType($scope, $innerType, $innerVarTypeType, $depth + 1);
		}

		if ($depth === 0 && $type->isConstantValue()->yes()) {
			return $this->isAtLeastMaybeSuperTypeOfVarType($scope, $type, $varTypeType);
		}

		return $this->isSuperTypeOfVarType($scope, $type, $varTypeType);
	}

	private function isSuperTypeOfVarType(Scope $scope, Type $type, Type $varTypeType): IsSuperTypeOfResult
	{
		if ($type->isSuperTypeOf($varTypeType)->yes()) {
			return IsSuperTypeOfResult::createYes();
		}

		try {
			$type = $this->typeNodeResolver->resolve($type->toPhpDocNode(), $this->createNameScope($scope));
		} catch (NameScopeAlreadyBeingCreatedException $e) {
			return IsSuperTypeOfResult::createYes();
		}

		return $type->isSuperTypeOf($varTypeType);
	}

	private function isAtLeastMaybeSuperTypeOfVarType(Scope $scope, Type $type, Type $varTypeType): IsSuperTypeOfResult
	{
		if (!$type->isSuperTypeOf($varTypeType)->no()) {
			return IsSuperTypeOfResult::createYes();
		}

		try {
			$type = $this->typeNodeResolver->resolve($type->toPhpDocNode(), $this->createNameScope($scope));
		} catch (NameScopeAlreadyBeingCreatedException $e) {
			return IsSuperTypeOfResult::createYes();
		}

		$isSuperTypeOf = $type->isSuperTypeOf($varTypeType);

		return $isSuperTypeOf->no() ? $isSuperTypeOf : IsSuperTypeOfResult::createYes();
	}

	/**
	 * @throws NameScopeAlreadyBeingCreatedException
	 */
	private function createNameScope(Scope $scope): NameScope
	{
		$function = $scope->getFunction();

		return $this->fileTypeMapper->getNameScope(
			$scope->getFile(),
			$scope->isInClass() ? $scope->getClassReflection()->getName() : null,
			$scope->isInTrait() ? $scope->getTraitReflection()->getName() : null,
			$function !== null ? $function->getName() : null,
		)->withoutNamespaceAndUses();
	}

}
