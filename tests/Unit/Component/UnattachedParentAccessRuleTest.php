<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Component;

use OriPhpstan\Nette\Component\UnattachedParentAccessRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function dirname;

/**
 * @extends RuleTestCase<UnattachedParentAccessRule>
 */
final class UnattachedParentAccessRuleTest extends RuleTestCase
{

	use VersionGroupGate;

	private const FixtureFile = __DIR__ . '/../../Doubles/Component/UnattachedParentAccessRuleFixture.php';

	protected function getRule(): Rule
	{
		return new UnattachedParentAccessRule(TestGuard::of(self::getContainer()), true);
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
		$attachTip = 'Attach the component first — $container[\'name\'] = $component — ';
		$formTip = $attachTip . 'or use getForm(false), which answers null instead of throwing.';

		$this->analyse(
			[self::FixtureFile],
			[
				// everyThrowingAccessor()
				[$this->message('getForm'), 27, $formTip],
				[
					$this->message('lookup'),
					28,
					$attachTip . 'or use lookup($type, false), which answers null instead of throwing.',
				],
				[
					$this->message('lookupPath'),
					29,
					$attachTip . 'or use lookupPath($type, false), which answers null instead of throwing.',
				],
				[
					$this->message('getPresenter'),
					32,
					$attachTip . 'or use getPresenterIfExists(), which answers null instead of throwing.',
				],
				// getUniqueId() has no no-throw spelling, so its tip is the bare one.
				[$this->message('getUniqueId'), 33, $attachTip . 'and ask afterwards.'],
				// aRemovedComponentIsDetachedAgain()
				[$this->message('getForm'), 90, $formTip],
				// armsThatAgreeStayDetached()
				[$this->message('getForm'), 120, $formTip],
				// aBranchArmIsEnteredAtTheHeaderState()
				[$this->message('getForm'), 127, $formTip],
				// anElseifConditionThatMovesNothingKeepsTheAnswer()
				[$this->message('getForm'), 153, $formTip],
				// aConstructionInsideALoopStillReports()
				[$this->message('getForm'), 177, $formTip],
				// aConstructionInsideAClosureStillReports()
				[$this->message('getForm'), 214, $formTip],
				// aConstructionCalledOnDirectly()
				[$this->message('getForm'), 233, $formTip],
			],
		);
	}

	private function message(string $accessor): string
	{
		return "Component is not attached to a parent here, so $accessor() always throws Nette\\InvalidStateException.";
	}

}
