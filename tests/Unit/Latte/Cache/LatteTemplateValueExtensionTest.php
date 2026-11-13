<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Cache;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteResultCacheMeta;
use OriPhpstan\Nette\Latte\Cache\LatteTemplateValueExtension;
use OriPhpstan\Nette\Latte\Cache\TemplateNeighbourhood;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use function array_fill_keys;
use function array_keys;
use function array_slice;
use function array_unique;
use function count;
use function dirname;
use function explode;
use function getmypid;
use function is_array;
use function sys_get_temp_dir;
use function uniqid;

final class LatteTemplateValueExtensionTest extends BaseTestCase
{

	private const ENGINE_LOADER = __DIR__ . '/../Customs/Fixtures/engine-loader.php';

	private const UNWIRED_CONTAINER_LOADER = __DIR__ . '/../Includes/Fixtures/factory-vars-container-loader.php';

	private const WIRED_CONTAINER_LOADER = __DIR__ . '/../Includes/Fixtures/factory-vars-wired-container-loader.php';

	private ?string $dir = null;

	protected function tearDown(): void
	{
		if ($this->dir !== null) {
			FileSystem::delete($this->dir);
			$this->dir = null;
		}
	}

	public function testDisabledLatteAnswersAConstant(): void
	{
		$dir = $this->dir();
		FileSystem::write($dir . '/a.latte', "{include 'b.latte'}\n");
		FileSystem::write($dir . '/b.latte', "content\n");

		$extension = $this->extensionFor([$dir], false);

		self::assertSame('disabled', $extension->getValue(LatteTemplateValueExtension::neighbourhoodKey('a.latte')));
		self::assertSame('disabled', $extension->getValue(LatteTemplateValueExtension::HARVEST));
	}

	/**
	 * @group latte2
	 */
	public function testANewIncludeSiteMovesBothEndsAndLeavesUnrelatedTemplatesAlone(): void
	{
		$dir = $this->dir();
		FileSystem::write($dir . '/a.latte', "{include 'b.latte'}\n");
		FileSystem::write($dir . '/b.latte', "content\n");
		FileSystem::write($dir . '/c.latte', "content\n");
		FileSystem::write($dir . '/d.latte', "{include 'e.latte'}\n");
		FileSystem::write($dir . '/e.latte', "content\n");

		$before = $this->neighbourhoodValues([$dir], ['a.latte', 'b.latte', 'c.latte', 'd.latte', 'e.latte']);

		FileSystem::write($dir . '/a.latte', "{include 'b.latte'}\n{include 'c.latte'}\n");

		$after = $this->neighbourhoodValues([$dir], ['a.latte', 'b.latte', 'c.latte', 'd.latte', 'e.latte']);

		self::assertNotSame($before['a.latte'], $after['a.latte']);
		self::assertNotSame(
			$before['b.latte'],
			$after['b.latte'],
			'b is analysed per context from a, whose edge set grew',
		);
		self::assertNotSame($before['c.latte'], $after['c.latte'], 'c gained its first includer');
		self::assertSame($before['d.latte'], $after['d.latte'], 'an unrelated component must keep its value');
		self::assertSame($before['e.latte'], $after['e.latte'], 'an unrelated component must keep its value');
	}

	/**
	 * @group latte2
	 */
	public function testARemovedIncludeSiteSplitsTheComponent(): void
	{
		$dir = $this->dir();
		FileSystem::write($dir . '/a.latte', "{include 'b.latte'}\n{include 'c.latte'}\n");
		FileSystem::write($dir . '/b.latte', "content\n");
		FileSystem::write($dir . '/c.latte', "content\n");

		$before = $this->neighbourhoodValues([$dir], ['a.latte', 'b.latte', 'c.latte']);
		self::assertSame(['a.latte', 'b.latte', 'c.latte'], $this->neighbourhood([$dir])->members('c.latte'));

		FileSystem::write($dir . '/a.latte', "{include 'b.latte'}\n");

		$after = $this->neighbourhoodValues([$dir], ['a.latte', 'b.latte', 'c.latte']);

		self::assertNotSame($before['c.latte'], $after['c.latte']);
		self::assertNotSame($before['b.latte'], $after['b.latte']);
		self::assertSame(['c.latte'], $this->neighbourhood([$dir])->members('c.latte'));
	}

	/**
	 * @group latte2
	 */
	public function testContentOnlyEditsKeepTheNeighbourhoodValue(): void
	{
		$dir = $this->dir();
		FileSystem::write($dir . '/a.latte', "{include 'b.latte', w => 1}\n");
		FileSystem::write($dir . '/b.latte', "{\$w}\n");

		$before = $this->neighbourhoodValues([$dir], ['a.latte', 'b.latte']);

		// Same edge, other argument value and other target content: the content channels (each file's
		// own hash, the exported edge fingerprint) carry these, the topology value must not move.
		FileSystem::write($dir . '/a.latte', "{include 'b.latte', w => 2}\n");
		FileSystem::write($dir . '/b.latte', "{\$w} edited\n");

		self::assertSame($before, $this->neighbourhoodValues([$dir], ['a.latte', 'b.latte']));
	}

	/**
	 * @group latte2
	 */
	public function testADiscoveryRecordChangeMovesItsComponentOnlyInEitherStoreLayout(): void
	{
		$dir = $this->dir();
		FileSystem::write($dir . '/analysed/a.latte', "content\n");
		FileSystem::write($dir . '/analysed/b.latte', "content\n");
		$paths = [$dir . '/analysed'];

		foreach ([$dir . '/analysed/discovery', $dir . '/outside'] as $storeDir) {
			DiscoveryStore::bootstrap($storeDir, ['a.latte', 'b.latte']);
			$before = $this->neighbourhoodValues($paths, ['a.latte', 'b.latte'], $storeDir);

			(new DiscoveryStore($storeDir))->replaceWith(
				['a.latte' => [['class' => 'App\\Foo', 'view' => null, 'kind' => 'setFile', 'certainty' => 'unknown']]],
				['App\\Foo'],
				[],
			);

			$after = $this->neighbourhoodValues($paths, ['a.latte', 'b.latte'], $storeDir);
			self::assertNotSame($before['a.latte'], $after['a.latte'], 'a record of a.latte moved: ' . $storeDir);
			self::assertSame(
				$before['b.latte'],
				$after['b.latte'],
				'b.latte shares no edge with a.latte: ' . $storeDir,
			);
		}
	}

	/**
	 * @group latte2
	 */
	public function testDisabledDiscoveryNeverReadsTheStore(): void
	{
		$dir = $this->dir();
		FileSystem::write($dir . '/a.latte', "content\n");
		$storeDir = $dir . '/discovery';
		DiscoveryStore::bootstrap($storeDir, ['a.latte']);
		(new DiscoveryStore($storeDir))->replaceWith(
			['a.latte' => [['class' => 'App\\Foo', 'view' => null, 'kind' => 'setFile', 'certainty' => 'unknown']]],
			['App\\Foo'],
			[],
		);

		$universe = new LatteUniverse([$dir], $dir);
		$index = new TemplateEdgeIndex($universe, TestAdapter::accessor());

		self::assertSame(
			(new TemplateNeighbourhood($index, $universe, new DiscoveryStore($dir . '/never-created'), false))->digest(
				'a.latte',
			),
			(new TemplateNeighbourhood($index, $universe, new DiscoveryStore($storeDir), false))->digest('a.latte'),
		);
	}

	/**
	 * @group latte2
	 */
	public function testAHarvestMovesTheHarvestValueAndAnUnconfiguredOneDoesNot(): void
	{
		$dir = $this->dir();
		FileSystem::write($dir . '/a.latte', "content\n");

		$none = $this->extensionFor([$dir], true)->getValue(LatteTemplateValueExtension::HARVEST);
		$unconfigured = $this->extensionFor([$dir], true, $this->harvester(null))->getValue(
			LatteTemplateValueExtension::HARVEST,
		);
		$populated = $this->extensionFor([$dir], true, $this->harvester(self::ENGINE_LOADER))
			->getValue(LatteTemplateValueExtension::HARVEST);

		self::assertSame($none, $unconfigured, 'no harvester wired vs a harvester with nothing configured must agree');
		self::assertNotSame($unconfigured, $populated);
	}

	/**
	 * @group latte2
	 */
	public function testTheThreeTemplateFactoryVerdictsStayDistinct(): void
	{
		$dir = $this->dir();
		FileSystem::write($dir . '/a.latte', "content\n");

		$values = [];
		foreach ([null, self::UNWIRED_CONTAINER_LOADER, self::WIRED_CONTAINER_LOADER] as $loaderFile) {
			$values[] = $this->extensionFor([$dir], true, null, null, new TemplateFactoryDefaultResolver($loaderFile))
				->getValue(LatteTemplateValueExtension::TEMPLATE_FACTORY);
		}

		self::assertSame(
			$values,
			array_unique($values),
			'"no container", "wires neither" and "wires both" must differ',
		);
		self::assertSame(
			$this->extensionFor([$dir], true)->getValue(LatteTemplateValueExtension::TEMPLATE_FACTORY),
			$values[0],
			'no resolver wired vs a resolver without a loader file must agree',
		);
	}

	public function testThePreAnalysisSeamNeverMovesItsHash(): void
	{
		$meta = new LatteResultCacheMeta(TestGuard::latte(true, false, true));

		self::assertSame(LatteResultCacheMeta::KEY, $meta->getKey());
		self::assertSame(LatteResultCacheMeta::HASH, $meta->getHash());
		self::assertSame(LatteResultCacheMeta::HASH, (new LatteResultCacheMeta(TestGuard::latte(false)))->getHash());
	}

	// PHPStan's own result-cache meta carries the whole project config array minus
	// %parametersNotInvalidatingCache%, which is what still invalidates on an orisai.nette.latte.* change. A
	// release that started excluding one would silently serve verdicts computed under a configuration
	// that has since moved, so the invariant is pinned rather than discovered as staleness.
	public function testPhpstanKeepsEveryOrisaiParameterInsideItsOwnResultCacheMeta(): void
	{
		$parameters = array_keys($this->wiringParametersSchema());
		$projectConfig = ['parameters' => array_fill_keys($parameters, 'value')];

		foreach ($this->phpstanParametersNotInvalidatingCache() as $parameterPath) {
			$this->unsetKeyAtPath(
				$projectConfig,
				is_array($parameterPath) ? $parameterPath : explode('.', $parameterPath),
			);
		}

		self::assertSame($parameters, array_keys($projectConfig['parameters'] ?? []));
	}

	/**
	 * @param list<string> $paths
	 * @param list<string> $templates
	 * @return array<string, string>
	 */
	private function neighbourhoodValues(array $paths, array $templates, ?string $storeDir = null): array
	{
		// A fresh universe, edge index and store per call: the contract holds from disk, not from a
		// memoized in-process value.
		$extension = $this->extensionFor(
			$paths,
			true,
			null,
			$storeDir === null ? null : new DiscoveryStore($storeDir),
		);

		$values = [];
		foreach ($templates as $template) {
			$values[$template] = $extension->getValue(LatteTemplateValueExtension::neighbourhoodKey($template));
		}

		return $values;
	}

	/**
	 * @param list<string> $paths
	 */
	private function neighbourhood(array $paths, ?DiscoveryStore $store = null): TemplateNeighbourhood
	{
		$universe = new LatteUniverse($paths, $paths[0]);

		return new TemplateNeighbourhood(
			new TemplateEdgeIndex($universe, TestAdapter::accessor(), null, $store, $store !== null),
			$universe,
			$store,
			$store !== null,
		);
	}

	/**
	 * @param list<string> $paths
	 */
	private function extensionFor(
		array $paths,
		bool $enabled,
		?CustomsHarvester $harvester = null,
		?DiscoveryStore $store = null,
		?TemplateFactoryDefaultResolver $templateFactoryDefault = null
	): LatteTemplateValueExtension
	{
		return new LatteTemplateValueExtension(
			TestGuard::latte($enabled, false, $enabled && $store !== null),
			$this->neighbourhood($paths, $store),
			$harvester,
			$templateFactoryDefault,
		);
	}

	private function harvester(?string $latteEngineLoaderFile): CustomsHarvester
	{
		return TestAdapter::harvester(new EngineSource(null, $latteEngineLoaderFile));
	}

	private function dir(): string
	{
		$this->dir = sys_get_temp_dir() . '/latte-template-value-test-' . getmypid() . '-' . uniqid('', true);
		FileSystem::createDir($this->dir);

		return $this->dir;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function wiringParametersSchema(): array
	{
		$wiring = Neon::decode(FileSystem::read(dirname(__DIR__, 4) . '/extension.neon'));
		self::assertIsArray($wiring);
		$schema = $wiring['parametersSchema'];
		self::assertIsArray($schema);

		return $schema;
	}

	/**
	 * @return array<int, string|array<int, string>>
	 */
	private function phpstanParametersNotInvalidatingCache(): array
	{
		// The phar root off an interface it ships, never a hardcoded vendor path.
		// @phpstan-ignore classConstant.deprecatedInterface
		$fileName = (new ReflectionClass(ResultCacheMetaExtension::class))->getFileName();
		self::assertNotFalse($fileName);

		$config = Neon::decode(FileSystem::read(dirname($fileName, 4) . '/conf/config.neon'));
		self::assertIsArray($config);
		self::assertIsArray($config['parameters']);
		$paths = $config['parameters']['parametersNotInvalidatingCache'];
		self::assertIsArray($paths);

		return $paths;
	}

	/**
	 * @param array<string, mixed> $array
	 * @param array<int, string> $path
	 */
	private function unsetKeyAtPath(array &$array, array $path): void
	{
		// PHPStan\Internal\ArrayHelper::unsetKeyAtPath, mirrored rather than called: it is @internal.
		[$head, $tail] = [$path[0], array_slice($path, 1)];
		if (count($tail) === 0) {
			unset($array[$head]);
		} elseif (isset($array[$head])) {
			$this->unsetKeyAtPath($array[$head], $tail);
		}
	}

}
