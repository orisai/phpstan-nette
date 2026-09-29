<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component;

use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use function assert;
use function glob;

final class ReplicatorRowsTypeTest extends BatchedDumpTypeTestCase
{

	/** @return list<string> */
	protected static function fixtureFiles(): array
	{
		$major = InstalledVersionsGuard::satisfies('kdyby/forms-replicator', '^3.0') ? 'Kdyby3' : 'Kdyby2';
		$files = glob(__DIR__ . '/Fixtures/ReplicatorRows/' . $major . '/*.php');
		assert($files !== false);

		return $files;
	}

	protected static function configFile(): string
	{
		return __DIR__ . '/replicator-rows.neon';
	}

}
