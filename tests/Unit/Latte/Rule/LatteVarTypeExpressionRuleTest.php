<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Rule\LatteVarTypeExpressionRule;
use OriPhpstan\Nette\Latte\Rule\VarTypeExpressionChecker;
use PHPStan\Analyser\Error;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPStan\Type\FileTypeMapper;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function array_map;
use function sprintf;

// gatherAnalyserErrors() rather than analyse(): the generated LatteTpl_* class is only reflectable
// through LatteTemplateSourceLocator's %paths%-derived map, which a RuleTestCase container does not
// build, so analyse()'s renderer appends its own "class not found in ReflectionProvider"
// misconfiguration note to the expected/actual comparison. Every fixture below therefore takes its
// types from template-LOCAL assignments only (never a {varType}/{templateType} parameter, whose type
// lives on that unreflectable class); the production reflection path is covered end-to-end by
// IntegrationSnapshotTest's real spawn instead.
/**
 * @extends RuleTestCase<LatteVarTypeExpressionRule>
 *
 * @group latte2
 */
final class LatteVarTypeExpressionRuleTest extends RuleTestCase
{

	use VersionGroupGate;

	private const NarrowingFixtureFile = __DIR__ . '/../Fixtures/Rule/vartype-expression-narrowing.latte';

	private const WideningFixtureFile = __DIR__ . '/../Fixtures/Rule/vartype-expression-widening.latte';

	private const NativeTypeFixtureFile = __DIR__ . '/../Fixtures/Rule/vartype-expression-native-type.latte';

	private const PhpDocTypeFixtureFile = __DIR__ . '/../Fixtures/Rule/vartype-expression-phpdoc-type.latte';

	private const ForeachFixtureFile = __DIR__ . '/../Fixtures/Rule/vartype-expression-foreach.latte';

	private const SameLineFixtureFile = __DIR__ . '/../Fixtures/Rule/vartype-expression-same-line.latte';

	private const SameLineOrderFixtureFile = __DIR__ . '/../Fixtures/Rule/vartype-expression-same-line-order.latte';

	private const LiteralFixtureFile = __DIR__ . '/../Fixtures/Rule/vartype-expression-literal.latte';

	private const BlockBoundaryFixtureFile = __DIR__ . '/../Fixtures/Rule/vartype-expression-block-boundary.latte';

	private bool $reportWrongPhpDocType = true;

	private bool $reportAnyTypeWidening = true;

	protected function getRule(): Rule
	{
		return new LatteVarTypeExpressionRule(
			TestGuard::latte(),
			new TemplateEdgeIndex(new LatteUniverse([], ''), TestAdapter::accessor()),
			self::getContainer()->getByType(TypeStringResolver::class),
			new VarTypeExpressionChecker(
				self::getContainer()->getByType(TypeNodeResolver::class),
				self::getContainer()->getByType(FileTypeMapper::class),
				$this->reportWrongPhpDocType,
				$this->reportAnyTypeWidening,
			),
		);
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test-vartype-expression.neon'];
	}

	// A {varType} that NARROWS the assigned expression is the whole point of the tag - silent even
	// with both options at their (non-core) strict defaults.
	public function testNarrowingIsSilentUnderBothOptions(): void
	{
		self::assertSame([], $this->reported(self::NarrowingFixtureFile));
	}

	// Widening and the phpdoc-type conflict each get a fixture exercised in BOTH flag states;
	// narrowing gets the same pairing rather than only ever being checked with both flags on.
	public function testNarrowingIsSilentWhenBothOptionsAreOff(): void
	{
		$this->reportWrongPhpDocType = false;
		$this->reportAnyTypeWidening = false;

		self::assertSame([], $this->reported(self::NarrowingFixtureFile));
	}

	public function testIncompatibleNativeTypeIsReportedWithBothOptionsOff(): void
	{
		$this->reportWrongPhpDocType = false;
		$this->reportAnyTypeWidening = false;

		self::assertSame(
			['02 orisaiNette.latte.varTypeNativeType {varType} for $wrong with type string is not subtype of native type 1.'],
			$this->reported(self::NativeTypeFixtureFile),
		);
	}

	public function testIncompatibleNativeTypeIsAlsoReportedUnderTheStrictDefaults(): void
	{
		self::assertSame(
			['02 orisaiNette.latte.varTypeNativeType {varType} for $wrong with type string is not subtype of native type 1.'],
			$this->reported(self::NativeTypeFixtureFile),
		);
	}

	// VarTagTypeRuleHelper special-cases a literal right-hand side (scalars, array literals,
	// constant fetches) BEFORE either knob is consulted, so widening a literal stays silent even
	// with both options on - the one place the strict defaults do not tighten core's behaviour.
	public function testALiteralRightHandSideKeepsCoreLeniencyUnderBothOptions(): void
	{
		self::assertSame([], $this->reported(self::LiteralFixtureFile));
	}

	// === reportAnyTypeWideningInVarType, both states ===

	public function testWideningIsSilentWhenTheWideningOptionIsOff(): void
	{
		$this->reportAnyTypeWidening = false;

		self::assertSame([], $this->reported(self::WideningFixtureFile));
	}

	public function testWideningIsReportedWhenTheWideningOptionIsOn(): void
	{
		$this->reportAnyTypeWidening = true;

		self::assertSame(
			['02 orisaiNette.latte.varTypeNativeType {varType} for $widened with type int is not subtype of native type 1.'],
			$this->reported(self::WideningFixtureFile),
		);
	}

	// === reportWrongPhpDocTypeInVarType, both states ===

	public function testWrongPhpDocTypeIsSilentWhenThatOptionIsOff(): void
	{
		$this->reportWrongPhpDocType = false;

		self::assertSame([], $this->reported(self::PhpDocTypeFixtureFile));
	}

	public function testWrongPhpDocTypeIsReportedWhenThatOptionIsOn(): void
	{
		$this->reportWrongPhpDocType = true;

		self::assertSame(
			['02 orisaiNette.latte.varTypeType {varType} for $nums with type array<int> is not subtype of type array<string>.'],
			$this->reported(self::PhpDocTypeFixtureFile),
		);
	}

	// === foreach anchors ===

	public function testForeachItemMatchingTheIterableElementTypeIsSilentAndAMismatchIsReported(): void
	{
		self::assertSame(
			['05 orisaiNette.latte.varTypeNativeType {varType} for $bad with type string is not subtype of native type 1|2.'],
			$this->reported(self::ForeachFixtureFile),
		);
	}

	// The pipeline injects its own `$x = self::$prop_N_x;` carrier at the {varType} tag's own line;
	// with tag and anchor on ONE line the author's assignment must still be the one checked.
	public function testAnAnchorOnTheSameLineAsTheTagIsStillChecked(): void
	{
		self::assertSame(
			['02 orisaiNette.latte.varTypeNativeType {varType} for $x with type string is not subtype of native type 5.'],
			$this->reported(self::SameLineFixtureFile),
		);
	}

	// matchPlacement() keys on (line, kind, name) alone; two {varType}s for the SAME variable that
	// share one anchor line (each re-declaring it right there with its own {var}) must each be
	// checked against their OWN declared type in source order, not both against the first one.
	public function testTwoSameLineRedeclarationsAreEachCheckedAgainstTheirOwnDeclaredType(): void
	{
		self::assertSame(
			['03 orisaiNette.latte.varTypeNativeType {varType} for $x with type int is not subtype of native type string|null.'],
			$this->reported(self::SameLineOrderFixtureFile),
		);
	}

	// A {block} written on the declaration's own line puts a second assignment to the same variable
	// on the same line inside the block's OWN compiled method, while the {varType} that assignment
	// belongs to is the block's input contract and never becomes a placement at all. Keyed on
	// (line, kind, name) alone the block's assignment matched the MAIN body's declaration and its
	// type was reported as a conflict - line 2 below must stay silent. Line 3 is the other half of
	// the same gate: a {varType} nested deeper inside a block IS a placement, and must still be
	// checked against its own anchor inside that block's method rather than dropped with it.
	public function testADeclarationIsNotMatchedAcrossACompiledMethodBoundary(): void
	{
		self::assertSame(
			['03 orisaiNette.latte.varTypeNativeType {varType} for $z with type string is not subtype of native type 1.'],
			$this->reported(self::BlockBoundaryFixtureFile),
		);
	}

	/**
	 * @return list<string>
	 */
	private function reported(string $file): array
	{
		return array_map(
			static fn (Error $error): string => sprintf(
				'%02d %s %s',
				$error->getLine() ?? 0,
				(string) $error->getIdentifier(),
				$error->getMessage(),
			),
			$this->gatherAnalyserErrors([$file]),
		);
	}

}
