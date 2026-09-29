<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Rule\LatteInternalAccessRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;

/**
 * @extends RuleTestCase<LatteInternalAccessRule>
 */
final class LatteInternalAccessRuleTest extends RuleTestCase
{

	use VersionGroupGate;

	private const FlaggedFixtureFile = __DIR__ . '/../Fixtures/Rule/internal-access-flagged.latte';

	private const ScaffoldingFixtureFile = __DIR__ . '/../Fixtures/Rule/internal-access-scaffolding.latte';

	private const MultilineFixtureFile = __DIR__ . '/../Fixtures/Rule/internal-access-multiline.latte';

	protected function getRule(): Rule
	{
		return new LatteInternalAccessRule(TestGuard::latte());
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test-internal-access.neon'];
	}

	public function testFlagsUserWrittenMethodCallAndPropertyFetchOnThis(): void
	{
		$this->analyse(
			[self::FlaggedFixtureFile],
			[
				['Access to Latte runtime internal $this->getReferringTemplate() from template code.', 1],
				['Access to Latte runtime internal $this->global from template code.', 5],
			],
		);
	}

	public function testReportedErrorsCarryTheInternalAccessIdentifier(): void
	{
		$errors = $this->gatherAnalyserErrors([self::FlaggedFixtureFile]);

		self::assertCount(2, $errors);
		self::assertSame('orisaiNette.latte.internalAccess', $errors[0]->getIdentifier());
		self::assertSame('orisaiNette.latte.internalAccess', $errors[1]->getIdentifier());
	}

	public function testDoesNotFlagCompilerEmittedBlockDispatchIncludeAndSnippetScaffolding(): void
	{
		$this->analyse([self::ScaffoldingFixtureFile], []);
	}

	public function testFlagsUserWrittenAccessSplitAcrossPhysicalLines(): void
	{
		$this->analyse(
			[self::MultilineFixtureFile],
			[
				['Access to Latte runtime internal $this->getReferringTemplate() from template code.', 1],
			],
		);
	}

}
