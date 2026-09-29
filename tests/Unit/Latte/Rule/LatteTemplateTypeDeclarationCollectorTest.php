<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Rule\LatteTemplateTypeDeclarationCollector;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use function getmypid;
use function sys_get_temp_dir;
use function uniqid;

final class LatteTemplateTypeDeclarationCollectorTest extends BaseTestCase
{

	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/orisai-template-type-collector-' . getmypid() . '-' . uniqid();
		FileSystem::createDir($this->dir);
	}

	protected function tearDown(): void
	{
		FileSystem::delete($this->dir);
		parent::tearDown();
	}

	public function testCollectsTheDeclarationOfAReadableTemplate(): void
	{
		FileSystem::write($this->dir . '/typed.latte', "\n{templateType Foo\\Bar}\n{\$x}\n");
		FileSystem::write($this->dir . '/untyped.latte', "{\$x}\n");

		self::assertSame(
			['path' => 'typed.latte', 'class' => 'Foo\Bar', 'line' => 2],
			$this->collector()->processNode(new FileNode([]), $this->scope($this->dir . '/typed.latte')),
		);
		self::assertSame(
			['path' => 'untyped.latte', 'class' => null, 'line' => 1],
			$this->collector()->processNode(new FileNode([]), $this->scope($this->dir . '/untyped.latte')),
		);
	}

	public function testUnreadableTemplateCollectsNothing(): void
	{
		self::assertNull(
			$this->collector()->processNode(new FileNode([]), $this->scope($this->dir . '/missing.latte')),
		);
	}

	public function testNonLatteFileCollectsNothing(): void
	{
		FileSystem::write($this->dir . '/Foo.php', "<?php\n");

		self::assertNull($this->collector()->processNode(new FileNode([]), $this->scope($this->dir . '/Foo.php')));
	}

	private function collector(): LatteTemplateTypeDeclarationCollector
	{
		$universe = new LatteUniverse([$this->dir], $this->dir);

		return new LatteTemplateTypeDeclarationCollector(
			TestGuard::latte(true, false, true),
			new TemplateEdgeIndex($universe, TestAdapter::accessor()),
			$universe,
		);
	}

	private function scope(string $file): Scope
	{
		$scope = $this->createStub(Scope::class);
		$scope->method('getFile')->willReturn($file);

		return $scope;
	}

}
