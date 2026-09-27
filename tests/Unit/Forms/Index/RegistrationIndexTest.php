<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Component\InterproceduralShapeKey;
use OriPhpstan\Nette\Forms\Index\FileFactIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationFact;
use OriPhpstan\Nette\Forms\Index\RegistrationIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationRecognizer;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\File\FileFinder;
use PHPStan\Parser\Parser;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestFileFinder;
use Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\OrderedRegistrationIndex;
use function array_map;
use function getcwd;
use function is_dir;
use function sha1;
use function sys_get_temp_dir;
use function uniqid;

final class RegistrationIndexTest extends BaseTestCase
{

	private const NS = 'RegIdxFx';

	/** @var list<string> */
	private array $dirs = [];

	protected function tearDown(): void
	{
		parent::tearDown();
		foreach ($this->dirs as $dir) {
			if (is_dir($dir)) {
				FileSystem::delete($dir);
			}
		}
	}

	public function testTwoFileUniverseBuildsReverseMaps(): void
	{
		$dir = $this->makeDir();
		$alpha = $this->write($dir, 'Alpha.php', $this->alphaFixture());
		$this->write($dir, 'Beta.php', $this->betaFixture());

		$index = $this->makeIndex([$dir]);

		$orderSites = $index->handlerSites(self::NS . '\Alpha', 'orderSucceeded', 0);
		self::assertCount(1, $orderSites, 'the overlap pair (both event grammars) dedupes to one contribution');
		self::assertSame($alpha, $orderSites[0]['file']);
		self::assertSame(
			[
				'kind' => RegistrationFact::KIND_EVENT_HANDLER,
				'registeringClass' => self::NS . '\Alpha',
				'registeringMethod' => 'createComponentOrder',
				'formVar' => 'form',
				'handlerClass' => self::NS . '\Alpha',
				'handlerMethod' => 'orderSucceeded',
				'eventProperty' => 'onSuccess',
			],
			$orderSites[0]['fact']->toArray(),
		);

		self::assertCount(1, $index->handlerSites(self::NS . '\Beta', 'paymentSucceeded', 0));

		$edges = $index->passThroughEdgesInto(self::NS . '\Alpha', 'fillForm', 0);
		self::assertCount(1, $edges);
		self::assertSame(
			[
				'kind' => RegistrationFact::KIND_PARAM_PASS_THROUGH,
				'callerClass' => self::NS . '\Alpha',
				'callerMethod' => 'passesFormParam',
				'callerOrigin' => 0,
				'calleeClass' => self::NS . '\Alpha',
				'calleeMethod' => 'fillForm',
				'calleeParamIdx' => 0,
			],
			$edges[0]['fact']->toArray(),
		);

		self::assertSame([], $index->handlerSites(self::NS . '\Alpha', 'nonExistent', 0));
		self::assertSame([], $index->passThroughEdgesInto(self::NS . '\Alpha', 'nonExistent', 0));
	}

	public function testTraitDeclaredRegistrationReKeysToUsingClassTransitively(): void
	{
		$dir = $this->makeDir();
		$file = $this->write($dir, 'Chain.php', $this->traitChainFixture());

		$index = $this->makeIndex([$dir]);

		$sites = $index->handlerSites(self::NS . '\UsesChain', 'innerSucceeded', 0);
		self::assertCount(1, $sites, 'a trait-declared registration re-keys transitively to the using class');
		self::assertSame($file, $sites[0]['file'], 'the contributing file stays the trait declaration');
		self::assertSame(
			[
				'kind' => RegistrationFact::KIND_EVENT_HANDLER,
				'registeringClass' => self::NS . '\UsesChain',
				'registeringMethod' => 'createComponentInner',
				'formVar' => 'form',
				'handlerClass' => self::NS . '\UsesChain',
				'handlerMethod' => 'innerSucceeded',
				'eventProperty' => 'onSuccess',
			],
			$sites[0]['fact']->toArray(),
			'the re-keyed fact carries the using class in every class field',
		);

		self::assertSame(
			[],
			$index->handlerSites(self::NS . '\RegInner', 'innerSucceeded', 0),
			'the registration is never keyed under the declaring trait itself',
		);
	}

	public function testTraitCycleTerminates(): void
	{
		$dir = $this->makeDir();
		$this->write($dir, 'Cycle.php', $this->traitCycleFixture());

		$index = $this->makeIndex([$dir]);

		self::assertSame([], $index->handlerSites(self::NS . '\CycA', 'cycSucceeded', 0));
		self::assertSame([], $index->handlerSites(self::NS . '\CycB', 'cycSucceeded', 0));
	}

	public function testFingerprintAndMapsAreInvariantToUniverseOrder(): void
	{
		$dir = $this->makeDir();
		$alpha = $this->write($dir, 'Alpha.php', $this->alphaFixture());
		$beta = $this->write($dir, 'Beta.php', $this->betaFixture());

		$forward = $this->makeIndexWithOrder([$dir], [$alpha, $beta]);
		$reversed = $this->makeIndexWithOrder([$dir], [$beta, $alpha]);

		$key = InterproceduralShapeKey::forMethodParam(self::NS . '\Alpha', 'orderSucceeded', 0);
		self::assertSame($forward->fingerprint($key), $reversed->fingerprint($key));

		self::assertSame(
			$this->normalize($forward->handlerSites(self::NS . '\Alpha', 'orderSucceeded', 0)),
			$this->normalize($reversed->handlerSites(self::NS . '\Alpha', 'orderSucceeded', 0)),
		);
		self::assertSame(
			$this->normalize($forward->passThroughEdgesInto(self::NS . '\Alpha', 'fillForm', 0)),
			$this->normalize($reversed->passThroughEdgesInto(self::NS . '\Alpha', 'fillForm', 0)),
		);
	}

	public function testFingerprintMovesWhenRegistrationRemoved(): void
	{
		$withDir = $this->makeDir();
		$this->write($withDir, 'Alpha.php', $this->alphaFixture());

		$withoutDir = $this->makeDir();
		$this->write($withoutDir, 'Alpha.php', $this->alphaWithoutRegistrationFixture());

		$key = InterproceduralShapeKey::forMethodParam(self::NS . '\Alpha', 'orderSucceeded', 0);

		$with = $this->makeIndex([$withDir])->fingerprint($key);
		$without = $this->makeIndex([$withoutDir])->fingerprint($key);

		self::assertNotSame($with, $without, 'removing the registration moves the key fingerprint');
		self::assertSame(
			sha1(''),
			$without,
			'a key that lost its only contributor collapses to the empty-set fingerprint',
		);
	}

	public function testZeroContributorFingerprintIsStableAndDefined(): void
	{
		$dir = $this->makeDir();
		$this->write($dir, 'Alpha.php', $this->alphaFixture());

		$index = $this->makeIndex([$dir]);
		$other = $this->makeIndex([$dir]);

		$absent = InterproceduralShapeKey::forMethodParam(self::NS . '\Ghost', 'missing', 0);
		$absentToo = InterproceduralShapeKey::forMethodParam(self::NS . '\Phantom', 'gone', 3);

		self::assertSame(sha1(''), $index->fingerprint($absent));
		self::assertSame($index->fingerprint($absent), $other->fingerprint($absent), 'stable across instances');
		self::assertSame(
			$index->fingerprint($absent),
			$index->fingerprint($absentToo),
			'every zero-contributor key shares the same defined fingerprint',
		);
	}

	/**
	 * @param list<array{file: string, fact: RegistrationFact}> $sites
	 * @return list<array{file: string, fact: array<string, mixed>}>
	 */
	private function normalize(array $sites): array
	{
		return array_map(
			static fn (array $site): array => ['file' => $site['file'], 'fact' => $site['fact']->toArray()],
			$sites,
		);
	}

	/**
	 * @param list<string> $analysedPaths
	 */
	private function makeIndex(array $analysedPaths): RegistrationIndex
	{
		return new RegistrationIndex($analysedPaths, $this->finder(), $this->facts());
	}

	/**
	 * @param list<string> $analysedPaths
	 * @param list<string> $order
	 */
	private function makeIndexWithOrder(array $analysedPaths, array $order): RegistrationIndex
	{
		return new OrderedRegistrationIndex($analysedPaths, $this->finder(), $this->facts(), $order);
	}

	private function facts(): FileFactIndex
	{
		return new FileFactIndex(new FormShapeCache($this->makeDir()), $this->parser(), new RegistrationRecognizer());
	}

	private function finder(): FileFinder
	{
		return TestFileFinder::create((string) getcwd());
	}

	private function parser(): Parser
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

	private function makeDir(): string
	{
		$dir = sys_get_temp_dir() . '/registration-index-test-' . uniqid('', true);
		FileSystem::createDir($dir);
		$this->dirs[] = $dir;

		return $dir;
	}

	private function write(string $dir, string $name, string $php): string
	{
		$path = $dir . '/' . $name;
		FileSystem::write($path, $php);

		return $path;
	}

	private function alphaFixture(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

namespace RegIdxFx;

class Alpha
{

	public function createComponentOrder(): Form
	{
		$form = new Form();
		$form->onSuccess[] = [$this, 'orderSucceeded'];

		return $form;
	}

	public function fillForm(Form $form): void
	{
	}

	public function passesFormParam(Form $form): void
	{
		$this->fillForm($form);
	}

}

PHP;
	}

	private function alphaWithoutRegistrationFixture(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

namespace RegIdxFx;

class Alpha
{

	public function createComponentOrder(): Form
	{
		$form = new Form();

		return $form;
	}

}

PHP;
	}

	private function betaFixture(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

namespace RegIdxFx;

class Beta
{

	public function createComponentPayment(): Form
	{
		$form = new Form();
		$form->onSuccess[] = [$this, 'paymentSucceeded'];

		return $form;
	}

}

PHP;
	}

	private function traitChainFixture(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

namespace RegIdxFx;

trait RegInner
{

	public function createComponentInner(): Form
	{
		$form = new Form();
		$form->onSuccess[] = [$this, 'innerSucceeded'];

		return $form;
	}

}

trait RegOuter
{

	use RegInner;

}

class UsesChain
{

	use RegOuter;

}

PHP;
	}

	private function traitCycleFixture(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

namespace RegIdxFx;

trait CycA
{

	use CycB;

	public function createComponentCyc(): Form
	{
		$form = new Form();
		$form->onSuccess[] = [$this, 'cycSucceeded'];

		return $form;
	}

}

trait CycB
{

	use CycA;

}

PHP;
	}

}
