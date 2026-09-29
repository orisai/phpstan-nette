<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Parser;

use LogicException;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\SliceClassName;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Parser\LatteRoutingParser;
use OriPhpstan\Nette\Latte\Runtime\Diag;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\InvocationCounter;
use function count;
use function in_array;
use function sha1;
use function sys_get_temp_dir;
use function uniqid;

final class LatteRoutingParserTest extends BaseTestCase
{

	private const ProjectRoot = '/project';

	public function testDelegatesPhpFiles(): void
	{
		$delegate = $this->createMock(Parser::class);
		$delegate->expects(self::once())
			->method('parseFile')
			->with('/tmp/foo.php')
			->willReturn([]);

		$parser = $this->createParser($delegate, true);
		self::assertSame([], $parser->parseFile('/tmp/foo.php'));
	}

	public function testLatteFileYieldsEmptyAstWhenDisabled(): void
	{
		$delegate = $this->createMock(Parser::class);
		$delegate->expects(self::never())->method('parseFile');

		// A nonexistent project root proves the disabled branch never even reaches
		// FileSystem::read()/LatteCompiler - real collaborators are safe to use here.
		$parser = $this->createParser($delegate, false);
		self::assertSame([], $parser->parseFile(self::ProjectRoot . '/foo.latte'));
	}

	// AnalysisPipeline is a constructor-injected dependency of LatteRoutingParser (production
	// wiring: defaultAnalysisParser! decorates PHPStan's own parser, an eagerly-instantiated
	// service) - constructing a disabled parser must never pay the harvest's container-load/engine-
	// create cost, since parseFile() short-circuits to [] before the pipeline is ever touched.
	public function testDisabledParserNeverInvokesTheHarvestLoaderEvenWhenParsingALatteFile(): void
	{
		InvocationCounter::$count = 0;
		$harvester = TestAdapter::harvester(
			new EngineSource(
				null,
				__DIR__ . '/../Customs/Fixtures/engine-loader-counting.php',
			),
		);

		$this->withTempLatteFile(
			"{* comment *}\n",
			function (string $projectRoot, string $latteFile) use ($harvester): void {
				$parser = $this->createParser(
					$this->createMock(Parser::class),
					false,
					$projectRoot,
					null,
					true,
					$harvester,
				);

				self::assertSame(0, InvocationCounter::$count, 'constructing a disabled parser must not harvest');

				self::assertSame([], $parser->parseFile($latteFile));

				self::assertSame(0, InvocationCounter::$count, 'parsing while disabled must not harvest either');
			},
		);
	}

	public function testParseStringDelegates(): void
	{
		$delegate = $this->createMock(Parser::class);
		$delegate->expects(self::once())
			->method('parseString')
			->with('<?php echo 1;')
			->willReturn([new Stmt\Nop()]);

		$parser = $this->createParser($delegate, true);
		self::assertCount(1, $parser->parseString('<?php echo 1;'));
	}

	public function testLatteFileDegradesToEmptyAstWhenFileUnreadable(): void
	{
		$parser = $this->createParser($this->createMock(Parser::class), true);
		self::assertSame([], $parser->parseFile(self::ProjectRoot . '/templates/does-not-exist.latte'));
	}

	public function testLatteFileRoutesThroughCompilerScannerAndPipelineWhenEnabled(): void
	{
		$this->withTempLatteFile("{* comment *}\n", function (string $projectRoot, string $latteFile): void {
			$parser = $this->createParser($this->createMock(Parser::class), true, $projectRoot);

			$stmts = $parser->parseFile($latteFile);

			self::assertNotSame([], $stmts);
			$class = (new NodeFinder())->findFirstInstanceOf($stmts, Class_::class);
			self::assertInstanceOf(Class_::class, $class);
			self::assertNotNull($class->name);
			self::assertSame(TemplateClassName::forPath('templates/foo.latte'), $class->name->toString());
		});
	}

	public function testParseFileMemoizesRepeatCallsForSamePath(): void
	{
		$this->withTempLatteFile("{* comment *}\n", function (string $projectRoot, string $latteFile): void {
			$parser = $this->createParser($this->createMock(Parser::class), true, $projectRoot);

			$first = $parser->parseFile($latteFile);
			$second = $parser->parseFile($latteFile);

			self::assertNotSame([], $first, 'sanity check: a real AST was produced');
			self::assertSame(
				$first,
				$second,
				'a second parseFile() of the same path must return the SAME node instances, not a fresh recompile',
			);
		});
	}

	public function testLatteFileMaterializesDiagnosticsWhenEnabled(): void
	{
		$latte = "{varType string \$greeting}\n{\$greeting|notARealLatteFilterRouterTest}\n";

		$this->withTempLatteFile($latte, function (string $projectRoot, string $latteFile): void {
			$parser = $this->createParser($this->createMock(Parser::class), true, $projectRoot);

			$stmts = $parser->parseFile($latteFile);

			self::assertNotSame([], $stmts, 'diagnostics must never be silently dropped');
			self::assertTrue(
				$this->containsDiagReportCall($stmts),
				'materialized AST must contain a Diag::report() call for the unknown-filter diagnostic',
			);
		});
	}

	// The includer's own SiteScopeStore slice must fold into its exported
	// LATTE_EDGE_FINGERPRINT - proven here at the REAL parser (not just EdgeFingerprint::compute()
	// in isolation, see EdgeFingerprintTest): the same source, routed through two parsers that
	// differ ONLY in which store instance is injected, must emit two DIFFERENT fingerprint values.
	public function testFingerprintReflectsInjectedSiteScopeStoreSlice(): void
	{
		$latte = "{varType int \$n}\n{include 'partial.latte', w => \$n}\n";

		$this->withTempLatteFile($latte, function (string $projectRoot, string $latteFile) use ($latte): void {
			$emptyStore = PipelineFactory::createSiteScopeStore($projectRoot);

			$seededStore = new SiteScopeStore($projectRoot . '/seeded-store.php');
			$seededStore->replaceForIncluders(
				['templates/foo.latte'],
				[
					SiteScopeStore::key('templates/foo.latte', 2, "'partial.latte'", 'anycontext') => [
						'sha' => sha1($latte),
						'vars' => ['n' => 'int'],
						'args' => [],
					],
				],
			);

			$withEmptySlice = $this->createParser($this->createMock(Parser::class), true, $projectRoot, $emptyStore)
				->parseFile($latteFile);
			$withPopulatedSlice = $this->createParser(
				$this->createMock(Parser::class),
				true,
				$projectRoot,
				$seededStore,
			)
				->parseFile($latteFile);

			self::assertNotSame(
				$this->fingerprintOf($withEmptySlice),
				$this->fingerprintOf($withPopulatedSlice),
				'the same source must yield a different LATTE_EDGE_FINGERPRINT when its own store slice differs',
			);
		});
	}

	// Routing-parser ref emission: a TARGET's compiled class references its
	// includer's slice class only when that slice already exists on disk - proven here against the
	// real parser (not just DependencyEdgeEmitter::emitSliceRefs() in isolation), using a real
	// two-file includer/target project so TemplateEdgeIndex resolves a genuine incoming edge.
	public function testTargetEmitsSliceRefForIncluderWhoseSliceExistsOnDisk(): void
	{
		$this->withTempIncluderAndTarget(function (string $projectRoot) {
			$storeDir = $projectRoot . '/sitescope';
			SiteScopeStore::bootstrap($storeDir, ['includer.latte']);
			$store = new SiteScopeStore($storeDir);

			$parser = $this->createParser($this->createMock(Parser::class), true, $projectRoot, $store);
			$stmts = $parser->parseFile($projectRoot . '/target.latte');

			self::assertTrue(
				$this->containsAnalyzedCallFor($stmts, SliceClassName::forPath('includer.latte')),
				"the target must reference its includer's slice class once that slice exists on disk",
			);
		});
	}

	public function testTargetEmitsNoSliceRefWhenIncludersSliceIsAbsent(): void
	{
		$this->withTempIncluderAndTarget(function (string $projectRoot) {
			// No SiteScopeStore::bootstrap() call at all: the store directory itself is absent.
			$parser = $this->createParser($this->createMock(Parser::class), true, $projectRoot);
			$stmts = $parser->parseFile($projectRoot . '/target.latte');

			self::assertFalse(
				$this->containsAnalyzedCallFor($stmts, SliceClassName::forPath('includer.latte')),
				'the target must never reference a slice class absent from disk - it would be a '
				. 'class.notFound error',
			);
		});
	}

	// Opt-in gate: disabled narrowing must never reference an includer's slice
	// class, even when that slice genuinely exists on disk - "no slice refs" holds regardless of
	// store state when the flag is off.
	public function testTargetEmitsNoSliceRefWhenNarrowingDisabledEvenWhenSliceExistsOnDisk(): void
	{
		$this->withTempIncluderAndTarget(function (string $projectRoot) {
			$storeDir = $projectRoot . '/sitescope';
			SiteScopeStore::bootstrap($storeDir, ['includer.latte']);
			$store = new SiteScopeStore($storeDir);

			$parser = $this->createParser($this->createMock(Parser::class), true, $projectRoot, $store, false);
			$stmts = $parser->parseFile($projectRoot . '/target.latte');

			self::assertFalse(
				$this->containsAnalyzedCallFor($stmts, SliceClassName::forPath('includer.latte')),
				'narrowing disabled must suppress the slice ref even though the slice exists on disk',
			);
		});
	}

	// Opt-in gate: no anchor is ever materialized when narrowing is disabled, even at a real
	// outgoing include site guarded by a narrowing-eligible {if} check.
	public function testNoEdgeScopeAnchorEmittedWhenNarrowingDisabled(): void
	{
		$this->withTempIncluderAndTarget(function (string $projectRoot) {
			$parser = $this->createParser($this->createMock(Parser::class), true, $projectRoot, null, false);
			$stmts = $parser->parseFile($projectRoot . '/includer.latte');

			self::assertFalse(
				$this->containsEdgeScopeCall($stmts),
				'narrowing disabled must never emit a Helpers::edgeScope() anchor',
			);
		});
	}

	// Opt-in gate: the fingerprint's own store-derived component must equal the value a real,
	// permanently-empty store would produce - proven against a store that is genuinely POPULATED
	// (so this cannot pass merely because nothing was ever captured).
	public function testFingerprintUsesTheStableEmptyHashWhenNarrowingDisabledEvenWithAPopulatedStore(): void
	{
		$latte = "{varType int \$n}\n{include 'partial.latte', w => \$n}\n";

		$this->withTempLatteFile($latte, function (string $projectRoot, string $latteFile) use ($latte): void {
			$emptyStore = PipelineFactory::createSiteScopeStore($projectRoot);

			$seededStore = new SiteScopeStore($projectRoot . '/seeded-store.php');
			$seededStore->replaceForIncluders(
				['templates/foo.latte'],
				[
					SiteScopeStore::key('templates/foo.latte', 2, "'partial.latte'", 'anycontext') => [
						'sha' => sha1($latte),
						'vars' => ['n' => 'int'],
						'args' => [],
					],
				],
			);

			$withEmptyStoreDisabled = $this->createParser(
				$this->createMock(Parser::class),
				true,
				$projectRoot,
				$emptyStore,
				false,
			)->parseFile($latteFile);
			$withPopulatedStoreDisabled = $this->createParser(
				$this->createMock(Parser::class),
				true,
				$projectRoot,
				$seededStore,
				false,
			)->parseFile($latteFile);

			self::assertSame(
				$this->fingerprintOf($withEmptyStoreDisabled),
				$this->fingerprintOf($withPopulatedStoreDisabled),
				'disabled must produce the identical fingerprint regardless of what the store contains',
			);
		});
	}

	/**
	 * @param array<Stmt> $stmts
	 */
	private function containsEdgeScopeCall(array $stmts): bool
	{
		foreach ((new NodeFinder())->findInstanceOf($stmts, StaticCall::class) as $call) {
			if (
				$call->class instanceof Name
				&& $call->class->toString() === Helpers::class
				&& $call->name instanceof Identifier
				&& $call->name->toString() === 'edgeScope'
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param callable(string): void $test
	 */
	private function withTempIncluderAndTarget(callable $test): void
	{
		$projectRoot = sys_get_temp_dir() . '/latte-router-slice-ref-test-' . uniqid('', true);
		FileSystem::write(
			$projectRoot . '/includer.latte',
			"{varType Exception|null \$x}\n{if \$x !== null}\n\t{include 'target.latte'}\n{/if}\n",
		);
		FileSystem::write($projectRoot . '/target.latte', "{\$x}\n");

		try {
			$test($projectRoot);
		} finally {
			FileSystem::delete($projectRoot);
		}
	}

	/**
	 * @param array<Stmt> $stmts
	 */
	private function containsAnalyzedCallFor(array $stmts, string $className): bool
	{
		foreach ((new NodeFinder())->findInstanceOf($stmts, StaticCall::class) as $call) {
			if (
				!$call->class instanceof Name
				|| $call->class->toString() !== Helpers::class
				|| !$call->name instanceof Identifier
				|| $call->name->toString() !== 'analyzed'
				|| count($call->args) !== 1
				|| !$call->args[0] instanceof Arg
				|| !$call->args[0]->value instanceof ClassConstFetch
				|| !$call->args[0]->value->class instanceof Name
			) {
				continue;
			}

			if ($call->args[0]->value->class->toString() === $className) {
				return true;
			}
		}

		return false;
	}

	private function createParser(
		Parser $delegate,
		bool $enabled,
		?string $projectRoot = null,
		?SiteScopeStore $siteScopeStore = null,
		bool $narrowingEnabled = true,
		?CustomsHarvester $harvester = null
	): LatteRoutingParser
	{
		$root = $projectRoot ?? self::ProjectRoot;
		$contextResolver = PipelineFactory::createContextResolver($root, null, $narrowingEnabled);

		return new LatteRoutingParser(
			$delegate,
			TestAdapter::accessor(),
			PipelineFactory::create(null, null, $narrowingEnabled, $harvester),
			$contextResolver,
			PipelineFactory::createIncludeContractChecker($root, $contextResolver, null, $narrowingEnabled),
			PipelineFactory::createDeclarationConsistencyChecker($root),
			PipelineFactory::createTemplateEdgeIndex($root),
			$siteScopeStore ?? PipelineFactory::createSiteScopeStore($root),
			PipelineFactory::createRichAttributeDecorator(),
			$root,
			$enabled,
			$narrowingEnabled,
		);
	}

	/**
	 * @param array<Stmt> $stmts
	 */
	private function fingerprintOf(array $stmts): string
	{
		foreach ($stmts as $stmt) {
			if (!$stmt instanceof Class_) {
				continue;
			}

			foreach ($stmt->stmts as $classStmt) {
				if (!$classStmt instanceof ClassConst) {
					continue;
				}

				foreach ($classStmt->consts as $const) {
					if ($const->name->toString() !== 'LATTE_EDGE_FINGERPRINT') {
						continue;
					}

					self::assertInstanceOf(String_::class, $const->value);

					return $const->value->value;
				}
			}
		}

		throw new LogicException('no LATTE_EDGE_FINGERPRINT class constant found');
	}

	/**
	 * @param callable(string, string): void $test
	 */
	private function withTempLatteFile(string $source, callable $test): void
	{
		$projectRoot = sys_get_temp_dir() . '/latte-router-test-' . uniqid('', true);
		$latteFile = $projectRoot . '/templates/foo.latte';
		FileSystem::write($latteFile, $source);

		try {
			$test($projectRoot, $latteFile);
		} finally {
			FileSystem::delete($projectRoot);
		}
	}

	/**
	 * @param array<Stmt> $stmts
	 */
	private function containsDiagReportCall(array $stmts): bool
	{
		foreach ($stmts as $stmt) {
			if (!$stmt instanceof Class_) {
				continue;
			}

			foreach ($stmt->stmts as $classStmt) {
				if (
					!$classStmt instanceof ClassMethod
					|| !in_array($classStmt->name->toString(), ['latteMain', 'latteMain_ctx0'], true)
				) {
					continue;
				}

				foreach ($classStmt->stmts ?? [] as $bodyStmt) {
					if (
						$bodyStmt instanceof Expression
						&& $bodyStmt->expr instanceof StaticCall
						&& $bodyStmt->expr->class instanceof Name
						&& $bodyStmt->expr->class->toString() === Diag::class
						&& $bodyStmt->expr->name instanceof Identifier
						&& $bodyStmt->expr->name->toString() === 'report'
					) {
						return true;
					}
				}
			}
		}

		return false;
	}

}
