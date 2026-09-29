<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_column;
use function dirname;
use function explode;
use function implode;
use function preg_replace;
use function rtrim;
use function sort;
use function str_replace;
use function strpos;
use function uniqid;
use const PHP_BINARY;
use const SORT_STRING;

// Task 5 (FactoryProvidedVars::intersect()) widens two disagreeing renderer types to their deepest
// common class (CommonClassAncestor::of()) instead of collapsing to mixed - the first consumer whose
// merged type depends on a renderer's INHERITANCE rather than only its name. emitDiscoveryRefs()
// already refs every record's renderer class and an `extends` change is an exported-node change, so
// the edit should propagate on a warm run - but that is an inference about PHPStan's own machinery,
// and this project's ledger records "cross-file state cached per-file" as its most recurrent bug
// class. This mirrors DiscoveryLayoutLinkInvalidationTest's spawn/store harness (its own header
// comment explains the two-hop mechanism in full), reusing the same mapping-loader trick that stands
// in for the missing container.
final class FactoryVarsRendererHierarchyInvalidationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const ORPHAN_MESSAGE = 'No analysable render, include or layout path reaches this template file.';

	private const LABEL_FINDING = 'Call to an undefined method LatteSpawnMerge\\SpawnMergeRoot::label()';

	public function testReparentingARendererReanalysesTheLayoutAndMovesTheMergedType(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $srcDir . '/discovery';
		$tmpDir = $scratch . '/pstmp';

		try {
			FileSystem::write($scratch . '/mapping-loader.php', <<<'PHP'
<?php declare(strict_types = 1);

return [
	'spawn' => new class extends \Nette\DI\Container {

		public function getByType(string $type, bool $throw = true): ?object
		{
			$factory = new \Nette\Application\PresenterFactory();
			$factory->setMapping(['Spawn' => 'LatteSpawnMerge\\*Presenter']);

			return $factory instanceof $type ? $factory : null;
		}

	},
];

PHP);
			FileSystem::write($srcDir . '/SpawnMergeRoot.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace LatteSpawnMerge;

abstract class SpawnMergeRoot extends \Nette\Application\UI\Presenter
{

}

PHP);
			FileSystem::write($srcDir . '/SpawnMergeMiddle.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace LatteSpawnMerge;

abstract class SpawnMergeMiddle extends SpawnMergeRoot
{

	public function label(): string
	{
		return 'x';
	}

}

PHP);
			FileSystem::write($srcDir . '/SpawnMergeOnePresenter.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace LatteSpawnMerge;

/**
 * @property-read \Nette\Bridges\ApplicationLatte\DefaultTemplate $template
 */
final class SpawnMergeOnePresenter extends SpawnMergeMiddle
{

	public function renderDefault(): void
	{
	}

}

PHP);
			FileSystem::write($srcDir . '/SpawnMergeTwoPresenter.php', self::twoPresenterExtending('SpawnMergeRoot'));

			FileSystem::write($srcDir . '/templates/SpawnMergeOne/default.latte', "<p>view</p>\n");
			FileSystem::write($srcDir . '/templates/SpawnMergeTwo/default.latte', "<p>view</p>\n");
			FileSystem::write($srcDir . '/templates/@layout.latte', "{\$presenter->label()}\n");

			$layoutRel = "$relSrc/templates/@layout.latte";
			$oneViewRel = "$relSrc/templates/SpawnMergeOne/default.latte";
			$twoViewRel = "$relSrc/templates/SpawnMergeTwo/default.latte";
			$mappingLoader = $scratch . '/mapping-loader.php';

			// Every template's store file exists BEFORE run 1, mirroring
			// DiscoveryLayoutLinkInvalidationTest's own pre-analysis-index precondition: the
			// template->store-class dependency edge must be baked into the cold parse before any
			// record change can propagate through it.
			DiscoveryStore::bootstrap($storeDir, [$layoutRel, $oneViewRel, $twoViewRel]);

			// controlArgumentCertaintyOf() calls is_a($rendererClass, Presenter::class, true), whose
			// $allow_string autoload is the ONLY way this scratch corpus's classes become real at
			// runtime (LattePhpstanConfig::create()'s own $extraBootstrapFiles doc explains why: a
			// scratch namespace has no PSR-4 mapping, so requiring vendor/autoload.php alone never
			// makes it class_exists()-visible). Without this, `presenter` silently drops out of every
			// renderer's vars (OPEN, never false-close) and the probe's precondition can never hold -
			// required in inheritance order so the plain `require` sees each parent already declared.
			$bootstrapFile = $scratch . '/bootstrap-classes.php';
			FileSystem::write(
				$bootstrapFile,
				"<?php declare(strict_types = 1);\n\n"
				. "require '$srcDir/SpawnMergeRoot.php';\n"
				. "require '$srcDir/SpawnMergeMiddle.php';\n"
				. "require '$srcDir/SpawnMergeOnePresenter.php';\n"
				. "require '$srcDir/SpawnMergeTwoPresenter.php';\n",
			);

			$run1 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, $mappingLoader, $bootstrapFile);

			// The probe's own precondition (spec-mandated): if this fails, the layout candidate is
			// not linked to BOTH presenters and there is nothing for the merge to widen.
			$store1 = new DiscoveryStore($storeDir);
			$layoutClasses = array_column($store1->recordsForTemplate($layoutRel), 'class');
			sort($layoutClasses, SORT_STRING);
			self::assertSame(
				['LatteSpawnMerge\\SpawnMergeOnePresenter', 'LatteSpawnMerge\\SpawnMergeTwoPresenter'],
				$layoutClasses,
				'run 1 must link the layout template to BOTH presenters through the writer - if this '
				. 'fails, print $storeDir\'s contents and correct the fixture paths above; do not '
				. 'proceed with an empty precondition',
			);
			self::assertStringContainsString(
				self::LABEL_FINDING,
				$run1['output'],
				'run 1 must report the shallow common ancestor missing label() - the merge '
				. 'precondition this probe exists to exercise: ' . $run1['output'],
			);

			// Settle the result cache before making the edit under test - the pre-analysis index
			// build still has to catch up to the bootstrap-empty store files it wrote before run 1,
			// exactly as DiscoveryLayoutLinkInvalidationTest's own runs 2-3 do.
			$run2 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, $mappingLoader, $bootstrapFile);
			self::assertSame($run1['output'], $run2['output']);

			$run3 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, $mappingLoader, $bootstrapFile);
			self::assertSame($run1['output'], $run3['output']);
			self::assertStringContainsString(
				'Result cache restored. 0 files will be reanalysed.',
				$run3['diagnostics'],
				'run 3 must be the settled fixpoint before the edit under test: ' . $run3['diagnostics'],
			);

			// The edit under test: an `extends`-clause change to a renderer, with the layout file
			// itself untouched.
			FileSystem::write(
				$srcDir . '/SpawnMergeTwoPresenter.php',
				self::twoPresenterExtending('SpawnMergeMiddle'),
			);

			$run4 = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, $mappingLoader, $bootstrapFile);
			self::assertStringNotContainsString(
				self::LABEL_FINDING,
				$run4['output'],
				'the reparented renderer must move the merged type to SpawnMergeMiddle, which '
				. 'declares label() - if this finding is still present, the extends-clause edit did '
				. 'not propagate to the layout on a warm run: ' . $run4['output'] . ' / '
				. $run4['diagnostics'],
			);

			// The SELECTIVE half of the claim, not just the "it fires at all" half: exactly 3 files,
			// never a blanket whole-corpus reanalysis. DiscoveryRefResolver::refClassNamesFor() emits
			// a direct class-ref edge per (template, record) pair, so SpawnMergeTwoPresenter.php's own
			// signature edit reaches only the templates that actually ref THAT class:
			// SpawnMergeTwoPresenter.php itself (edited), templates/SpawnMergeTwo/default.latte (its
			// own view, single renderer ref), and templates/@layout.latte (refs BOTH presenters - one
			// of its two refs changed). templates/SpawnMergeOne/default.latte never refs
			// SpawnMergeTwoPresenter and the three ancestor class files were not edited, so all four
			// stay outside the count - a future regression that widened this to a blanket
			// reanalysis (or narrowed it to miss the layout) would move this number.
			self::assertStringContainsString(
				'Result cache restored. 3 files will be reanalysed.',
				$run4['diagnostics'],
				'run 4 must reanalyse exactly the edited renderer, its own view and the shared layout '
				. 'it also refs - never more, never less: ' . $run4['diagnostics'],
			);

			// The postcondition mirroring run 1's precondition: the finding's absence must come from
			// the merge correctly widening off BOTH renderers' CURRENT hierarchy, not from one
			// renderer's link to the layout silently vanishing (which would leave only
			// SpawnMergeOnePresenter - already `extends SpawnMergeMiddle` - and make the finding
			// disappear for the wrong reason).
			$store4 = new DiscoveryStore($storeDir);
			$layoutClassesAfter = array_column($store4->recordsForTemplate($layoutRel), 'class');
			sort($layoutClassesAfter, SORT_STRING);
			self::assertSame(
				['LatteSpawnMerge\\SpawnMergeOnePresenter', 'LatteSpawnMerge\\SpawnMergeTwoPresenter'],
				$layoutClassesAfter,
				'run 4 must still link the layout to BOTH presenters - if only one remains, the '
				. 'finding\'s absence proves nothing about the merge',
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	private static function twoPresenterExtending(string $parent): string
	{
		if ($parent === 'SpawnMergeRoot') {
			return <<<'PHP'
<?php declare(strict_types = 1);

namespace LatteSpawnMerge;

/**
 * @property-read \Nette\Bridges\ApplicationLatte\DefaultTemplate $template
 */
final class SpawnMergeTwoPresenter extends SpawnMergeRoot
{

	public function renderDefault(): void
	{
	}

}

PHP;
		}

		return <<<'PHP'
<?php declare(strict_types = 1);

namespace LatteSpawnMerge;

/**
 * @property-read \Nette\Bridges\ApplicationLatte\DefaultTemplate $template
 */
final class SpawnMergeTwoPresenter extends SpawnMergeMiddle
{

	public function renderDefault(): void
	{
	}

}

PHP;
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir: ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file) - a path outside $projectRoot never
		// relativizes, breaking every rel-path lookup that assumes project-relative paths.
		$dir = $projectRoot . '/var/tmp/latte-factory-vars-hierarchy-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

	/**
	 * @return array{output: string, diagnostics: string}
	 */
	private function spawn(
		string $projectRoot,
		string $srcDir,
		string $tmpDir,
		string $storeDir,
		string $mappingLoader,
		string $bootstrapFile
	): array
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			[
				'orisaiNette.latte.discovery.enabled' => true,
				'orisaiNette.latte.discovery.storePath' => $storeDir,
				'orisaiNette.latte.firstPartyPaths' => [$srcDir],
				'orisaiNette.latte.templateFactoryContainerLoader' => $mappingLoader,
			],
			[$bootstrapFile],
		);

		$process = new Process(
			[
				PHP_BINARY,
				$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
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
			if ($line !== '' && strpos($line, self::ORPHAN_MESSAGE) === false) {
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
