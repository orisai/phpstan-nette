<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Rule;

use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use OriPhpstan\Nette\Dic\Rule\TagCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\FixtureContainerFactory;

/**
 * @extends RuleTestCase<TagCallRule>
 */
final class TagCallRuleTest extends RuleTestCase
{

	use VersionGroupGate;

	private const LoaderFile = __DIR__ . '/../Fixtures/fixture-container-loader.php';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		require self::LoaderFile;
	}

	protected function getRule(): Rule
	{
		return new TagCallRule(
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
		$this->analyse([__DIR__ . '/Fixtures/TagCallRuleFixture.php'], [
			['Tag \'alpha.tag\' is not present in container(s): beta.', 13],
			['Tag \'no.such.tag\' is not present in any analysed container (alpha, beta).', 14],
			['Dynamic tag in Nette\DI\Container::findByTag() cannot be analysed. Provide a literal tag name.', 15],
		]);
	}

	public function testReceiverClasses(): void
	{
		$this->analyse([FixtureContainerFactory::receiverFixture(__DIR__ . '/Fixtures/ReceiverClassFixture.php.tpl')], [
			['Tag \'alpha.tag\' is not present in any analysed container (beta).', 27],
		]);
	}

}
