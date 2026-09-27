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
final class InferenceFormAddsShapeTest extends InferenceRuleTest
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

	public function testAnnotatedAddersResolve(): void
	{
		$this->analyse([__DIR__ . '/Fixtures/Rule/FormAdds.php'], []);
	}

	/**
	 * The regression pin for narrowing registration: a form subclassed to build ONE specific form is
	 * resolved by the walk descending from its constructor (or its factory) into a build method that
	 * is not named add*, and what resolves there are the INNER vendor add* calls. A narrowing that
	 * stopped that descent would turn every control below into a component the reader is told does
	 * not exist.
	 */
	public function testSpecificFormSubclassesKeepTheirControls(): void
	{
		$this->analyse(
			[
				__DIR__ . '/Fixtures/Rule/SpecificFormSubclass.php',
				__DIR__ . '/Fixtures/Rule/SpecificProjectBaseForm.php',
				__DIR__ . '/Fixtures/Rule/SpecificPrivateBuildForm.php',
				__DIR__ . '/Fixtures/Rule/SpecificProtectedBuildForm.php',
				__DIR__ . '/Fixtures/Rule/SpecificVendorParentForm.php',
				__DIR__ . '/Fixtures/Rule/SpecificNestedBuildForm.php',
				__DIR__ . '/Fixtures/Rule/SpecificFactoryBuiltForm.php',
			],
			[],
		);
	}

}
