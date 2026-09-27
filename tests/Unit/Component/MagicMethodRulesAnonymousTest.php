<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Component;

use OriPhpstan\Nette\Component\MagicMethodReturnTypeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use function dirname;

/**
 * @extends RuleTestCase<MagicMethodReturnTypeRule>
 */
final class MagicMethodRulesAnonymousTest extends RuleTestCase
{

	private const FixtureFile = __DIR__ . '/../../Doubles/Component/AnonymousComponentFixture.php';

	protected function getRule(): Rule
	{
		return new MagicMethodReturnTypeRule(TestGuard::of(self::getContainer()), true);
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [
			dirname(__DIR__, 2) . '/Fixtures/Component/phpstan-test.neon',
		];
	}

	public function testRule(): void
	{
		$this->analyse(
			[self::FixtureFile],
			[
				['Method class@anonymous::handleFoo() must return void or never.', 14],
			],
		);
	}

}
