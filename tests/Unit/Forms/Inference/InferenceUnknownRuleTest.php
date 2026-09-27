<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Rule\FormShapeUnknownAccessRule;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<FormShapeUnknownAccessRule>
 */
final class InferenceUnknownRuleTest extends InferenceRuleTest
{

	/** @return FormShapeUnknownAccessRule */
	protected function getRule(): Rule
	{
		return new FormShapeUnknownAccessRule(TestGuard::of(self::getContainer()), true);
	}

	public function testUnknownAccess(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/UnknownAccess.php'],
			[
				[
					"Form value 'nope' may not exist; the form shape is open.",
					17,
					'Form shape opened by: dynamic_name',
				],
				[
					"Form value 'nope' may not exist; the form shape is open.",
					26,
					'Form shape opened by: extension_method',
				],
				[
					"Form value 'nope' may not exist; the form shape is open.",
					36,
					'Form shape opened by: dynamic_name,extension_method',
				],
				[
					"Form value 'nope' does not exist.",
					59,
				],
				[
					"Form component 'nope' does not exist.",
					67,
				],
			],
		);
	}

	/**
	 * An opaque slot lost its VALUE type, not its component. FormShapeProjector::offset() now reads
	 * the control class back out of componentTypes for these names instead of answering mixed, and
	 * this report is what still says the value side is unreadable — the two axes are independent and
	 * the type resolution must not silence the report. Both spellings keep it: the component access
	 * (whose type does now resolve) and the value read (which is what the message is about).
	 */
	public function testOpaqueSlotStillReportsAnUnknownValue(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/OpaqueSlotAccess.php'],
			[
				[
					"Form value 'a' has an unknown type.",
					17,
					'Form shape opened by: form_aliased',
				],
				[
					"Form value 'a' has an unknown type.",
					27,
					'Form shape opened by: form_aliased',
				],
			],
		);
	}

	/**
	 * The property channel's openness reason names LOST fields — an unmodelled external write may
	 * have added the accessed name, and may equally have removed a listed one. It therefore belongs
	 * in the rule's lost-field set, exactly as it belongs in LOST_FIELD_UNKNOWN_REASONS; a rule
	 * whose own copy of that set omits it turns every property-held form into a false-positive
	 * factory the moment the consumer channel is wired up.
	 */
	public function testPropertyWriteUnmodelledIsNotAnUnknownAccess(): void
	{
		$this->analyse([__DIR__ . '/Fixtures/Rule/PropertyWriteUnmodelledAccess.php'], []);
	}

}
