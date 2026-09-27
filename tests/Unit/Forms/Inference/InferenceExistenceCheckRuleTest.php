<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Rule\FormShapeUnknownAccessRule;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<FormShapeUnknownAccessRule>
 */
final class InferenceExistenceCheckRuleTest extends InferenceRuleTest
{

	/** @return FormShapeUnknownAccessRule */
	protected function getRule(): Rule
	{
		return new FormShapeUnknownAccessRule(TestGuard::of(self::getContainer()), true);
	}

	public function testExistenceChecks(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/ExistenceCheck.php'],
			[
				[
					"Form component 'nope' does not exist.",
					59,
				],
				[
					"Form component 'nope' does not exist.",
					67,
				],
				[
					"Form component 'nope' does not exist.",
					75,
				],
				[
					"Form value 'save' does not exist.",
					118,
				],
				[
					"Form component 'nope' does not exist.",
					154,
				],
				[
					"Form component 'nope' does not exist.",
					197,
				],
				[
					"Form component 'rep-nope' does not exist.",
					269,
				],
				[
					"Form component 'nope' does not exist.",
					305,
				],
				[
					"Form component 'nope' does not exist.",
					337,
				],
			],
		);
	}

}
