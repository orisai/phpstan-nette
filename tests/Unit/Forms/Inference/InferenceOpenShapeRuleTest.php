<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Rule\FormShapeUnknownAccessRule;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<FormShapeUnknownAccessRule>
 */
final class InferenceOpenShapeRuleTest extends InferenceRuleTest
{

	/** @return FormShapeUnknownAccessRule */
	protected function getRule(): Rule
	{
		return new FormShapeUnknownAccessRule(TestGuard::of(self::getContainer()), true);
	}

	public function testOpenShapeSuppressesFalsePositive(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/OpenShapeNoFalsePositive.php'],
			[],
		);
	}

}
