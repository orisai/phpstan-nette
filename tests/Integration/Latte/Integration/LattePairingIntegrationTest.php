<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function dirname;
use function in_array;
use function str_replace;
use function uniqid;
use function usort;
use const PHP_BINARY;

// Real subprocess spawn (LatteDebugDumpIntegrationTest's pattern): proves LattePairingRule is
// registered live through wiring.neon's service graph (lattePhpRenderWalk/lattePhpFactsCache/
// lattePairingJudge), firing on plain PHP classes in an analysis run - no dump call involved.
final class LattePairingIntegrationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	public function testPairingConflictAndOpaqueFireInARealAnalysisRun(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		try {
			FileSystem::write($srcDir . '/SpawnTemplates.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace LatteSpawnPairing;

class SpawnBaseTemplate extends \Nette\Bridges\ApplicationLatte\Template
{

}

final class SpawnChildTemplate extends SpawnBaseTemplate
{

}

PHP);
			FileSystem::write($srcDir . '/SpawnDriftPresenter.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace LatteSpawnPairing;

/**
 * @property-read SpawnChildTemplate $template
 */
final class SpawnDriftPresenter
{

	protected function createTemplate(): SpawnBaseTemplate
	{
		return new SpawnBaseTemplate();
	}

}

PHP);
			FileSystem::write($srcDir . '/SpawnDynamicControl.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace LatteSpawnPairing;

final class SpawnDynamicControl
{

	/** @var \Nette\Application\UI\Template|\stdClass */
	public $template;

	/** @var string */
	public $templateClassName = SpawnBaseTemplate::class;

	protected function getTemplateClass(): string
	{
		return $this->templateClassName;
	}

}

PHP);

			$messages = $this->pairingMessages(
				$projectRoot,
				$srcDir,
				$scratch . '/pstmp',
				['orisaiNette.latte.firstPartyPaths' => [$srcDir]],
			);

			self::assertCount(2, $messages);

			self::assertSame('orisaiNette.latte.pairingConflict', $messages[0]['identifier']);
			self::assertSame("$relSrc/SpawnDriftPresenter.php", $messages[0]['file']);
			self::assertSame(13, $messages[0]['line']);
			self::assertTrue($messages[0]['ignorable'], 'pairing conflicts must stay baselineable');
			self::assertSame(
				'Template class pairing conflict: LatteSpawnPairing\SpawnChildTemplate (phpdoc, genericBinding)'
				. ' vs LatteSpawnPairing\SpawnBaseTemplate (new).',
				$messages[0]['message'],
			);

			self::assertSame('orisaiNette.latte.pairingOpaque', $messages[1]['identifier']);
			self::assertSame("$relSrc/SpawnDynamicControl.php", $messages[1]['file']);
			self::assertSame(16, $messages[1]['line']);
			self::assertTrue($messages[1]['ignorable'], 'pairing opaques must stay baselineable');
			self::assertSame('Template class pairing is opaque in channel convention.', $messages[1]['message']);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	/**
	 * @param array<string, bool|int|string|list<string>> $extraParameters
	 * @return list<array{file: string, message: string, line: int, ignorable: bool, identifier: string}>
	 */
	private function pairingMessages(
		string $projectRoot,
		string $srcDir,
		string $tmpDir,
		array $extraParameters = []
	): array
	{
		$isolated = LattePhpstanConfig::create(self::REAL_CONFIG_PATH, [$srcDir], $tmpDir, $extraParameters);

		try {
			$process = new Process(
				[
					PHP_BINARY,
					$projectRoot . '/vendor/bin/phpstan',
					'analyse',
					'--no-progress',
					'--level=8',
					'--error-format=json',
					'-c',
					$isolated->getConfigPath(),
				],
				$projectRoot,
			);
			$process->run();

			/** @var array{files: array<string, array{messages: list<array{message: string, line: int, ignorable: bool, identifier: string}>}>} $decoded */
			$decoded = Json::decode($process->getOutput(), Json::FORCE_ARRAY);

			$messages = [];
			foreach ($decoded['files'] as $file => $fileMessages) {
				foreach ($fileMessages['messages'] as $message) {
					if (!in_array(
						$message['identifier'],
						['orisaiNette.latte.pairingConflict', 'orisaiNette.latte.pairingOpaque'],
						true,
					)) {
						continue;
					}

					$message['file'] = str_replace($projectRoot . '/', '', $file);
					$messages[] = $message;
				}
			}

			usort($messages, static fn (array $a, array $b): int => $a['file'] <=> $b['file']);

			return $messages;
		} finally {
			$isolated->cleanup();
		}
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir - see LatteDebugDumpIntegrationTest's own note on
		// ProjectRelativePath::relativize.
		$dir = $projectRoot . '/var/tmp/latte-pairing-integration-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

}
