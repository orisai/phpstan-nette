<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Index\FileFactIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationFact;
use OriPhpstan\Nette\Forms\Index\RegistrationRecognizer;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\Parser\Parser;
use stdClass;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_map;
use function glob;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

final class FileFactIndexTest extends BaseTestCase
{

	private const ALL_KINDS_FIXTURE = __DIR__ . '/Fixtures/AllKinds.php';

	private const TRAIT_FIXTURE = __DIR__ . '/Fixtures/TraitRegistrations.php';

	private string $dir;

	private string $noRegistrationsFixture;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/file-fact-index-test-' . uniqid('', true);

		$this->noRegistrationsFixture = sys_get_temp_dir()
			. '/file-fact-index-no-registrations-' . uniqid('', true) . '.php';
		FileSystem::write($this->noRegistrationsFixture, <<<'PHP'
<?php declare(strict_types = 1);

namespace App\Sample;

class Plain
{

	public function getName(): string
	{
		return $this->name;
	}

}

PHP);
	}

	protected function tearDown(): void
	{
		parent::tearDown();
		if (is_dir($this->dir)) {
			FileSystem::delete($this->dir);
		}

		FileSystem::delete($this->noRegistrationsFixture);
	}

	public function testNoRegistrationFileYieldsEmptyAfterRealParseAndCachesTheEmptyResult(): void
	{
		[$index, $counter] = $this->makeIndex($this->dir);

		$facts = $index->factsFor($this->noRegistrationsFixture, 'hash-1');

		self::assertSame([], $facts);
		self::assertSame(1, $counter->count, 'every file must be parsed — there is no prefilter');
		$entries = glob($this->dir . '/v*/*.ser');
		self::assertNotFalse($entries);
		self::assertCount(1, $entries, 'the empty result must be cached so warm sweeps are parse-free');

		$index->factsFor($this->noRegistrationsFixture, 'hash-1');
		self::assertSame(1, $counter->count, 'a warm hit on the cached empty result must not re-parse');
	}

	public function testExtractsEventAndPassThroughFactsOnly(): void
	{
		[$index] = $this->makeIndex($this->dir);

		$facts = $index->factsFor(self::ALL_KINDS_FIXTURE, 'hash-1');

		self::assertSame(
			[
				[
					'kind' => RegistrationFact::KIND_EVENT_HANDLER,
					'registeringClass' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\AllKinds',
					'registeringMethod' => 'createComponentOrder',
					'formVar' => 'form',
					'handlerClass' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\AllKinds',
					'handlerMethod' => 'orderSucceeded',
					'eventProperty' => 'onSuccess',
				],
				[
					'kind' => RegistrationFact::KIND_EVENT_HANDLER,
					'registeringClass' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\AllKinds',
					'registeringMethod' => 'createComponentOrder',
					'formVar' => 'form',
					'handlerClass' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\AllKinds',
					'handlerMethod' => 'orderSucceeded',
					'eventProperty' => 'onSuccess',
				],
				[
					'kind' => RegistrationFact::KIND_PARAM_PASS_THROUGH,
					'callerClass' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\AllKinds',
					'callerMethod' => 'passesFormParam',
					'callerOrigin' => 0,
					'calleeClass' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\AllKinds',
					'calleeMethod' => 'fillForm',
					'calleeParamIdx' => 0,
				],
				[
					'kind' => RegistrationFact::KIND_PARAM_MUTATION,
					'paramClass' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\AllKinds',
					'paramMethod' => 'passesFormParam',
					'paramIdx' => 0,
					'handOverCallee' => RegistrationFact::calleeKey('fillForm', 0),
				],
			],
			array_map(static fn (RegistrationFact $fact): array => $fact->toArray(), $facts),
			'the event overlap pair, the pass-through edge and the hand-over of that same parameter'
			. ' (fillForm is what decides whether the hand-over registers anything), and nothing from'
			. ' the plain form-returning method (buildsFormDirectly contributes no fold fact)',
		);
	}

	public function testTraitDeclaredRegistrationsAreTraitKeyedAndTraitUseEdgesEmitted(): void
	{
		[$index] = $this->makeIndex($this->dir);

		$facts = $index->factsFor(self::TRAIT_FIXTURE, 'hash-1');

		self::assertSame(
			[
				[
					'kind' => RegistrationFact::KIND_TRAIT_USE,
					'className' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\NotificationFormTrait',
					'traitName' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\NotificationLogTrait',
				],
				[
					'kind' => RegistrationFact::KIND_EVENT_HANDLER,
					'registeringClass' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\NotificationFormTrait',
					'registeringMethod' => 'createComponentNotification',
					'formVar' => 'form',
					'handlerClass' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\NotificationFormTrait',
					'handlerMethod' => 'notificationSucceeded',
					'eventProperty' => 'onSuccess',
				],
				[
					'kind' => RegistrationFact::KIND_EVENT_HANDLER,
					'registeringClass' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\NotificationFormTrait',
					'registeringMethod' => 'createComponentNotification',
					'formVar' => 'form',
					'handlerClass' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\NotificationFormTrait',
					'handlerMethod' => 'notificationSucceeded',
					'eventProperty' => 'onSuccess',
				],
				[
					'kind' => RegistrationFact::KIND_TRAIT_USE,
					'className' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\UsesNotificationForm',
					'traitName' => 'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\NotificationFormTrait',
				],
			],
			array_map(static fn (RegistrationFact $fact): array => $fact->toArray(), $facts),
			'trait-declared registrations keyed by the trait itself; traitUse edges from trait and class alike',
		);

		self::assertSame(
			'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\UsesNotificationForm',
			$facts[3]->getClassName(),
		);
		self::assertSame(
			'Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\NotificationFormTrait',
			$facts[3]->getTraitName(),
		);
	}

	public function testColdInstanceRereadsPersistedEmptyEntryFromDisk(): void
	{
		[$warmIndex] = $this->makeIndex($this->dir);
		self::assertSame([], $warmIndex->factsFor($this->noRegistrationsFixture, 'hash-1'));

		[$coldIndex, $coldCounter] = $this->makeIndex($this->dir);
		$reread = $coldIndex->factsFor($this->noRegistrationsFixture, 'hash-1');

		self::assertSame([], $reread);
		self::assertSame(0, $coldCounter->count, 'a persisted empty entry must serve a fresh instance without a parse');
	}

	public function testSecondCallWithSameHashReusesCacheWithoutReparsing(): void
	{
		[$index, $counter] = $this->makeIndex($this->dir);

		$first = $index->factsFor(self::ALL_KINDS_FIXTURE, 'hash-1');
		$second = $index->factsFor(self::ALL_KINDS_FIXTURE, 'hash-1');

		self::assertSame(1, $counter->count, 'a cache hit must not re-parse the file');
		self::assertEquals(
			array_map(static fn (RegistrationFact $fact): array => $fact->toArray(), $first),
			array_map(static fn (RegistrationFact $fact): array => $fact->toArray(), $second),
		);
	}

	public function testChangedHashForcesRecompute(): void
	{
		[$index, $counter] = $this->makeIndex($this->dir);

		$index->factsFor(self::ALL_KINDS_FIXTURE, 'hash-1');
		$index->factsFor(self::ALL_KINDS_FIXTURE, 'hash-2');

		self::assertSame(2, $counter->count, 'a different content hash must recompute, not reuse hash-1\'s entry');
	}

	public function testColdInstanceRereadsPersistedFactsFromDisk(): void
	{
		[$warmIndex] = $this->makeIndex($this->dir);
		$written = $warmIndex->factsFor(self::ALL_KINDS_FIXTURE, 'hash-1');

		[$coldIndex, $coldCounter] = $this->makeIndex($this->dir);
		$reread = $coldIndex->factsFor(self::ALL_KINDS_FIXTURE, 'hash-1');

		self::assertSame(0, $coldCounter->count, 'a disk-cache hit on a fresh instance must not re-parse the file');
		self::assertEquals(
			array_map(static fn (RegistrationFact $fact): array => $fact->toArray(), $written),
			array_map(static fn (RegistrationFact $fact): array => $fact->toArray(), $reread),
		);
	}

	/**
	 * @return array{FileFactIndex, stdClass}
	 */
	private function makeIndex(string $dir): array
	{
		$counter = new stdClass();
		$counter->count = 0;

		$parser = new class ($counter) implements Parser {

			private stdClass $counter;

			public function __construct(stdClass $counter)
			{
				$this->counter = $counter;
			}

			/** @return array<Stmt> */
			public function parseFile(string $file): array
			{
				$this->counter->count++;

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

		$cache = new FormShapeCache($dir);
		$index = new FileFactIndex($cache, $parser, new RegistrationRecognizer());

		return [$index, $counter];
	}

}
