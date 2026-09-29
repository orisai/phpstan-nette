<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Cache\TypeCanonicalizer;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\UnionType;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function get_class;

final class TypeCanonicalizerTest extends PHPStanTestCase
{

	use VersionGroupGate;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::getContainer();
	}

	public function testUnhandledCompoundTypeDegradesToMixed(): void
	{
		$unhandled = new class ([new ObjectType('Foo'), new ObjectType('Bar')]) extends IntersectionType
		{

		};

		self::assertInstanceOf(MixedType::class, TypeCanonicalizer::canonicalize($unhandled));
	}

	// BenevolentUnionType lives only inside the PHPStan phar runtime and cannot be constructed
	// from a plain test process, so its benevolence-preserving rebuild is exercised only through
	// a real analysis; a plain union must still rebuild as a plain (non-benevolent) union.
	public function testPlainUnionStaysPlain(): void
	{
		$union = new UnionType([new IntegerType(), new StringType()]);

		self::assertSame(UnionType::class, get_class(TypeCanonicalizer::canonicalize($union)));
	}

}
