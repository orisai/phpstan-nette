<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function dirname;
use function explode;
use function implode;
use function rtrim;
use function sort;
use function str_replace;
use function uniqid;
use const PHP_BINARY;
use const SORT_STRING;

// End-to-end proof that orisaiNette.latte.includeIsolation reaches a real analysis through DI (EdgeScopeIsolationTest
// pins the seam itself). The same fixture pair is analysed twice, flag off then on: under Latte 2's
// union semantics the includer's own $shared satisfies the target's declaration, under Latte 3's
// isolated params it does not - and that difference IS the migration worklist the flag exists to
// produce.
final class IncludeIsolationFlagTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	public function testFlagOffKeepsTheUnionSemanticsAndFlagOnReportsTheInheritedVariable(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $projectRoot . '/var/tmp/latte-include-isolation-test-' . uniqid('', true);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		FileSystem::write(
			$srcDir . '/isolation-includer.latte',
			"{varType string \$shared}\n{include 'isolation-target.latte'}\n",
		);
		FileSystem::write($srcDir . '/isolation-target.latte', "{varType string \$shared}\n{\$shared}\n");

		try {
			self::assertSame(
				"(no errors)\n",
				$this->spawn($projectRoot, $srcDir, $scratch . '/off', false),
				'Latte 2 union semantics: the include site names nothing, but the includer\'s entire'
					. ' param set reaches the target, so its declared $shared is provided',
			);
			self::assertSame(
				"$relSrc/isolation-includer.latte:2:Include target '$relSrc/isolation-target.latte'"
					. " requires variable \$shared (string) that is not provided and has no default.\n",
				$this->spawn($projectRoot, $srcDir, $scratch . '/on', true),
				'isolation on: the edge provides only its (empty) explicit args, so the same'
					. ' declaration is unsatisfied - reported at the include site as'
					. ' orisaiNette.latte.includeMissingVariable',
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	private function spawn(string $projectRoot, string $srcDir, string $tmpDir, bool $includeIsolation): string
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			['orisaiNette.latte.includeIsolation' => $includeIsolation],
		);

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

		$lines = [];
		foreach (explode("\n", str_replace($projectRoot . '/', '', $process->getOutput())) as $line) {
			$line = rtrim($line);
			if ($line !== '') {
				$lines[] = $line;
			}
		}

		if ($lines === []) {
			return "(no errors)\n";
		}

		sort($lines, SORT_STRING);

		return implode("\n", $lines) . "\n";
	}

}
