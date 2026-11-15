<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Rule\LatteFixStrippingRule;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures\AnalysedFileCollector;
use Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures\CollectedDataTrueConstantFixRule;

/**
 * @extends RuleTestCase<LatteFixStrippingRule<CollectedDataNode>>
 */
final class LatteFixStrippingCollectedDataTest extends RuleTestCase
{

	use VersionGroupGate;

	private const FixtureDir = __DIR__ . '/../Fixtures/Rule';

	private const InputFile = self::FixtureDir . '/true-constant.input.php';

	private string $targetFile = self::FixtureDir . '/cross-file-target.php';

	/**
	 * @return LatteFixStrippingRule<CollectedDataNode>
	 */
	protected function getRule(): Rule
	{
		return new LatteFixStrippingRule(new CollectedDataTrueConstantFixRule(self::getParser(), $this->targetFile));
	}

	protected function getCollectors(): array
	{
		return [new AnalysedFileCollector()];
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

	public function testFixIntoAPhpFileIsKept(): void
	{
		$this->fixOtherFile(self::InputFile, $this->targetFile, self::FixtureDir . '/cross-file-target.fixed.php');
	}

	public function testFixIntoATemplateIsStripped(): void
	{
		$this->targetFile = self::FixtureDir . '/true-constant.latte';

		$this->fixOtherFile(self::InputFile, $this->targetFile, $this->targetFile);
	}

}
