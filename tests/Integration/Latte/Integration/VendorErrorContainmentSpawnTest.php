<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function dirname;
use const PHP_BINARY;

// ResultCacheManager's ExportedNodeFetcher parses each analysed file through LatteRoutingParser
// BEFORE FileAnalyser's own collectErrors() handler is installed, on every cold/invalidated result
// cache - LattePhpstanConfig::create() mints a fresh tmpDir (and therefore a fresh result cache) by
// default, so every spawn here is that cold path, the empirically-leaking one.
final class VendorErrorContainmentSpawnTest extends BaseTestCase
{

	public function testDeprecatedConstructNeverLeaksRawPhpWarningTextOnAColdSpawn(): void
	{
		$output = $this->spawn();

		self::assertStringNotContainsString(
			'Warning:',
			$output,
			'vendor Latte trigger_error() output must never reach stdout as raw PHP warning text',
		);
		self::assertStringNotContainsString(
			'Deprecated:',
			$output,
			'vendor Latte trigger_error() output must never reach stdout as raw PHP warning text',
		);
		self::assertStringNotContainsString('Internal error', $output);
	}

	public function testDeprecatedConstructStillSurfacesAsAnOrdinaryPhpstanFinding(): void
	{
		$output = $this->spawn();

		self::assertStringContainsString('Unnecessary n:ifcontent', $output);
	}

	private function spawn(): string
	{
		$projectRoot = dirname(__DIR__, 4);
		$isolated = LattePhpstanConfig::create(
			__DIR__ . '/Fixtures/integration.neon',
			[__DIR__ . '/Fixtures/deprecated-ifcontent.latte'],
		);

		try {
			$process = new Process(
				[
					PHP_BINARY,
					$projectRoot . '/vendor/bin/phpstan',
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

			return $process->getOutput() . $process->getErrorOutput();
		} finally {
			$isolated->cleanup();
		}
	}

}
