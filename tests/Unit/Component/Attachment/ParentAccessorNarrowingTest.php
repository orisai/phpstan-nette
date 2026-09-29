<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Component\Attachment;

use PHPStan\Testing\TypeInferenceTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function dirname;

final class ParentAccessorNarrowingTest extends TypeInferenceTestCase
{

	use VersionGroupGate;

	private const FixtureFile = __DIR__ . '/../../../Doubles/Component/Attachment/ParentAccessorNarrowing.php';

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [dirname(__DIR__, 3) . '/Fixtures/Component/Attachment/phpstan-test.neon'];
	}

	public function testNarrowing(): void
	{
		foreach ($this->gatherAssertTypes(self::FixtureFile) as $args) {
			$this->assertFileAsserts(...$args);
		}
	}

}
