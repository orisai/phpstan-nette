<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Toolkit;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function dirname;
use const PHP_VERSION_ID;

/**
 * The permanent regression pin for the reusable invalidation harness.
 *
 * PHPStan's JSON report has a SECOND top-level key besides `files`: `errors`, holding
 * getNotFileSpecificErrors() - invalid ignore entries, a crashed analysis worker, internal
 * errors. A decode path that reads only `files` turns such a report into "(no errors)", which every
 * scenario step whose expectation is the empty set then passes vacuously - in the one class that
 * exists to stop scenarios passing vacuously. The bug was fixed without a test; this is that test.
 *
 * The trigger used here is the cheapest reproducible one: an `ignoreErrors` entry with a path but
 * neither a message nor an identifier, which PHPStan reports as `{"files":{},"errors":[…]}` with
 * exit code 1. A never-matching pattern no longer does: PHPStan 2.3 reports it at its line in the
 * config file.
 */
final class InvalidationScenarioTest extends BaseTestCase
{

	public function testNotFileSpecificErrorsAreDecodedAsErrors(): void
	{
		$scenario = InvalidationScenario::create(
			dirname(__DIR__, 3),
			'harness-pin',
			static function (array $paths, string $tmpDir): string {
				FileSystem::createDir($tmpDir);
				$configPath = $tmpDir . '/pin.neon';
				FileSystem::write($configPath, Neon::encode(
					[
						'parameters' => [
							'customRulesetUsed' => true,
							'level' => 8,
							'phpVersion' => PHP_VERSION_ID,
							'tmpDir' => $tmpDir,
							// Plain `paths`, not the `paths!` override every other scenario uses: this
							// config includes nothing, and the override marker has no key to override.
							'paths' => [$paths[0]],
							'ignoreErrors' => [['path' => $paths[0]]],
						],
					],
					true,
				));

				return $configPath;
			},
		);

		try {
			// Deliberately declares nothing: the report has to be file-error-free for the
			// not-file-specific channel to be the ONLY thing under test.
			$scenario->write('scratch.php', "<?php declare(strict_types = 1);\n");

			$run = $scenario->run();

			self::assertNotSame(
				'(no errors)',
				$run->getErrorText(),
				'a report the analysis signalled as failing must never decode as the empty error set',
			);
			self::assertStringStartsWith('(not file-specific) :: Ignored error {"path":', $run->getErrorText());
			self::assertStringEndsWith('} is missing a message or an identifier.', $run->getErrorText());
		} finally {
			$scenario->cleanup();
		}
	}

}
