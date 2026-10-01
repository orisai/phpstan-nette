<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component;

use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use function assert;
use function glob;

final class ComponentFormsDisabledDumpTypeTest extends BatchedDumpTypeTestCase
{

	/** @return list<string> */
	protected static function fixtureFiles(): array
	{
		$files = glob(__DIR__ . '/Fixtures/DumpTypeFormsDisabled/*.php');
		assert($files !== false);

		return $files;
	}

	protected static function configFile(): string
	{
		return __DIR__ . '/component-forms-disabled.neon';
	}

	protected static function skipReason(string $fixture): ?string
	{
		return InstalledVersionsGuard::skipReason(['componentModel4']);
	}

}
