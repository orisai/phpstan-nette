<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Type;

use PHPStan\Testing\TypeInferenceTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\FixtureContainerFactory;
use function assert;
use function basename;
use function glob;

final class DicTypeInferenceTest extends TypeInferenceTestCase
{

	use VersionGroupGate;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		require __DIR__ . '/../Fixtures/fixture-container-loader.php';
	}

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

	/** @return iterable<string, array{string}> */
	public static function dataFixtures(): iterable
	{
		$line = InstalledVersionsGuard::satisfies('nette/di', '^3.2') ? 'Nette32' : 'Nette31';
		foreach ([__DIR__ . '/Fixtures/TypeInference', __DIR__ . '/Fixtures/TypeInference/' . $line] as $dir) {
			$files = glob($dir . '/*.php');
			assert($files !== false);

			foreach ($files as $file) {
				yield basename($file) => [$file];
			}
		}

		yield 'ReceiverClassFixture.php' => [
			FixtureContainerFactory::receiverFixture(__DIR__ . '/Fixtures/TypeInference/ReceiverClassFixture.php.tpl'),
		];
	}

	/**
	 * @dataProvider dataFixtures
	 */
	public function testFixture(string $file): void
	{
		foreach ($this->gatherAssertTypes($file) as $args) {
			$this->assertFileAsserts(...$args);
		}
	}

}
