<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Rule\FormShapeUnknownAccessRule;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<FormShapeUnknownAccessRule>
 */
final class InferenceComponentPathRuleTest extends InferenceRuleTest
{

	/** @return FormShapeUnknownAccessRule */
	protected function getRule(): Rule
	{
		return new FormShapeUnknownAccessRule(TestGuard::of(self::getContainer()), true);
	}

	public function testComponentPathAccess(): void
	{
		$invalid = '; a component name must be a non-empty alphanumeric string.';

		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/ComponentPathAccess.php'],
			[
				["Form component 'nope-x' does not exist.", 30],
				["Form component 'nope' does not exist.", 40],
				["Form component 'outer-nope' does not exist.", 50],
				["Form component 'outer-mid-nope' does not exist.", 85],
				["Form component 'outer-nope-a' does not exist.", 96],
				[
					"Form value 'nope-x' may not exist; the form shape is open.",
					118,
					'Form shape opened by: dynamic_name',
				],
				["Form component path 'outer-' has an invalid segment ''" . $invalid, 130],
				["Form component path '-a' has an invalid segment ''" . $invalid, 139],
				["Form component path 'outer--a' has an invalid segment ''" . $invalid, 149],
				["Form component path '-' has an invalid segment ''" . $invalid, 158],
				["Form component path 'nope-' has an invalid segment ''" . $invalid, 169],
				["Form component path 'nope-' has an invalid segment ''" . $invalid, 180],
				["Form component 'a b' does not exist.", 190],
				["Form component 'rows-nope' does not exist.", 219],
				["Form component 'nope-x' does not exist.", 244],
				["Form value 'outer-a' does not exist.", 296],
			],
		);
	}

}
