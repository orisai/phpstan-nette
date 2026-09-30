<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs;

use Latte\Engine;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Customs\ExtensionSourceSalt;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function chmod;
use function dirname;
use function function_exists;
use function posix_geteuid;
use function str_repeat;
use function uniqid;
use const DIRECTORY_SEPARATOR;

final class ExtensionSourceSaltTest extends BaseTestCase
{

	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 4) . '/var/tmp/extension-source-salt-' . uniqid();
	}

	protected function tearDown(): void
	{
		@chmod($this->root . '/ext/Locked', 0755);
		FileSystem::delete($this->root);
		parent::tearDown();
	}

	public function testNestedNodeFilesAreSalted(): void
	{
		$class = $this->extension('ext');
		$this->write('ext/Nodes/HelloNode.php', 1);

		$before = $this->salt()->describe($class);
		$this->write('ext/Nodes/HelloNode.php', 2);
		$after = $this->salt()->describe($class);

		self::assertFalse($before['shallow']);
		self::assertSame([], $before['unreadable']);
		self::assertNotSame($before['salt'], $after['salt']);
	}

	/**
	 * @dataProvider provideExcludedDirectories
	 */
	public function testExcludedDirectoriesDoNotReachTheSalt(string $churningFile, string $marker): void
	{
		$class = $this->extension('ext');
		if ($marker !== '') {
			FileSystem::write($this->root . '/' . $marker, '{}');
		}

		$this->write($churningFile, 1);
		$before = $this->salt()->describe($class);
		$this->write($churningFile, 2);
		$after = $this->salt()->describe($class);

		self::assertFalse($before['shallow']);
		self::assertSame($before['salt'], $after['salt']);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public function provideExcludedDirectories(): iterable
	{
		yield 'vendor directory' => ['ext/vendor/acme/lib/Lib.php', 'ext/vendor/composer/installed.json'];
		yield 'composer directory' => ['ext/composer/autoload_real.php', 'ext/composer/installed.json'];
		yield 'dot directory' => ['ext/.cache/Cached.php', ''];
		yield 'nested project' => ['ext/tools/Tool.php', 'ext/tools/composer.json'];
	}

	// A tmpDir inside the extension's tree - an extension declared high up in the project - makes the
	// walk shallow: PHPStan's own cache files churn on every run.
	public function testTmpDirInsideTheTreeFallsBackToTheShallowSalt(): void
	{
		$class = $this->extension('ext');
		$this->write('ext/tmp/resultCache.php', 1);
		$this->write('ext/Nodes/HelloNode.php', 1);

		$salt = new ExtensionSourceSalt($this->root . '/ext/tmp', null, ProjectInstalledVersions::get());
		$before = $salt->describe($class);
		$this->write('ext/tmp/resultCache.php', 2);
		$this->write('ext/Nodes/HelloNode.php', 2);
		$after = (new ExtensionSourceSalt($this->root . '/ext/tmp', null, ProjectInstalledVersions::get()))
			->describe($class);

		self::assertTrue($before['shallow']);
		self::assertSame($before['salt'], $after['salt']);

		$this->write('ext/Sibling.php', 1);
		self::assertNotSame(
			$before['salt'],
			(new ExtensionSourceSalt($this->root . '/ext/tmp', null, ProjectInstalledVersions::get()))
				->describe($class)['salt'],
		);
	}

	/**
	 * @dataProvider provideStoreFile
	 */
	public function testAStoreInsideTheTreeIsLeftOut(string $store, string $file): void
	{
		$class = $this->extension('ext');
		$this->write('ext/' . $store . '/' . $file . 'a.php', 1);
		$salt = fn (): ExtensionSourceSalt => new ExtensionSourceSalt(
			null,
			null,
			ProjectInstalledVersions::get(),
			[$this->root . '/ext/discovery', $this->root . '/ext/sitescope'],
		);

		$before = $salt()->describe($class);
		$this->write('ext/' . $store . '/' . $file . 'a.php', 2);
		$this->write('ext/' . $store . '/' . $file . 'b.php', 1);
		$after = $salt()->describe($class);

		self::assertFalse($before['shallow']);
		self::assertSame($before['salt'], $after['salt']);

		$this->write('ext/Nodes/HelloNode.php', 1);
		self::assertNotSame($before['salt'], $salt()->describe($class)['salt']);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public function provideStoreFile(): iterable
	{
		yield 'discovery store' => ['discovery', 'LatteDiscovery_'];
		yield 'narrowing store' => ['sitescope', 'LatteSlice_'];
	}

	public function testProjectRootIsSaltedShallowly(): void
	{
		$class = $this->extension('ext');
		$this->write('ext/Nodes/HelloNode.php', 1);

		$salt = new ExtensionSourceSalt(null, $this->root . '/ext', ProjectInstalledVersions::get());
		$before = $salt->describe($class);
		$this->write('ext/Nodes/HelloNode.php', 2);
		$after = (new ExtensionSourceSalt(null, $this->root . '/ext', ProjectInstalledVersions::get()))
			->describe($class);

		self::assertTrue($before['shallow']);
		self::assertSame($before['salt'], $after['salt']);
	}

	public function testAWalkOverTheFileCapFallsBackToTheShallowSalt(): void
	{
		$class = $this->extension('ext');
		for ($i = 0; $i <= ExtensionSourceSalt::MAX_FILES; $i++) {
			FileSystem::write($this->root . '/ext/Many/File' . $i . '.php', '<?php');
		}

		self::assertTrue($this->salt()->describe($class)['shallow']);
	}

	// An unreadable directory is salted as such and reported, never fatal; a permission flip changes
	// the salt.
	public function testUnreadableDirectoryIsSaltedAndReported(): void
	{
		if (DIRECTORY_SEPARATOR === '\\' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
			self::markTestSkipped('Needs a filesystem that can deny a directory to this user.');
		}

		$class = $this->extension('ext');
		$this->write('ext/Locked/Node.php', 1);
		$readable = $this->salt()->describe($class);

		chmod($this->root . '/ext/Locked', 0000);
		$locked = $this->salt()->describe($class);

		self::assertFalse($locked['shallow']);
		self::assertSame([$this->root . '/ext/Locked'], $locked['unreadable']);
		self::assertNotSame($readable['salt'], $locked['salt']);
	}

	public function testInstalledPackageIsSaltedByItsVersion(): void
	{
		$installed = ProjectInstalledVersions::get();

		self::assertSame(
			'package:latte/latte@' . $installed->getVersion('latte/latte') . '#' . ($installed->getReference(
				'latte/latte',
			) ?? '?'),
			$this->salt()->describe(Engine::class)['salt'],
		);
	}

	private function salt(): ExtensionSourceSalt
	{
		return new ExtensionSourceSalt(null, null, ProjectInstalledVersions::get());
	}

	/**
	 * @return class-string
	 */
	private function extension(string $directory): string
	{
		$namespace = 'ScratchSalt' . uniqid();
		$file = $this->root . '/' . $directory . '/Extension.php';
		FileSystem::write(
			$file,
			"<?php declare(strict_types = 1);\n\nnamespace $namespace;\n\nfinal class Extension\n{\n}\n",
		);
		require_once $file;

		/** @var class-string $class */
		$class = $namespace . '\Extension';

		return $class;
	}

	private function write(string $relative, int $version): void
	{
		FileSystem::write($this->root . '/' . $relative, '<?php // ' . str_repeat('v', $version) . "\n");
	}

}
