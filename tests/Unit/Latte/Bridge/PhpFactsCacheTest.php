<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\AssignmentFact;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryFact;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\TemplateDirectoryListing;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function getmypid;
use function glob;
use function sha1_file;
use function sys_get_temp_dir;
use function uniqid;

final class PhpFactsCacheTest extends BaseTestCase
{

	private const FactoryDefaultContainerLoaderFile = __DIR__ . '/Fixtures/factory-default-container-loader.php';

	private const PresenterMappingContainerLoaderFile = __DIR__ . '/Fixtures/presenter-mapping-container-loader.php';

	private string $workDir;

	protected function setUp(): void
	{
		$this->workDir = sys_get_temp_dir() . '/php-facts-cache-test-' . getmypid() . '-' . uniqid('', true);
		FileSystem::createDir($this->workDir);
	}

	protected function tearDown(): void
	{
		FileSystem::delete($this->workDir);
	}

	public function testRememberComputesOnceAndReloadsFromCache(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-a-v1');
		$ancestorFile = $this->writeFile('AncestorFile.php', 'ancestor-v1');

		$cache = $this->cache();
		$calls = 0;
		$compute = static function () use (&$calls, $classFile, $ancestorFile): PhpRenderFacts {
			$calls++;

			return new PhpRenderFacts(
				['tpl' => new AssignmentFact('string', Certainty::HAPPENS, [['file' => $classFile, 'line' => 1]])],
				[],
				null,
				[],
				[$classFile, $ancestorFile],
			);
		};

		$first = $cache->remember('App\\FooPresenter', $compute);
		$second = $cache->remember('App\\FooPresenter', $compute);

		self::assertSame(1, $calls);
		self::assertSame($first->getCanonicalHash(), $second->getCanonicalHash());

		$reopened = $this->cache();
		$third = $reopened->remember('App\\FooPresenter', $compute);
		self::assertSame(1, $calls);
		self::assertSame($first->getCanonicalHash(), $third->getCanonicalHash());
	}

	public function testRememberRecomputesWhenReadSetMemberContentChanges(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-b-v1');
		$ancestorFile = $this->writeFile('AncestorFile.php', 'ancestor-v1');

		$cache = $this->cache();
		$firstFacts = new PhpRenderFacts([], [], null, [], [$classFile, $ancestorFile]);
		$secondFacts = new PhpRenderFacts(
			['tpl' => new AssignmentFact('int', Certainty::MAYBE, [['file' => $ancestorFile, 'line' => 2]])],
			[],
			null,
			[],
			[$classFile, $ancestorFile],
		);

		$calls = 0;
		$first = $cache->remember('App\\BarPresenter', static function () use (&$calls, $firstFacts): PhpRenderFacts {
			$calls++;

			return $firstFacts;
		});
		self::assertSame(1, $calls);
		self::assertSame($firstFacts->getCanonicalHash(), $first->getCanonicalHash());

		// Untouched read-set: still cached.
		$cache->remember('App\\BarPresenter', static function () use (&$calls, $firstFacts): PhpRenderFacts {
			$calls++;

			return $firstFacts;
		});
		self::assertSame(1, $calls);

		// Touch one read-set member's content, then reopen the cache (a fresh instance, mirroring a
		// separate PHPStan run): in-process memoization only holds for the lifetime of one instance,
		// so this is where disk-level staleness detection is expected to recompute.
		FileSystem::write($ancestorFile, 'ancestor-v2');

		$reopened = $this->cache();
		$third = $reopened->remember(
			'App\\BarPresenter',
			static function () use (&$calls, $secondFacts): PhpRenderFacts {
				$calls++;

				return $secondFacts;
			},
		);
		self::assertSame(2, $calls);
		self::assertSame($secondFacts->getCanonicalHash(), $third->getCanonicalHash());
	}

	public function testRememberMemoizesInProcessEvenAfterReadSetMemberChangesOnDisk(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-c-v1');
		$ancestorFile = $this->writeFile('AncestorFile.php', 'ancestor-v1');

		$cache = $this->cache();
		$facts = new PhpRenderFacts([], [], null, [], [$classFile, $ancestorFile]);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$first = $cache->remember('App\\BazPresenter', $compute);
		self::assertSame(1, $calls);

		// A disk-level lookup would now recompute (the read-set member's content changed), but the
		// in-process memoization must short-circuit before ever re-consulting the cache/read-set.
		FileSystem::write($ancestorFile, 'ancestor-v2');

		$second = $cache->remember('App\\BazPresenter', $compute);
		self::assertSame(1, $calls);
		self::assertSame($first->getCanonicalHash(), $second->getCanonicalHash());
	}

	public function testRememberTreatsWriteTimeUnreadableReadSetMemberAsPerpetuallyStale(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-d-v1');
		$missingFile = $this->workDir . '/Missing.php';

		$cache = $this->cache();
		$facts = new PhpRenderFacts([], [], null, [], [$classFile, $missingFile]);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$first = $cache->remember('App\\QuxPresenter', $compute);
		self::assertSame(1, $calls);

		// $missingFile did not exist at write time, so sha1_file() failed there. A fresh cache
		// instance (bypassing the in-process memo) must still recompute now that the file is
		// readable, proving the write-time failure was never recorded as a real content hash.
		FileSystem::write($missingFile, 'now-readable');

		$reopened = $this->cache();
		$second = $reopened->remember('App\\QuxPresenter', $compute);

		self::assertSame(2, $calls);
		self::assertSame($first->getCanonicalHash(), $second->getCanonicalHash());
	}

	public function testRememberRecomputesWhenTheResolvedFactoryDefaultChanges(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-e-v1');
		$facts = new PhpRenderFacts([], [], null, [], [$classFile]);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$cache = $this->cache();
		$cache->remember('App\\CorgePresenter', $compute);
		self::assertSame(1, $calls);

		// Same resolved default: still cached.
		$reopenedSameDefault = $this->cache();
		$reopenedSameDefault->remember('App\\CorgePresenter', $compute);
		self::assertSame(1, $calls);

		// The container-loader seam appearing flips the resolved default (null -> fixture class)
		// while the read-set is untouched - only the envelope's resolved value can catch it.
		$reopenedChangedDefault = $this->cache(self::FactoryDefaultContainerLoaderFile);
		$reopenedChangedDefault->remember('App\\CorgePresenter', $compute);
		self::assertSame(2, $calls);
	}

	public function testRememberRecomputesWhenTheResolvedMappingChanges(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-m-v1');
		$facts = new PhpRenderFacts([], [], null, [], [$classFile]);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$cache = $this->cache();
		$cache->remember('App\\MappedPresenter', $compute);
		self::assertSame(1, $calls);

		// Same resolved mapping (none): still cached.
		$reopenedSameMapping = $this->cache();
		$reopenedSameMapping->remember('App\\MappedPresenter', $compute);
		self::assertSame(1, $calls);

		// The mapping seam appearing flips the resolved value (null -> fixture mapping) while the
		// read-set is untouched - only the envelope's resolved mapping can catch it.
		$reopenedChangedMapping = $this->cache(
			null,
			new DiscoveryResolver(self::PresenterMappingContainerLoaderFile, [], $this->workDir),
		);
		$reopenedChangedMapping->remember('App\\MappedPresenter', $compute);
		self::assertSame(2, $calls);
	}

	public function testRememberRecomputesWhenARecordedProbeFlipsFromAbsentToPresent(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-n-v1');
		$probePath = $this->workDir . '/probe-created.latte';
		$facts = $this->factsWithExistenceSet(
			$classFile,
			[['path' => $probePath, 'kind' => DiscoveryFact::PROBE_FILE, 'result' => false]],
		);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$cache = $this->cache();
		$cache->remember('App\\ProbeCreatedPresenter', $compute);
		self::assertSame(1, $calls);

		// Unchanged filesystem: still cached.
		$reopenedUnchanged = $this->cache();
		$reopenedUnchanged->remember('App\\ProbeCreatedPresenter', $compute);
		self::assertSame(1, $calls);

		// Creating a probed-absent file changes what discovery would resolve while no read-set
		// member's content changed - only the envelope's re-probed existence-set can catch it.
		FileSystem::write($probePath, 'now-existing');

		$reopenedCreated = $this->cache();
		$reopenedCreated->remember('App\\ProbeCreatedPresenter', $compute);
		self::assertSame(2, $calls);
	}

	public function testRememberRecomputesWhenARecordedProbeFlipsFromPresentToAbsent(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-o-v1');
		$probePath = $this->writeFile('probe-deleted.latte', 'chosen-template');
		$facts = $this->factsWithExistenceSet(
			$classFile,
			[['path' => $probePath, 'kind' => DiscoveryFact::PROBE_FILE, 'result' => true]],
		);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$cache = $this->cache();
		$cache->remember('App\\ProbeDeletedPresenter', $compute);
		self::assertSame(1, $calls);

		FileSystem::delete($probePath);

		$reopened = $this->cache();
		$reopened->remember('App\\ProbeDeletedPresenter', $compute);
		self::assertSame(2, $calls);
	}

	public function testRememberRecomputesWhenADirProbeIsReplacedByASameNamedFile(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-r-v1');
		$probePath = $this->workDir . '/templates';
		FileSystem::createDir($probePath);
		$facts = $this->factsWithExistenceSet(
			$classFile,
			[['path' => $probePath, 'kind' => DiscoveryFact::PROBE_DIR, 'result' => true]],
		);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$cache = $this->cache();
		$cache->remember('App\\DirSwapPresenter', $compute);
		self::assertSame(1, $calls);

		// Unchanged filesystem: still cached.
		$reopenedUnchanged = $this->cache();
		$reopenedUnchanged->remember('App\\DirSwapPresenter', $compute);
		self::assertSame(1, $calls);

		// A same-named FILE still satisfies file_exists(), so only a kind-matched is_dir() re-probe
		// can see that the formula's dir-adjustment branch flipped.
		FileSystem::delete($probePath);
		FileSystem::write($probePath, 'now-a-plain-file');

		$reopened = $this->cache();
		$reopened->remember('App\\DirSwapPresenter', $compute);
		self::assertSame(2, $calls);
	}

	public function testRememberRecomputesWhenAFileProbeIsReplacedByASameNamedDir(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-s-v1');
		$probePath = $this->writeFile('chosen.latte', 'chosen-template');
		$facts = $this->factsWithExistenceSet(
			$classFile,
			[['path' => $probePath, 'kind' => DiscoveryFact::PROBE_FILE, 'result' => true]],
		);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$cache = $this->cache();
		$cache->remember('App\\FileSwapPresenter', $compute);
		self::assertSame(1, $calls);

		// The mirror-image swap: a same-named DIRECTORY still satisfies file_exists(), only the
		// kind-matched is_file() re-probe catches it.
		FileSystem::delete($probePath);
		FileSystem::createDir($probePath);

		$reopened = $this->cache();
		$reopened->remember('App\\FileSwapPresenter', $compute);
		self::assertSame(2, $calls);
	}

	public function testRememberRecomputesWhenAnEnumeratedDirectoryGainsATemplate(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-la-v1');
		$directory = $this->workDir . '/enumerated-added';
		FileSystem::createDir($directory);
		$facts = $this->factsWithExistenceSet(
			$classFile,
			[[
				'path' => $directory,
				'kind' => DiscoveryFact::PROBE_LISTING,
				'result' => TemplateDirectoryListing::digest($directory),
			]],
		);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$cache = $this->cache();
		$cache->remember('App\\ListingAddedPresenter', $compute);
		self::assertSame(1, $calls);

		// Unchanged filesystem: still cached.
		$reopenedUnchanged = $this->cache();
		$reopenedUnchanged->remember('App\\ListingAddedPresenter', $compute);
		self::assertSame(1, $calls);

		// The C1-class hole a stat-only existence-set cannot see: the directory still is_dir()s, and
		// no candidate the resolver ever probed changed - only the LISTING did, and it is a new view.
		FileSystem::write($directory . '/appeared.latte', 'new view');

		$reopened = $this->cache();
		$reopened->remember('App\\ListingAddedPresenter', $compute);
		self::assertSame(2, $calls);
	}

	public function testRememberRecomputesWhenAnEnumeratedDirectoryLosesATemplate(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-lb-v1');
		$directory = $this->workDir . '/enumerated-removed';
		FileSystem::write($directory . '/vanishing.latte', 'a view');
		$facts = $this->factsWithExistenceSet(
			$classFile,
			[[
				'path' => $directory,
				'kind' => DiscoveryFact::PROBE_LISTING,
				'result' => TemplateDirectoryListing::digest($directory),
			]],
		);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$cache = $this->cache();
		$cache->remember('App\\ListingRemovedPresenter', $compute);
		self::assertSame(1, $calls);

		FileSystem::delete($directory . '/vanishing.latte');

		$reopened = $this->cache();
		$reopened->remember('App\\ListingRemovedPresenter', $compute);
		self::assertSame(2, $calls);
	}

	public function testRememberStaysCachedWhenAnEnumeratedDirectoryGainsANonTemplateFile(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-lc-v1');
		$directory = $this->workDir . '/enumerated-unrelated';
		FileSystem::createDir($directory);
		$facts = $this->factsWithExistenceSet(
			$classFile,
			[[
				'path' => $directory,
				'kind' => DiscoveryFact::PROBE_LISTING,
				'result' => TemplateDirectoryListing::digest($directory),
			]],
		);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$cache = $this->cache();
		$cache->remember('App\\ListingUnrelatedPresenter', $compute);
		self::assertSame(1, $calls);

		// Scoped to what the reverse operation can read: no formula ever offers a non-.latte view,
		// so an unrelated file must not invalidate every presenter sharing the directory.
		FileSystem::write($directory . '/README.md', 'not a template');

		$reopened = $this->cache();
		$reopened->remember('App\\ListingUnrelatedPresenter', $compute);
		self::assertSame(1, $calls);
	}

	public function testAnEnvelopeStoringABooleanResultForAListingProbeReadsAsStale(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-ld-v1');
		$directory = $this->workDir . '/enumerated-mistyped';
		FileSystem::createDir($directory);

		$facts = new PhpRenderFacts([], [], null, [], [$classFile]);

		// A listing probe carries a digest; a boolean under that kind is a shape no writer of this
		// version produces, so it can only be a foreign envelope - stale, never trusted.
		$analysisCache = new LatteAnalysisCache($this->cacheDir(), 'testv1');
		$analysisCache->writeContentAddressed('envelope', 'phpfacts|App\\ListingMistypedPresenter', [
			'formatVersion' => PhpFactsCache::FORMAT_VERSION,
			'facts' => $facts->toArray(),
			'readSetHashes' => [$classFile => sha1_file($classFile)],
			'factoryDefault' => null,
			'mapping' => null,
			'formulas' => [],
			'existenceSet' => [
				['path' => $directory, 'kind' => DiscoveryFact::PROBE_LISTING, 'result' => true],
			],
		]);

		$calls = 0;
		$this->cache()->remember(
			'App\\ListingMistypedPresenter',
			static function () use (&$calls, $facts): PhpRenderFacts {
				$calls++;

				return $facts;
			},
		);

		self::assertSame(1, $calls);
	}

	public function testRememberRecomputesWhenAFormulaAssignmentChanges(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-t-v1');
		$facts = new PhpRenderFacts([], [], null, [], [$classFile]);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$cache = $this->cache();
		$cache->remember('App\\AssignedPresenter', $compute);
		self::assertSame(1, $calls);

		// Same formulas value (none): still cached.
		$reopenedSameFormulas = $this->cache();
		$reopenedSameFormulas->remember('App\\AssignedPresenter', $compute);
		self::assertSame(1, $calls);

		// A config-only assignment change reflects in no read-set file - only the envelope's
		// resolved formulas value can catch it.
		$reopenedChangedFormulas = $this->cache(
			null,
			new DiscoveryResolver(null, ['App\\GridControlBase' => 'dirname-templates-lcfirst'], $this->workDir),
		);
		$reopenedChangedFormulas->remember('App\\AssignedPresenter', $compute);
		self::assertSame(2, $calls);
	}

	public function testEnvelopePredatingTheMappingFieldReadsAsStale(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-p-v1');
		$facts = new PhpRenderFacts([], [], null, [], [$classFile]);

		// Every other field is present and fresh - only the missing mapping field (which ?? would
		// conflate with a currently-null resolved mapping) can force the recompute.
		$analysisCache = new LatteAnalysisCache($this->cacheDir(), 'testv1');
		$analysisCache->writeContentAddressed('envelope', 'phpfacts|App\\UnmappedEnvelopePresenter', [
			'formatVersion' => PhpFactsCache::FORMAT_VERSION,
			'facts' => $facts->toArray(),
			'readSetHashes' => [$classFile => sha1_file($classFile)],
			'factoryDefault' => null,
			'formulas' => [],
			'existenceSet' => [],
		]);

		$calls = 0;
		$cache = $this->cache();
		$cache->remember('App\\UnmappedEnvelopePresenter', static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		});

		self::assertSame(1, $calls);
	}

	public function testEnvelopePredatingTheExistenceSetFieldReadsAsStale(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-q-v1');
		$facts = new PhpRenderFacts([], [], null, [], [$classFile]);

		$analysisCache = new LatteAnalysisCache($this->cacheDir(), 'testv1');
		$analysisCache->writeContentAddressed('envelope', 'phpfacts|App\\UnprobedEnvelopePresenter', [
			'formatVersion' => PhpFactsCache::FORMAT_VERSION,
			'facts' => $facts->toArray(),
			'readSetHashes' => [$classFile => sha1_file($classFile)],
			'factoryDefault' => null,
			'mapping' => null,
			'formulas' => [],
		]);

		$calls = 0;
		$cache = $this->cache();
		$cache->remember('App\\UnprobedEnvelopePresenter', static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		});

		self::assertSame(1, $calls);
	}

	public function testEnvelopePredatingTheFactoryDefaultFieldReadsAsStale(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-f-v1');
		$facts = new PhpRenderFacts([], [], null, [], [$classFile]);

		// formatVersion is current and the read-set hashes are fresh - only the missing
		// factoryDefault field can force the recompute.
		$analysisCache = new LatteAnalysisCache($this->cacheDir(), 'testv1');
		$analysisCache->writeContentAddressed('envelope', 'phpfacts|App\\GraultPresenter', [
			'formatVersion' => PhpFactsCache::FORMAT_VERSION,
			'facts' => $facts->toArray(),
			'readSetHashes' => [$classFile => sha1_file($classFile)],
			'mapping' => null,
			'formulas' => [],
			'existenceSet' => [],
		]);

		$calls = 0;
		$cache = $this->cache();
		$cache->remember('App\\GraultPresenter', static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		});

		self::assertSame(1, $calls);
	}

	public function testEnvelopePredatingTheFormulasFieldReadsAsStale(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-u-v1');
		$facts = new PhpRenderFacts([], [], null, [], [$classFile]);

		// Every other field is present and fresh - only the missing formulas field (which ?? would
		// conflate with a currently-empty assignment map) can force the recompute.
		$analysisCache = new LatteAnalysisCache($this->cacheDir(), 'testv1');
		$analysisCache->writeContentAddressed('envelope', 'phpfacts|App\\UnassignedEnvelopePresenter', [
			'formatVersion' => PhpFactsCache::FORMAT_VERSION,
			'facts' => $facts->toArray(),
			'readSetHashes' => [$classFile => sha1_file($classFile)],
			'factoryDefault' => null,
			'mapping' => null,
			'existenceSet' => [],
		]);

		$calls = 0;
		$cache = $this->cache();
		$cache->remember('App\\UnassignedEnvelopePresenter', static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		});

		self::assertSame(1, $calls);
	}

	public function testVersionOneShapedEnvelopeWithoutAFormatVersionFieldReadsAsStale(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-g-v1');
		$facts = new PhpRenderFacts([], [], null, [], [$classFile]);

		// factoryDefault/mapping/formulas/existenceSet present and matching, read-set hashes fresh -
		// only the missing formatVersion field can force the recompute.
		$analysisCache = new LatteAnalysisCache($this->cacheDir(), 'testv1');
		$analysisCache->writeContentAddressed('envelope', 'phpfacts|App\\GarplyPresenter', [
			'facts' => $facts->toArray(),
			'readSetHashes' => [$classFile => sha1_file($classFile)],
			'factoryDefault' => null,
			'mapping' => null,
			'formulas' => [],
			'existenceSet' => [],
		]);

		$calls = 0;
		$compute = static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		};

		$cache = $this->cache();
		$cache->remember('App\\GarplyPresenter', $compute);
		self::assertSame(1, $calls);

		// The recompute rewrote the envelope with the current FORMAT_VERSION - a fresh instance
		// must now validate it normally.
		$reopened = $this->cache();
		$reopened->remember('App\\GarplyPresenter', $compute);
		self::assertSame(1, $calls);
	}

	// Relative to the current constant on purpose: every bump (v8: kind-tagged existence-set +
	// formulas envelope field) automatically pins the immediately superseded envelope shape as
	// stale.
	public function testEnvelopeWithAMismatchedFormatVersionReadsAsStale(): void
	{
		$classFile = $this->writeFile('ClassFile.php', 'class-h-v1');
		$facts = new PhpRenderFacts([], [], null, [], [$classFile]);

		// The genuinely superseded v7 envelope: every v7 key present and fresh (its existence-set
		// was a flat path => bool map), the formulas field absent exactly as a real v7 write left
		// it - the version gate forces the recompute.
		$analysisCache = new LatteAnalysisCache($this->cacheDir(), 'testv1');
		$analysisCache->writeContentAddressed('envelope', 'phpfacts|App\\WaldoPresenter', [
			'formatVersion' => PhpFactsCache::FORMAT_VERSION - 1,
			'facts' => $facts->toArray(),
			'readSetHashes' => [$classFile => sha1_file($classFile)],
			'factoryDefault' => null,
			'mapping' => null,
			'existenceSet' => [],
		]);

		$calls = 0;
		$cache = $this->cache();
		$cache->remember('App\\WaldoPresenter', static function () use (&$calls, $facts): PhpRenderFacts {
			$calls++;

			return $facts;
		});

		self::assertSame(1, $calls);
	}

	public function testRememberNeverPersistsAnEmptyReadSet(): void
	{
		$emptyFacts = PhpRenderFacts::empty();

		$calls = 0;
		$compute = static function () use (&$calls, $emptyFacts): PhpRenderFacts {
			$calls++;

			return $emptyFacts;
		};

		$cache = $this->cache();
		$cache->remember('App\\DoesNotExistPresenter', $compute);
		self::assertSame(1, $calls);
		self::assertSame([], glob($this->cacheDir() . '/vtestv1/*.ser'));

		$reopened = $this->cache();
		$reopened->remember('App\\DoesNotExistPresenter', $compute);
		self::assertSame(2, $calls);
	}

	private function cache(
		?string $containerLoaderFile = null,
		?DiscoveryResolver $discoveryResolver = null
	): PhpFactsCache
	{
		return new PhpFactsCache(
			new LatteAnalysisCache($this->cacheDir(), 'testv1'),
			new TemplateFactoryDefaultResolver($containerLoaderFile),
			$discoveryResolver ?? new DiscoveryResolver(null, [], $this->workDir),
		);
	}

	/**
	 * @param list<array{path: string, kind: DiscoveryFact::PROBE_*, result: bool|string}> $existenceSet
	 */
	private function factsWithExistenceSet(string $classFile, array $existenceSet): PhpRenderFacts
	{
		return new PhpRenderFacts(
			[],
			[],
			null,
			[],
			[$classFile],
			[],
			[],
			[],
			false,
			new DiscoveryFact(
				['default' => [new CandidatePath('probe.latte', false, false, CandidatePath::KIND_FORMULA)]],
				[],
				[],
				$existenceSet,
			),
		);
	}

	private function writeFile(string $name, string $content): string
	{
		$path = $this->workDir . '/' . $name;
		FileSystem::write($path, $content);

		return $path;
	}

	private function cacheDir(): string
	{
		return $this->workDir . '/.cache';
	}

}
