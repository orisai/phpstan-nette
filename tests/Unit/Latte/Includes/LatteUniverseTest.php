<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use LogicException;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_keys;
use function dirname;
use function sort;
use function sys_get_temp_dir;

final class LatteUniverseTest extends BaseTestCase
{

	public function testEnumeratesSortedLatteFiles(): void
	{
		$fixturesDir = dirname(__DIR__) . '/Fixtures';
		$universe = new LatteUniverse([$fixturesDir], dirname(__DIR__, 3));
		$files = $universe->files();

		self::assertNotSame([], $files);
		$sorted = $files;
		sort($sorted);
		self::assertSame($sorted, $files);
		foreach ($files as $file) {
			self::assertStringEndsWith('.latte', $file);
		}
	}

	public function testMissingPathSkippedAndFileEntryAccepted(): void
	{
		$fixturesDir = dirname(__DIR__) . '/Fixtures';
		$universe = new LatteUniverse(
			[$fixturesDir . '/declarations.latte', $fixturesDir . '/does-not-exist'],
			dirname(__DIR__, 3),
		);

		self::assertSame([$fixturesDir . '/declarations.latte'], $universe->files());
	}

	public function testContentHashesKeyedSortedAndStable(): void
	{
		$fixturesDir = dirname(__DIR__) . '/Fixtures';
		$universe = new LatteUniverse([$fixturesDir], dirname(__DIR__, 3));

		self::assertSame($universe->contentHashes(), $universe->contentHashes());
		self::assertSame($universe->files(), array_keys($universe->contentHashes()));
	}

	public function testFileOutsideProjectRootThrowsLogicException(): void
	{
		$fixturesDir = dirname(__DIR__) . '/Fixtures';
		$fakeProjectRoot = sys_get_temp_dir() . '/latte-universe-fake-root';
		$universe = new LatteUniverse([$fixturesDir], $fakeProjectRoot);

		try {
			$universe->files();
			self::fail('Expected LogicException for a discovered file outside projectRoot.');
		} catch (LogicException $e) {
			self::assertStringContainsString($fixturesDir, $e->getMessage());
			self::assertStringContainsString($fakeProjectRoot, $e->getMessage());
		}
	}

}
