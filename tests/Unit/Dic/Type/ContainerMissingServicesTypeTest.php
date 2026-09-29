<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Type;

use Nette\DI\Container;
use OriPhpstan\Nette\Dic\Type\ContainerMissingServicesType;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Accessory\HasMethodType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;

final class ContainerMissingServicesTypeTest extends PHPStanTestCase
{

	use VersionGroupGate;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::getContainer();
	}

	public function testDescribeAndNameNormalization(): void
	{
		$marker = new ContainerMissingServicesType(['createServiceB', 'createServiceA', 'createServiceB']);
		self::assertSame(
			'Nette\DI\Container~service:createServiceA,createServiceB',
			$marker->describe(VerbosityLevel::precise()),
		);
	}

	public function testEquals(): void
	{
		$markerA = new ContainerMissingServicesType(['createServiceA']);
		self::assertTrue($markerA->equals(new ContainerMissingServicesType(['createServiceA'])));
		self::assertFalse($markerA->equals(new ContainerMissingServicesType(['createServiceB'])));
		self::assertFalse($markerA->equals(new ObjectType(Container::class)));
		self::assertFalse((new ObjectType(Container::class))->equals($markerA));
	}

	public function testMarkerSurvivesIntersectWithPlainContainer(): void
	{
		$marker = new ContainerMissingServicesType(['createServiceA']);
		$intersected = TypeCombinator::intersect(new ObjectType(Container::class), $marker);
		self::assertSame(
			'Nette\DI\Container~service:createServiceA',
			$intersected->describe(VerbosityLevel::precise()),
		);
	}

	public function testMarkerCollapsesInUnionWithPlainContainer(): void
	{
		$marker = new ContainerMissingServicesType(['createServiceA']);
		$union = TypeCombinator::union(new ObjectType(Container::class), $marker);
		self::assertSame('Nette\DI\Container', $union->describe(VerbosityLevel::precise()));
	}

	public function testProbeDetection(): void
	{
		$probe = new ContainerMissingServicesType(['createServiceA']);
		$markerWide = new ContainerMissingServicesType(['createServiceA', 'createServiceB']);

		self::assertTrue($probe->isSuperTypeOf($markerWide)->yes());
		self::assertFalse($markerWide->isSuperTypeOf($probe)->yes());
		self::assertFalse($probe->isSuperTypeOf(new ObjectType(Container::class))->yes());

		$intersection = TypeCombinator::intersect($markerWide, new HasMethodType('createServiceC'));
		self::assertTrue($probe->isSuperTypeOf($intersection)->yes());
	}

}
