<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Discovery;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\FileClassScanner;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function getmypid;
use function sys_get_temp_dir;
use function uniqid;

final class FileClassScannerTest extends BaseTestCase
{

	public function testEveryDeclarationCarriesWhetherItIsAClass(): void
	{
		$file = sys_get_temp_dir() . '/file-class-scanner-' . getmypid() . '-' . uniqid() . '.php';
		FileSystem::write($file, <<<'PHP'
<?php declare(strict_types = 1);

namespace Scan\Me;

interface Renders
{

}

trait RendersToo
{

}

abstract class BaseRenderer implements Renders
{

	public function name(): string
	{
		return self::class . get_class(new class {
		});
	}

}

final class Renderer extends BaseRenderer
{

}

PHP);

		try {
			$scanner = new FileClassScanner();

			self::assertSame(
				[
					['Scan\Me\Renders', false],
					['Scan\Me\RendersToo', false],
					['Scan\Me\BaseRenderer', true],
					['Scan\Me\Renderer', true],
				],
				$scanner->declarationsIn($file),
			);
			self::assertSame('Scan\Me\Renders', $scanner->firstClassLikeIn($file));
		} finally {
			FileSystem::delete($file);
		}
	}

	public function testAnUnreadableFileDeclaresNothing(): void
	{
		$scanner = new FileClassScanner();
		$file = sys_get_temp_dir() . '/file-class-scanner-missing-' . uniqid() . '.php';

		self::assertSame([], $scanner->declarationsIn($file));
		self::assertNull($scanner->firstClassLikeIn($file));
	}

}
