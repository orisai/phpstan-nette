<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use PHPStan\Rules\Arrays\NonexistentOffsetInArrayDimFetchRule;
use PHPStan\Rules\Rule;

/**
 * Exercises the CORE PHPStan offsetAccess.notFound rule directly (not a project rule), since
 * FormReplicatorType::hasOffsetValueType() is what this rule consults - a dumpType fixture only
 * checks the resolved type, never the presence/absence of this error.
 *
 * @extends InferenceRuleTest<NonexistentOffsetInArrayDimFetchRule>
 */
final class InferenceReplicatorOffsetAccessRuleTest extends InferenceRuleTest
{

	/** @return NonexistentOffsetInArrayDimFetchRule */
	protected function getRule(): Rule
	{
		return self::getContainer()->getByType(NonexistentOffsetInArrayDimFetchRule::class);
	}

	public function testRuntimeIntOffsetOnReplicatorIsNotReported(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/ReplicatorOffsetAccess.php'],
			[
				[
					'Offset 5 does not exist on array{1, 2, 3}.',
					42,
				],
			],
		);
	}

}
