<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Cache\DependencyRecorder;
use OriPhpstan\Nette\Forms\Cache\ShapeDependencyCollector;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;

final class ShapeDependencyCollectorTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private function collector(DependencyRecorder $recorder): ShapeDependencyCollector
	{
		return new ShapeDependencyCollector(self::createReflectionProvider(), $recorder);
	}

	public function testRecordsDefaultContainerClassFallbackFileForNestedContainer(): void
	{
		$recorder = new DependencyRecorder();
		$recorder->beginFrame();

		$inner = FormShape::empty(FormContainer::class);
		$shape = new FormShape(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm',
			[],
			['child' => $inner],
			[],
			new UnknownInfo(),
			[],
		);

		$this->collector($recorder)->record($shape);
		$deps = $recorder->endFrame();

		$file = (new ReflectionClass(FormContainer::class))->getFileName();
		self::assertNotFalse($file);
		self::assertArrayHasKey($file, $deps);
	}

	public function testRecordsDefaultContainerClassFallbackFileForReplicatorInner(): void
	{
		$recorder = new DependencyRecorder();
		$recorder->beginFrame();

		$inner = FormShape::empty(FormContainer::class);
		$shape = new FormShape(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm',
			[],
			[],
			['rep' => new ReplicatorShape($inner, FormShape::empty())],
			new UnknownInfo(),
			[],
		);

		$this->collector($recorder)->record($shape);
		$deps = $recorder->endFrame();

		$file = (new ReflectionClass(FormContainer::class))->getFileName();
		self::assertNotFalse($file);
		self::assertArrayHasKey($file, $deps);
	}

}
