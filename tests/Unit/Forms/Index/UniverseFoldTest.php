<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index;

use Nette\Utils\Json;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_merge;
use function dirname;
use function preg_match;
use function strpos;
use function trim;
use const PHP_BINARY;

/**
 * B5: the RegistrationIndex universe folds from the config's declared paths, not phpstan's
 * CLI-narrowed analysedPaths, so a single-file/IDE run still sees registrations living in a
 * different, non-CLI-listed file. Consumer.php (the only CLI-listed file here) carries the
 * handler-param candidate; RegistersOrderForm.php (reachable only through shadow-compare.neon's
 * declared paths, never CLI-listed by this test) carries the createComponentOrder registration
 * Consumer's trait pulls in.
 *
 * A CLI-narrowed universe would miss the trait's registration (its declaring file is never
 * CLI-listed by this test), so the index would report nothing for this key. Because the fold reads
 * the config's declared paths, the index resolves the cross-file registration and the rule renders
 * its real shape (carrying the `note` field) for the single CLI-listed Consumer.php run.
 */
final class UniverseFoldTest extends BaseTestCase
{

	private const KEY = 'mp:Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\UniverseFold\Consumer::orderSucceeded#0';

	public function testHandlerRegisteredInANonCliListedFileStillResolves(): void
	{
		$file = __DIR__ . '/Fixtures/UniverseFold/Consumer.php';
		$isolated = IsolatedPhpstanConfig::create(__DIR__ . '/shadow-compare.neon');

		try {
			$indexRender = $this->indexRenderingFor($isolated->getConfigPath(), $file);

			self::assertNotNull(
				$indexRender,
				'the index alone must resolve the trait-declared registration living in a non-CLI-listed file '
					. '— no index rendering was reported for ' . self::KEY,
			);
			self::assertStringContainsString(
				'note',
				$indexRender,
				'the resolved shape must carry the field the trait-declared createComponentOrder adds',
			);
		} finally {
			$isolated->cleanup();
		}
	}

	private function indexRenderingFor(string $configFile, string $file): ?string
	{
		$root = dirname(__DIR__, 4);
		$process = new Process(
			array_merge(
				[PHP_BINARY, $root . '/' . VendorDirectory::name() . '/bin/phpstan', 'analyse', $file],
				[
					'-c',
					$configFile,
					'--error-format=json',
					'--no-progress',
					'--memory-limit=2048M',
				],
			),
			$root,
		);
		$process->setTimeout(600.0);
		$process->run();

		/** @var array{files?: array<string, array{messages?: list<array{identifier?: string, message: string}>}>} $decoded */
		$decoded = Json::decode($process->getOutput(), Json::FORCE_ARRAY);

		foreach ($decoded['files'] ?? [] as $info) {
			foreach ($info['messages'] ?? [] as $message) {
				if (($message['identifier'] ?? '') !== 'orisai.nette.forms.shadowDivergence') {
					continue;
				}

				if (strpos($message['message'], self::KEY) === false) {
					continue;
				}

				if (preg_match('~index = (.*)$~s', $message['message'], $m) === 1) {
					return trim($m[1]);
				}
			}
		}

		return null;
	}

}
