<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Type;

use PHPStan\Testing\TypeInferenceTestCase;
use function assert;
use function basename;
use function glob;

final class DicTypeInferenceTest extends TypeInferenceTestCase
{

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
		$files = glob(__DIR__ . '/Fixtures/TypeInference/*.php');
		assert($files !== false);

		foreach ($files as $file) {
			yield basename($file) => [$file];
		}
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
