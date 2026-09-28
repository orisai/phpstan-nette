<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\SliceClassName;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function dirname;
use function explode;
use function implode;
use function preg_replace;
use function rtrim;
use function sort;
use function str_replace;
use function uniqid;
use const PHP_BINARY;

// PHPStan's own result cache only propagates a changed file's edges to its dependents when
// ResultCacheManager::exportedNodesChanged() sees a SIGNATURE-level difference (a method's own
// param/return phpDoc, a class's shape) - a body-only edit (e.g. which args an {include} call
// passes) never trips it, with or without our edges. The first two scenarios below therefore edit
// a {varType} TYPE - which surfaces in the generated method's own phpDoc - rather than an include
// site's argument list, so the edit is guaranteed to trip that gate and isolate what our edges
// specifically fix: whether the DEPENDENT file (never itself re-hashed) gets reanalysed at all.
// The third scenario is the gap those two can't reach: an include-site ARGUMENT edit is body-only
// in the includer's own generated class (no param/return phpDoc changes), so exportedNodesChanged()
// alone never trips for it - closed by DependencyEdgeEmitter::emitFingerprint()'s exported
// LATTE_EDGE_FINGERPRINT class constant, whose VALUE (not just presence) changes with the argument
// list, which IS a signature-level difference ExportedClassConstantNode::equals() catches.
final class ResultCacheInvalidationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	public function testIncluderVarTypeEditInvalidatesUneditedTargetsCachedResult(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		try {
			FileSystem::write(
				$srcDir . '/inc-root.latte',
				"{varType int \$n}\n{include 'inc-partial.latte', w => \$n}\n",
			);
			FileSystem::write($srcDir . '/inc-partial.latte', "{=str_repeat('x', \$w)}\n");

			$spawn1 = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp');
			self::assertSame("(no errors)\n", $spawn1, 'initial state must be clean');

			$spawn2 = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp');
			self::assertSame($spawn1, $spawn2, 'warm no-op spawn must be a cache hit (byte-identical output)');

			// Includer-only edit: inc-root.latte's own declared type changes; inc-partial.latte is
			// never touched.
			FileSystem::write(
				$srcDir . '/inc-root.latte',
				"{varType string \$n}\n{include 'inc-partial.latte', w => \$n}\n",
			);

			$spawn3 = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp');
			self::assertSame(
				"$relSrc/inc-partial.latte:1:Parameter #2 \$multiplier of function str_repeat expects int, "
				. "string given.\n",
				$spawn3,
				"the un-edited target must be reanalysed once its includer's declared type changes",
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	public function testTargetVarTypeEditInvalidatesUneditedIncludersContractCheck(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		try {
			FileSystem::write(
				$srcDir . '/inc-root.latte',
				"{varType int \$n}\n{include 'inc-partial.latte', w => \$n}\n",
			);
			FileSystem::write($srcDir . '/inc-partial.latte', "{\$w}\n");

			$spawn1 = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp');
			self::assertSame("(no errors)\n", $spawn1, 'initial state must be clean');

			$spawn2 = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp');
			self::assertSame($spawn1, $spawn2, 'warm no-op spawn must be a cache hit (byte-identical output)');

			// Target-only edit: inc-partial.latte gains a declared type; inc-root.latte is never
			// touched.
			FileSystem::write($srcDir . '/inc-partial.latte', "{varType string \$w}\n{\$w}\n");

			$spawn3 = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp');
			self::assertSame(
				"$relSrc/inc-root.latte:2:Variable \$w provided as int does not match declared type string in "
				. "'$relSrc/inc-partial.latte'.\n",
				$spawn3,
				"the un-edited includer's contract check must be redone once its target's declared type changes",
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	public function testIncluderArgumentEditInvalidatesUneditedTargetsCachedResult(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		try {
			FileSystem::write(
				$srcDir . '/inc-root.latte',
				"{varType int \$n}\n{include 'inc-partial.latte', w => \$n}\n",
			);
			// No local {varType} for $w: its only source of a declared type is the include site's
			// argument list (context-provided), so dropping that argument below must turn $w from
			// a known parameter into an undefined variable, never a type-mismatch diagnostic.
			FileSystem::write($srcDir . '/inc-partial.latte', "{\$w}\n");

			$spawn1 = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp');
			self::assertSame("(no errors)\n", $spawn1, 'initial state must be clean');

			$spawn2 = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp');
			self::assertSame($spawn1, $spawn2, 'warm no-op spawn must be a cache hit (byte-identical output)');

			// Includer-only, body-only edit: inc-root.latte stops passing the `w` argument at its
			// {include} site; its own latteMain_ctx0($n) signature is untouched, and inc-partial.latte
			// is never touched. Pre-fix (fingerprint disabled), this is exactly the edit
			// exportedNodesChanged() cannot see, so the cached clean result for inc-partial.latte
			// would incorrectly survive.
			FileSystem::write($srcDir . '/inc-root.latte', "{varType int \$n}\n{include 'inc-partial.latte'}\n");

			$spawn3 = $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp');
			self::assertSame(
				"$relSrc/inc-partial.latte:1:Undefined variable: \$w\n",
				$spawn3,
				"the un-edited target must be reanalysed once its includer's argument list changes",
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// Slice-file store design: a store-only change can never propagate through a SINGLE shared
	// store file, because PHPStan's own
	// ResultCacheManager::restore() only ever recomputes a file's exported nodes once that file's
	// RAW CONTENT HASH has already changed for another reason - a write to a separate store file
	// never touches the includer's own `.latte` bytes. The fix: the store is a directory of
	// per-includer slice FILES, and the TARGET (not the includer) references its includer's slice
	// class (LatteRoutingParser::emitSliceRefs()). A capture write now edits the SLICE FILE's own
	// bytes directly, which IS a real content change restore() observes on that file - propagating
	// to exactly the target(s) that reference it, on a plain warm run, with zero cache clearing.
	// Empirically verified with `vendor/bin/phpstan -vv` on this exact fixture: with every fixture
	// includer's slice pre-seeded (so no BRAND NEW file appears between runs to confound the
	// signal), run 2 reanalyses exactly 2 files (the changed slice + its one dependent target).
	public function testSliceFileContentChangePropagatesToItsTargetOnAPlainWarmRun(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $srcDir . '/sitescope';
		$tmpDir = $scratch . '/pstmp';

		try {
			FileSystem::write(
				$srcDir . '/narrow-includer.latte',
				"{varType Exception|null \$x}\n{if \$x !== null}\n\t{include 'narrow-target.latte'}\n{/if}\n",
			);
			// No local {varType} for $x: narrowing it can only ever come from the store overlay.
			FileSystem::write($srcDir . '/narrow-target.latte', "{\$x->getMessage()}\n");
			// Never referenced by the pair above - its cached result must survive byte-for-byte
			// across every run, proving the mechanism is per-edge, never whole-cache.
			FileSystem::write($srcDir . '/unrelated.latte', "{\$undefinedVar}\n");

			// Every known includer's slice already exists
			// (empty) BEFORE run 1 - a target->slice dependency edge must exist before a
			// capture change can propagate through it, and this also keeps run 2 from having to
			// absorb brand-new files (which would confound the signal this test pins). The store
			// directory sits inside $srcDir precisely so it is part of the analysed `paths!` below:
			// slices must live inside analysed paths for
			// PHPStan's restore() to ever observe their hash.
			SiteScopeStore::bootstrap($storeDir, [
				"$relSrc/narrow-includer.latte",
				"$relSrc/narrow-target.latte",
				"$relSrc/unrelated.latte",
			]);

			$run1 = $this->spawnWithStore($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertSame(
				"$relSrc/narrow-target.latte:1:Cannot call method getMessage() on Exception|null.\n"
				. "$relSrc/unrelated.latte:1:Undefined variable: \$undefinedVar\n",
				$run1['output'],
				'run 1 (empty slices, cold cache) must report the wide nullability error: ' . $run1['diagnostics'],
			);

			$sliceFile = $this->sliceFile($storeDir, "$relSrc/narrow-includer.latte");
			$sliceAfterRun1 = FileSystem::read($sliceFile);
			self::assertStringContainsString(
				"'x' => 'Exception'",
				$sliceAfterRun1,
				"run 1 must capture the if-narrowed \$x type into the includer's own slice: " . $sliceAfterRun1,
			);

			// Run 2: SAME tmpDir (warm result cache) and SAME store dir - nothing under $srcDir's
			// .latte sources changed, only the slice file run 1 just wrote. No fresh tmpDir, no
			// cache clearing anywhere.
			$run2 = $this->spawnWithStore($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertSame(
				"$relSrc/unrelated.latte:1:Undefined variable: \$undefinedVar\n",
				$run2['output'],
				'run 2 (plain warm run) must narrow $x to Exception, dropping the nullability error, '
				. "while the unrelated file's error survives byte-for-byte: " . $run2['diagnostics'],
			);
			self::assertStringContainsString(
				'2 files will be reanalysed',
				$run2['diagnostics'],
				'exactly the changed slice and its one dependent target must be reanalysed - never a '
				. 'whole-cache invalidation, never a silent 0-file no-op: ' . $run2['diagnostics'],
			);

			$sliceAfterRun2 = FileSystem::read($sliceFile);

			$run3 = $this->spawnWithStore($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertSame($run2['output'], $run3['output'], 'run 3 must be byte-identical to run 2 (fixpoint)');
			self::assertStringContainsString(
				'Result cache restored. 0 files will be reanalysed.',
				$run3['diagnostics'],
				'fixpoint: nothing changed since run 2, so run 3 must be a pure cache hit: ' . $run3['diagnostics'],
			);
			self::assertSame(
				$sliceAfterRun2,
				FileSystem::read($sliceFile),
				'the slice file must stay byte-stable across runs 2-3: nothing gets reanalysed, so the '
				. 'writer rule never observes new collector data to merge',
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	private function sliceFile(string $storeDir, string $includerRel): string
	{
		return $storeDir . '/' . SliceClassName::forPath($includerRel) . '.php';
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir: ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file) - a path outside $projectRoot never
		// relativizes, breaking every include-target/edge lookup that assumes project-relative
		// paths.
		$dir = $projectRoot . '/var/tmp/latte-rc-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

	// Reused across all three spawns of a scenario with the SAME $tmpDir: LattePhpstanConfig's
	// resultCachePath defaults under tmpDir, so only a shared tmpDir makes spawn 2 a warm/cached
	// run of spawn 1's analysis.
	private function spawn(string $projectRoot, string $srcDir, string $tmpDir): string
	{
		// integration.neon turns narrowing on; LattePhpstanConfig::create() defaults
		// orisaiNette.latte.narrowing.storePath to scratch under $tmpDir, so this spawn never touches the default
		// phpstan-latte-store/.
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
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

		return $this->normalize($process->getOutput(), $projectRoot);
	}

	// -vv (unlike the plain spawn() helper above) is required to surface PHPStan's own
	// "N files will be reanalysed" diagnostic on stderr; it also appends a " [identifier=...]"
	// suffix to --error-format=raw's stdout lines, stripped here so the returned output stays
	// comparable across all three -vv runs of a scenario, and to plain spawn()'s convention.

	/**
	 * @return array{output: string, diagnostics: string}
	 */
	private function spawnWithStore(string $projectRoot, string $srcDir, string $tmpDir, string $storeDir): array
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			['orisaiNette.latte.narrowing.storePath' => $storeDir],
		);

		$process = new Process(
			[
				PHP_BINARY,
				$projectRoot . '/vendor/bin/phpstan',
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=raw',
				'-vv',
				'-c',
				$isolated->getConfigPath(),
			],
			$projectRoot,
		);
		$process->run();

		$rawOutput = preg_replace('/ \[identifier=[^\]]+\]$/m', '', $process->getOutput());

		return [
			'output' => $this->normalize($rawOutput ?? $process->getOutput(), $projectRoot),
			'diagnostics' => $process->getErrorOutput(),
		];
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
