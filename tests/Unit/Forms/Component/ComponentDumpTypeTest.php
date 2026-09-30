<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component;

use PHPStan\Type\IntegerType;
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

		return null;
	}

}
