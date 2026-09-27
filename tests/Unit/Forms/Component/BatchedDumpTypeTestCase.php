<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use function array_map;
use function basename;
use function dirname;
use function preg_match_all;
use function sprintf;
use function trim;
use function usort;
use const PHP_BINARY;
use const PREG_SET_ORDER;

/**
 * Runs every `// => Type` dumpType fixture in a SINGLE PHPStan analysis (the process and
 * container boot dominate, so one run for all fixtures is ~80x faster than spawning a
 * process per fixture) and asserts each fixture's dumped types against its comments. The
 * batched result is memoised per concrete test class.
 */
abstract class BatchedDumpTypeTestCase extends BaseTestCase
{

	/** @var array<class-string, array<string, list<string>>> */
	private static array $dumpedByClass = [];

	/** @return list<string> */
	abstract protected static function fixtureFiles(): array;

	abstract protected static function configFile(): string;

	/** @return iterable<string, array{string}> */
	public static function dataFixtures(): iterable
	{
		foreach (static::fixtureFiles() as $file) {
			yield basename($file) => [$file];
		}
	}

	/**
	 * @dataProvider dataFixtures
	 */
	public function testFixture(string $file): void
	{
		$source = FileSystem::read($file);
		preg_match_all('~//\s*=>\s*(.+)$~m', $source, $m);
		$expected = array_map('trim', $m[1]);
		self::assertNotSame([], $expected, sprintf('Fixture %s has no `// => ` expectations', $file));

		self::assertSame(
			$expected,
			self::dumpedTypes()[basename($file)] ?? [],
			sprintf('dumpType mismatch in %s', basename($file)),
		);
	}

	/**
	 * @return array<string, list<string>>
	 */
	private static function dumpedTypes(): array
	{
		if (isset(self::$dumpedByClass[static::class])) {
			return self::$dumpedByClass[static::class];
		}

		$root = dirname(__DIR__, 4);
		$isolated = IsolatedPhpstanConfig::create(static::configFile());

		try {
			$args = [PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse'];
			foreach (static::fixtureFiles() as $file) {
				$args[] = $file;
			}

			$args[] = '-c';
			$args[] = $isolated->getConfigPath();
			$args[] = '--error-format=raw';
			$args[] = '--no-progress';
			$args[] = '--memory-limit=2048M';

			$process = new Process($args, $root);
			$process->setTimeout(600.0);
			$process->run();
			$out = $process->getOutput() . $process->getErrorOutput();

			preg_match_all(
				'~^(?<file>.+?\.php):(?<line>\d+):Dumped type:\s*(?<type>.+)$~m',
				$out,
				$matches,
				PREG_SET_ORDER,
			);

			$byFixture = [];
			foreach ($matches as $match) {
				$byFixture[basename($match['file'])][] = ['line' => (int) $match['line'], 'type' => trim(
					$match['type'],
				)];
			}

			$result = [];
			foreach ($byFixture as $name => $entries) {
				usort($entries, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
				$result[$name] = array_map(static fn (array $e): string => $e['type'], $entries);
			}

			return self::$dumpedByClass[static::class] = $result;
		} finally {
			$isolated->cleanup();
		}
	}

}
