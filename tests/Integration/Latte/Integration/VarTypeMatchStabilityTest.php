<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_values;
use function dirname;
use function explode;
use function implode;
use function sort;
use function str_replace;
use function strpos;
use function uniqid;
use const PHP_BINARY;
use const SORT_STRING;

// LatteVarTypeExpressionRule pairs a compiled assignment with one of several {varType} placements
// sharing its anchor line through a cursor that advances in scan order. The compiled class holds one
// latteMain_ctx{i} clone per distinct include context, and every clone re-traverses the identical
// statement sequence, so a cursor bounded by the FILE rather than by the traversal let the pairing
// drift by (statements per traversal * clone index) mod (candidate count). A run of two {varType}s
// anchored on ONE statement is the shape where that arithmetic does not cancel: at one includer the
// statement paired with the first declaration and nothing was reported, at two includers the second
// traversal paired the very same statement with the SECOND declaration and a
// orisaiNette.latte.varTypeNativeType finding appeared - a template's own findings changing because an unrelated
// file started including it.
final class VarTypeMatchStabilityTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	// Both sites pass a DIFFERENT literal type for $v, which is what makes the two include contexts
	// distinct (equal contexts would collapse to a single clone and prove nothing).
	private const RUN_TEMPLATE = "hello\n{varType int \$x}{varType string \$x}{var \$x = 1}\n{\$x}{\$v}\n";

	public function testAVarTypeRunAnchoredOnOneStatementReportsTheSameAtOneAndAtTwoIncludeContexts(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';

		try {
			FileSystem::write($srcDir . '/partial.latte', self::RUN_TEMPLATE);
			FileSystem::write($srcDir . '/aSite.latte', "{include 'partial.latte', v: 'a-literal'}\n");

			$oneContext = $this->varTypeFindings($this->spawn($projectRoot, $srcDir, $scratch . '/pstmp-one'));

			FileSystem::write($srcDir . '/bSite.latte', "{include 'partial.latte', v: 5}\n");

			$twoContexts = $this->varTypeFindings($this->spawn($projectRoot, $srcDir, $scratch . '/pstmp-two'));

			self::assertSame(
				$oneContext,
				$twoContexts,
				'the {varType} findings of a template must not depend on how many other templates include it',
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// The pairing must also stay correct where it genuinely matters: two {varType}s each with their
	// OWN {var} on one anchor line still pair one-to-one in scan order, in every clone, so the
	// second declaration's real conflict is reported exactly once whatever the includer count is.
	public function testTwoSameLineRedeclarationsStayPairedOneToOneAtBothIncludeCounts(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';

		try {
			FileSystem::write(
				$srcDir . '/partial.latte',
				"hello\n{varType int \$x}{var \$x = 1}{varType string \$x}{var \$x = 2}\n{\$x}{\$v}\n",
			);
			FileSystem::write($srcDir . '/aSite.latte', "{include 'partial.latte', v: 'a-literal'}\n");

			$oneContext = $this->varTypeFindings($this->spawn($projectRoot, $srcDir, $scratch . '/pstmp-one'));

			self::assertSame(
				'src/partial.latte:2:{varType} for $x with type string is not subtype of native type 2.',
				$oneContext,
				"only the SECOND declaration conflicts with its own {var}; the first one must stay silent: $oneContext",
			);

			FileSystem::write($srcDir . '/bSite.latte', "{include 'partial.latte', v: 5}\n");

			self::assertSame(
				$oneContext,
				$this->varTypeFindings($this->spawn($projectRoot, $srcDir, $scratch . '/pstmp-two')),
				'a second include context must not change which declaration each {var} pairs with',
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// The SET of distinct {varType} messages, not the multiset: every clone of latteMain re-emits
	// whatever its own traversal found (core's own "Overwriting variable" scales the same way on
	// these fixtures), so a multiset comparison would fail on that unrelated - and correct -
	// clone-count scaling instead of on the pairing this test is about. WHICH declaration a
	// statement pairs with is a set question: the defect showed up as a message present at two
	// includers and absent at one, which survives deduplication intact.
	private function varTypeFindings(string $output): string
	{
		$lines = [];
		foreach (explode("\n", $output) as $line) {
			if (strpos($line, '{varType}') !== false) {
				$lines[$line] = $line;
			}
		}

		$lines = array_values($lines);
		sort($lines, SORT_STRING);

		return implode("\n", $lines);
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

		return str_replace($srcDir, 'src', $process->getOutput());
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir - ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file), so a path outside $projectRoot never
		// relativizes and every include-target lookup that assumes a project-relative path breaks.
		$dir = $projectRoot . '/var/tmp/latte-vartype-stability-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

}
