<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteResultCacheMeta;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
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
use function fclose;
use function fopen;
use function getmypid;
use function is_array;
use function rewind;
use function stream_get_contents;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;

final class LatteResultCacheMetaTest extends BaseTestCase
{

	private const ENGINE_LOADER = __DIR__ . '/../Customs/Fixtures/engine-loader.php';

	private const UNWIRED_CONTAINER_LOADER = __DIR__ . '/Fixtures/factory-vars-container-loader.php';

	private const WIRED_CONTAINER_LOADER = __DIR__ . '/Fixtures/factory-vars-wired-container-loader.php';

	public function testKeyIsStable(): void
	{
		self::assertSame('orisaiNette.latte.edgeTopology', $this->metaFor([], false)->getKey());
	}

	public function testDisabledFlagYieldsConstantHash(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/a.latte', "{include 'b.latte'}\n");
			FileSystem::write($dir . '/b.latte', "content\n");

			self::assertSame('disabled', $this->metaFor([$dir], false)->getHash());
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	public function testTopologyChangeChangesHash(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/a.latte', "{include 'b.latte'}\n");
			FileSystem::write($dir . '/b.latte', "content\n");
			FileSystem::write($dir . '/c.latte', "content\n");

			$before = $this->metaFor([$dir], true)->getHash();

			// A brand-new site: a.latte now ALSO includes c.latte - a genuinely new edge, not an
			// edit to an existing one.
			FileSystem::write($dir . '/a.latte', "{include 'b.latte'}\n{include 'c.latte'}\n");

			$after = $this->metaFor([$dir], true)->getHash();

			self::assertNotSame($before, $after);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	public function testContentOnlyChangeKeepsHashStable(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/a.latte', "{include 'b.latte', w => 1}\n");
			FileSystem::write($dir . '/b.latte', "{\$w}\n");

			$before = $this->metaFor([$dir], true)->getHash();

			// Body-only edit: same edge (a.latte -> b.latte still exists), only the argument value
			// and b.latte's own content change - no topology change.
			FileSystem::write($dir . '/a.latte', "{include 'b.latte', w => 2}\n");
			FileSystem::write($dir . '/b.latte', "{\$w} edited\n");

			$after = $this->metaFor([$dir], true)->getHash();

			self::assertSame($before, $after);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	// The harvest salt: a real harvest changes the meta hash even with the edge topology
	// held fixed, while "no harvester wired at all" and "a harvester with nothing configured" must
	// be BYTE-IDENTICAL - both degrade to HarvestedCustoms::empty(), mirroring
	// SiteScopeStore::emptySliceHash()'s own store-absent-vs-present-but-empty discipline, so a
	// spawn that never wires orisaiNette.dic.containerLoader/orisaiNette.latte.engineLoader never sees its cache churn.
	public function testHarvestChangeChangesTheHashWithTopologyHeldConstant(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/a.latte', "content\n");

			$noHarvesterHash = $this->metaFor([$dir], true)->getHash();
			$unconfiguredHash = $this->metaFor([$dir], true, $this->harvester(null))->getHash();
			$populatedHash = $this->metaFor([$dir], true, $this->harvester(self::ENGINE_LOADER))->getHash();

			self::assertSame(
				$noHarvesterHash,
				$unconfiguredHash,
				'no harvester wired vs a harvester with nothing configured must be STABLE',
			);
			self::assertNotSame($unconfiguredHash, $populatedHash, 'a real harvest must change the meta hash');
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDisabledFlagStaysConstantRegardlessOfTheHarvest(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/a.latte', "content\n");

			self::assertSame(
				'disabled',
				$this->metaFor([$dir], false, $this->harvester(self::ENGINE_LOADER))->getHash(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	// The discovery salt: which templates HAVE a store file is the meta input - a template whose
	// cached parse predates its store file's existence never baked in the self-ref, so the file
	// set changing is exactly the window only a whole-cache invalidation can close. Record CONTENT
	// deliberately stays out: record changes propagate granularly through the per-template store
	// file's own bytes (RECORDS_HASH), never a whole-cache invalidation.
	public function testDiscoveryTemplateSetChangeChangesTheHash(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/a.latte', "content\n");
			$storeDir = $dir . '/discovery';
			DiscoveryStore::bootstrap($storeDir, ['a.latte']);

			$before = $this->metaFor([$dir], true, null, new DiscoveryStore($storeDir), true, $storeDir)->getHash();

			DiscoveryStore::bootstrap($storeDir, ['b.latte']);

			$after = $this->metaFor([$dir], true, null, new DiscoveryStore($storeDir), true, $storeDir)->getHash();

			self::assertNotSame($before, $after);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	// THE LIVE-CONTAINER SALT. FactoryProvidedVars decides whether a factory-provided template
	// variable is DEFINITELY present by reading the compiled container's wired TemplateFactory
	// dependencies - an input no analysed file's hash reflects, no config parameter carries (so
	// PHPStan's own projectConfig meta cannot see it) and no discovery record moves with. This
	// whole-cache salt is therefore its only invalidation channel, and the three verdicts it must
	// keep apart are "no container to ask", "a container wiring neither" and "a container wiring
	// both" - collapsing any two would serve a stale nullability answer on every warm run.
	public function testWiredContainerChangesTheHashAndTheThreeVerdictsStayDistinct(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/a.latte', "content\n");

			$hashes = [];
			foreach ([null, self::UNWIRED_CONTAINER_LOADER, self::WIRED_CONTAINER_LOADER] as $loaderFile) {
				$hashes[] = $this->metaFor(
					[$dir],
					true,
					null,
					null,
					false,
					'',
					false,
					new TemplateFactoryDefaultResolver($loaderFile),
				)->getHash();
			}

			self::assertSame($hashes, array_unique($hashes), 'the three container verdicts must not share a hash');
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	// No resolver wired at all must stay byte-identical to a resolver with no loader file, the same
	// absent-vs-unconfigured discipline the harvest salt above already holds to: a spawn that never
	// configures orisaiNette.latte.templateFactoryContainerLoader must never see its cache churn.
	public function testAbsentResolverAndUnconfiguredResolverAreByteIdentical(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/a.latte', "content\n");

			self::assertSame(
				$this->metaFor([$dir], true)->getHash(),
				$this->metaFor([$dir], true, null, null, false, '', false, new TemplateFactoryDefaultResolver(null))
					->getHash(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	public function testDiscoveryRecordContentChangeKeepsTheHashStable(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/a.latte', "content\n");
			$storeDir = $dir . '/discovery';
			DiscoveryStore::bootstrap($storeDir, ['a.latte']);

			$before = $this->metaFor([$dir], true, null, new DiscoveryStore($storeDir), true, $storeDir)->getHash();

			$this->linkRecord($storeDir);

			$after = $this->metaFor([$dir], true, null, new DiscoveryStore($storeDir), true, $storeDir)->getHash();

			self::assertSame($before, $after);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	// The other regime: with the store directory outside the analysed paths there is no per-template
	// propagation channel at all (PHPStan tracks no dependency on a file it never analysed), so
	// record CONTENT has to move the whole-cache salt or the per-file consumers serve a verdict
	// computed from records that have since moved.
	public function testDiscoveryRecordContentChangeChangesTheHashWhenTheStoreIsNotAnalysed(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/analysed/a.latte', "content\n");
			$storeDir = $dir . '/outside';
			DiscoveryStore::bootstrap($storeDir, ['a.latte']);

			$analysed = [$dir . '/analysed'];
			$before = $this->metaFor($analysed, true, null, new DiscoveryStore($storeDir), true, $storeDir)->getHash();

			$this->linkRecord($storeDir);

			$after = $this->metaFor($analysed, true, null, new DiscoveryStore($storeDir), true, $storeDir)->getHash();

			self::assertNotSame($before, $after);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	// Boundary-anchored containment: a sibling directory sharing a prefix with an analysed path is
	// NOT inside it, so it must fall into the content regime rather than silently claim the
	// granular one.
	public function testStoreInAPrefixSharingSiblingDirectoryIsNotTreatedAsAnalysed(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/app/a.latte', "content\n");
			$storeDir = $dir . '/appfoo/discovery';
			DiscoveryStore::bootstrap($storeDir, ['a.latte']);

			$analysed = [$dir . '/app'];
			$before = $this->metaFor($analysed, true, null, new DiscoveryStore($storeDir), true, $storeDir)->getHash();

			$this->linkRecord($storeDir);

			$after = $this->metaFor($analysed, true, null, new DiscoveryStore($storeDir), true, $storeDir)->getHash();

			self::assertNotSame($before, $after);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	// The store directory named as an analysed path itself is inside it - the trailing-separator
	// anchoring must not exclude the equal case.
	public function testStoreDirectoryThatIsItselfAnAnalysedPathKeepsTheGranularRegime(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/a.latte', "content\n");
			$storeDir = $dir . '/discovery';
			DiscoveryStore::bootstrap($storeDir, ['a.latte']);

			$analysed = [$dir, $storeDir];
			$before = $this->metaFor($analysed, true, null, new DiscoveryStore($storeDir), true, $storeDir)->getHash();

			$this->linkRecord($storeDir);

			$after = $this->metaFor($analysed, true, null, new DiscoveryStore($storeDir), true, $storeDir)->getHash();

			self::assertSame($before, $after);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The one coarse regime no layout can escape: with %paths% undeclared (PHPStan's own default -
	// the analysed set arrives on the command line) containment is structurally impossible, so the
	// whole-store salt is forced for good and the parameter's "keep it inside %paths%" advice cannot
	// be followed. Correct, but a permanent result-cache cliff, so it is announced - once, however
	// many times PHPStan asks for the meta hash in a run.
	public function testUndeclaredPathsAnnounceTheUnreachableGranularRegimeOnce(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$storeDir = $dir . '/discovery';
			DiscoveryStore::bootstrap($storeDir, ['a.latte']);

			$stream = fopen('php://memory', 'w+');
			self::assertNotFalse($stream);

			$meta = $this->metaFor(
				[],
				true,
				null,
				new DiscoveryStore($storeDir),
				true,
				$storeDir,
				false,
				null,
				$stream,
			);
			$meta->getHash();
			$meta->getHash();

			$notice = $this->readStream($stream);

			self::assertSame(1, substr_count($notice, 'Note: no paths are declared'));
			self::assertStringContainsString('orisaiNette.latte.discovery.coarseInvalidationAccepted', $notice);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testAcceptingTheCoarseRegimeSilencesTheNotice(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			$storeDir = $dir . '/discovery';
			DiscoveryStore::bootstrap($storeDir, ['a.latte']);

			$stream = fopen('php://memory', 'w+');
			self::assertNotFalse($stream);

			$this->metaFor(
				[],
				true,
				null,
				new DiscoveryStore($storeDir),
				true,
				$storeDir,
				true,
				null,
				$stream,
			)->getHash();

			self::assertSame('', $this->readStream($stream));
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	// A coarse regime the consumer CAN escape by moving the store says nothing: that one is the
	// parameter documentation's job, and a per-run notice about a fixable layout would be noise.
	public function testTheCoarseRegimeUnderDeclaredPathsAnnouncesNothing(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/analysed/a.latte', "content\n");
			$storeDir = $dir . '/outside';
			DiscoveryStore::bootstrap($storeDir, ['a.latte']);

			$stream = fopen('php://memory', 'w+');
			self::assertNotFalse($stream);

			$analysed = [$dir . '/analysed'];
			$this->metaFor(
				$analysed,
				true,
				null,
				new DiscoveryStore($storeDir),
				true,
				$storeDir,
				false,
				null,
				$stream,
			)->getHash();

			self::assertSame('', $this->readStream($stream));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// This extension ships NO salt for its own latte* parameters, and the only reason that is sound
	// is third-party: PHPStan's result-cache meta already carries the whole merged projectConfig
	// array (ResultCacheManager::getMeta()), minus every path listed in
	// %parametersNotInvalidatingCache%. A release that started excluding a latte* parameter would
	// silently serve verdicts computed from a configuration that has since moved, so the invariant is
	// pinned here instead of discovered as staleness. The parameter list is read from extension.neon's
	// own parametersSchema, so a parameter added later is covered without touching this test.
	public function testPhpstanKeepsEveryLatteParameterInsideItsOwnResultCacheMeta(): void
	{
		$latteParameters = array_keys($this->wiringParametersSchema());
		$projectConfig = ['parameters' => array_fill_keys($latteParameters, 'value')];

		foreach ($this->phpstanParametersNotInvalidatingCache() as $parameterPath) {
			$this->unsetKeyAtPath(
				$projectConfig,
				is_array($parameterPath) ? $parameterPath : explode('.', $parameterPath),
			);
		}

		self::assertSame($latteParameters, array_keys($projectConfig['parameters'] ?? []));
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
		// The phar root off the interface this extension implements - never a hardcoded vendor path.
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
		// PHPStan\Internal\ArrayHelper::unsetKeyAtPath verbatim - mirrored rather than called so this
		// test does not reach into an @internal phar class.
		[$head, $tail] = [$path[0], array_slice($path, 1)];
		if (count($tail) === 0) {
			unset($array[$head]);
		} elseif (isset($array[$head])) {
			$this->unsetKeyAtPath($array[$head], $tail);
		}
	}

	/**
	 * @param resource $stream
	 */
	private function readStream($stream): string
	{
		rewind($stream);
		$contents = stream_get_contents($stream);
		self::assertNotFalse($contents);
		fclose($stream);

		return $contents;
	}

	private function linkRecord(string $storeDir): void
	{
		(new DiscoveryStore($storeDir))->replaceWith(
			['a.latte' => [['class' => 'App\\Foo', 'view' => null, 'kind' => 'setFile', 'certainty' => 'unknown']]],
			['App\\Foo'],
			[],
		);
	}

	/**
	 * @group latte2
	 */
	public function testDiscoveryDisabledNeverTouchesTheStoreAndStaysConstant(): void
	{
		$dir = $this->scratchDir();
		FileSystem::createDir($dir);

		try {
			FileSystem::write($dir . '/a.latte', "content\n");
			$populatedStoreDir = $dir . '/discovery';
			DiscoveryStore::bootstrap($populatedStoreDir, ['a.latte']);

			// The absent-store instance points at a directory that never exists: identical hashes
			// prove the disabled flag short-circuits before any store read.
			$absent = $this->metaFor(
				[$dir],
				true,
				null,
				new DiscoveryStore($dir . '/never-created'),
				false,
			)->getHash();
			$populated = $this->metaFor(
				[$dir],
				true,
				null,
				new DiscoveryStore($populatedStoreDir),
				false,
			)->getHash();

			self::assertSame($absent, $populated);
		} finally {
			FileSystem::delete($dir);
		}
	}

	private function harvester(?string $latteEngineLoaderFile): CustomsHarvester
	{
		return TestAdapter::harvester(new EngineSource(null, $latteEngineLoaderFile));
	}

	private function scratchDir(): string
	{
		return sys_get_temp_dir() . '/latte-resultcachemeta-test-' . getmypid() . '-' . uniqid('', true);
	}

	/**
	 * @param list<string> $paths
	 * @param resource|null $noticeStream
	 */
	private function metaFor(
		array $paths,
		bool $enabled,
		?CustomsHarvester $harvester = null,
		?DiscoveryStore $discoveryStore = null,
		bool $discoveryStoreEnabled = false,
		string $discoveryStoreDirPath = '',
		bool $coarseInvalidationAccepted = false,
		?TemplateFactoryDefaultResolver $templateFactoryDefault = null,
		$noticeStream = null
	): LatteResultCacheMeta
	{
		// A FRESH LatteUniverse/TemplateEdgeIndex per call - not a shared/cached instance - so this
		// proves the contract from disk, not merely from a memoized in-process value.
		$universe = new LatteUniverse($paths, sys_get_temp_dir());
		$index = new TemplateEdgeIndex($universe, new TemplateFactExtractor());

		return new LatteResultCacheMeta(
			TestGuard::latte($enabled, false, $enabled && $discoveryStoreEnabled),
			$index,
			$paths,
			$discoveryStoreDirPath,
			$harvester,
			$discoveryStore,
			$coarseInvalidationAccepted,
			$templateFactoryDefault,
			$noticeStream,
		);
	}

}
