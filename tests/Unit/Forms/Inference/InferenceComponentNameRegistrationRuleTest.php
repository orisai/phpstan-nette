<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Rule\ComponentNameRegistrationRule;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<ComponentNameRegistrationRule>
 */
final class InferenceComponentNameRegistrationRuleTest extends InferenceRuleTest
{

	/** @return ComponentNameRegistrationRule */
	protected function getRule(): Rule
	{
		return new ComponentNameRegistrationRule(TestGuard::of(self::getContainer()), true);
	}

	public function testAConstantNameNetteWouldRejectIsReportedAtItsRegistration(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/ComponentNameRegistration.php'],
			[
				[
					"Component name 'first-name' is rejected by Nette at registration: addComponent() "
					. 'accepts a non-empty name of [a-zA-Z0-9_] only, and throws otherwise.',
					26,
				],
				[
					"Component name 'bad name' is rejected by Nette at registration: addComponent() "
					. 'accepts a non-empty name of [a-zA-Z0-9_] only, and throws otherwise.',
					32,
				],
				[
					"Component name '' is rejected by Nette at registration: addComponent() "
					. 'accepts a non-empty name of [a-zA-Z0-9_] only, and throws otherwise.',
					38,
				],
				[
					"Component name 'bad name' is rejected by Nette at registration: addComponent() "
					. 'accepts a non-empty name of [a-zA-Z0-9_] only, and throws otherwise.',
					52,
				],
				[
					"Component name 'bad name' is rejected by Nette at registration: addComponent() "
					. 'accepts a non-empty name of [a-zA-Z0-9_] only, and throws otherwise.',
					58,
				],
			],
		);
	}

	// The argument is found by the PARAMETER it binds to, not by its position, so a named argument
	// is judged exactly like a positional one.
	public function testANamedArgumentIsJudgedTheSameAsAPositionalOne(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/ComponentNameRegistrationPhp8.php'],
			[
				[
					"Component name 'bad name' is rejected by Nette at registration: addComponent() "
					. 'accepts a non-empty name of [a-zA-Z0-9_] only, and throws otherwise.',
					18,
				],
			],
		);
	}

}
