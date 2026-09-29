<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms;

use Nette\Utils\Json;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function glob;
use function implode;
use const PHP_BINARY;

/**
 * Drives the migrated Matrix fixtures through a real PHPStan analysis: each assertComponent()
 * / assertFormValues() call renders the resolved shape via the production ContainerModel and a
 * mismatch is reported as an error. A green run means every fixture shape matches its asserted
 * literal, exercised through the real extension wiring rather than the analyzer in isolation.
 */
final class MatrixAssertTest extends BaseTestCase
{

	public function testEveryMatrixAssertionMatches(): void
	{
		$root = dirname(__DIR__, 3);

		$files = glob(dirname(__DIR__, 2) . '/Doubles/Forms/MatrixAssert/*.php');
		self::assertNotFalse($files);
		self::assertNotSame([], $files);

		$isolated = IsolatedPhpstanConfig::create(dirname(__DIR__, 2) . '/Unit/Forms/Component/component-php84.neon');

		try {
			$process = new Process(
				[
					PHP_BINARY,
					$root . '/' . VendorDirectory::name() . '/bin/phpstan',
					'analyse',
					...$files,
					'-c',
					$isolated->getConfigPath(),
					'--error-format=json',
					'--no-progress',
					'--memory-limit=2048M',
				],
				$root,
			);
			$process->setTimeout(600.0);
			$process->run();

			/** @var array{files?: array<string, array{messages?: list<array{identifier?: string, line: int, message: string}>}>} $decoded */
			$decoded = Json::decode($process->getOutput(), Json::FORCE_ARRAY);
			$mismatches = [];
			foreach ($decoded['files'] ?? [] as $file => $info) {
				foreach ($info['messages'] ?? [] as $message) {
					$identifier = $message['identifier'] ?? '';
					if (
						$identifier === 'orisaiNette.forms.componentShapeAssert'
						|| $identifier === 'orisaiNette.forms.formValuesAssert'
					) {
						$mismatches[] = $file . ':' . $message['line'] . "\n" . $message['message'];
					}
				}
			}

			self::assertSame([], $mismatches, implode("\n\n", $mismatches));
		} finally {
			$isolated->cleanup();
		}
	}

}
