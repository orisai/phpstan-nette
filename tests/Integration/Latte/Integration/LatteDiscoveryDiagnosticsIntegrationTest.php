<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use OriPhpstan\Nette\Latte\Rule\LatteDiscoveryRule;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function dirname;
use function in_array;
use function str_replace;
use function uniqid;
use function usort;
use const PHP_BINARY;

// Real subprocess spawn (LattePairingIntegrationTest's pattern): proves LatteDiscoveryRule is
// registered live through wiring.neon's service graph (lattePhpRenderWalk/lattePhpFactsCache plus
// the latteDiscoveryResolver seam behind them), firing on plain PHP presenters in an analysis run.
final class LatteDiscoveryDiagnosticsIntegrationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	public function testDiscoveryOpaqueAndIneffectiveMutationFireInARealAnalysisRun(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		try {
			// Nowdoc: the spawned phpstan already bootstraps the project autoloader (integration.neon's
			// bootstrapFiles), so the loader needs no require of its own.
			FileSystem::write($scratch . '/mapping-loader.php', <<<'PHP'
<?php declare(strict_types = 1);

return [
	'spawn' => new class extends \Nette\DI\Container {

		public function getByType(string $type, bool $throw = true): ?object
		{
			$factory = new \Nette\Application\PresenterFactory();
			$factory->setMapping(['Spawn' => 'LatteSpawnDiscovery\\*Presenter']);

			return $factory instanceof $type ? $factory : null;
		}

	},
];

PHP);
			FileSystem::write($srcDir . '/SpawnDynamicFilePresenter.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace LatteSpawnDiscovery;

final class SpawnDynamicFilePresenter extends \Nette\Application\UI\Presenter
{

	public function actionDefault(): void
	{
		$path = $this->buildPath();
		$this->getTemplate()->setFile($path);
	}

	private function buildPath(): string
	{
		return 'spawn-dynamic.latte';
	}

}

PHP);
			FileSystem::write($srcDir . '/SpawnLatePresenter.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace LatteSpawnDiscovery;

final class SpawnLatePresenter extends \Nette\Application\UI\Presenter
{

	protected function shutdown(\Nette\Application\Response $response): void
	{
		$this->setView('never');
	}

}

PHP);

			$messages = $this->discoveryMessages(
				$projectRoot,
				$srcDir,
				$scratch . '/pstmp',
				[
					'orisaiNette.latte.firstPartyPaths' => [$srcDir],
					'orisaiNette.latte.templateFactoryContainerLoader' => $scratch . '/mapping-loader.php',
				],
			);

			self::assertCount(2, $messages);

			self::assertSame(LatteDiscoveryRule::OPAQUE_IDENTIFIER, $messages[0]['identifier']);
			self::assertSame("$relSrc/SpawnDynamicFilePresenter.php", $messages[0]['file']);
			self::assertSame(11, $messages[0]['line']);
			self::assertTrue($messages[0]['ignorable'], 'discovery opaques must stay baselineable');
			self::assertSame(
				'Template file discovery is opaque: setFile argument is not statically resolvable.',
				$messages[0]['message'],
			);

			self::assertSame(LatteDiscoveryRule::MUTATION_IDENTIFIER, $messages[1]['identifier']);
			self::assertSame("$relSrc/SpawnLatePresenter.php", $messages[1]['file']);
			self::assertSame(10, $messages[1]['line']);
			self::assertTrue($messages[1]['ignorable'], 'ineffective mutations must stay baselineable');
			self::assertSame(
				'Call to setView() has no effect at this point of the presenter lifecycle.',
				$messages[1]['message'],
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	/**
	 * @param array<string, bool|int|string|list<string>> $extraParameters
	 * @return list<array{file: string, message: string, line: int, ignorable: bool, identifier: string}>
	 */
	private function discoveryMessages(
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
					$identifiers = [LatteDiscoveryRule::OPAQUE_IDENTIFIER, LatteDiscoveryRule::MUTATION_IDENTIFIER];
					if (!in_array($message['identifier'], $identifiers, true)) {
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
		$dir = $projectRoot . '/var/tmp/latte-discovery-integration-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

}
