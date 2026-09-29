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

// DeclarationInjectorTest's own multi-context tests
// (testTwoContextsProduceTwoMainClones et al.) only assert the PRINTED PHP TEXT of each
// latteMain_ctx<N> clone - they never run the result through PHPStan's actual type checker, so a
// defect where the engine only honors ctx0's captured/declared type (the rest silently degrading
// to implicit mixed) could ship undetected. is_object() makes a
// function.impossibleType "always false" hit with the TYPE embedded in the message, so the
// message itself is proof the engine actually resolved that specific clone's own type - not just
// that codegen printed the right PHPDoc text.
final class MultiContextClonesReachTheEngineTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	public function testTwoContextsWithDifferentTypesForTheSameParamAreEachHonoredDistinctly(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';

		try {
			FileSystem::write($srcDir . '/target.latte', "{if is_object(\$x)}\n\t{\$x}\n{/if}\n");
			FileSystem::write($srcDir . '/stringSite.latte', "{include 'target.latte', x: 'a-literal'}\n");
			FileSystem::write($srcDir . '/intSite.latte', "{include 'target.latte', x: 5}\n");

			$output = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp');

			// Each context's OWN param type shows up in the is_object() message: string's clone
			// sees $x as string, int's clone sees it as int - neither is ever an object, so both
			// are genuine, distinct, non-ignorable impossibleType hits. An engine that only
			// honors one clone (ctx0) would show exactly one of these two lines, not both.
			self::assertStringContainsString(
				'Call to function is_object() with string will always evaluate to false.',
				$output,
				"the string-typed context's clone must have its own captured type honored: $output",
			);
			self::assertStringContainsString(
				'Call to function is_object() with int will always evaluate to false.',
				$output,
				"the int-typed context's clone must have its own captured type honored: $output",
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// Not just the mechanism in isolation: PHPStan's
	// own FileTypeMapper/BetterReflection reflection caches persist to disk across separate CLI
	// invocations, invalidated only by hash_file() of the referenced .latte path - a hash that
	// never changes when target.latte's OWN bytes stay fixed but the SET of includers reaching it
	// grows (exactly what happens as the narrowing store's captured universe converges over
	// repeated `make phpstan` runs). A warm second run - SAME tmpDir, SAME cache directory as run
	// 1 - that adds a brand-new context must still see that new context's own type live, not a
	// stale, pre-cloning-change reflection.
	public function testWarmSecondRunWithANewlyAddedContextStillHonorsItsOwnType(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$tmpDir = $scratch . '/pstmp';

		try {
			FileSystem::write($srcDir . '/target.latte', "{if is_object(\$x)}\n\t{\$x}\n{/if}\n");
			FileSystem::write($srcDir . '/stringSite.latte', "{include 'target.latte', x: 'a-literal'}\n");

			$firstRun = $this->spawn($projectRoot, $srcDir, $tmpDir);
			self::assertStringContainsString(
				'Call to function is_object() with string will always evaluate to false.',
				$firstRun,
				"run 1's sole context must be honored before run 2 adds a second one: $firstRun",
			);

			// target.latte's own bytes are untouched; only the edge topology (a new includer)
			// changes, exactly like a converging site-scope store adds coverage without any
			// individual target file's source changing.
			FileSystem::write($srcDir . '/intSite.latte', "{include 'target.latte', x: 5}\n");

			$secondRun = $this->spawn($projectRoot, $srcDir, $tmpDir);
			self::assertStringContainsString(
				'Call to function is_object() with string will always evaluate to false.',
				$secondRun,
				"the pre-existing context must stay live on the warm run: $secondRun",
			);
			self::assertStringContainsString(
				'Call to function is_object() with int will always evaluate to false.',
				$secondRun,
				'the newly-added context must be honored on a WARM run reusing run 1\'s cache '
				. "directory, never silently degraded to mixed by a stale reflection cache: $secondRun",
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	private function spawn(string $projectRoot, string $srcDir, string $tmpDir): string
	{
		$isolated = LattePhpstanConfig::create(self::REAL_CONFIG_PATH, [$srcDir], $tmpDir);

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

		return str_replace($projectRoot . '/', '', $process->getOutput());
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir: ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file) - a path outside $projectRoot never
		// relativizes, breaking every include-target/edge lookup that assumes project-relative
		// paths.
		$dir = $projectRoot . '/var/tmp/latte-multicontext-engine-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

}
