<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Parser;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Parser\BootstrapFilesLoader;
use PHPStan\Command\BootstrapFilesRunner;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_filter;
use function array_values;
use function class_exists;
use function spl_autoload_unregister;
use function sys_get_temp_dir;
use function uniqid;

final class BootstrapFilesLoaderTest extends BaseTestCase
{

	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/latte-bootstrap-loader-' . uniqid('', true);
		FileSystem::createDir($this->dir);
	}

	protected function tearDown(): void
	{
		FileSystem::delete($this->dir);
		parent::tearDown();
	}

	public function testLoadsEachExistingFileOnceAndPublishesItsAutoloaders(): void
	{
		$class = 'LatteBootstrapProbe_' . uniqid();
		$autoloaderClass = 'LatteBootstrapAutoloaded_' . uniqid();
		$counter = '__latteBootstrapLoaderRuns_' . uniqid();
		FileSystem::write($this->dir . '/bootstrap.php', <<<PHP
<?php declare(strict_types = 1);

\$GLOBALS['$counter'] = (\$GLOBALS['$counter'] ?? 0) + 1;

class $class
{
}

\$GLOBALS['{$counter}_autoloader'] = static function (string \$name): void {
	if (\$name === '$autoloaderClass') {
		eval('class $autoloaderClass {}');
	}
};
spl_autoload_register(\$GLOBALS['{$counter}_autoloader']);

PHP);

		$loader = new BootstrapFilesLoader([$this->dir . '/bootstrap.php', $this->dir . '/missing.php']);

		try {
			$loader->load();
			$loader->load();

			self::assertTrue(class_exists($class, false));
			self::assertSame(1, $GLOBALS[$counter]);
			self::assertTrue(class_exists($autoloaderClass));

			if (class_exists(BootstrapFilesRunner::class)) { // @phpstan-ignore phpstanApi.classConstant
				self::assertContains($GLOBALS[$counter . '_autoloader'], $GLOBALS['__phpstanAutoloadFunctions'] ?? []);
			}
		} finally {
			spl_autoload_unregister($GLOBALS[$counter . '_autoloader']);
			/** @var list<callable> $published */
			$published = $GLOBALS['__phpstanAutoloadFunctions'] ?? [];
			$GLOBALS['__phpstanAutoloadFunctions'] = array_values(array_filter(
				$published,
				static fn ($function): bool => $function !== $GLOBALS[$counter . '_autoloader'],
			));
			unset($GLOBALS[$counter], $GLOBALS[$counter . '_autoloader']);
		}
	}

}
