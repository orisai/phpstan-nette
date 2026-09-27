<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Rule\FormAddsAnnotationRule;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<FormAddsAnnotationRule>
 */
final class InferenceFormAddsRuleTest extends InferenceRuleTest
{

	/** @return FormAddsAnnotationRule */
	protected function getRule(): Rule
	{
		return new FormAddsAnnotationRule(
			TestGuard::of(self::getContainer()),
			true,
			self::getContainer()->getByType(ControlAnnotationValueTypeReader::class),
		);
	}

	public function testEveryInvalidTagIsReported(): void
	{
		$class = 'Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsInvalid';

		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/FormAddsInvalid.php'],
			[
				[
					'Malformed @form-adds tag on ' . $class . '::addMalformed(); '
						. 'the grammar is @form-adds $parameterName [FullyQualifiedControlClass].',
					15,
				],
				[
					'@form-adds names $missing, which is not a parameter of '
						. $class . '::addUnknownParameter().',
					23,
				],
				[
					'@form-adds names $name more than once on ' . $class . '::addDuplicateParameter(); '
						. 'each occurrence must name a distinct parameter.',
					32,
				],
				[
					'@form-adds on ' . $class . '::addUnqualifiedClass() names control class TextInput, '
						. 'which does not exist; write it as a fully qualified name.',
					42,
				],
				[
					'@form-adds on ' . $class . '::addNonComponentClass() names control class DateTimeImmutable, '
						. 'which is not a Nette\ComponentModel\IComponent and cannot be modelled as a form component.',
					50,
				],
				[
					'@form-adds on ' . $class . '::addContradictingReturnType() names control class '
						. 'Nette\Forms\Controls\TextInput, which is not a subtype of the declared return type '
						. 'Nette\Forms\Controls\SelectBox.',
					58,
				],
				[
					'@form-adds on ' . $class . '::addNoClassAnywhere() names no control class and the declared '
						. 'return type names none either, so the tag has no class to register.',
					66,
				],
				[
					'@form-adds is only valid on a method declared on a Nette\Forms\Container subclass, and '
						. 'Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsNotAContainer::addThing() '
						. 'is not declared on one.',
					79,
				],
				[
					'@form-adds is only valid on a method declared on a Nette\Forms\Container subclass, '
						. 'and this is not a method of one.',
					88,
				],
			],
		);
	}

	public function testValidTagsAreSilent(): void
	{
		$this->analyse([__DIR__ . '/Fixtures/Rule/FormAddsForm.php'], []);
	}

}
