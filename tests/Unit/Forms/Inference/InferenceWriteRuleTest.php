<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Rule\FormWriteAccessRule;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<FormWriteAccessRule>
 */
final class InferenceWriteRuleTest extends InferenceRuleTest
{

	/** @return FormWriteAccessRule */
	protected function getRule(): Rule
	{
		return new FormWriteAccessRule(
			TestGuard::of(self::getContainer()),
			true,
			self::getContainer()->getByType(TypeStringResolver::class),
		);
	}

	public function testWrite(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/Write.php'],
			[
				[
					"Form field 'a' (Nette\\Forms\\Controls\\TextInput) accepts scalar|Stringable|null, array{1, 2} given.",
					41,
				],
				[
					"Form field 'a' (Nette\\Forms\\Controls\\Checkbox) accepts scalar|null, array{'x'} given.",
					73,
				],
				[
					"Form field 'a' (Nette\\Forms\\Controls\\SelectBox) accepts string|int|BackedEnum|null, float given.",
					89,
				],
				[
					"Form field 'a' (Nette\\Forms\\Controls\\MultiSelectBox) accepts iterable<scalar|Stringable|BackedEnum>|scalar|null, stdClass given.",
					105,
				],
				[
					"Form field 'a' (Nette\\Forms\\Controls\\DateTimeControl) accepts DateTimeInterface|string|int|null, true given.",
					129,
				],
				[
					"Form field 'a' (Nette\\Forms\\Controls\\ColorPicker) accepts string|null, int given.",
					145,
				],
				[
					"setValue() on upload field 'a' has no effect.",
					153,
				],
				[
					"setValue() on upload field 'a' has no effect.",
					161,
				],
			],
		);
	}

	public function testSetValues(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/SetValues.php'],
			[
				[
					'Form values must be an array or Traversable, string given.',
					85,
				],
			],
		);
	}

	public function testUploadButtons(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/UploadButtons.php'],
			[
				[
					"setValue() on upload field 'a' has no effect.",
					15,
				],
				[
					"setValue() on upload field 'a' has no effect.",
					23,
				],
			],
		);
	}

	public function testAsymmetry(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/Asymmetry.php'],
			[],
		);
	}

	public function testCustomControlWrite(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/CustomControlWrite.php'],
			[
				[
					"Form field 'x' (Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\WriteRating) accepts 'a'|'b'|'c', string given.",
					21,
				],
			],
		);
	}

}
