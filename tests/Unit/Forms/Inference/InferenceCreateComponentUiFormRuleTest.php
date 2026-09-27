<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Rule\CreateComponentReturnsUiFormRule;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<CreateComponentReturnsUiFormRule>
 */
final class InferenceCreateComponentUiFormRuleTest extends InferenceRuleTest
{

	/** @return CreateComponentReturnsUiFormRule */
	protected function getRule(): Rule
	{
		return new CreateComponentReturnsUiFormRule(TestGuard::of(self::getContainer()), true);
	}

	public function testCreateComponentMustReturnUiForm(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/CreateComponentUiForm.php'],
			[
				[
					'createComponentContentForm() returns Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm, which is a '
						. 'Nette\Forms\Form but not a Nette\Application\UI\Form. Presenter-attached forms '
						. 'must extend Nette\Application\UI\Form so that '
						. 'signal handling and submission work.',
					28,
				],
				[
					'createComponentBareForm() returns Nette\Forms\Form, which is a Nette\Forms\Form but '
						. 'not a Nette\Application\UI\Form. Presenter-attached forms must extend '
						. 'Nette\Application\UI\Form so that signal '
						. 'handling and submission work.',
					34,
				],
			],
		);
	}

}
