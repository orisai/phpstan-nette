<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Includes\VarTypePlacementChecker;
use OriPhpstan\Nette\Latte\Version\Latte2\DeclarationScanner;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_map;

/**
 * @group latte2
 */
final class VarTypePlacementCheckerTest extends BaseTestCase
{

	// === exempt positions: a {varType} that declares a PARAMETER, never a mid-file local ===

	public function testHeaderVarTypeIsExempt(): void
	{
		self::assertSame([], $this->idsFor("{varType string \$x}\n{\$x}\n"));
	}

	public function testBlockBodyVarTypeIsExempt(): void
	{
		// A {block}/{define} body varType at the block's own top level is that block's input
		// contract (TemplateFactExtractor records it as a blockDeclaredVar), i.e. a parameter
		// declaration exactly like a header one - never a mid-file local.
		self::assertSame([], $this->idsFor("{var \$q = 1}\n{define b}\n{varType string \$x}\n{\$x}\n{/define}\n"));
	}

	public function testDefineBodyVarTypeNestedInsideAConditionIsNotExempt(): void
	{
		self::assertSame(
			['orisaiNette.latte.varTypeMisplaced'],
			$this->idsFor("{var \$q = 1}\n{define b}\n{if true}\n{varType string \$x}\n{/if}\n{/define}\n"),
		);
	}

	// An anonymous {block} (no name at all) is never a call target - TemplateFactExtractor never
	// records a blockDeclaredVar for it either - so a {varType} at its own top level gets no
	// exemption from either axis and is checked as an ordinary mid-file local (BlockBodyTracker's
	// shared name gate, matching TemplateFactExtractor's own blockNames/defineNames one).
	public function testAnonymousBlockBodyVarTypeIsNotExempt(): void
	{
		self::assertSame(
			['orisaiNette.latte.varTypeMisplaced'],
			$this->idsFor("{var \$q = 1}\n{block}\n{varType string \$x}\n{\$x}\n{/block}\n"),
		);
	}

	public function testDynamicallyNamedDefineBodyVarTypeIsNotExempt(): void
	{
		self::assertSame(
			['orisaiNette.latte.varTypeMisplaced'],
			$this->idsFor(
				"{var \$q = 1}\n{var \$name = 'b'}\n{define \$name}\n{varType string \$x}\n{\$x}\n{/define}\n",
			),
		);
	}

	// === accepted anchors ===

	public function testDirectlyAboveVarIsSilent(): void
	{
		self::assertSame([], $this->idsFor("{var \$q = 1}\n{varType string \$x}\n{var \$x = 'a'}\n"));
	}

	public function testDirectlyAboveDefaultIsSilent(): void
	{
		self::assertSame([], $this->idsFor("{var \$q = 1}\n{varType string \$x}\n{default \$x = 'a'}\n"));
	}

	public function testDirectlyAboveCaptureIsSilent(): void
	{
		self::assertSame([], $this->idsFor("{var \$q = 1}\n{varType string \$v}\n{capture \$v}hi{/capture}\n"));
	}

	public function testDirectlyAboveDoAssignmentIsSilent(): void
	{
		self::assertSame([], $this->idsFor("{var \$q = 1}\n{varType string \$x}\n{do \$x = 'a'}\n"));
	}

	public function testDirectlyAboveForeachNamingTheItemIsSilent(): void
	{
		// The owner ruled AGAINST an "the iterable would be the better declaration site" warning -
		// a foreach is a first-class anchor, exactly as it is for PHPStan's own @var.
		self::assertSame(
			[],
			$this->idsFor("{var \$q = 1}\n{varType string \$item}\n{foreach \$items as \$item}{/foreach}\n"),
		);
	}

	public function testDirectlyAboveForeachNamingTheKeyIsSilent(): void
	{
		self::assertSame(
			[],
			$this->idsFor("{var \$q = 1}\n{varType int \$k}\n{foreach \$items as \$k => \$item}{/foreach}\n"),
		);
	}

	public function testDirectlyAboveForeachNamingTheIterableIsSilent(): void
	{
		self::assertSame(
			[],
			$this->idsFor("{var \$q = 1}\n{varType array \$items}\n{foreach \$items as \$item}{/foreach}\n"),
		);
	}

	public function testDirectlyAboveAnNForeachElementIsSilent(): void
	{
		self::assertSame(
			[],
			$this->idsFor(
				"{var \$q = 1}\n{varType string \$item}\n<div n:foreach=\"\$items as \$item\">{\$item}</div>\n",
			),
		);
	}

	public function testNForeachElementNotBindingTheNamedVariableReportsDifferentVariable(): void
	{
		self::assertSame(
			['orisaiNette.latte.varTypeDifferentVariable'],
			$this->idsFor("{var \$q = 1}\n{varType string \$other}\n<div n:foreach=\"\$items as \$item\">x</div>\n"),
		);
	}

	public function testAnElementWithoutAForeachAttributeIsMisplaced(): void
	{
		$diagnostics = $this->diagnosticsFor("{var \$q = 1}\n{varType string \$x}\n<div n:if=\"\$q\">x</div>\n");

		self::assertCount(1, $diagnostics);
		self::assertSame(
			'Mid-file {varType} for $x must sit directly above an assignment to $x, not above <div>.',
			$diagnostics[0]->getMessage(),
		);
	}

	public function testCommentsAndBlankTextDoNotBreakTheAnchor(): void
	{
		self::assertSame([], $this->idsFor("{var \$q = 1}\n{varType string \$x}\n{* why *}\n\n{var \$x = 'a'}\n"));
	}

	public function testARunOfVarTypesSharesOneAnchor(): void
	{
		self::assertSame(
			[],
			$this->idsFor("{var \$q = 1}\n{varType string \$x}\n{varType int \$y}\n{var \$x = 'a', \$y = 1}\n"),
		);
	}

	// === misplaced ===

	public function testSeparatedFromItsAssignmentByAnotherStatementIsMisplaced(): void
	{
		$diagnostics = $this->diagnosticsFor("{var \$q = 1}\n{varType string \$x}\n{\$q}\n{var \$x = 'a'}\n");

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.varTypeMisplaced', $diagnostics[0]->getIdentifier());
		self::assertSame(2, $diagnostics[0]->getLatteLine());
		self::assertSame(
			'Mid-file {varType} for $x must sit directly above an assignment to $x, not above an output tag.',
			$diagnostics[0]->getMessage(),
		);
	}

	public function testAboveAConstructThatAssignsNothingIsMisplaced(): void
	{
		$diagnostics = $this->diagnosticsFor("{var \$q = 1}\n{varType string \$x}\n{if \$q}{/if}\n");

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.varTypeMisplaced', $diagnostics[0]->getIdentifier());
		self::assertSame(
			'Mid-file {varType} for $x must sit directly above an assignment to $x, not above {if}.',
			$diagnostics[0]->getMessage(),
		);
	}

	public function testAboveAClosingTagIsMisplaced(): void
	{
		$diagnostics = $this->diagnosticsFor("{if true}{varType string \$x}{/if}\n");

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.varTypeMisplaced', $diagnostics[0]->getIdentifier());
		self::assertSame(
			'Mid-file {varType} for $x must sit directly above an assignment to $x, not above {/if}.',
			$diagnostics[0]->getMessage(),
		);
	}

	public function testAtTheEndOfTheTemplateIsMisplaced(): void
	{
		$diagnostics = $this->diagnosticsFor("{var \$q = 1}\n{varType string \$x}");

		self::assertCount(1, $diagnostics);
		self::assertSame(
			'Mid-file {varType} for $x must sit directly above an assignment to $x, not above the end of the template.',
			$diagnostics[0]->getMessage(),
		);
	}

	// === the demoted-parameter tip ===

	// A {varType} for a variable the template never binds is a PARAMETER declaration that lost its
	// header position to a preceding non-header tag: the assignment the message asks for cannot be
	// written, so the tip names the only real fix.
	public function testAVariableNeverAssignedAnywhereGetsTheHeaderTip(): void
	{
		$diagnostics = $this->diagnosticsFor("{var \$q = 1}\n{varType array<string> \$items}\n{\$items}\n");

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.varTypeMisplaced', $diagnostics[0]->getIdentifier());
		self::assertSame(
			'Variable $items is never assigned in this template - move the {varType} above the first'
			. ' non-header tag to declare it as a parameter for the whole template instead.',
			$diagnostics[0]->getTip(),
		);
	}

	// Assigned further down: a genuinely misplaced LOCAL declaration, where the message's own remedy
	// is the right one and the parameter tip would be noise.
	public function testAVariableAssignedElsewhereGetsNoTip(): void
	{
		$diagnostics = $this->diagnosticsFor("{var \$q = 1}\n{varType string \$x}\n{\$q}\n{var \$x = 'a'}\n");

		self::assertCount(1, $diagnostics);
		self::assertNull($diagnostics[0]->getTip());
	}

	public function testAVariableBoundOnlyByAForeachGetsNoTip(): void
	{
		$diagnostics = $this->diagnosticsFor(
			"{var \$q = 1}\n{varType string \$item}\n{\$q}\n{foreach \$rows as \$item}{/foreach}\n",
		);

		self::assertCount(1, $diagnostics);
		self::assertNull($diagnostics[0]->getTip());
	}

	public function testAVariableBoundOnlyByAnNForeachElementGetsNoTip(): void
	{
		$diagnostics = $this->diagnosticsFor(
			"{var \$q = 1}\n{varType string \$item}\n{\$q}\n<div n:foreach=\"\$rows as \$item\">x</div>\n",
		);

		self::assertCount(1, $diagnostics);
		self::assertNull($diagnostics[0]->getTip());
	}

	// An unresolvable {do}/{php} body might assign anything, so "never assigned" is unknown rather
	// than true - the tip must not claim otherwise.
	public function testAnOpaqueAssignmentAnywhereSuppressesTheTip(): void
	{
		$diagnostics = $this->diagnosticsFor(
			"{var \$q = 1}\n{varType array<string> \$items}\n{\$items}\n{do \$obj->fill()}\n",
		);

		self::assertCount(1, $diagnostics);
		self::assertNull($diagnostics[0]->getTip());
	}

	public function testANameMismatchNeverCarriesTheHeaderTip(): void
	{
		$diagnostics = $this->diagnosticsFor("{var \$q = 1}\n{varType string \$other}\n{var \$x = 'a'}\n");

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.varTypeDifferentVariable', $diagnostics[0]->getIdentifier());
		self::assertNull($diagnostics[0]->getTip());
	}

	public function testAboveNonBlankTextIsMisplaced(): void
	{
		self::assertSame(
			['orisaiNette.latte.varTypeMisplaced'],
			$this->idsFor("{var \$q = 1}\n{varType string \$x}\nplain text\n{var \$x = 'a'}\n"),
		);
	}

	public function testEveryVarTypeInARunWithoutAnAnchorIsReported(): void
	{
		self::assertSame(
			['orisaiNette.latte.varTypeMisplaced', 'orisaiNette.latte.varTypeMisplaced'],
			$this->idsFor("{var \$q = 1}\n{varType string \$x}\n{varType int \$y}\n{\$q}\n"),
		);
	}

	// === name mismatches ===

	public function testForeachNotBindingTheNamedVariableReportsDifferentVariable(): void
	{
		$diagnostics = $this->diagnosticsFor(
			"{var \$q = 1}\n{varType string \$other}\n{foreach \$items as \$item}{/foreach}\n",
		);

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.varTypeDifferentVariable', $diagnostics[0]->getIdentifier());
		self::assertSame(
			'Variable $other in {varType} does not match any variable in the foreach loop: $items, $item',
			$diagnostics[0]->getMessage(),
		);
	}

	// Only a BARE `{foreach $items as ...}` names its iterable; an expression that merely contains a
	// variable is not one, matching WrongVariableNameInVarTagRule's `$iterateeExpr instanceof
	// Variable` gate.
	public function testForeachOverAnExpressionDoesNotBindTheVariableInsideIt(): void
	{
		$diagnostics = $this->diagnosticsFor(
			"{var \$q = 1}\n{varType string \$a}\n{foreach \$a->rows as \$item}{/foreach}\n",
		);

		self::assertCount(1, $diagnostics);
		self::assertSame(
			'Variable $a in {varType} does not match any variable in the foreach loop: $item',
			$diagnostics[0]->getMessage(),
		);
	}

	public function testAssignmentToAnotherSingleVariableReportsDifferentVariable(): void
	{
		$diagnostics = $this->diagnosticsFor("{var \$q = 1}\n{varType string \$other}\n{var \$x = 'a'}\n");

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.varTypeDifferentVariable', $diagnostics[0]->getIdentifier());
		self::assertSame(
			'Variable $other in {varType} does not match assigned variable $x.',
			$diagnostics[0]->getMessage(),
		);
	}

	public function testAssignmentToSeveralVariablesReportsVariableNotFound(): void
	{
		$diagnostics = $this->diagnosticsFor("{var \$q = 1}\n{varType string \$other}\n{var \$x = 'a', \$y = 1}\n");

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.varTypeVariableNotFound', $diagnostics[0]->getIdentifier());
		self::assertSame(
			'Variable $other in {varType} does not exist.',
			$diagnostics[0]->getMessage(),
		);
	}

	public function testARunOfSeveralTagsReportsVariableNotFoundForTheUnmatchedOne(): void
	{
		$diagnostics = $this->diagnosticsFor(
			"{var \$q = 1}\n{varType string \$x}\n{varType int \$other}\n{var \$x = 'a'}\n",
		);

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.varTypeVariableNotFound', $diagnostics[0]->getIdentifier());
	}

	// A {do}/{php} body this scanner cannot resolve to a plain variable assignment binds an
	// unknown set - accepted as an anchor, never name-checked, so an unresolvable expression can
	// never manufacture a name mismatch.
	public function testOpaqueDoBodyIsAcceptedWithoutANameCheck(): void
	{
		self::assertSame([], $this->idsFor("{var \$q = 1}\n{varType string \$x}\n{do \$obj->method()}\n"));
	}

	public function testDoBodyThatIsNotAnAssignmentAtAllIsStillOpaque(): void
	{
		self::assertSame([], $this->idsFor("{var \$q = 1}\n{varType string \$x}\n{php [\$a, \$b] = \$pair}\n"));
	}

	// === a name already known before this point is accepted, even mismatched ===
	// Mirrors WrongVariableNameInVarTagRule::processAssign()'s `!$scope->hasVariableType($key)->no()`
	// guard: a name the scope already knows about is accepted silently regardless of whether it
	// matches THIS particular anchor - upstream is silent here, so this stays silent too, rather than
	// inventing a stricter model than the one the owner told us to mirror.

	public function testANameKnownFromTheHeaderIsAcceptedEvenWhenItMismatchesTheAnchor(): void
	{
		self::assertSame(
			[],
			$this->idsFor("{varType string \$p}\n{var \$q = 1}\n{varType string \$p}\n{var \$other = 2}\n"),
		);
	}

	public function testANameKnownFromParametersIsAcceptedEvenWhenItMismatchesTheAnchor(): void
	{
		self::assertSame(
			[],
			$this->idsFor("{parameters int \$p}\n{var \$q = 1}\n{varType string \$p}\n{var \$other = 2}\n"),
		);
	}

	public function testANameKnownFromAnEarlierAssignmentIsAcceptedEvenWhenItMismatchesTheAnchor(): void
	{
		self::assertSame(
			[],
			$this->idsFor("{var \$q = 1}\n{var \$p = 'x'}\n{varType string \$p}\n{var \$other = 2}\n"),
		);
	}

	public function testAKnownNameAboveACaptureAnchorIsAlsoAccepted(): void
	{
		self::assertSame(
			[],
			$this->idsFor("{varType string \$p}\n{var \$q = 1}\n{varType string \$p}\n{capture \$v}hi{/capture}\n"),
		);
	}

	// processAssign()'s guard also covers the run-of-several-tags case (differentVariable's sibling,
	// variableNotFound): both fall under the SAME `!$scope->hasVariableType($key)->no()` check.
	public function testAKnownNameSuppressesVariableNotFoundTooOnSeveralAssignedVariables(): void
	{
		self::assertSame(
			[],
			$this->idsFor(
				"{varType string \$p}\n{var \$q = 1}\n{varType string \$p}\n{var \$x = 'a', \$y = 1}\n",
			),
		);
	}

	// processForeach() has NO such guard upstream (only processAssign()/processStmt() do) - a
	// foreach mismatch is reported even for a name the header already knows.
	public function testAForeachMismatchIsStillReportedEvenWhenTheNameIsKnownFromTheHeader(): void
	{
		$diagnostics = $this->diagnosticsFor(
			"{varType string \$p}\n{var \$q = 1}\n{varType string \$p}\n{foreach \$items as \$item}{/foreach}\n",
		);

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.varTypeDifferentVariable', $diagnostics[0]->getIdentifier());
		self::assertSame(
			'Variable $p in {varType} does not match any variable in the foreach loop: $items, $item',
			$diagnostics[0]->getMessage(),
		);
	}

	// An earlier {do}/{php} this scanner cannot resolve to a plain assignment might bind anything,
	// so every name is treated as known from that point on - the same MAYBE-counts-as-YES direction
	// `hasVariableType()->no()` takes on a scope it cannot fully resolve either.
	public function testAnEarlierOpaqueAssignmentTreatsEveryNameAsKnown(): void
	{
		self::assertSame(
			[],
			$this->idsFor("{var \$q = 1}\n{do \$obj->fill()}\n{varType string \$p}\n{var \$other = 2}\n"),
		);
	}

	// A binding that comes AFTER the {varType} tag must not retroactively make its name "known" -
	// only what dominates it in the token stream counts, matching a real scope's flow direction.
	public function testANameOnlyBoundAfterTheTagIsStillReportedAsDifferentVariable(): void
	{
		$diagnostics = $this->diagnosticsFor(
			"{var \$q = 1}\n{varType string \$p}\n{var \$other = 2}\n{var \$p = 'z'}\n",
		);

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.varTypeDifferentVariable', $diagnostics[0]->getIdentifier());
		self::assertSame(
			'Variable $p in {varType} does not match assigned variable $other.',
			$diagnostics[0]->getMessage(),
		);
	}

	/**
	 * @return list<string>
	 */
	private function idsFor(string $source): array
	{
		return array_map(
			static fn (Diagnostic $d): string => $d->getIdentifier(),
			$this->diagnosticsFor($source),
		);
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function diagnosticsFor(string $source): array
	{
		$declarations = (new DeclarationScanner())->scan($source);

		return (new VarTypePlacementChecker())->check($declarations);
	}

}
