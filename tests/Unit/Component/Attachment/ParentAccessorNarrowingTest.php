<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Component\Attachment;

use Nette\ComponentModel\Component;
use PHPStan\Testing\TypeInferenceTestCase;
use ReflectionMethod;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function dirname;
use function strpos;

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

	// nette/component-model 3.1+ types lookup() through a generic @return, so the vendor answer
	// the shared fixture leaves alone is the installed docblock's.
	public function testLookupKeepsTheInstalledVendorAnswer(): void
	{
		$docComment = (string) (new ReflectionMethod(Component::class, 'lookup'))->getDocComment();
		$fixture = dirname(__DIR__, 3) . '/Doubles/Component/Attachment/'
			. (strpos($docComment, '@template') !== false ? 'GenericLookup' : 'PlainLookup')
			. '/ParentAccessorLookup.php';

		foreach ($this->gatherAssertTypes($fixture) as $args) {
			$this->assertFileAsserts(...$args);
		}
	}

}
