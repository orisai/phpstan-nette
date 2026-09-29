<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component;

use PHPStan\Testing\TypeInferenceTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function assert;
use function basename;
use function glob;

final class ComponentTypeInferenceTest extends TypeInferenceTestCase
{

	use VersionGroupGate;

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
		if ($files === []) {
			yield 'no fixtures yet' => [__FILE__];

			return;
		}

		foreach ($files as $file) {
			yield basename($file) => [$file];
		}
	}

	/**
	 * @dataProvider dataFixtures
	 */
	public function testFixture(string $file): void
	{
		if ($file === __FILE__) {
			self::markTestSkipped('Fixtures/TypeInference/ is empty — nothing to assert');
		}

		foreach ($this->gatherAssertTypes($file) as $args) {
			$this->assertFileAsserts(...$args);
		}
	}

	/** @return list<string> */
	public static function getAdditionalAnalysedFiles(): array
	{
		return [__DIR__ . '/../Support/markers.php'];
	}

}
