<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Toolkit;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function dirname;
use function is_array;

// PHPStan resolves an unset phpVersion to the running PHP, which is what every profile run needs:
// the fixtures and vendor signatures are those of the interpreter the suite runs on.
final class HarnessPhpVersionTest extends BaseTestCase
{

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function provideUnpinnedConfigs(): iterable
	{
		$tests = dirname(__DIR__, 2);

		yield 'component-real.neon' => [$tests . '/Unit/Forms/Component/component-real.neon'];
		yield 'Forms invalidation.neon' => [$tests . '/Integration/Forms/Fixtures/invalidation.neon'];
		yield 'Dic invalidation.neon' => [$tests . '/Integration/Dic/Fixtures/invalidation.neon'];
	}

	/**
	 * @dataProvider provideUnpinnedConfigs
	 */
	public function testHarnessConfigLeavesPhpVersionToTheRuntime(string $config): void
	{
		$decoded = Neon::decode(FileSystem::read($config));

		self::assertIsArray($decoded);
		self::assertTrue(is_array($decoded['parameters'] ?? null));
		self::assertArrayNotHasKey('phpVersion', $decoded['parameters']);
	}

}
