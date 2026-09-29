<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;

/**
 * @template TRule of Rule
 * @extends RuleTestCase<TRule>
 */
abstract class InferenceRuleTest extends RuleTestCase
{

	use VersionGroupGate;

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

}
