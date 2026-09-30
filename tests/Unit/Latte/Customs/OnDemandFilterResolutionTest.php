<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Postprocess\FilterTable;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureLoaderFilters;
use function array_keys;
use function bin2hex;
use function random_bytes;
use function sys_get_temp_dir;

final class OnDemandFilterResolutionTest extends BaseTestCase
{

	private const ENGINE_LOADER = __DIR__ . '/Fixtures/engine-loader-filter-loader.php';

	private const TYPED_CUSTOMS_ENGINE_LOADER = __DIR__ . '/Fixtures/engine-loader-typed-customs.php';

	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/orisai-on-demand-filters-' . bin2hex(random_bytes(6));
		FileSystem::createDir($this->dir);
	}

	protected function tearDown(): void
	{
		FileSystem::delete($this->dir);
		parent::tearDown();
	}

	public function testLoaderFilterResolvesToItsCallable(): void
	{
		$table = $this->filterTable($this->harvest(self::ENGINE_LOADER, "{\$a|dyn}\n"));

		self::assertSame(
			[FixtureLoaderFilters::class, 'dyn', false, false, true],
			$table->resolveForTemplate('dyn', null, null, 'dyn'),
		);
		self::assertNull($table->resolveForTemplate('nope', null, null, 'nope'));
	}

	public function testLoaderIsAskedWithTheCaseTheLatteLineUses(): void
	{
		$table = $this->filterTable($this->harvest(self::ENGINE_LOADER, "{\$a|dyn}\n{\$a|dYn}\n"));

		$resolved = $table->resolveForTemplate('dyn', null, null, 'dYn');
		if ($this->isLatte2()) {
			self::assertSame([FixtureLoaderFilters::class, 'dyn', false, false, true], $resolved);
		} else {
			self::assertNull($resolved);
		}
	}

	public function testAnsweredTemplateFilterNamesJoinTheSalt(): void
	{
		$harvested = $this->harvest(self::ENGINE_LOADER, "{\$a|dyn}\n{\$a|nope}\n{\$a|upper}\n");

		self::assertNotNull($harvested->getFilterLoaders());
		self::assertSame(['dyn'], array_keys($harvested->getLoaderFilters()));
		self::assertNotSame(
			$this->harvest(self::ENGINE_LOADER, "{\$a|nope}\n")->getSaltHash(),
			$harvested->getSaltHash(),
		);
		self::assertSame(
			$this->harvest(self::ENGINE_LOADER, "{\$b|dyn}\n")->getSaltHash(),
			$harvested->getSaltHash(),
		);
	}

	public function testNoLoaderKeepsTheStaticHarvest(): void
	{
		$harvested = $this->harvest(self::TYPED_CUSTOMS_ENGINE_LOADER, "{\$a|dyn}\n");

		self::assertNull($harvested->getFilterLoaders());
		self::assertSame([], $harvested->getLoaderFilters());
		self::assertNull($this->filterTable($harvested)->resolveForTemplate('dyn', null, null, 'dyn'));
	}

	private function harvest(string $engineLoader, string $template): HarvestedCustoms
	{
		$dir = $this->dir . '/' . bin2hex(random_bytes(4));
		FileSystem::write($dir . '/template.latte', $template);

		return TestAdapter::harvester(
			new EngineSource(null, $engineLoader),
			new LatteUniverse([$dir], $dir),
		)->harvest();
	}

	private function filterTable(HarvestedCustoms $harvested): FilterTable
	{
		return new FilterTable(TestAdapter::create()->defaultCallables(), $harvested);
	}

	private function isLatte2(): bool
	{
		return TestAdapter::factory()->family()->latteLine === ShapeFamily::LATTE_2;
	}

}
