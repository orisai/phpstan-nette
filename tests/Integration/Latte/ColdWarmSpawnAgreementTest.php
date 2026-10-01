<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte;

use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LatteIntegrationSpawn;
use function getmypid;
use function in_array;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

final class ColdWarmSpawnAgreementTest extends BaseTestCase
{

	public function testColdAndWarmSpawnsSucceedAndAgree(): void
	{
		$tmpDir = sys_get_temp_dir() . '/latte-cold-warm-' . getmypid() . '-' . uniqid('', true);

		try {
			$cold = LatteIntegrationSpawn::analyse($tmpDir);
			$warm = LatteIntegrationSpawn::analyse($tmpDir);
		} finally {
			FileSystem::delete($tmpDir);
		}

		self::assertTrue(
			in_array($cold['exitCode'], [0, 1], true),
			"cold spawn must complete without crashing, got exit code {$cold['exitCode']}. Output: "
			. substr($cold['output'], 0, 500),
		);
		self::assertTrue(
			in_array($warm['exitCode'], [0, 1], true),
			"warm spawn must complete without crashing, got exit code {$warm['exitCode']}. Output: "
			. substr($warm['output'], 0, 500),
		);
		self::assertSame(
			$cold['output'],
			$warm['output'],
			'cold and warm spawns over the unchanged fixture directory must agree on the analysis result',
		);
	}

}
