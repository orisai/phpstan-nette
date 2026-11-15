<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Rule\LatteFixStrippingRule;
use PhpParser\Node\Expr\ConstFetch;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures\CrossFileTrueConstantFixRule;

/**
 * @extends RuleTestCase<LatteFixStrippingRule<ConstFetch>>
 */
final class LatteFixStrippingCrossFileTest extends RuleTestCase
{

	use VersionGroupGate;

	private const FixtureDir = __DIR__ . '/../Fixtures/Rule';

	private const InputFile = self::FixtureDir . '/true-constant.input.php';

	private const PhpTarget = self::FixtureDir . '/cross-file-target.php';

	private const LatteTarget = self::FixtureDir . '/true-constant.latte';

	private string $targetFile = self::PhpTarget;

	private string $mode = CrossFileTrueConstantFixRule::ReportedInTarget;

	/**
	 * @return LatteFixStrippingRule<ConstFetch>
	 */
	protected function getRule(): Rule
	{
		return new LatteFixStrippingRule(
			new CrossFileTrueConstantFixRule(self::getParser(), $this->targetFile, $this->mode),
		);
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function provideModes(): iterable
	{
		yield 'error reported in the target' => [CrossFileTrueConstantFixRule::ReportedInTarget];
		yield 'node fix in the target' => [CrossFileTrueConstantFixRule::NodeFixFile];
	}

	/**
	 * @dataProvider provideModes
	 */
	public function testFixIntoAPhpFileIsKept(string $mode): void
	{
		$this->mode = $mode;

		$this->fixOtherFile(self::InputFile, self::PhpTarget, self::FixtureDir . '/cross-file-target.fixed.php');
	}

	/**
	 * @dataProvider provideModes
	 */
	public function testFixIntoATemplateIsStripped(string $mode): void
	{
		$this->targetFile = self::LatteTarget;
		$this->mode = $mode;

		$this->fixOtherFile(self::InputFile, self::LatteTarget, self::LatteTarget);
	}

}
