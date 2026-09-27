<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Rule\FormShapeUnknownAccessRule;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<FormShapeUnknownAccessRule>
 */
final class InferenceAliasedFormRuleTest extends InferenceRuleTest
{

	/** @return FormShapeUnknownAccessRule */
	protected function getRule(): Rule
	{
		return new FormShapeUnknownAccessRule(TestGuard::of(self::getContainer()), true);
	}

	public function testAliasedFormStaysOpen(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/AliasedFormOpen.php'],
			[
				[
					"Form component 'nope' does not exist.",
					37,
				],
				[
					"Form component 'nope' does not exist.",
					76,
				],
				[
					"Form component 'nope' does not exist.",
					100,
				],
			],
		);
	}

}
