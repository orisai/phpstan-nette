<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Rule;

use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use OriPhpstan\Nette\Dic\Rule\TypeLookupCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends RuleTestCase<TypeLookupCallRule>
 */
final class TypeLookupCallRuleTest extends RuleTestCase
{

	private const LoaderFile = __DIR__ . '/../Fixtures/fixture-container-loader.php';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		require self::LoaderFile;
	}

	protected function getRule(): Rule
	{
		return new TypeLookupCallRule(
			TestGuard::withContainerLoader(self::getContainer(), self::LoaderFile),
			new MultiContainerRegistry(self::LoaderFile),
		);
	}

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

	public function testRule(): void
	{
		$this->analyse([__DIR__ . '/Fixtures/TypeLookupCallRuleFixture.php'], [
			[
				'Type Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaOnlyService is not autowirable in container(s): beta; getByType() throws there.',
				18,
			],
			[
				'Type Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\NotAutowiredService is registered but not autowired in container(s): alpha, beta; getByType() throws.',
				19,
			],
			[
				'Type Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\DuplicateService is ambiguous in container(s): alpha (dupA, dupB), beta (dupA, dupB); getByType() throws.',
				20,
			],
			[
				'Type Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\DuplicateService is ambiguous in container(s): alpha (dupA, dupB), beta (dupA, dupB); getByType() throws.',
				21,
			],
			[
				'Type Tests\Nowhere\Unknown is not registered in any analysed container (alpha, beta).',
				23,
			],
			[
				'Dynamic type in Nette\DI\Container::getByType() cannot be analysed. Provide a literal ::class type.',
				24,
			],
			[
				'Type Tests\Nowhere\Unknown is never resolvable; findByType() always returns an empty array.',
				26,
			],
			[
				'Type Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaOnlyService is not registered in container(s): beta.',
				27,
			],
			[
				'Dynamic type in Nette\DI\Container::findByType() cannot be analysed. Provide a literal ::class type.',
				28,
			],
			[
				'Dynamic type in Nette\DI\Container::createInstance() cannot be analysed. Provide a literal ::class type.',
				29,
			],
		]);
	}

	public function testReceiverClasses(): void
	{
		$this->analyse([__DIR__ . '/Fixtures/ReceiverClassFixture.php'], [
			[
				'Type Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\BetaOnlyService is not registered in any analysed container (alpha).',
				20,
			],
		]);
	}

}
