<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Component\ContainerModel;
use OriPhpstan\Nette\Forms\Rule\FormValuesDescriber;
use OriPhpstan\Nette\Forms\Rule\FormValuesDumpRule;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<FormValuesDumpRule>
 */
final class InferenceFormValuesDumpRuleTest extends InferenceRuleTest
{

	/** @return FormValuesDumpRule */
	protected function getRule(): Rule
	{
		return new FormValuesDumpRule(
			TestGuard::of(self::getContainer()),
			true,
			new FormValuesDescriber(self::getContainer()->getByType(ContainerModel::class)),
		);
	}

	public function testNestedFormValues(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/FormValues.php'],
			[
				[
					'Nette\Utils\ArrayHash{name: string, address: Nette\Utils\ArrayHash{city: string, zip: string}}',
					19,
				],
			],
		);
	}

}
