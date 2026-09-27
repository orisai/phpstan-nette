<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\FormsCodeVersion;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Cache\TypeCanonicalizer;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentSlot;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function glob;
use function is_dir;
use function serialize;
use function sys_get_temp_dir;
use function uniqid;
use function unserialize;
use const GLOB_ONLYDIR;

final class FormShapeCacheTest extends BaseTestCase
{

	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/form-shape-cache-test-' . uniqid('', true);
	}

	protected function tearDown(): void
	{
		parent::tearDown();
		if (is_dir($this->dir)) {
			FileSystem::delete($this->dir);
		}
	}

	private function representativeShape(): FormShape
	{
		$inner = new FormShape(
			'Nette\Forms\Container',
			['x' => new ComponentSlot('x', new StringType(), Certainty::HAPPENS, ['n1'])],
			[],
			[],
			new UnknownInfo(),
			['n1'],
		);

		// A control added straight onto the replicator holder (e.g. addSubmit('addNode', …) on the
		// addDynamic() return value) - not trivially empty, so the round-trip test below actually
		// exercises serializing/describing a replicator's OWN children, not just its inner row.
		$repOwn = new FormShape(
			null,
			[
				'addNode' => new ComponentSlot(
					'addNode',
					new ObjectType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton'),
					Certainty::HAPPENS,
					['n5'],
				),
			],
			[],
			[],
			new UnknownInfo(),
			['n5'],
		);

		return new FormShape(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm',
			[
				'a' => new ComponentSlot('a', new StringType(), Certainty::HAPPENS, ['n2']),
				'b' => new ComponentSlot(
					'b',
					TypeCombinator::union(new IntegerType(), new NullType()),
					Certainty::MAYBE,
					['n3'],
				),
				'file' => new ComponentSlot(
					'file',
					new ObjectType('Nette\Http\FileUpload'),
					Certainty::HAPPENS,
					['n4'],
				),
			],
			['child' => $inner],
			['rep' => new ReplicatorShape($inner, $repOwn)],
			(new UnknownInfo())->withReason(UnknownReason::EXTENSION_METHOD)->withReason(UnknownReason::DYNAMIC_NAME),
			['n2', 'n3', 'n4'],
			['child' => Certainty::HAPPENS],
			['rep' => Certainty::MAYBE],
		);
	}

	public function testRoundTripsRepresentativeFormShapeLosslessly(): void
	{
		$cache = new FormShapeCache($this->dir);
		$cold = $this->representativeShape();
		$coldString = $cold->getClassName() . $cold->describe(VerbosityLevel::precise());

		$calls = 0;
		$stored = $cache->remember('hash1', 'node1', static function () use ($cold, &$calls): FormShape {
			$calls++;

			return $cold;
		});
		self::assertSame(1, $calls);
		self::assertSame($coldString, $stored->getClassName() . $stored->describe(VerbosityLevel::precise()));

		$warm = $cache->remember('hash1', 'node1', static function () use ($cold, &$calls): FormShape {
			$calls++;

			return $cold;
		});
		self::assertSame(1, $calls);
		self::assertSame(
			$coldString,
			$warm->getClassName() . $warm->describe(VerbosityLevel::precise()),
			'PHPStan Type objects must round-trip losslessly through serialize/unserialize',
		);
	}

	public function testClearForcesRecompute(): void
	{
		$cache = new FormShapeCache($this->dir);
		$shape = $this->representativeShape();

		$calls = 0;
		$compute = static function () use ($shape, &$calls): FormShape {
			$calls++;

			return $shape;
		};

		$cache->remember('hash1', 'node1', $compute);
		$cache->remember('hash1', 'node1', $compute);
		self::assertSame(1, $calls);

		$cache->clear();

		$cache->remember('hash1', 'node1', $compute);
		self::assertSame(2, $calls);
	}

	public function testDistinctKeysAreIndependentAndSameKeyIsCached(): void
	{
		$cache = new FormShapeCache($this->dir);

		$a = FormShape::empty('A');
		$b = FormShape::empty('B');

		$callsA = 0;
		$callsB = 0;

		$r1 = $cache->remember('h', 'n1', static function () use ($a, &$callsA): FormShape {
			$callsA++;

			return $a;
		});
		$r2 = $cache->remember('h', 'n2', static function () use ($b, &$callsB): FormShape {
			$callsB++;

			return $b;
		});
		self::assertSame('A', $r1->getClassName());
		self::assertSame('B', $r2->getClassName());
		self::assertSame(1, $callsA);
		self::assertSame(1, $callsB);

		$r1b = $cache->remember('h', 'n1', static function () use ($a, &$callsA): FormShape {
			$callsA++;

			return $a;
		});
		self::assertSame('A', $r1b->getClassName());
		self::assertSame(1, $callsA);

		// Different fileContentHash, same nodeId => independent entry (cache invalidation on edit).
		$callsAEdited = 0;
		$r1Edited = $cache->remember('h-edited', 'n1', static function () use (&$callsAEdited): FormShape {
			$callsAEdited++;

			return FormShape::empty('A-edited');
		});
		self::assertSame('A-edited', $r1Edited->getClassName());
		self::assertSame(1, $callsAEdited);
	}

	public function testCanonicalizerIsDescribeLosslessAndSerializable(): void
	{
		$shape = $this->representativeShape();
		$canonical = TypeCanonicalizer::canonicalizeShape($shape);

		self::assertSame(
			$shape->getClassName() . $shape->describe(VerbosityLevel::precise()),
			$canonical->getClassName() . $canonical->describe(VerbosityLevel::precise()),
		);

		$round = unserialize(serialize($canonical));
		self::assertInstanceOf(FormShape::class, $round);
		self::assertSame(
			$shape->getClassName() . $shape->describe(VerbosityLevel::precise()),
			$round->getClassName() . $round->describe(VerbosityLevel::precise()),
		);
	}

	public function testDifferentDefaultContainerClassRotatesVersionDirectory(): void
	{
		$a = new FormShapeCache($this->dir, null, null, null, 'Nette\\Forms\\Container');
		$a->remember('h', 'n', static fn (): FormShape => FormShape::empty('A'));

		$b = new FormShapeCache(
			$this->dir,
			null,
			null,
			null,
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer',
		);
		$b->remember('h', 'n', static fn (): FormShape => FormShape::empty('B'));

		$dirs = glob($this->dir . '/v*', GLOB_ONLYDIR);
		self::assertNotFalse($dirs);
		self::assertCount(
			2,
			$dirs,
			'a different orisaiNette.forms.defaultContainerClass must rotate the code-version directory',
		);
	}

	public function testSameDefaultContainerClassSharesVersionDirectory(): void
	{
		$a = new FormShapeCache($this->dir, null, null, null, 'Nette\\Forms\\Container');
		$a->remember('h', 'n', static fn (): FormShape => FormShape::empty('A'));

		$b = new FormShapeCache($this->dir, null, null, null, 'Nette\\Forms\\Container');
		$b->remember('h', 'n', static fn (): FormShape => FormShape::empty('B'));

		$dirs = glob($this->dir . '/v*', GLOB_ONLYDIR);
		self::assertNotFalse($dirs);
		self::assertCount(1, $dirs);
	}

	public function testCodeVersionIsStableAndDeterministic(): void
	{
		$version = FormsCodeVersion::get('Nette\\Forms\\Container');
		self::assertSame($version, FormsCodeVersion::get('Nette\\Forms\\Container'));
		self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $version);
	}

}
