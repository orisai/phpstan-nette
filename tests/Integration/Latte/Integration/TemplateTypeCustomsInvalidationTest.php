<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_pop;
use function dirname;
use function explode;
use function glob;
use function implode;
use function str_replace;
use function uniqid;
use const PHP_BINARY;

// Spec §5's invalidation clause for per-template customs: "PHPStan's own exported-nodes machinery
// invalidates dependents when C's methods/docblocks change (verify: docblock tag changes
// propagate as exported-node diffs; if not, fold C's relevant surface into the consuming
// template's edge fingerprint)". This spawns a real phpstan process twice against the SAME warm
// tmpDir (result cache) - only the {templateType} class C's OWN file changes (a docblock tag
// added to an existing, already-typed method) between the two runs; the consuming .latte file's
// own bytes never change. The templateType class must live at a REAL PSR-4-mapped path
// (Tests\ => tests/) for class_exists()/native reflection to find it at all (see
// PropertyTypeResolver/TemplateTypeCustoms - neither goes through PHPStan's own BetterReflection),
// so this test creates a BRAND NEW fixture file there for the duration of the test and deletes it
// in `finally`, never touching an existing committed fixture.
/**
 * @group latte2
 */
final class TemplateTypeCustomsInvalidationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const FIXTURES_DIR = __DIR__ . '/../../../Unit/Latte/Customs/Fixtures';

	private const PROBE_PREFIX = 'InvalidationProbe';

	// A fatal in an earlier run skips the `finally` below and leaves its probe behind.
	protected function setUp(): void
	{
		parent::setUp();
		foreach ((array) glob(self::FIXTURES_DIR . '/' . self::PROBE_PREFIX . '*.php') as $stale) {
			FileSystem::delete((string) $stale);
		}
	}

	public function testAddingAFilterDocblockTagToAnAlreadyTypedMethodInvalidatesTheUneditedConsumingTemplate(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$className = self::PROBE_PREFIX . str_replace('.', '', uniqid('', true));
		$fqcn = 'Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\\' . $className;
		$classFile = self::FIXTURES_DIR . '/' . $className . '.php';
		$scratch = $projectRoot . '/var/tmp/latte-template-type-invalidation-test-' . uniqid('', true);
		$srcDir = $scratch . '/src';
		FileSystem::createDir($srcDir);

		try {
			FileSystem::write($classFile, $this->classSource($fqcn, false));
			FileSystem::write(
				$srcDir . '/main.latte',
				"{templateType $fqcn}\n{varType int \$n}\n{\$n|probeMethod}\n",
			);

			$tmpDir = $scratch . '/pstmp';

			// $classFile is listed alongside $srcDir (not just reachable via class_exists()'s real
			// autoload): production's own templateType classes live inside app/, itself part of the
			// real config's analysed paths, so this mirrors that shape - a templateType class OUTSIDE
			// paths! is never in PHPStan's own tracked-file/dependency-hash universe at all, regardless
			// of any edge referencing its class name, and undercounts what the edge fix can prove.
			$run1 = $this->spawn($projectRoot, $srcDir, $classFile, $tmpDir);
			self::assertStringContainsString(
				"Unknown Latte filter 'probeMethod'.",
				$run1,
				'run 1 (no @filter tag yet) must report unknownFilter: ' . $run1,
			);

			$run2 = $this->spawn($projectRoot, $srcDir, $classFile, $tmpDir);
			self::assertSame($run1, $run2, 'warm no-op spawn must be a cache hit (byte-identical output)');

			// The templateType class's OWN file changes (a docblock tag added to an ALREADY public,
			// already-typed method - no native signature delta); the consuming main.latte is never
			// touched.
			FileSystem::write($classFile, $this->classSource($fqcn, true));

			$run3 = $this->spawn($projectRoot, $srcDir, $classFile, $tmpDir);
			self::assertStringNotContainsString(
				'Unknown Latte filter',
				$run3,
				'the unedited consuming template must be reanalysed once its {templateType} class '
				. 'gains a @filter tag - a stale cached result here is exactly the gap this test guards '
				. 'against: ' . $run3,
			);
		} finally {
			FileSystem::delete($classFile);
			FileSystem::delete($scratch);
		}
	}

	private function classSource(string $fqcn, bool $tagged): string
	{
		$parts = explode('\\', $fqcn);
		$class = array_pop($parts);
		$namespace = implode('\\', $parts);
		$doc = $tagged ? "\t/**\n\t * @filter\n\t */\n" : '';

		return "<?php declare(strict_types = 1);\n\n"
			. "namespace $namespace;\n\n"
			. "final class $class\n"
			. "{\n\n"
			. $doc
			. "\tpublic function probeMethod(int \$a): string\n"
			. "\t{\n"
			. "\t\treturn (string) \$a;\n"
			. "\t}\n\n"
			. "}\n";
	}

	private function spawn(string $projectRoot, string $srcDir, string $classFile, string $tmpDir): string
	{
		$isolated = LattePhpstanConfig::create(self::REAL_CONFIG_PATH, [$srcDir, $classFile], $tmpDir);

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
	}

}
