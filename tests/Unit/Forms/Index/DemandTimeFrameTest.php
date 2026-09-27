<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\DependencyRecorder;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Cache\RecordingParser;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Component\InterproceduralShapeKey;
use OriPhpstan\Nette\Forms\Index\FileFactIndex;
use OriPhpstan\Nette\Forms\Index\IndexShapeResolver;
use OriPhpstan\Nette\Forms\Index\RegistrationIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationRecognizer;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\File\FileFinder;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestFileFinder;
use function assert;
use function getcwd;
use function is_dir;
use function is_file;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Demand-time frame semantics (Task B4, C2): the two hazards of demanding a fold answer while an enclosing
 * recorder frame is open. (a) The fold loads every file's facts, so its per-entry replays must not reach
 * the enclosing frame — otherwise it depends on the whole universe (an invalidation storm). (b) When the
 * resolver DOES answer inside a frame, the enclosing shape embeds that answer, whose correctness depends
 * on the key's contributor set — so the resolver records the key's contributor fingerprint into the frame
 * (not the whole universe), the sound validation payload that makes such an embedding recompute when the
 * contributor set moves — a registration entering or leaving any file, including one created afterwards —
 * while an unrelated file leaves the fingerprint untouched (warm neutrality the universe replay destroyed).
 */
final class DemandTimeFrameTest extends FormShapeTestCase
{

	private const NS = 'Tests\\OriPhpstan\\Nette\\Unit\\Forms\\Index\\Fixtures';

	private const FIXTURES = __DIR__ . '/Fixtures';

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

	public function testTheFoldRecordsNothingIntoAnActiveFrame(): void
	{
		$recorder = new DependencyRecorder();
		$index = $this->makeIndex($this->makeCache($recorder));

		$recorder->beginFrame();
		$sites = $index->handlerSites(self::NS . '\HandlerForm', 'orderSucceeded', 0);
		$deps = $recorder->endFrame();

		self::assertNotSame([], $sites, 'the fold must have loaded facts for the queried key');
		self::assertSame(
			[],
			$deps,
			'loading every file\'s facts must not make the enclosing frame depend on the file universe',
		);
	}

	public function testAnsweringInsideAFrameRecordsTheKeyFingerprintNotTheUniverse(): void
	{
		$recorder = new DependencyRecorder();
		$cache = $this->makeCache($recorder);
		$index = $this->makeIndex($cache);
		$resolver = $this->makeResolver($index, $cache);

		$key = InterproceduralShapeKey::forMethodParam(self::NS . '\HandlerForm', 'orderSucceeded', 0);
		$fingerprint = $index->fingerprint($key);

		$recorder->beginFrame();
		$shape = $resolver->resolveMethodParam(self::NS . '\HandlerForm', 'orderSucceeded', 0);
		$deps = $recorder->endFrame();

		self::assertNotNull($shape, 'the queried key resolves to a shape');

		$fingerprintKey = DependencyRecorder::fingerprintDependencyKey($key);
		self::assertArrayHasKey(
			$fingerprintKey,
			$deps,
			'answering inside a frame must record the key contributor fingerprint into that frame',
		);
		self::assertSame($fingerprint, $deps[$fingerprintKey]);

		// Warm neutrality: unlike the replaced universe replay, only contributing files (via their
		// site-shape loads) reach the frame. The contributing site is recorded; an unrelated fixture is
		// not, so its edit cannot invalidate the embedding entry.
		$recordedFiles = [];
		foreach ($deps as $depKey => $_) {
			if ($depKey !== $fingerprintKey && is_file($depKey)) {
				$recordedFiles[$depKey] = true;
			}
		}

		self::assertArrayHasKey(
			self::FIXTURES . '/HandlerForm.php',
			$recordedFiles,
			'the contributing site file is recorded through its shape walk',
		);
		self::assertArrayNotHasKey(
			self::FIXTURES . '/PassThroughForm.php',
			$recordedFiles,
			'an unrelated fixture is not recorded — the whole universe is not pulled in (warm neutrality)',
		);
	}

	public function testAnsweringTheClassChannelInsideAFrameRecordsTheKeyFingerprint(): void
	{
		$recorder = new DependencyRecorder();
		$cache = $this->makeCache($recorder);
		$index = $this->makeIndex($cache);
		$resolver = $this->makeResolver($index, $cache);

		$key = InterproceduralShapeKey::forMethodParam(self::NS . '\HandlerForm', 'orderSucceeded', 0);
		$fingerprint = $index->fingerprint($key);

		$recorder->beginFrame();
		$resolvedClass = $resolver->resolveMethodParamClass(self::NS . '\HandlerForm', 'orderSucceeded', 0);
		$deps = $recorder->endFrame();

		self::assertNotNull($resolvedClass, 'the queried key resolves to a class');

		$fingerprintKey = DependencyRecorder::fingerprintDependencyKey($key);
		self::assertArrayHasKey(
			$fingerprintKey,
			$deps,
			'answering the class channel inside a frame must record the key contributor fingerprint into that frame',
		);
		self::assertSame($fingerprint, $deps[$fingerprintKey]);
	}

	public function testAnsweringWithoutAFrameRecordsNothing(): void
	{
		$recorder = new DependencyRecorder();
		$cache = $this->makeCache($recorder);
		$resolver = $this->makeResolver($this->makeIndex($cache), $cache);

		$resolver->resolveMethodParam(self::NS . '\HandlerForm', 'orderSucceeded', 0);

		self::assertFalse($recorder->hasActiveFrame(), 'a resolution outside any frame leaves no dangling frame');
	}

	private function makeCache(DependencyRecorder $recorder): FormShapeCache
	{
		return new FormShapeCache($this->makeDir(), $recorder);
	}

	private function makeIndex(FormShapeCache $cache): RegistrationIndex
	{
		$facts = new FileFactIndex($cache, $this->parser(), new RegistrationRecognizer());

		return new RegistrationIndex([self::FIXTURES], $this->finder(), $facts);
	}

	private function makeResolver(RegistrationIndex $index, FormShapeCache $cache): IndexShapeResolver
	{
		$resolver = new IndexShapeResolver(
			$index,
			$cache,
			// The recording parser threads the walked site files into the active frame exactly as the
			// production wiring does, so an embedding entry's dependencies are the contributing files
			// (this channel) plus the key fingerprint (the resolver's own recording).
			new RecordingParser($this->parser(), $cache->recorder()),
			self::createReflectionProvider(),
			$this->catalogReader(),
			[self::FIXTURES],
		);
		$resolver->bindScope($this->scope());

		return $resolver;
	}

	private function scope(): Scope
	{
		$captured = null;
		self::processFile(
			self::FIXTURES . '/HandlerForm.php',
			static function (Node $node, Scope $scope) use (&$captured): void {
				if ($captured === null && $scope->isInClass() && $scope->getFunctionName() !== null) {
					$captured = $scope;
				}
			},
		);

		self::assertNotNull($captured);

		return $captured;
	}

	private function parser(): Parser
	{
		$parser = self::getContainer()->getService('defaultAnalysisParser');
		assert($parser instanceof Parser);

		return $parser;
	}

	private function finder(): FileFinder
	{
		return TestFileFinder::create((string) getcwd());
	}

	private function catalogReader(): ControlAnnotationValueTypeReader
	{
		return new ControlAnnotationValueTypeReader(
			self::createReflectionProvider(),
			self::getContainer()->getByType(TypeStringResolver::class),
		);
	}

	private function makeDir(): string
	{
		$dir = sys_get_temp_dir() . '/demand-time-frame-test-' . uniqid('', true);
		FileSystem::createDir($dir);
		$this->dirs[] = $dir;

		return $dir;
	}

}
