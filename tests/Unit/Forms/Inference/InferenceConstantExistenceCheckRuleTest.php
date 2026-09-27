<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Rule\FormExistenceCheckConstantRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<FormExistenceCheckConstantRule>
 */
final class InferenceConstantExistenceCheckRuleTest extends InferenceRuleTest
{

	/** @return FormExistenceCheckConstantRule */
	protected function getRule(): Rule
	{
		return new FormExistenceCheckConstantRule(
			TestGuard::of(self::getContainer()),
			true,
			PHPStanTestCase::createReflectionProvider(),
		);
	}

	public function testConstantExistenceChecks(): void
	{
		$noFactoryTip = 'The form declares no createComponentNope(), so the check cannot build one either.';

		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/ConstantExistenceCheck.php'],
			[
				// CE-01 a child the closed shape holds
				["Form component 'a' in isset() always exists.", 21],
				// CE-02 neither held nor buildable
				["Form component 'nope' in isset() never exists.", 29, $noFactoryTip],
				// CE-03 the trap: absent when the check starts, and TRUE because it runs the factory
				["Form component 'sub' in isset() always exists.", 38],
				// CE-04 a capitalised name can have no factory at all
				[
					"Form component 'Sub' in isset() never exists.",
					47,
					'Nette resolves no factory for a capitalised name, so the check cannot build one either.',
				],
				// CE-08 / CE-09 the spelled-out check answers identically
				["Form component 'nope' in offsetExists() never exists.", 83, $noFactoryTip],
				["Form component 'a' in offsetExists() always exists.", 91],
				// CE-14 a nested container's own closed shape
				["Form component 'nope' in isset() never exists.", 132, $noFactoryTip],
				// CE-16 every hop of the chain is definite
				["Form component 'inner' in isset() always exists.", 150],
				// CE-18 the innermost provably absent hop settles the chain and is what is named
				[
					"Form component 'nosuch' in isset() never exists.",
					173,
					'The form declares no createComponentNosuch(), so the check cannot build one either.',
				],
			],
		);
	}

}
