<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Declarations\VarTypePlacement;
use function array_map;
use function count;
use function implode;
use function in_array;
use function sprintf;

// Mid-file {varType} PLACEMENT, modelled on PHPStan\Rules\PhpDoc\WrongVariableNameInVarTagRule:
// a declaration that only applies from its own position onward must sit directly above the
// construct that binds the variable, so its scope is self-evident from where it is written.
//
// Deliberately stricter than upstream for the same reason the two type options below are: PHP's
// `@var` may be unnamed, so upstream's anchor list mostly serves to decide WHICH variable an
// unnamed tag means, and a NAMED tag above an arbitrary statement is only checked for existence.
// Every Latte {varType} is named (Latte's own compiler rejects `{varType string}` outright), so the
// anchor list is repurposed here as the placement contract it describes.
//
// The neighbouring axis is DeclarationConsistencyChecker's: {varType} against a NATIVE declaration
// (a {templateType} property, a {define} param). This one is about POSITION only; the third axis,
// {varType} against the assigned EXPRESSION's inferred type, needs a real Scope and lives in
// LatteVarTypeExpressionRule.
final class VarTypePlacementChecker
{

	public const MISPLACED_IDENTIFIER = 'orisai.nette.latte.varTypeMisplaced';

	public const DIFFERENT_VARIABLE_IDENTIFIER = 'orisai.nette.latte.varTypeDifferentVariable';

	public const VARIABLE_NOT_FOUND_IDENTIFIER = 'orisai.nette.latte.varTypeVariableNotFound';

	/**
	 * @return list<Diagnostic>
	 */
	public function check(Declarations $declarations): array
	{
		$diagnostics = [];
		foreach ($declarations->getVarTypePlacements() as $placement) {
			$diagnostic = $this->checkPlacement($placement);
			if ($diagnostic !== null) {
				$diagnostics[] = $diagnostic;
			}
		}

		return $diagnostics;
	}

	private function checkPlacement(VarTypePlacement $placement): ?Diagnostic
	{
		if ($placement->getAnchorKind() === null) {
			// A variable nothing in the template ever binds cannot be given the assignment the
			// message asks for - the tag is a template PARAMETER that lost its header position to a
			// preceding non-header tag, and the header is the only fix. Carried as a tip rather than
			// a second identifier: it is the same misplacement, with a different remedy.
			$tip = $placement->isNeverAssigned()
				? sprintf(
					'Variable $%s is never assigned in this template - move the {varType} above the'
					. ' first non-header tag to declare it as a parameter for the whole template instead.',
					$placement->getName(),
				)
				: null;

			return new Diagnostic(
				self::MISPLACED_IDENTIFIER,
				sprintf(
					'Mid-file {varType} for $%s must sit directly above an assignment to $%s, not above %s.',
					$placement->getName(),
					$placement->getName(),
					$placement->getAnchorLabel(),
				),
				$placement->getLine(),
				$tip,
			);
		}

		$bound = $placement->getBoundVariables();
		if ($bound === null || in_array($placement->getName(), $bound, true)) {
			return null;
		}

		// Mirrors WrongVariableNameInVarTagRule::processAssign()'s `hasVariableType()` guard: a name
		// already known in scope before this tag is accepted silently, even though it does not match
		// THIS particular anchor - upstream is silent here, so this stays silent too.
		if ($placement->isKnownBeforeTag()) {
			return null;
		}

		if ($placement->getAnchorKind() === VarTypePlacement::ANCHOR_FOREACH) {
			return new Diagnostic(
				self::DIFFERENT_VARIABLE_IDENTIFIER,
				sprintf(
					'Variable $%s in {varType} does not match any variable in the foreach loop: %s',
					$placement->getName(),
					$this->listVariables($bound),
				),
				$placement->getLine(),
			);
		}

		// Upstream names the single mismatched counterpart only when there is exactly one of each;
		// with more than one tag or more than one assigned variable it can no longer say which one
		// was meant, and falls back to the generic identifier.
		if ($placement->getRunSize() === 1 && count($bound) === 1) {
			return new Diagnostic(
				self::DIFFERENT_VARIABLE_IDENTIFIER,
				sprintf(
					'Variable $%s in {varType} does not match assigned variable $%s.',
					$placement->getName(),
					$bound[0],
				),
				$placement->getLine(),
			);
		}

		// Mirrors processAssign()'s own variableNotFound branch exactly: with more than one
		// candidate, upstream no longer lists them - it just says the name does not exist.
		return new Diagnostic(
			self::VARIABLE_NOT_FOUND_IDENTIFIER,
			sprintf('Variable $%s in {varType} does not exist.', $placement->getName()),
			$placement->getLine(),
		);
	}

	/**
	 * @param list<string> $names
	 */
	private function listVariables(array $names): string
	{
		return implode(', ', array_map(static fn (string $name): string => '$' . $name, $names));
	}

}
