<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component;

use function assert;
use function glob;

final class ComponentPhp84DumpTypeTest extends BatchedDumpTypeTestCase
{

	/** @return list<string> */
	protected static function fixtureFiles(): array
	{
		$files = glob(__DIR__ . '/Fixtures/DumpTypePhp84/*.php');
		assert($files !== false);

		return $files;
	}

	protected static function configFile(): string
	{
		return __DIR__ . '/component-php84.neon';
	}

}
