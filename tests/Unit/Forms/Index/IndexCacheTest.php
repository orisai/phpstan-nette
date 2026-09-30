<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use OriPhpstan\Nette\Forms\Cache\DependencyRecorder;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Component\InterproceduralShapeKey;
use OriPhpstan\Nette\Forms\Index\FileFactIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationRecognizer;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\File\FileFinder;
use PHPStan\Parser\Parser;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\TestFileFinder;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_merge;
use function basename;
use function dirname;
use function filemtime;
use function getcwd;
use function glob;
use function is_dir;
use function is_file;
use function sha1;
use function sha1_file;
use function str_replace;
use function strpos;
use function sys_get_temp_dir;
use function uniqid;
use const GLOB_ONLYDIR;
use const PHP_BINARY;
use const PHP_VERSION_ID;

/**
 * IndexCacheTest (InferenceCacheTest idiom): FileFactIndex caches each file's registration facts
 * under FormShapeCache::remember($contentHash, 'registration-facts|' . configIdentity, ...) — the
 * same shared, content-addressed cache directory IndexShapeResolver's own shape walk uses. This test
 * targets that fact-level entry specifically, computing its on-disk path directly
 * (sha1(sha1_file($file) . '|registration-facts|phpVersion:<PHP_VERSION_ID>').ser — the identity resolved from
 * this harness config's phpVersion, the running PHP's — under the tmpDir's form-shape-cache version
 * directory) rather than introspecting the whole directory — the interprocedural shape entries share
 * the same directory but are recomputed every run by design (the index memo is in-memory, never
 * persisted), so a whole-directory mtime comparison would misreport them as "not reused".
 *
 * The CacheProbe fixture is a factory-indirection registration (RegisteringControl builds through
 * ProbeFactory::build(), which B1 follows, so the built field flows in). The registering method then
 * adds a nested container inline, whose runtime class is Nette\Forms\Container but the scope-free
 * index falls back to the configured default container class — a permanent scope-dependent label (A6
 * class 4). That reliably produces an index-rendering message, giving a stable, parseable rendering
 * to diff before/after an edit to the factory-built shape.
 */
final class IndexCacheTest extends BaseTestCase
{

	private const CONFIG = __DIR__ . '/shadow-compare.neon';

	private const KEY = 'mp:Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\CacheProbe\RegisteringControl::formSucceeded#0';

	/** @var list<string> */
	private array $dirs = [];

	protected function tearDown(): void
	{
		foreach ($this->dirs as $dir) {
			if (is_dir($dir)) {
				FileSystem::delete($dir);
			}
		}

		$this->dirs = [];
	}

	public function testColdShadowRunEqualsWarmShadowRunOutput(): void
	{
		$isolated = IsolatedPhpstanConfig::create(self::CONFIG);

		try {
			$files = $this->fixtureFiles(__DIR__ . '/Fixtures/CacheProbe');

			$cold = $this->spawnAnalysis($isolated->getConfigPath(), $files);
			$warm = $this->spawnAnalysis($isolated->getConfigPath(), $files);

			self::assertSame($cold, $warm, 'warm shadow run must produce byte-identical output to the cold run');
		} finally {
			$isolated->cleanup();
		}
	}

	public function testFactEntriesAreReusedOnWarm(): void
	{
		$isolated = IsolatedPhpstanConfig::create(self::CONFIG);

		try {
			$files = $this->fixtureFiles(__DIR__ . '/Fixtures/CacheProbe');
			$this->spawnAnalysis($isolated->getConfigPath(), $files);

			$before = [];
			foreach ($files as $file) {
				$before[$file] = $this->factEntryMtime($isolated->getTmpDir(), $file);
				self::assertNotNull(
					$before[$file],
					basename($file) . ' must have a cached fact entry after the cold run',
				);
			}

			$this->spawnAnalysis($isolated->getConfigPath(), $files);

			foreach ($files as $file) {
				self::assertSame(
					$before[$file],
					$this->factEntryMtime($isolated->getTmpDir(), $file),
					basename($file) . "'s fact entry must not be rewritten on a warm, content-unchanged run",
				);
			}
		} finally {
			$isolated->cleanup();
		}
	}

	public function testEditingTheRegisteringFixtureUpdatesTheKeysAnswer(): void
	{
		$universe = $this->tempUniverse();
		$isolated = IsolatedPhpstanConfig::create(self::CONFIG, [$universe]);

		try {
			$files = $this->fixtureFiles($universe);

			$before = $this->indexDivergenceMessage($isolated->getConfigPath(), $files);
			self::assertNotNull($before, 'the cache-probe fixture must produce an index rendering to observe');
			self::assertStringNotContainsString('  b:', $before);

			$factory = $universe . '/Factory.php';
			FileSystem::write(
				$factory,
				str_replace("addText('a');", "addText('a');\n\t\t\$form->addText('b');", FileSystem::read($factory)),
			);

			$after = $this->indexDivergenceMessage($isolated->getConfigPath(), $files);
			self::assertNotNull($after);
			self::assertNotSame(
				$before,
				$after,
				'editing the fixture that feeds the registered shape must recompute, never serve a stale entry',
			);
			self::assertStringContainsString('  b:', $after, 'the recomputed rendering must reflect the added field');
		} finally {
			$isolated->cleanup();
		}
	}

	public function testEditingAnUnrelatedFixtureLeavesTheRegisteringKeysFactEntryUntouched(): void
	{
		$universe = $this->tempUniverse();
		$isolated = IsolatedPhpstanConfig::create(self::CONFIG, [$universe]);

		try {
			$files = $this->fixtureFiles($universe);
			$this->spawnAnalysis($isolated->getConfigPath(), $files);

			$control = $universe . '/Control.php';
			$factory = $universe . '/Factory.php';
			$beforeControl = $this->factEntryMtime($isolated->getTmpDir(), $control);
			$beforeFactory = $this->factEntryMtime($isolated->getTmpDir(), $factory);
			self::assertNotNull($beforeControl);
			self::assertNotNull($beforeFactory);

			$unrelated = $universe . '/Unrelated.php';
			FileSystem::write($unrelated, str_replace('noop', 'noopRenamed', FileSystem::read($unrelated)));

			$this->spawnAnalysis($isolated->getConfigPath(), $files);

			self::assertSame(
				$beforeControl,
				$this->factEntryMtime($isolated->getTmpDir(), $control),
				"the registering fixture's fact entry must stay untouched by an unrelated edit",
			);
			self::assertSame(
				$beforeFactory,
				$this->factEntryMtime($isolated->getTmpDir(), $factory),
				"the factory fixture's fact entry must stay untouched by an unrelated edit",
			);
		} finally {
			$isolated->cleanup();
		}
	}

	public function testAddingARegistrationInANewFileLeavesExistingFactEntriesUntouched(): void
	{
		$universe = $this->tempUniverse();
		$isolated = IsolatedPhpstanConfig::create(self::CONFIG, [$universe]);

		try {
			$this->spawnAnalysis($isolated->getConfigPath(), $this->fixtureFiles($universe));

			$control = $universe . '/Control.php';
			$factory = $universe . '/Factory.php';
			$beforeControl = $this->factEntryMtime($isolated->getTmpDir(), $control);
			$beforeFactory = $this->factEntryMtime($isolated->getTmpDir(), $factory);
			self::assertNotNull($beforeControl);
			self::assertNotNull($beforeFactory);

			// A brand-new file registering its own handler — a new contributor entering the universe. The
			// fold loads its facts, but with the fold isolated from recorder frames that must neither
			// rewrite nor invalidate the existing files' content-addressed fact entries.
			$added = $universe . '/Added.php';
			FileSystem::write($added, $this->addedRegistrationFixture());

			$this->spawnAnalysis($isolated->getConfigPath(), $this->fixtureFiles($universe));

			self::assertSame(
				$beforeControl,
				$this->factEntryMtime($isolated->getTmpDir(), $control),
				"an existing file's fact entry must survive a registration added in a new file",
			);
			self::assertSame(
				$beforeFactory,
				$this->factEntryMtime($isolated->getTmpDir(), $factory),
				"the factory fixture's fact entry must survive a registration added in a new file",
			);
			self::assertNotNull(
				$this->factEntryMtime($isolated->getTmpDir(), $added),
				'the fold must incorporate the new file with its own fact entry',
			);
		} finally {
			$isolated->cleanup();
		}
	}

	public function testGetComponentMutatedChildAnswersEqualColdAndWarm(): void
	{
		// The cold≠warm regression class: the parent-inheritance compensation consulted a persisted
		// parent forClassComponent entry BEFORE the lost-field guard, so a child that mutates a pulled-in
		// parent container ($x = $form->getComponent('filter'); $x->addText(...)) closed its inherited
		// container when the parent's entry was already in the store (warm) but stayed honestly open when
		// it was not (cold) — the same file's error set depended on cache temperature. Reproduced
		// deterministically: seed the parent's entry into one store (spawn over the base only), then
		// analyse the child against that warm store and against a fresh store; both answers must match.
		$base = __DIR__ . '/Fixtures/InheritedParentBase.php';
		$child = __DIR__ . '/Fixtures/InheritedParentMutatedForm.php';

		$warmStore = IsolatedPhpstanConfig::create(self::CONFIG, [$base, $child]);
		$coldStore = IsolatedPhpstanConfig::create(self::CONFIG, [$base, $child]);

		try {
			$this->spawnAnalysis($warmStore->getConfigPath(), [$base]);

			$warm = $this->fileMessages($this->spawnAnalysis($warmStore->getConfigPath(), [$child]), $child);
			$cold = $this->fileMessages($this->spawnAnalysis($coldStore->getConfigPath(), [$child]), $child);

			self::assertNotSame([], $cold, 'the handler accesses must produce observable messages');
			self::assertSame(
				$cold,
				$warm,
				"the child's error set must not depend on whether the parent's shape entry is already in the store",
			);
		} finally {
			$warmStore->cleanup();
			$coldStore->cleanup();
		}
	}

	public function testEmbeddingEntryRecomputesWhenANewFileRegistersForTheKey(): void
	{
		[$universe, $contributingFile] = $this->fingerprintUniverse();
		$cacheDir = $this->makeCacheDir();

		$deps = $this->recordEmbedding($universe, $contributingFile, $cacheDir);
		self::assertTrue(
			$this->validateEmbedding($universe, $deps, $cacheDir),
			'the freshly recorded embedding is valid against its own universe',
		);

		// A brand-new file whose trait registration re-keys to the embedded key — a contributor the
		// recorded files could never name. The universe-deps replay this replaces was blind to it.
		FileSystem::write($universe . '/RegistersOrderB.php', $this->orderTraitFixture('RegistersOrderB', 'Beta'));

		self::assertFalse(
			$this->validateEmbedding($universe, $deps, $cacheDir),
			'a new file registering for the embedded key must move the fingerprint and recompute the entry',
		);
	}

	public function testEmbeddingEntryRecomputesWhenAContributingFileIsEdited(): void
	{
		[$universe, $contributingFile] = $this->fingerprintUniverse();
		$cacheDir = $this->makeCacheDir();

		$deps = $this->recordEmbedding($universe, $contributingFile, $cacheDir);

		// A content edit that leaves the registration itself unchanged (an extra field): the contributor
		// fingerprint stays put, so this exercises the contributing-file hash channel of the same entry.
		FileSystem::write(
			$contributingFile,
			str_replace(
				'$form = new Form();',
				"\$form = new Form();\n\t\t\$form->addText('extra');",
				FileSystem::read($contributingFile),
			),
		);

		self::assertFalse(
			$this->validateEmbedding($universe, $deps, $cacheDir),
			'editing a contributing file must invalidate the embedding through its recorded file dependency',
		);
	}

	public function testEmbeddingEntryRecomputesWhenAContributingFileIsDeleted(): void
	{
		[$universe, $contributingFile] = $this->fingerprintUniverse();
		$cacheDir = $this->makeCacheDir();

		$deps = $this->recordEmbedding($universe, $contributingFile, $cacheDir);

		FileSystem::delete($contributingFile);

		self::assertFalse(
			$this->validateEmbedding($universe, $deps, $cacheDir),
			'deleting a contributing file must invalidate the embedding',
		);
	}

	public function testEmbeddingEntrySurvivesAnUnrelatedFileAddition(): void
	{
		[$universe, $contributingFile] = $this->fingerprintUniverse();
		$cacheDir = $this->makeCacheDir();

		$deps = $this->recordEmbedding($universe, $contributingFile, $cacheDir);

		// A new file that registers nothing for the embedded key leaves the contributor set unchanged, so
		// the fingerprint holds — warm neutrality the whole-universe replay could never preserve.
		FileSystem::write($universe . '/Unrelated.php', $this->unrelatedFixture());

		self::assertTrue(
			$this->validateEmbedding($universe, $deps, $cacheDir),
			'an unrelated file that changes no contributor set must not invalidate the embedding',
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function recordEmbedding(string $universe, string $contributingFile, string $cacheDir): array
	{
		$recorder = new DependencyRecorder();
		$index = $this->fingerprintIndex($universe, $recorder, $cacheDir);
		$key = $this->fingerprintKey();

		$recorder->beginFrame();
		// (a) the contributing site file, as its shape walk would record it, and (b) the key fingerprint,
		// as IndexShapeResolver records it when answering inside the frame.
		$recorder->record($contributingFile);
		$recorder->recordFingerprint($key, $index->fingerprint($key));

		return $recorder->endFrame();
	}

	/**
	 * @param array<string, string> $deps
	 */
	private function validateEmbedding(string $universe, array $deps, string $cacheDir): bool
	{
		$recorder = new DependencyRecorder();
		$index = $this->fingerprintIndex($universe, $recorder, $cacheDir);
		$recorder->setFingerprintValidator($index);

		return $recorder->stillValid($deps);
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function fingerprintUniverse(): array
	{
		$dir = sys_get_temp_dir() . '/index-fingerprint-universe-' . uniqid('', true);
		FileSystem::createDir($dir);
		$this->dirs[] = $dir;

		FileSystem::write($dir . '/OrderUser.php', $this->orderUserFixture());
		$contributingFile = $dir . '/RegistersOrderA.php';
		FileSystem::write($contributingFile, $this->orderTraitFixture('RegistersOrderA', 'Alpha'));

		return [$dir, $contributingFile];
	}

	private function fingerprintKey(): string
	{
		return InterproceduralShapeKey::forMethodParam('CacheFpFx\OrderUser', 'orderSucceeded', 0);
	}

	private function fingerprintIndex(
		string $universe,
		DependencyRecorder $recorder,
		string $cacheDir
	): RegistrationIndex
	{
		$cache = new FormShapeCache($cacheDir, $recorder);
		$facts = new FileFactIndex($cache, $this->fingerprintParser(), new RegistrationRecognizer());

		return new RegistrationIndex([$universe], $this->fingerprintFinder(), $facts, $cache);
	}

	private function makeCacheDir(): string
	{
		$dir = sys_get_temp_dir() . '/index-fingerprint-cache-' . uniqid('', true);
		FileSystem::createDir($dir);
		$this->dirs[] = $dir;

		return $dir;
	}

	private function fingerprintFinder(): FileFinder
	{
		return TestFileFinder::create((string) getcwd());
	}

	private function fingerprintParser(): Parser
	{
		return new class implements Parser {

			/** @return array<Stmt> */
			public function parseFile(string $file): array
			{
				return $this->parseString(FileSystem::read($file));
			}

			/** @return array<Stmt> */
			public function parseString(string $sourceCode): array
			{
				$stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($sourceCode) ?? [];

				$traverser = new NodeTraverser();
				$traverser->addVisitor(new NameResolver());

				/** @var array<Stmt> */
				return $traverser->traverse($stmts);
			}

		};
	}

	private function orderUserFixture(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

namespace CacheFpFx;

class OrderUser
{

	use RegistersOrderA;
	use RegistersOrderB;

}
PHP;
	}

	private function orderTraitFixture(string $trait, string $component): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

namespace CacheFpFx;

use Nette\\Application\\UI\\Form;

trait $trait
{

	public function createComponent$component(): Form
	{
		\$form = new Form();
		\$form->onSuccess[] = [\$this, 'orderSucceeded'];

		return \$form;
	}

}
PHP;
	}

	private function unrelatedFixture(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

namespace CacheFpFx;

class UnrelatedThing
{

	public function noop(): void
	{
	}

}
PHP;
	}

	/**
	 * @return list<array{line: int, message: string}>
	 */
	private function fileMessages(string $output, string $file): array
	{
		/** @var array{files?: array<string, array{messages?: list<array{identifier?: string, line: int, message: string}>}>} $decoded */
		$decoded = Json::decode($output, Json::FORCE_ARRAY);

		$messages = [];
		foreach ($decoded['files'] ?? [] as $path => $info) {
			if (strpos($path, basename($file)) === false) {
				continue;
			}

			foreach ($info['messages'] ?? [] as $message) {
				$messages[] = ['line' => $message['line'], 'message' => $message['message']];
			}
		}

		return $messages;
	}

	private function addedRegistrationFixture(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\CacheProbe;

use Nette\Application\UI\Form;

class AddedControl
{

	public function createComponentExtra(): Form
	{
		$form = new Form();
		$form->addText('added');
		$form->onSuccess[] = [$this, 'extraSucceeded'];

		return $form;
	}

	public function extraSucceeded(Form $form): void
	{
	}

}
PHP;
	}

	private function tempUniverse(): string
	{
		$dir = sys_get_temp_dir() . '/index-cache-universe-' . uniqid('', true);
		FileSystem::copy(__DIR__ . '/Fixtures/CacheProbe', $dir);
		$this->dirs[] = $dir;

		return $dir;
	}

	/**
	 * @return list<string>
	 */
	private function fixtureFiles(string $dir): array
	{
		$files = glob($dir . '/*.php');
		self::assertNotFalse($files);
		self::assertNotSame([], $files);

		return $files;
	}

	private function factEntryMtime(string $tmpDir, string $file): ?int
	{
		$path = $this->factEntryPath($tmpDir, $file);
		if ($path === null || !is_file($path)) {
			return null;
		}

		$mtime = filemtime($path);

		return $mtime === false ? null : $mtime;
	}

	private function factEntryPath(string $tmpDir, string $file): ?string
	{
		$versions = glob($tmpDir . '/form-shape-cache/v*', GLOB_ONLYDIR);
		if ($versions === false || $versions === []) {
			return null;
		}

		$key = sha1(sha1_file($file) . '|registration-facts|phpVersion:' . PHP_VERSION_ID);

		return $versions[0] . '/' . $key . '.ser';
	}

	/**
	 * @param list<string> $files
	 */
	private function indexDivergenceMessage(string $config, array $files): ?string
	{
		/** @var array{files?: array<string, array{messages?: list<array{identifier?: string, line: int, message: string}>}>} $decoded */
		$decoded = Json::decode($this->spawnAnalysis($config, $files), Json::FORCE_ARRAY);

		foreach ($decoded['files'] ?? [] as $info) {
			foreach ($info['messages'] ?? [] as $message) {
				if (($message['identifier'] ?? '') !== 'orisai.nette.forms.shadowDivergence') {
					continue;
				}

				if (strpos($message['message'], self::KEY) === false) {
					continue;
				}

				return $message['message'];
			}
		}

		return null;
	}

	/**
	 * @param list<string> $files
	 */
	private function spawnAnalysis(string $config, array $files): string
	{
		$root = dirname(__DIR__, 4);
		$process = new Process(
			array_merge(
				[PHP_BINARY, $root . '/' . VendorDirectory::name() . '/bin/phpstan', 'analyse'],
				$files,
				[
					'-c',
					$config,
					'--error-format=json',
					'--no-progress',
					'--memory-limit=2048M',
				],
			),
			$root,
		);
		$process->setTimeout(600.0);
		$process->run();

		return $process->getOutput();
	}

}
