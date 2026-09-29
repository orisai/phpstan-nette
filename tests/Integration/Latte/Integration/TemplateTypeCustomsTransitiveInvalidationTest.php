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

// TemplateTypeCustomsInvalidationTest only proves the SAME-FILE case (a
// file that both declares {templateType C} and consumes it directly). This test proves the
// TRANSITIVE-includer case one hop further out: file A {include}s file B, and B (not A) declares
// {templateType C} - A never reflects C directly, it only reads C-derived var types THROUGH B's
// own exported facts (ContextResolver/IncludeContractChecker). Before EdgeFingerprint's
// $templateTypeVars fold, B's own fingerprint never changed when C changed (only B's real compiled
// signature did, which the same-file test already proved DOES get recomputed
// correctly on B itself) - but a fingerprint-value-only comparison is what propagates to B's own
// dependents, so A could still see B's STALE (pre-edit) declared-var facts on a warm run.
/**
 * @group latte2
 */
final class TemplateTypeCustomsTransitiveInvalidationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const FIXTURES_DIR = __DIR__ . '/../../../Unit/Latte/Customs/Fixtures';

	private const PROBE_PREFIX = 'TransitiveProbe';

	// A fatal in an earlier run skips the `finally` below and leaves its probe behind.
	protected function setUp(): void
	{
		parent::setUp();
		foreach ((array) glob(self::FIXTURES_DIR . '/' . self::PROBE_PREFIX . '*.php') as $stale) {
			FileSystem::delete((string) $stale);
		}
	}

	public function testRetypingATransitivelyReachedTemplateTypePropertyInvalidatesTheIncluderOnAWarmRun(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$className = self::PROBE_PREFIX . str_replace('.', '', uniqid('', true));
		$fqcn = 'Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\\' . $className;
		$classFile = self::FIXTURES_DIR . '/' . $className . '.php';
		$scratch = $projectRoot . '/var/tmp/latte-template-type-transitive-test-' . uniqid('', true);
		$srcDir = $scratch . '/src';
		FileSystem::createDir($srcDir);

		try {
			FileSystem::write($classFile, $this->classSource($fqcn, 'string'));
			// B declares {templateType C} and merely uses $n - it never itself gets edited.
			FileSystem::write($srcDir . '/b.latte', "{templateType $fqcn}\n{\$n}\n");
			// A only {include}s B - it never declares {templateType C} itself, and never gets
			// edited either. Its own $s starts type-matched against C's initial `string $n`.
			FileSystem::write($srcDir . '/a.latte', "{varType string \$s}\n{include 'b.latte', n => \$s}\n");

			$tmpDir = $scratch . '/pstmp';

			$run1 = $this->spawn($projectRoot, $srcDir, $classFile, $tmpDir);
			self::assertSame('(no errors)', $run1, 'run 1 (types match: both string) must be clean: ' . $run1);

			$run2 = $this->spawn($projectRoot, $srcDir, $classFile, $tmpDir);
			self::assertSame($run1, $run2, 'warm no-op spawn must be a cache hit (byte-identical output)');

			// C's OWN file changes (b.latte's declared $n flips from string to int via
			// {templateType}); NEITHER a.latte NOR b.latte is ever touched.
			FileSystem::write($classFile, $this->classSource($fqcn, 'int'));

			$run3 = $this->spawn($projectRoot, $srcDir, $classFile, $tmpDir);
			self::assertStringContainsString(
				'a.latte:2:Variable $n provided as string does not match declared type int in ',
				$run3,
				'a.latte (the includer, never itself edited) must be reanalysed once b.latte\'s '
				. '{templateType} class retypes $n - a stale cached result here is exactly the '
				. 'transitive gap this test guards against: ' . $run3,
			);
		} finally {
			FileSystem::delete($classFile);
			FileSystem::delete($scratch);
		}
	}

	private function classSource(string $fqcn, string $propertyType): string
	{
		$parts = explode('\\', $fqcn);
		$class = array_pop($parts);
		$namespace = implode('\\', $parts);

		return "<?php declare(strict_types = 1);\n\n"
			. "namespace $namespace;\n\n"
			. "final class $class\n"
			. "{\n\n"
			// This class is written to exist purely to be REFLECTED (PropertyTypeResolver), never
			// analysed as ordinary PHP - the same shape as Support/TypedFixtureTemplate
			// (integration.neon's own excludePaths comment explains why), except this fixture is
			// generated per-test at a dynamic path excludePaths can't glob ahead of time, so the
			// suppression is inline instead.
			. "\t/** @phpstan-ignore shipmonk.deadProperty.neverRead, shipmonk.deadProperty.neverWritten */\n"
			. "\tpublic $propertyType \$n;\n\n"
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
