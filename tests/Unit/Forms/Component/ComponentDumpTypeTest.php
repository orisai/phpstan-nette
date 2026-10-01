<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component;

use PHPStan\Type\IntegerType;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use function assert;
use function glob;

final class ComponentDumpTypeTest extends BatchedDumpTypeTestCase
{

	/** @return list<string> */
	protected static function fixtureFiles(): array
	{
		$files = glob(__DIR__ . '/Fixtures/DumpType/*.php');
		assert($files !== false);

		return $files;
	}

	protected static function configFile(): string
	{
		return __DIR__ . '/component-real.neon';
	}

	protected static function skipReason(string $fixture): ?string
	{
		if (
			$fixture === 'ReplicatorDecimalStringOffsetResolves.php'
			&& !(new IntegerType())->toString()->isDecimalIntegerString()->yes()
		) {
			return 'requires a PHPStan which types (string) $int as decimal-int-string';
		}

		if ($fixture === 'CmGetComponentsControls.php') {
			return InstalledVersionsGuard::unmetReason('nette/component-model', '~3.0.0')
				?? InstalledVersionsGuard::unmetReason('nette/forms', '<3.3');
		}

		if ($fixture === 'CmGetComponentsIterable.php') {
			return InstalledVersionsGuard::unmetReason('nette/component-model', '~3.2.0')
				?? InstalledVersionsGuard::unmetReason('nette/forms', '<3.3');
		}

		if ($fixture === 'CmGetComponentsArray.php') {
			return InstalledVersionsGuard::skipReason(['componentModel4'])
				?? InstalledVersionsGuard::unmetReason('nette/forms', '^3.3');
		}

		return null;
	}

}
