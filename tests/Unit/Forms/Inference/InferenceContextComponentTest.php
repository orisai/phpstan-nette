<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Component\ContainerModel;
use OriPhpstan\Nette\Forms\Rule\ComponentShapeDescriber;
use OriPhpstan\Nette\Forms\Rule\ComponentShapeDumpRule;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<ComponentShapeDumpRule>
 */
final class InferenceContextComponentTest extends InferenceRuleTest
{

	/** @return ComponentShapeDumpRule */
	protected function getRule(): Rule
	{
		return new ComponentShapeDumpRule(
			TestGuard::of(self::getContainer()),
			true,
			new ComponentShapeDescriber(
				self::getContainer()->getByType(ContainerModel::class),
				self::getContainer()->getByType(TypeStringResolver::class),
			),
		);
	}

	public function testValidatedContextNarrowsReadTypes(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/ContextAware.php'],
			[
				[
					<<<'OUTPUT'
					Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
					  age: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, int|null>,
					  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, non-empty-string>,
					}
					OUTPUT,
					26,
				],
				[
					<<<'OUTPUT'
					Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
					  age: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, int|null>,
					  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
					}
					OUTPUT,
					30,
				],
			],
		);
	}

}
