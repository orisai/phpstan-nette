<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Unit;

use OriPhpstan\Nette\Forms\Inference\EnclosingFunctionLikeLocator;
use OriPhpstan\Nette\Forms\Inference\FormFileIndex;
use OriPhpstan\Nette\Forms\Inference\IntraProceduralFormGate;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use PhpParser\Node\Stmt;
use PHPStan\Parser\Parser;
use ReflectionProperty;
use stdClass;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use function assert;

final class FormFileIndexTest extends FormShapeTestCase
{

	public function testParsesEachFileAtMostOnceAcrossManyQueries(): void
	{
		[$index, $counter] = $this->makeIndex();
		$file = __DIR__ . '/Support/NoFormFixture.php';

		for ($i = 0; $i < 50; $i++) {
			$index->hasAnyTrackedForm($file);
		}

		self::assertSame(1, $counter->count, 'FormFileIndex must parse a file at most once');
	}

	public function testNoFormFileReportsHasAnyTrackedFormFalse(): void
	{
		[$index] = $this->makeIndex();
		$file = __DIR__ . '/Support/NoFormFixture.php';

		self::assertFalse($index->hasAnyTrackedForm($file));
	}

	public function testSwitchingFileEvictsPreviousFileEntry(): void
	{
		[$index] = $this->makeIndex();
		$fileA = __DIR__ . '/Support/NoFormFixture.php';
		$fileB = __DIR__ . '/../Fixtures/Rule/MyNonFormComponent.php';

		$index->hasAnyTrackedForm($fileA);

		$filesProp = new ReflectionProperty(FormFileIndex::class, 'files');
		$filesProp->setAccessible(true);
		$fn = $filesProp->getValue($index)[$fileA]['fns'][0];
		$index->formShape($fileA, $fn, 'x', static fn (): FormShape => FormShape::empty());

		$shapesProp = new ReflectionProperty(FormFileIndex::class, 'shapes');
		$shapesProp->setAccessible(true);
		self::assertNotSame([], $shapesProp->getValue($index), 'precondition: formShape() must populate $shapes');

		$index->hasAnyTrackedForm($fileB);

		$files = $filesProp->getValue($index);
		self::assertCount(1, $files, 'FormFileIndex must retain only the current file');
		self::assertArrayHasKey($fileB, $files);

		self::assertSame([], $shapesProp->getValue($index), 'shapes owned by the evicted file must be evicted too');
	}

	/** @return array{FormFileIndex, stdClass} */
	private function makeIndex(): array
	{
		$real = self::getContainer()->getService('defaultAnalysisParser');
		assert($real instanceof Parser);
		$counter = new stdClass();
		$counter->count = 0;
		$spy = new class ($real, $counter) implements Parser {

			private Parser $inner;

			private stdClass $counter;

			public function __construct(Parser $inner, stdClass $counter)
			{
				$this->inner = $inner;
				$this->counter = $counter;
			}

			/** @return array<Stmt> */
			public function parseFile(string $file): array
			{
				$this->counter->count++;

				return $this->inner->parseFile($file);
			}

			/** @return array<Stmt> */
			public function parseString(string $sourceCode): array
			{
				return $this->inner->parseString($sourceCode);
			}

		};

		$index = new FormFileIndex($spy, new EnclosingFunctionLikeLocator(), new IntraProceduralFormGate());

		return [$index, $counter];
	}

}
