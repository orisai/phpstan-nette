<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function trim;
use const PHP_BINARY;

// Independent of IntegrationSnapshotTest's committed text: a broken/invalid template must always
// flag through spawned phpstan, never crash, never silently produce zero errors.
final class InvalidCodeSnapshotTest extends BaseTestCase
{

	/**
	 * @dataProvider provideInvalidFixtures
	 */
	public function testInvalidTemplateProducesNonEmptyErrorSet(string $fixture): void
	{
		$projectRoot = dirname(__DIR__, 4);
		// Fixtures/integration.neon turns narrowing on; LattePhpstanConfig::create() defaults
		// orisaiNette.latte.narrowing.storePath to scratch, so this spawn never touches the default
		// phpstan-latte-store/.
		$isolated = LattePhpstanConfig::create(
			__DIR__ . '/Fixtures/integration.neon',
			[__DIR__ . '/Fixtures/' . $fixture],
		);

		try {
			$process = new Process(
				[
					PHP_BINARY,
					$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
					'analyse',
					'--no-progress',
					'--level=8',
					'--error-format=raw',
					'-c',
					$isolated->getConfigPath(),
				],
				$projectRoot,
			);
			$process->run();

			$output = $process->getOutput();
			self::assertStringNotContainsString('Internal error', $output, "$fixture must never crash the analyser");
			self::assertNotSame('', trim($output), "$fixture must produce at least one error");
		} finally {
			$isolated->cleanup();
		}
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public function provideInvalidFixtures(): iterable
	{
		yield 'broken.latte' => ['broken.latte'];
		yield 'errors.latte' => ['errors.latte'];
		yield 'malformed-parameters.latte' => ['malformed-parameters.latte'];
		yield 'malformed-define.latte' => ['malformed-define.latte'];
	}

}
