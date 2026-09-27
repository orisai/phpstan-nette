<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Component\ContainerModel;
use OriPhpstan\Nette\Forms\Rule\FormValuesAssertRule;
use OriPhpstan\Nette\Forms\Rule\FormValuesDescriber;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<FormValuesAssertRule>
 */
final class InferenceFormValuesAssertRuleTest extends InferenceRuleTest
{

	/** @return FormValuesAssertRule */
	protected function getRule(): Rule
	{
		return new FormValuesAssertRule(
			TestGuard::of(self::getContainer()),
			true,
			new FormValuesDescriber(self::getContainer()->getByType(ContainerModel::class)),
		);
	}

	public function testMatchingValuesPassAndMismatchIsReported(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/FormValues.php'],
			[
				[
					<<<'OUTPUT'
					Form values do not match assertFormValues() expectation.
					expected:
					Nette\Utils\ArrayHash{}
					actual:
					Nette\Utils\ArrayHash{name: string}
					OUTPUT,
					33,
				],
			],
		);
	}

}
