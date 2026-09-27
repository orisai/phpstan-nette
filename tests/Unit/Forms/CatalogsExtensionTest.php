<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Doubles\Forms\Catalog\ColorCatalog;
use function dirname;

final class CatalogsExtensionTest extends PHPStanTestCase
{

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [dirname(__DIR__, 2) . '/Fixtures/Forms/Catalog/prototype.neon'];
	}

	/**
	 * @param list<string> $catalogs
	 */
	private function reader(array $catalogs): ControlAnnotationValueTypeReader
	{
		return new ControlAnnotationValueTypeReader(
			self::createReflectionProvider(),
			self::getContainer()->getByType(TypeStringResolver::class),
			$catalogs,
		);
	}

	public function testUserCatalogAddsAMethod(): void
	{
		self::assertNull($this->reader([])->readTypeForAddMethod('addRgbColor'));

		$type = $this->reader([ColorCatalog::class])->readTypeForAddMethod('addRgbColor');
		self::assertNotNull($type);
		self::assertSame('non-empty-string|null', $type->describe(VerbosityLevel::precise()));
	}

	public function testBuiltInsStillAnswerNextToAUserCatalog(): void
	{
		$type = $this->reader([ColorCatalog::class])->readTypeForAddMethod('addText');
		self::assertNotNull($type);
		self::assertSame('string', $type->describe(VerbosityLevel::precise()));
	}

}
