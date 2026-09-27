<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use PHPStan\Rules\Debug\DumpTypeRule;
use PHPStan\Rules\Rule;

/**
 * @extends InferenceRuleTest<DumpTypeRule>
 */
final class InferenceDumpParityTest extends InferenceRuleTest
{

	/** @return DumpTypeRule */
	protected function getRule(): Rule
	{
		return new DumpTypeRule(self::createReflectionProvider());
	}

	public function testDumpTypeParity(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/Dump.php'],
			[
				[
					'Dumped type: Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string}',
					17,
				],
				[
					'Dumped type: Nette\Utils\ArrayHash{a: string}',
					32,
				],
			],
		);
	}

}
