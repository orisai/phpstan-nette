<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Rule;

use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use OriPhpstan\Nette\Dic\Rule\ServiceNameCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends RuleTestCase<ServiceNameCallRule>
 */
final class ServiceNameCallRuleTest extends RuleTestCase
{

	private const LoaderFile = __DIR__ . '/../Fixtures/fixture-container-loader.php';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		require self::LoaderFile;
	}

	protected function getRule(): Rule
	{
		return new ServiceNameCallRule(
			TestGuard::withContainerLoader(self::getContainer(), self::LoaderFile),
			new MultiContainerRegistry(self::LoaderFile),
		);
	}

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/hasservice-narrowing.neon'];
	}

	public function testRule(): void
	{
		$this->analyse([__DIR__ . '/Fixtures/ServiceNameCallRuleFixture.php'], [
			['Service \'alphaOnly\' is not registered in container(s): beta.', 13],
			['Service \'nonexistent\' is not registered in any analysed container (alpha, beta).', 14],
			['Dynamic service name in Nette\DI\Container::getService() cannot be analysed. Provide a literal service name.', 15],
			['Service \'betaOnly\' is not registered in container(s): alpha.', 17],
			['Service \'nonexistent\' is not registered in any analysed container (alpha, beta).', 18],
			['Service \'nonexistent\' is not registered in any analysed container (alpha, beta).', 19],
			['Service \'nonexistent\' is not registered in any analysed container (alpha, beta).', 20],
			['Service \'nonexistent\' is not registered in any analysed container (alpha, beta); hasService() always returns false.', 21],
			['Dynamic service name in Nette\DI\Container::hasService() cannot be analysed. Provide a literal service name.', 23],
			['Service \'chainedAlias\' is not registered in any analysed container (alpha, beta); hasService() always returns false.', 26],
			['Service \'chainedAlias\' is not registered in any analysed container (alpha, beta).', 27],
			['Service \'chainedAlias\' is not registered in any analysed container (alpha, beta).', 28],
		]);
	}

	public function testHasServiceGuardNarrowing(): void
	{
		$this->analyse([__DIR__ . '/Fixtures/HasServiceGuardFixture.php'], [
			['Service \'nonexistent\' is not registered in any analysed container (alpha, beta); hasService() always returns false.', 12],
			['Service \'foo\' is registered in every analysed container (alpha, beta); hasService() always returns true.', 13],
			['Service \'fooRealAlias\' is registered in every analysed container (alpha, beta); hasService() always returns true.', 51],
			['Service \'chainedAlias\' is not registered in any analysed container (alpha, beta); hasService() always returns false.', 58],
			['Service \'alphaOnly\' is excluded by the hasService() guard in this branch; the call always throws here.', 68],
			['Service \'alphaOnly\' is excluded by the hasService() guard in this branch; the call always throws here.', 75],
			['Service \'alphaOnly\' is excluded by the hasService() guard in this branch; the call always throws here.', 87],
			['Service \'fooRealAlias\' is registered in every analysed container (alpha, beta); hasService() always returns true.', 92],
			['Service \'foo\' is excluded by the hasService() guard in this branch; the call always throws here.', 96],
			['Service \'alphaOnly\' is excluded by the hasService() guard in this branch; the call always throws here.', 104],
			['Service \'alphaOnly\' is excluded by the hasService() guard in this branch; the call always throws here.', 114],
			['Service \'alphaOnly\' is excluded by the hasService() guard in this branch; the call always throws here.', 124],
			['Service \'alphaOnly\' is excluded by the hasService() guard in this branch; the call always throws here.', 126],
			['Service \'betaOnly\' is excluded by the hasService() guard in this branch; the call always throws here.', 127],
		]);
	}

	public function testReceiverClasses(): void
	{
		$this->analyse([__DIR__ . '/Fixtures/ReceiverClassFixture.php'], [
			['Service \'betaOnly\' is not registered in any analysed container (alpha).', 16],
			['Service \'nonexistent\' is not registered in any analysed container (alpha).', 17],
			['Service \'alphaOnly\' is registered in every analysed container (alpha); hasService() always returns true.', 18],
			['Service \'betaOnly\' is not registered in any analysed container (alpha); hasService() always returns false.', 19],
			['Service \'alphaOnly\' is not registered in container(s): beta.', 36],
			['Service \'foo\' is registered in every analysed container (alpha, beta); hasService() always returns true.', 37],
		]);
	}

}
