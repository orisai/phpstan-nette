<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Cache\LatteTemplateValueExtension;
use OriPhpstan\Nette\Latte\Cache\TemplateNeighbourhood;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Rule\LatteDependencyRule;
use PHPStan\Node\FileNode;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Toolkit\TrackingScope;
use function getmypid;
use function sys_get_temp_dir;
use function uniqid;

final class LatteDependencyRuleTest extends BaseTestCase
{

	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/latte-dependency-rule-' . getmypid() . '-' . uniqid('', true);
		FileSystem::write($this->dir . '/a.latte', "{include 'b.latte'}\n");
		FileSystem::write($this->dir . '/b.latte', "{include 'c.latte'}\n");
		FileSystem::write($this->dir . '/c.latte', "content\n");
	}

	protected function tearDown(): void
	{
		FileSystem::delete($this->dir);
	}

	/**
	 * @group latte2
	 */
	public function testATemplateTracksItsNeighbourhoodTheGlobalsAndEveryIncludersSlice(): void
	{
		[$values, $files] = $this->track(true, $this->dir . '/c.latte');

		self::assertSame(
			[
				LatteTemplateValueExtension::HARVEST,
				LatteTemplateValueExtension::TEMPLATE_FACTORY,
				LatteTemplateValueExtension::neighbourhoodKey('c.latte'),
			],
			$values,
		);
		self::assertSame(
			[$this->store()->slicePath('a.latte'), $this->store()->slicePath('b.latte')],
			$files,
			'the slices of every transitive includer feed the contexts, the target\'s own never does',
		);
	}

	public function testNarrowingOffTracksNoSlice(): void
	{
		[$values, $files] = $this->track(false, $this->dir . '/c.latte');

		self::assertCount(3, $values);
		self::assertSame([], $files);
	}

	public function testAPhpFileTracksNothing(): void
	{
		self::assertSame([[], []], $this->track(true, $this->dir . '/Presenter.php'));
	}

	/**
	 * @return array{list<string>, list<string>}
	 */
	private function track(bool $narrowing, string $file): array
	{
		$universe = new LatteUniverse([$this->dir], $this->dir);
		$rule = new LatteDependencyRule(
			TestGuard::latte(true, $narrowing),
			$universe,
			new TemplateNeighbourhood(new TemplateEdgeIndex($universe, TestAdapter::accessor()), $universe),
			$this->store(),
		);

		$values = [];
		$files = [];
		$scope = $this->createMock(TrackingScope::class);
		$scope->method('getFile')->willReturn($file);
		$scope->method('trackValueDependency')->willReturnCallback(
			static function (string $extensionClass, string $key) use (&$values): void {
				self::assertSame(LatteTemplateValueExtension::class, $extensionClass);
				$values[] = $key;
			},
		);
		$scope->method('trackFileDependency')->willReturnCallback(
			static function (string $tracked) use (&$files): void {
				$files[] = $tracked;
			},
		);

		self::assertSame([], $rule->processNode(new FileNode([]), $scope));

		return [$values, $files];
	}

	private function store(): SiteScopeStore
	{
		return new SiteScopeStore($this->dir . '/store');
	}

}
