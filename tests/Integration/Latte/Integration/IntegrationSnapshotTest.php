<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function explode;
use function getenv;
use function implode;
use function rtrim;
use function sort;
use function str_replace;
use const PHP_BINARY;

final class IntegrationSnapshotTest extends BaseTestCase
{

	private const EXPECTED_FILE = __DIR__ . '/expected/integration.txt';

	public function testIntegrationSnapshot(): void
	{
		$actual = $this->analyseFixturesDirectory(dirname(__DIR__, 4));

		if (getenv('UPDATE_SNAPSHOTS') === '1') {
			FileSystem::write(self::EXPECTED_FILE, $actual);
			self::assertFileExists(self::EXPECTED_FILE);

			return;
		}

		self::assertFileExists(
			self::EXPECTED_FILE,
			'Committed snapshot tests/Integration/Latte/Integration/expected/integration.txt is missing. '
			. 'Run with UPDATE_SNAPSHOTS=1 to generate it, then review the diff before committing.',
		);
		self::assertSame(FileSystem::read(self::EXPECTED_FILE), $actual);
	}

	// Two independent cold spawns (separate tmpDir/result-cache each, via LattePhpstanConfig::create)
	// must agree with each other, not just with the committed snapshot - guards the map
	// LatteTemplateSourceLocator builds from %paths% (Nette\Utils\Finder + sort) against any
	// ordering-sensitive drift.
	public function testIntegrationSnapshotIsDeterministic(): void
	{
		$projectRoot = dirname(__DIR__, 4);

		self::assertSame(
			$this->analyseFixturesDirectory($projectRoot),
			$this->analyseFixturesDirectory($projectRoot),
		);
	}

	// Directory-shaped paths is the real-world configuration shape (a project's own `paths` entry
	// points at a directory, not an enumerated file list): a single spawn analyses every fixture
	// together, exercising LatteTemplateSourceLocator instead of the single-file
	// OptimizedSingleFileSourceLocator reflection shortcut a single named fixture would take.
	private function analyseFixturesDirectory(string $projectRoot): string
	{
		// Fixtures/integration.neon turns narrowing on; LattePhpstanConfig::create() defaults
		// orisaiNette.latte.narrowing.storePath to scratch, so this spawn never touches the default
		// phpstan-latte-store/.
		$isolated = LattePhpstanConfig::create(
			__DIR__ . '/Fixtures/integration.neon',
			[__DIR__ . '/Fixtures'],
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

			return $this->normalize($process->getOutput(), $projectRoot);
		} finally {
			$isolated->cleanup();
		}
	}

	private function normalize(string $raw, string $projectRoot): string
	{
		$lines = [];
		foreach (explode("\n", str_replace($projectRoot . '/', '', $raw)) as $line) {
			$line = rtrim($line);
			if ($line !== '') {
				$lines[] = $line;
			}
		}

		if ($lines === []) {
			return "(no errors)\n";
		}

		sort($lines);

		return implode("\n", $lines) . "\n";
	}

}
