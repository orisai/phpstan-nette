<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Support;

// Expression sources for the {varType}-vs-assigned-expression fixtures. Each method deliberately
// carries a NATIVE return type wider than its PHPDoc one, which is what separates
// orisai.nette.latte.varTypeNativeType (the unconditional check) from orisai.nette.latte.varTypeType
// (reportWrongPhpDocTypeInVarType); a template-local literal can never produce that split.
final class VarTypeExpressionFixtureSource
{

	/**
	 * @return array<string>
	 */
	public static function rows(): array
	{
		return ['a'];
	}

	public static function maybeString(): ?string
	{
		return null;
	}

}
