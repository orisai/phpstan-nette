<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Parser;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Parser\BootstrapFilesLoader;
use PHPStan\Command\BootstrapFilesRunner;
use PHPStan\DependencyInjection\Container;
use PHPStan\Testing\PHPStanTestCase;
use RuntimeException;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_filter;
use function array_values;
use function class_exists;
use function method_exists;
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

	public function testLoadsEachExistingFileOnceWithTheContainerInScopeAndPublishesItsAutoloaders(): void
	{
		$class = 'LatteBootstrapProbe_' . uniqid();
		$autoloaderClass = 'LatteBootstrapAutoloaded_' . uniqid();
		$counter = '__latteBootstrapLoaderRuns_' . uniqid();
		FileSystem::write($this->dir . '/bootstrap.php', <<<PHP
<?php declare(strict_types = 1);

\$GLOBALS['$counter'] = (\$GLOBALS['$counter'] ?? 0) + 1;
\$GLOBALS['{$counter}_tmpDir'] = \$container->getParameter('tmpDir');

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

		$published = [];
		$loader = new BootstrapFilesLoader(
			$this->container(),
			[$this->dir . '/bootstrap.php', $this->dir . '/missing.php'],
			static function ($autoloadFunctionsBefore) use (&$published): void {
				$published[] = $autoloadFunctionsBefore;
			},
		);

		try {
			$loader->load();
			$loader->load();

			self::assertTrue(class_exists($class, false));
			self::assertSame(1, $GLOBALS[$counter]);
			self::assertSame($this->container()->getParameter('tmpDir'), $GLOBALS[$counter . '_tmpDir']);
			self::assertTrue(class_exists($autoloaderClass));
			self::assertCount(1, $published);
			self::assertIsArray($published[0]);
			self::assertNotContains($GLOBALS[$counter . '_autoloader'], $published[0]);
		} finally {
			spl_autoload_unregister($GLOBALS[$counter . '_autoloader']);
			unset($GLOBALS[$counter], $GLOBALS[$counter . '_autoloader'], $GLOBALS[$counter . '_tmpDir']);
		}
	}

	public function testCreatePublishesThroughPhpstansRunner(): void
	{
		// @phpstan-ignore phpstanApi.classConstant, function.alreadyNarrowedType
		if (!method_exists(BootstrapFilesRunner::class, 'mergeNewAutoloadFunctions')) {
			self::markTestSkipped('requires PHPStan with BootstrapFilesRunner::mergeNewAutoloadFunctions()');
		}

		$autoloaderClass = 'LatteBootstrapAutoloaded_' . uniqid();
		$key = '__latteBootstrapLoaderAutoloader_' . uniqid();
		FileSystem::write($this->dir . '/bootstrap.php', <<<PHP
<?php declare(strict_types = 1);

\$GLOBALS['$key'] = static function (string \$name): void {
	if (\$name === '$autoloaderClass') {
		eval('class $autoloaderClass {}');
	}
};
spl_autoload_register(\$GLOBALS['$key']);

PHP);

		try {
			BootstrapFilesLoader::create($this->container(), [$this->dir . '/bootstrap.php'])->load();

			self::assertContains($GLOBALS[$key], $GLOBALS['__phpstanAutoloadFunctions'] ?? []);
		} finally {
			spl_autoload_unregister($GLOBALS[$key]);
			/** @var list<callable> $functions */
			$functions = $GLOBALS['__phpstanAutoloadFunctions'] ?? [];
			$GLOBALS['__phpstanAutoloadFunctions'] = array_values(array_filter(
				$functions,
				static fn ($function): bool => $function !== $GLOBALS[$key],
			));
			unset($GLOBALS[$key]);
		}
	}

	public function testWithoutAPublisherNothingIsLoaded(): void
	{
		$class = 'LatteBootstrapProbe_' . uniqid();
		FileSystem::write($this->dir . '/bootstrap.php', "<?php declare(strict_types = 1);\n\nclass $class\n{\n}\n");

		(new BootstrapFilesLoader($this->container(), [$this->dir . '/bootstrap.php'], null))->load();

		self::assertFalse(class_exists($class, false));
	}

	public function testAThrowingBootstrapFileIsReportedWithItsPath(): void
	{
		FileSystem::write(
			$this->dir . '/bootstrap.php',
			"<?php declare(strict_types = 1);\n\nthrow new LogicException('boom');\n",
		);

		$loader = new BootstrapFilesLoader(
			$this->container(),
			[$this->dir . '/bootstrap.php'],
			static function (): void {
			},
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(
			'LogicException thrown in ' . $this->dir . '/bootstrap.php on line 3 while loading bootstrap file ' . $this->dir . '/bootstrap.php: boom',
		);
		$loader->load();
	}

	private function container(): Container
	{
		return PHPStanTestCase::getContainer();
	}

}
