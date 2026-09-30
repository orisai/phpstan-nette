<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function str_replace;
use function uniqid;
use const PHP_BINARY;

// Real-app-style spawn (production wiring, not PipelineFactory): proves the harvested-MACRO
// consumption end to end - a fixture engine-loader registers the real vendor gettext macro set
// (h4kuna\Gettext\Macros\Gettext, the same class GettextLatteExtension installs in production),
// and the spawned analysis run must compile {_}/{g_}/{ng_}/{dg_}/{dng_} through that real macro
// code (no orisaiNette.latte.unknownMacro) while an unrelated, genuinely unregistered macro name keeps
// reporting exactly what it reports today (passthrough stays the fallback).
/**
 * @group latte2
 */
final class HarvestedGettextMacroIntegrationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const GETTEXT_ENGINE_LOADER = __DIR__ . '/../../../Unit/Latte/Customs/Fixtures/engine-loader-gettext.php';

	public function testGettextFamilyMacrosCompileNativelyWithoutUnknownMacroDiagnostic(): void
	{
		$output = $this->spawnWithGettext(
			"{_'Hello'}\n{g_'Hi'}\n{ng_ 'one item', 'many items', \$count}\n"
			. "{dg_ 'domain', 'Domain text'}\n{dng_ 'domain', 'one item', 'many items', \$count}\n",
		);

		self::assertStringNotContainsString('Unknown Latte macro', $output);
		self::assertStringNotContainsString('orisaiNette.latte.unknownMacro', $output);
	}

	public function testGenuinelyUnknownMacroStillReportsUnknownMacroWithGettextEngineLoaderConfigured(): void
	{
		$output = $this->spawnWithGettext("{totallyUnknownMacroForTask4}\n");

		self::assertStringContainsString('Unknown Latte macro', $output);
	}

	private function spawnWithGettext(string $latte): string
	{
		return $this->spawn($latte, ['orisai.nette.latte.engineLoader' => self::GETTEXT_ENGINE_LOADER]);
	}

	/**
	 * @param array<string, bool|int|string|list<string>> $extraParameters
	 */
	private function spawn(string $latte, array $extraParameters): string
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $projectRoot . '/var/tmp/latte-harvested-gettext-test-' . uniqid('', true);
		FileSystem::createDir($scratch);
		$srcDir = $scratch . '/src';
		FileSystem::write($srcDir . '/target.latte', $latte);

		try {
			$isolated = LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				[$srcDir],
				$scratch . '/pstmp',
				$extraParameters,
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
				$process->setTimeout(120.0);
				$process->run();

				$output = str_replace($projectRoot . '/', '', $process->getOutput());

				return $output === '' ? '(no errors)' : $output;
			} finally {
				$isolated->cleanup();
			}
		} finally {
			FileSystem::delete($scratch);
		}
	}

}
