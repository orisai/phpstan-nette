<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Rule\LatteFixStrippingRule;
use PhpParser\Node\Expr\ConstFetch;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures\AlwaysFixesTrueConstantRule;

/**
 * @extends RuleTestCase<LatteFixStrippingRule<ConstFetch>>
 */
final class LatteFixStrippingRuleTest extends RuleTestCase
{

	private const FixtureDir = __DIR__ . '/../Fixtures/Rule';

	/**
	 * @return LatteFixStrippingRule<ConstFetch>
	 */
	protected function getRule(): Rule
	{
		return new LatteFixStrippingRule(new AlwaysFixesTrueConstantRule());
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

	public function testFixIsAppliedForAPhpFile(): void
	{
		$this->fix(
			self::FixtureDir . '/true-constant.input.php',
			self::FixtureDir . '/true-constant.fixed.php',
		);
	}

	public function testFixIsStrippedButErrorIsKeptForALatteFile(): void
	{
		$this->fix(
			self::FixtureDir . '/true-constant.latte',
			self::FixtureDir . '/true-constant.latte',
		);

		$this->analyse(
			[self::FixtureDir . '/true-constant.latte'],
			[
				['true constant is forbidden by this test fixture.', 5],
			],
		);
	}

}
