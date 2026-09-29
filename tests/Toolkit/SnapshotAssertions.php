<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use Nette\Utils\FileSystem;
use function getenv;

trait SnapshotAssertions
{

	protected function assertSnapshot(string $snapshotPath, string $actual): void
	{
		if (getenv('UPDATE_SNAPSHOTS') === '1') {
			FileSystem::write($snapshotPath, $actual);
			self::assertFileExists($snapshotPath);

			return;
		}

		self::assertFileExists(
			$snapshotPath,
			"Committed snapshot $snapshotPath is missing. "
			. 'Run with UPDATE_SNAPSHOTS=1 to generate it, then review the diff before committing.',
		);
		self::assertStringEqualsFile($snapshotPath, $actual);
	}

}
