<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Component\ContainerModel;
use OriPhpstan\Nette\Forms\Rule\ComponentShapeAssertRule;
use OriPhpstan\Nette\Forms\Rule\ComponentShapeDescriber;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<ComponentShapeAssertRule>
 */
final class InferenceAssertRuleTest extends InferenceRuleTest
{

	/** @return ComponentShapeAssertRule */
	protected function getRule(): Rule
	{
		return new ComponentShapeAssertRule(
			TestGuard::of(self::getContainer()),
			true,
			new ComponentShapeDescriber(
				self::getContainer()->getByType(ContainerModel::class),
				self::getContainer()->getByType(TypeStringResolver::class),
			),
		);
	}

	public function testMatchingShapePassesAndMismatchIsReported(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/AssertComponent.php'],
			[
				[
					<<<'OUTPUT'
					Component shape does not match assertComponent() expectation.
					expected:
					Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{}
					actual:
					Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
					  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
					}
					OUTPUT,
					26,
				],
			],
		);
	}

}
