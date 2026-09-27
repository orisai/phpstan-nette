<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Component\ContainerModel;
use OriPhpstan\Nette\Forms\Rule\ComponentShapeDescriber;
use OriPhpstan\Nette\Forms\Rule\ComponentShapeDumpRule;
use OriPhpstan\Nette\Forms\Rule\FormShapeUnknownAccessRule;
use OriPhpstan\Nette\Forms\Rule\FormWriteAccessRule;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends RuleTestCase<FormWriteAccessRule|FormShapeUnknownAccessRule|ComponentShapeDumpRule>
 */
final class InferenceKillSwitchRuleTest extends RuleTestCase
{

	/** @var FormWriteAccessRule|FormShapeUnknownAccessRule|ComponentShapeDumpRule */
	private Rule $ruleUnderTest;

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/disabled.neon'];
	}

	/** @return FormWriteAccessRule|FormShapeUnknownAccessRule|ComponentShapeDumpRule */
	protected function getRule(): Rule
	{
		return $this->ruleUnderTest;
	}

	public function testWriteRuleSilentWhenDisabled(): void
	{
		$this->ruleUnderTest = new FormWriteAccessRule(
			TestGuard::of(self::getContainer()),
			false,
			self::getContainer()->getByType(TypeStringResolver::class),
		);
		$this->analyse([__DIR__ . '/Fixtures/Rule/Write.php'], []);
	}

	public function testUnknownAccessRuleSilentWhenDisabled(): void
	{
		$this->ruleUnderTest = new FormShapeUnknownAccessRule(TestGuard::of(self::getContainer()), false);
		$this->analyse([__DIR__ . '/Fixtures/Rule/UnknownAccess.php'], []);
	}

	public function testDumpRuleSilentWhenDisabled(): void
	{
		$this->ruleUnderTest = new ComponentShapeDumpRule(
			TestGuard::of(self::getContainer()),
			false,
			new ComponentShapeDescriber(
				self::getContainer()->getByType(ContainerModel::class),
				self::getContainer()->getByType(TypeStringResolver::class),
			),
		);
		$this->analyse([__DIR__ . '/Fixtures/Rule/Dump.php'], []);
	}

}
