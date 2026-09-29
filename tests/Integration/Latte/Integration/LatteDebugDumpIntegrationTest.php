<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ConditionalCertaintyPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryVendorPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingBaseTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingChildTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingNarrowerDeclarationPresenter;
use function dirname;
use function str_replace;
use function uniqid;
use function usort;
use const PHP_BINARY;

// Real subprocess spawns (mirrors NarrowingFlagTest), fixtures written to scratch under var/tmp/ -
// never tests/Integration/Latte/Integration/Fixtures/, which IntegrationSnapshotTest diffs against
// a committed snapshot; a dump call left there would corrupt that snapshot rather than exercise
// this test. --error-format=json (not raw): dumpLatteVarOrigin's message is multi-line, and raw's
// per-physical-line rendering would fight IntegrationSnapshotTest-style line-based normalization -
// json preserves the embedded "\n" as one string, so the exact text can be asserted directly.
final class LatteDebugDumpIntegrationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	public function testDumpIncludersReportsExactZeroEdgeTextForAFileWithNoIncomingEdges(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';

		try {
			FileSystem::write(
				$srcDir . '/lonely.latte',
				"{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLatteIncluders()}\nHello.\n",
			);

			$messages = $this->dumpMessages($projectRoot, $srcDir, $scratch . '/pstmp');

			self::assertCount(1, $messages);
			self::assertSame('orisaiNette.latte.debugDump', $messages[0]['identifier']);
			self::assertFalse($messages[0]['ignorable'], 'a leftover dump call must never be baselineable');
			self::assertSame(1, $messages[0]['line']);
			self::assertSame(
				'no incoming Latte edges (convention-wired? PHP-side wiring is invisible until phase 3)',
				$messages[0]['message'],
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// Bare {do dumpLatteIncluders()} is the documented usage
	// syntax - the form a user actually types. The compiled Latte class carries no `namespace`
	// statement, so that bare call resolves to the plain unqualified name at the AST level, never
	// the OriPhpstan\Nette\Latte\Testing\... FQN every other test in this file uses. End-to-end through
	// the real compiler/pipeline, not just the rule in isolation (see LatteDebugDumpRuleTest's own
	// unit-level pin of the same requirement).
	public function testDumpIncludersFiresForTheBareUnqualifiedFormAUserActuallyTypes(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';

		try {
			FileSystem::write($srcDir . '/lonely.latte', "{do dumpLatteIncluders()}\nHello.\n");

			$messages = $this->dumpMessages($projectRoot, $srcDir, $scratch . '/pstmp');

			self::assertCount(1, $messages);
			self::assertSame(
				'no incoming Latte edges (convention-wired? PHP-side wiring is invisible until phase 3)',
				$messages[0]['message'],
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	public function testDumpIncludersListsEachIncomingEdgeWithItsIncluderContextCount(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		try {
			FileSystem::write($srcDir . '/includer.latte', "{include 'included.latte'}\n");
			FileSystem::write(
				$srcDir . '/included.latte',
				"{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLatteIncluders()}\nHello.\n",
			);

			$messages = $this->dumpMessages($projectRoot, $srcDir, $scratch . '/pstmp');

			self::assertCount(1, $messages);
			self::assertSame("$relSrc/includer.latte:1 (include) - 1 context(s)", $messages[0]['message']);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// The two-call-site union case: includer-a/includer-b each declare $x as a
	// different class and pass it into the SAME target - target.latte's main() gets cloned once per
	// context (DeclarationInjector::cloneMainPerContext), so the dump call fires twice, one error
	// per context, each carrying the SAME union summary line.
	public function testDumpVarOriginReportsOneErrorPerContextPlusAUnionSummary(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		try {
			FileSystem::write(
				$srcDir . '/includer-a.latte',
				"{varType Exception \$x}\n{include 'target.latte', x => \$x}\n",
			);
			FileSystem::write(
				$srcDir . '/includer-b.latte',
				"{varType RuntimeException \$x}\n{include 'target.latte', x => \$x}\n",
			);
			FileSystem::write(
				$srcDir . '/target.latte',
				"{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLatteVarOrigin(\$x)}\nHello.\n",
			);

			$messages = $this->dumpMessages($projectRoot, $srcDir, $scratch . '/pstmp');

			usort($messages, static fn (array $a, array $b): int => $a['message'] <=> $b['message']);

			self::assertCount(2, $messages);
			self::assertSame(
				"\$x: Exception\nchain: $relSrc/includer-a.latte\n"
				. "provenance: arg:$relSrc/includer-a.latte#2\n"
				. 'union: RuntimeException|Exception across 2 contexts',
				$messages[0]['message'],
			);
			self::assertSame(
				"\$x: RuntimeException\nchain: $relSrc/includer-b.latte\n"
				. "provenance: arg:$relSrc/includer-b.latte#2\n"
				. 'union: RuntimeException|Exception across 2 contexts',
				$messages[1]['message'],
			);
			self::assertSame('orisaiNette.latte.debugDump', $messages[0]['identifier']);
			self::assertFalse($messages[0]['ignorable']);
			self::assertFalse($messages[1]['ignorable']);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// End-to-end proof of the bare+FQN dump through the real compiler/pipeline AND the wiring.neon
	// service graph (lattePhpRenderWalk/lattePhpFactsCache reaching the rule): the ::class constant
	// argument must survive Latte compilation as a resolvable ClassConstFetch, and the walk's
	// site/literal paths must come out project-relative.
	public function testDumpRenderFactsListsFactsByDomainForAFixtureClass(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$fixtureRel = 'tests/Unit/Latte/Bridge/Fixtures/App/ConditionalCertaintyPresenter.php';
		$fqcn = ConditionalCertaintyPresenter::class;

		try {
			FileSystem::write(
				$srcDir . '/facts.latte',
				"{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLatteRenderFacts(\\$fqcn::class)}\nHello.\n",
			);

			$messages = $this->dumpMessages(
				$projectRoot,
				$srcDir,
				$scratch . '/pstmp',
				['orisaiNette.latte.firstPartyPaths' => [$projectRoot . '/tests/Unit/Latte/Bridge/Fixtures/App']],
			);

			self::assertCount(1, $messages);
			self::assertSame('orisaiNette.latte.debugDump', $messages[0]['identifier']);
			self::assertFalse($messages[0]['ignorable']);
			self::assertSame(
				"class: $fqcn"
				. "\ntemplate class: Nette\\Application\\UI\\Template (templateFloor, happens)"
				. "\nassignments:"
				. "\n\$definite: string (happens) @ $fixtureRel:19"
				. "\n\$maybe: string (maybe) @ $fixtureRel:22"
				. "\nsetFile targets: (none)"
				. "\nrender sites: (none)",
				$messages[0]['message'],
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// End-to-end proof of dumpLattePairing through the real wiring.neon service graph
	// (lattePhpRenderWalk/lattePhpFactsCache/lattePairingJudge/reflectionProvider reaching the
	// rule): the verdict - primary, candidates, merged conflict naming both channels - renders
	// exactly as the in-process pins say.
	public function testDumpPairingRendersTheVerdictForAFixtureClass(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$fqcn = PairingNarrowerDeclarationPresenter::class;
		$child = PairingChildTemplateReplica::class;
		$base = PairingBaseTemplateReplica::class;

		try {
			FileSystem::write(
				$srcDir . '/pairing.latte',
				"{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLattePairing(\\$fqcn::class)}\nHello.\n",
			);

			$messages = $this->dumpMessages(
				$projectRoot,
				$srcDir,
				$scratch . '/pstmp',
				['orisaiNette.latte.firstPartyPaths' => [$projectRoot . '/tests/Unit/Latte/Bridge/Pairing/Fixtures/App']],
			);

			self::assertCount(1, $messages);
			self::assertSame('orisaiNette.latte.debugDump', $messages[0]['identifier']);
			self::assertFalse($messages[0]['ignorable']);
			self::assertSame(
				"class: $fqcn"
				. "\nprimary: $base (new, happens)"
				. "\ncandidates:"
				. "\n$child (phpdoc, happens)"
				. "\n$child (genericBinding, happens)"
				. "\n$base (new, happens) @ 13"
				. "\nsite pairings: (none)"
				. "\nconflicts:"
				. "\n$child (phpdoc, genericBinding) vs $base (new)"
				. "\nopaques: (none)",
				$messages[0]['message'],
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// End-to-end proof of dumpLatteDiscovery through the real wiring.neon service graph
	// (latteDiscoveryResolver reaching the walk behind the rule, presenter names reverse-mapped
	// through the container-loader seam): views, per-view candidates and the layout walk come out
	// project-relative, exactly as the in-process pins say.
	public function testDumpDiscoveryRendersTheViewCandidatesForAFixturePresenter(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$fixtureDir = 'tests/Unit/Latte/Bridge/Fixtures';
		$fqcn = DiscoveryVendorPresenter::class;

		try {
			FileSystem::write(
				$srcDir . '/discovery.latte',
				"{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLatteDiscovery(\\$fqcn::class)}\nHello.\n",
			);

			$messages = $this->dumpMessages(
				$projectRoot,
				$srcDir,
				$scratch . '/pstmp',
				[
					'orisaiNette.latte.firstPartyPaths' => [$projectRoot . '/' . $fixtureDir . '/App'],
					'orisaiNette.latte.templateFactoryContainerLoader' => $projectRoot . '/' . $fixtureDir
						. '/presenter-mapping-container-loader.php',
				],
			);

			self::assertCount(1, $messages);
			self::assertSame('orisaiNette.latte.debugDump', $messages[0]['identifier']);
			self::assertFalse($messages[0]['ignorable']);
			self::assertSame(
				"class: $fqcn"
				. "\nopen view set: no"
				. "\nviews:"
				. "\ndefault (happens) @ $fixtureDir/App/DiscoveryVendorPresenter.php:10 from actionDefault"
				. "\ndetail (happens) @ $fixtureDir/App/DiscoveryVendorPresenter.php:14 from renderDetail"
				. "\nview candidates:"
				. "\ndefault:"
				. "\n$fixtureDir/App/templates/DiscoveryVendor/default.latte (formula, exists, chosen)"
				. "\n$fixtureDir/App/templates/DiscoveryVendor.default.latte (formula, missing)"
				. "\ndetail:"
				. "\n$fixtureDir/App/templates/DiscoveryVendor/detail.latte (formula, missing)"
				. "\n$fixtureDir/App/templates/DiscoveryVendor.detail.latte (formula, missing)"
				. "\nlayout candidates:"
				. "\n$fixtureDir/App/templates/DiscoveryVendor/@layout.latte (layout, missing)"
				. "\n$fixtureDir/App/templates/DiscoveryVendor.@layout.latte (layout, missing)"
				. "\n$fixtureDir/App/templates/@layout.latte (layout, missing)"
				. "\n$fixtureDir/templates/@layout.latte (layout, missing)"
				. "\nopaques: (none)"
				. "\nineffective mutations: (none)",
				$messages[0]['message'],
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// A leftover dump call in the
	// analysed corpus is nonIgnorable, so it can never be silenced by a baseline entry - proven
	// here by asserting the spawn's exit code is non-zero (a real `make phpstan` gate failure)
	// rather than only inspecting the JSON payload the other tests in this file assert on.
	public function testLeftoverDumpCallFailsTheAnalysisNonIgnorably(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';

		try {
			FileSystem::write(
				$srcDir . '/leftover.latte',
				"{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLatteIncluders()}\nHello.\n",
			);

			$isolated = LattePhpstanConfig::create(self::REAL_CONFIG_PATH, [$srcDir], $scratch . '/pstmp');
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

			self::assertNotSame(
				0,
				$process->getExitCode(),
				'a leftover dump call must fail the gate: ' . $process->getOutput() . $process->getErrorOutput(),
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	/**
	 * @param array<string, bool|int|string|list<string>> $extraParameters
	 * @return list<array{message: string, line: int, ignorable: bool, identifier: string}>
	 */
	private function dumpMessages(
		string $projectRoot,
		string $srcDir,
		string $tmpDir,
		array $extraParameters = []
	): array
	{
		$isolated = LattePhpstanConfig::create(self::REAL_CONFIG_PATH, [$srcDir], $tmpDir, $extraParameters);

		try {
			$process = new Process(
				[
					PHP_BINARY,
					$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
					'analyse',
					'--no-progress',
					'--level=8',
					'--error-format=json',
					'-c',
					$isolated->getConfigPath(),
				],
				$projectRoot,
			);
			$process->run();

			/** @var array{files: array<string, array{messages: list<array{message: string, line: int, ignorable: bool, identifier: string}>}>} $decoded */
			$decoded = Json::decode($process->getOutput(), Json::FORCE_ARRAY);

			$messages = [];
			foreach ($decoded['files'] as $fileMessages) {
				foreach ($fileMessages['messages'] as $message) {
					if ($message['identifier'] !== 'orisaiNette.latte.debugDump') {
						continue;
					}

					$messages[] = $message;
				}
			}

			return $messages;
		} finally {
			$isolated->cleanup();
		}
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir: ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file) - a path outside $projectRoot never
		// relativizes, breaking every include-target/edge lookup that assumes project-relative
		// paths.
		$dir = $projectRoot . '/var/tmp/latte-debug-dump-integration-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

}
