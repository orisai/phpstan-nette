<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\DeadCode;

use LogicException;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Dic\DeadCode\ContainerUsageExtractor;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaOnlyService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaVariant;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\BarService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\ChildService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\DuplicateService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\ImportedService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\NotAutowiredService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\NumericNameService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\ParentService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\SetupService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\StaticConfigurator;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\TaggedService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\Widget;
use function tempnam;

final class ContainerUsageExtractorTest extends BaseTestCase
{

	public function testExtractsUsagesFromFixtureContainer(): void
	{
		$usages = (new ContainerUsageExtractor())->extractFromFile($this->alphaContainerFile());

		self::assertArrayHasKey('__construct', $usages[FooService::class]);
		self::assertArrayHasKey('__construct', $usages[BarService::class]);
		self::assertArrayHasKey('__construct', $usages[NumericNameService::class]);
		self::assertArrayHasKey('__construct', $usages[TaggedService::class]);
		self::assertArrayHasKey('__construct', $usages[DuplicateService::class]);
		self::assertArrayHasKey('__construct', $usages[NotAutowiredService::class]);
		self::assertArrayHasKey('__construct', $usages[SetupService::class]);
		self::assertArrayHasKey('__construct', $usages[AlphaVariant::class]);
		self::assertArrayHasKey('__construct', $usages[AlphaOnlyService::class]);
		self::assertArrayHasKey('__construct', $usages[Widget::class]);

		self::assertArrayHasKey('setdependency', $usages[SetupService::class]);
		self::assertArrayHasKey('configure', $usages[StaticConfigurator::class]);

		self::assertArrayHasKey('__construct', $usages[ChildService::class]);
		self::assertArrayHasKey('configureparent', $usages[ChildService::class]);
		self::assertArrayNotHasKey(ParentService::class, $usages);

		self::assertArrayNotHasKey(ImportedService::class, $usages);
		self::assertArrayNotHasKey(Container::class, $usages);
	}

	public function testUnparseableFileThrows(): void
	{
		[$base, $file] = $this->writeTempContainerFile('<?php class {');
		$this->expectException(LogicException::class);

		try {
			(new ContainerUsageExtractor())->extractFromFile($file);
		} finally {
			$this->deleteTempContainerFile($base, $file);
		}
	}

	public function testUnreadableFileThrows(): void
	{
		$this->expectException(LogicException::class);
		(new ContainerUsageExtractor())->extractFromFile('/nonexistent/path/to/container.php');
	}

	public function testAnonymousClassMidMethodDoesNotWipeOuterScope(): void
	{
		$code = <<<'PHP'
<?php
class ScopeStackContainer
{
	public function createServiceFoo()
	{
		$service = new Foo();
		$x = new class {
			public function m()
			{
			}
		};
		$service->setBar();
	}
}
PHP;

		[$base, $file] = $this->writeTempContainerFile($code);

		try {
			$usages = (new ContainerUsageExtractor())->extractFromFile($file);
		} finally {
			$this->deleteTempContainerFile($base, $file);
		}

		self::assertArrayHasKey('setbar', $usages['Foo']);
	}

	public function testClosureParamShadowsOuterBindingWithoutLeaking(): void
	{
		$code = <<<'PHP'
<?php
class ClosureShadowContainer
{
	public function createServiceFoo()
	{
		$service = new Foo();
		array_map(function ($service) {
			$service->other();
		}, []);
		$service->setBar();
	}
}
PHP;

		[$base, $file] = $this->writeTempContainerFile($code);

		try {
			$usages = (new ContainerUsageExtractor())->extractFromFile($file);
		} finally {
			$this->deleteTempContainerFile($base, $file);
		}

		self::assertArrayHasKey('setbar', $usages['Foo']);
		self::assertArrayNotHasKey('other', $usages['Foo']);
	}

	public function testClosureUseCapturesOuterBinding(): void
	{
		$code = <<<'PHP'
<?php
class ClosureUseContainer
{
	public function createServiceFoo()
	{
		$service = new Foo();
		$fn = function () use ($service) {
			$service->viaUse();
		};
	}
}
PHP;

		[$base, $file] = $this->writeTempContainerFile($code);

		try {
			$usages = (new ContainerUsageExtractor())->extractFromFile($file);
		} finally {
			$this->deleteTempContainerFile($base, $file);
		}

		self::assertArrayHasKey('viause', $usages['Foo']);
	}

	private function alphaContainerFile(): string
	{
		/** @var array<string, Container> $containers */
		$containers = require __DIR__ . '/../Fixtures/fixture-container-loader.php';

		$file = (new ReflectionClass($containers['alpha']))->getFileName();

		if ($file === false) {
			throw new LogicException('Fixture container "alpha" has no file.');
		}

		return $file;
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function writeTempContainerFile(string $code): array
	{
		$dir = __DIR__ . '/../../../../var/tools/PHPUnit.DicFixtures';
		FileSystem::createDir($dir);

		$base = tempnam($dir, 'dic');

		if ($base === false) {
			throw new LogicException('Cannot create temp container fixture file.');
		}

		$file = $base . '.php';
		FileSystem::write($file, $code);

		return [$base, $file];
	}

	private function deleteTempContainerFile(string $base, string $file): void
	{
		FileSystem::delete($file);
		FileSystem::delete($base);
	}

}
