<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Rule\LatteDiagnosticRule;
use OriPhpstan\Nette\Latte\Runtime\Diag;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends RuleTestCase<LatteDiagnosticRule>
 */
final class LatteDiagnosticRuleTest extends RuleTestCase
{

	private const FixtureFile = __DIR__ . '/../Fixtures/Rule/diag.php';

	private const InvalidFixtureFile = __DIR__ . '/../Fixtures/Rule/diag-invalid.php';

	private const TipFixtureFile = __DIR__ . '/../Fixtures/Rule/diag-tip.php';

	private const NonConstantTipFixtureFile = __DIR__ . '/../Fixtures/Rule/diag-nonconstant-tip.php';

	protected function getRule(): Rule
	{
		return new LatteDiagnosticRule(TestGuard::latte());
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

	public function testReportsUnknownMacroDiagnostic(): void
	{
		$this->analyse(
			[self::FixtureFile],
			[
				["Unknown Latte macro or attribute 'g_'.", 7],
			],
		);
	}

	public function testReportedErrorCarriesTheDiagnosticIdentifier(): void
	{
		$errors = $this->gatherAnalyserErrors([self::FixtureFile]);

		self::assertCount(1, $errors);
		self::assertSame('orisaiNette.latte.unknownMacro', $errors[0]->getIdentifier());
	}

	public function testReportsInternalErrorForNonConstantArgs(): void
	{
		$this->analyse(
			[self::InvalidFixtureFile],
			[
				[
					Diag::class . '::report() called with non-constant arguments; the Latte analysis pipeline '
					. 'must always materialize literal-string identifier/message pairs.',
					12,
				],
			],
		);
	}

	public function testInternalErrorCarriesTheInternalErrorIdentifier(): void
	{
		$errors = $this->gatherAnalyserErrors([self::InvalidFixtureFile]);

		self::assertCount(1, $errors);
		self::assertSame('orisaiNette.latte.internalError', $errors[0]->getIdentifier());
	}

	// The optional third argument carries a Diagnostic's tip through the materialized report call -
	// the transitive-includer tip orisaiNette.latte.orphanTemplate attaches is the only producer today.
	public function testTipArgumentReachesTheReportedError(): void
	{
		$errors = $this->gatherAnalyserErrors([self::TipFixtureFile]);

		self::assertCount(1, $errors);
		self::assertSame('orisaiNette.latte.orphanTemplate', $errors[0]->getIdentifier());
		self::assertSame(
			'Included only from templates that are themselves unreachable: dead.latte.',
			$errors[0]->getTip(),
		);
	}

	public function testNonConstantTipIsAnInternalError(): void
	{
		$errors = $this->gatherAnalyserErrors([self::NonConstantTipFixtureFile]);

		self::assertCount(1, $errors);
		self::assertSame('orisaiNette.latte.internalError', $errors[0]->getIdentifier());
	}

	// NO FIXER, EVER (spec section 9b): this rule materializes every pipeline diagnostic, orphan
	// findings among them, and those rest on UNDER-detected usage - an auto-fix would delete files
	// that are genuinely rendered. A fixNode() payload survives into the analyser error as its fixed
	// diff, so a null diff is the end-to-end proof that none was ever attached.
	public function testReportedErrorsCarryNoFixPayload(): void
	{
		foreach ([self::FixtureFile, self::TipFixtureFile] as $file) {
			$errors = $this->gatherAnalyserErrors([$file]);
			self::assertCount(1, $errors, $file);

			foreach ($errors as $error) {
				self::assertNull($error->getFixedErrorDiff(), $file);
			}
		}
	}

}
