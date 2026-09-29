<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Includes;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Compile\ProjectRelativePath;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\CapturedOverlay;
use OriPhpstan\Nette\Latte\Includes\ContextResolver;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_map;
use function array_unique;
use function dirname;
use function getmypid;
use function preg_match;
use function sha1_file;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use const PHP_BINARY;

/**
 * @group latte2
 */
final class ContextResolverTest extends BaseTestCase
{

	public function testSharedPartialGetsTwoContextsDedupedByVars(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('partial.latte'));

		self::assertCount(2, $contexts);
		$varsA = $contexts[0]->getVars();
		$varsB = $contexts[1]->getVars();
		self::assertNotSame($varsA, $varsB);
		// partial.latte declares `{varType int $extra}`, so the target's declared type wins on
		// every edge (see testTargetDeclaredTypeOverridesProvidedType) - both contexts show
		// extra: 'int', even though other-root.latte provides it as a string. The two contexts
		// stay distinct because root.latte/third-root.latte also carry count/title, while
		// other-root.latte carries only extra.
		self::assertSame('int', $varsA['extra']);
		self::assertSame('int', $varsB['extra']);
	}

	public function testRootContextForUnincludedFile(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('root.latte'));

		self::assertCount(1, $contexts);
		self::assertSame(['count' => 'int', 'title' => 'string'], $contexts[0]->getVars());
	}

	public function testLayoutSeesChildParamsAndTopLevelVars(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('@layout.latte'));

		self::assertCount(1, $contexts);
		self::assertArrayHasKey('childVar', $contexts[0]->getVars());
	}

	public function testCycleTerminates(): void
	{
		// contextsFor() is statically typed to always return a list<TemplateContext>, so the
		// meaningful assertion is that resolution actually terminates (returns at all, rather
		// than recursing forever) with the cut edge recorded.
		$contexts = $this->resolver()->contextsFor($this->rel('cycle-a.latte'));

		self::assertCount(1, $contexts);
	}

	public function testIdenticalContextsDedupe(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('partial.latte'));
		$hashes = array_map(static fn (TemplateContext $c): string => $c->canonicalHash(), $contexts);

		self::assertSame($hashes, array_unique($hashes));
	}

	public function testSandboxOnlySeesExplicitArgsNotIncluderVars(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('blocks.latte'));

		$withLabel = null;
		foreach ($contexts as $context) {
			if (isset($context->getVars()['label'])) {
				$withLabel = $context;
			}
		}

		self::assertNotNull($withLabel);
		self::assertSame('string', $withLabel->getVars()['label']);
		self::assertArrayNotHasKey('secret', $withLabel->getVars());
	}

	public function testIncludeDoesNotLeakIncluderTopLevelVars(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('leak-check-target.latte'));

		self::assertCount(1, $contexts);
		self::assertArrayNotHasKey('localOnly', $contexts[0]->getVars());
	}

	public function testExpandOpensContextFillingUnprovidedDeclaredVarsAsMixed(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('expand-target.latte'));

		self::assertCount(1, $contexts);
		self::assertSame('int', $contexts[0]->getVars()['known']);
		self::assertSame('mixed', $contexts[0]->getVars()['other']);
	}

	public function testChainProvenanceRecorded(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('@layout.latte'));

		self::assertSame([$this->rel('child.latte')], $contexts[0]->getChain());
	}

	public function testDeepChainIsCutAtDepthCapAndRecorded(): void
	{
		// chain-0 includes chain-1 includes chain-2 ... includes chain-19 (root, no include):
		// resolving the deepest target (chain-19) requires walking 20 levels of incoming edges,
		// past the depth cap.
		$dir = sys_get_temp_dir() . '/latte-context-resolver-test-' . getmypid() . '-' . uniqid('', true);

		for ($i = 0; $i < 20; $i++) {
			$content = $i === 19 ? "Root.\n" : "{include 'chain-" . ($i + 1) . ".latte'}\n";
			FileSystem::write($dir . '/chain-' . $i . '.latte', $content);
		}

		$universe = new LatteUniverse([$dir], $dir);
		$resolver = new ContextResolver(
			new TemplateEdgeIndex($universe, new TemplateFactExtractor()),
			new DeclarationScanner(),
			$universe,
			new CapturedOverlay(new SiteScopeStore($dir . '/__absent_sitescope_store__'), true),
		);

		$contexts = $resolver->contextsFor('chain-19.latte');

		self::assertNotSame([], $contexts);
		// A straight, non-cyclic chain: the cut belongs in depthCapCuts(), never cutCycleEdges()
		// (see Important 3 - a depth-cap cut reported as orisaiNette.latte.includeCycle would be
		// worker/traversal-order-dependent).
		self::assertNotSame([], $resolver->depthCapCuts());
		self::assertSame([], $resolver->cutCycleEdges());

		FileSystem::delete($dir);
	}

	public function testCycleMemberContextsAreCallOrderIndependent(): void
	{
		$resolverA = $this->resolver();
		$resolverA->contextsFor($this->rel('cycle-a.latte'));
		$viaAFirst = $resolverA->contextsFor($this->rel('cycle-b.latte'));

		$resolverB = $this->resolver();
		$viaFresh = $resolverB->contextsFor($this->rel('cycle-b.latte'));

		self::assertEquals($viaFresh, $viaAFirst);
	}

	public function testCutCycleEdgesStableAcrossRepeatedCalls(): void
	{
		$resolver = $this->resolver();
		$resolver->contextsFor($this->rel('cycle-a.latte'));
		$first = $resolver->cutCycleEdges();
		$resolver->contextsFor($this->rel('cycle-a.latte'));

		self::assertSame($first, $resolver->cutCycleEdges());
	}

	public function testTargetDeclaredTypeOverridesProvidedType(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('partial.latte'));

		foreach ($contexts as $context) {
			self::assertSame('int', $context->getVars()['extra']);
		}
	}

	// Provenance (consumed by dumpLatteVarOrigin) tests below - each pins one label the resolver
	// can emit, reusing the SAME fixtures/values the matching non-provenance test above already
	// pins, so a provenance regression can never hide behind a type-only assertion passing.

	public function testDeclaredVarTypeProvenanceRecordsTheDeclaringLine(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('root.latte'));

		self::assertCount(1, $contexts);
		self::assertSame('declared:varType@2', $contexts[0]->getProvenance()['count']);
		self::assertSame('declared:varType@3', $contexts[0]->getProvenance()['title']);
	}

	public function testClosedIncludeDeclaredTargetVarProvenanceWinsOverProvidedArg(): void
	{
		// partial.latte declares {varType int $extra} at line 3 AND receives it as an arg from
		// root.latte - testTargetDeclaredTypeOverridesProvidedType above pins that the declared
		// type wins; this pins that its provenance says so too, never "arg:...".
		$contexts = $this->resolver()->contextsFor($this->rel('partial.latte'));

		foreach ($contexts as $context) {
			self::assertSame('declared:varType@3', $context->getProvenance()['extra']);
		}
	}

	public function testOpenIncludeProvenanceDistinguishesNamedArgFromDefaultMixed(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('expand-target.latte'));

		self::assertCount(1, $contexts);
		// 'known' is named at the call site (expand-site.latte: `known => 1`) - the open branch's
		// own declared-type-wins rule applies, so its provenance is the target's OWN declaration,
		// not an "arg:" label.
		self::assertSame('declared:varType@2', $contexts[0]->getProvenance()['known']);
		self::assertSame('default:mixed', $contexts[0]->getProvenance()['other']);
	}

	public function testLayoutInheritsChildsTopLevelVarProvenance(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('@layout.latte'));

		self::assertCount(1, $contexts);
		self::assertSame('topLevel:' . $this->rel('child.latte'), $contexts[0]->getProvenance()['childVar']);
	}

	public function testSandboxArgProvenanceRecordsIncluderAndLine(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('blocks.latte'));

		$withLabel = null;
		foreach ($contexts as $context) {
			if (isset($context->getVars()['label'])) {
				$withLabel = $context;
			}
		}

		self::assertNotNull($withLabel);
		self::assertSame('arg:' . $this->rel('sandbox-parent.latte') . '#3', $withLabel->getProvenance()['label']);
	}

	public function testTemplateTypePropertyProvenance(): void
	{
		$dir = $this->isolatedDir('provenance-templatetype');
		FileSystem::write(
			$dir . '/root.latte',
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Fixtures\\Support\\FixtureTemplate}\n",
		);

		try {
			$contexts = $this->isolatedResolver($dir, $this->emptyStore($dir))->contextsFor('root.latte');

			self::assertCount(1, $contexts);
			self::assertSame('declared:templateType', $contexts[0]->getProvenance()['title']);
			self::assertSame('declared:templateType', $contexts[0]->getProvenance()['count']);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testParametersProvenanceRecordsTheDeclaringLine(): void
	{
		$dir = $this->isolatedDir('provenance-parameters');
		FileSystem::write($dir . '/target.latte', "{parameters string \$name}\n{\$name}\n");

		try {
			$contexts = $this->isolatedResolver($dir, $this->emptyStore($dir))->contextsFor('target.latte');

			self::assertSame('declared:parameters@1', $contexts[0]->getProvenance()['name']);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testMultiHopChainOrderedRootToNearest(): void
	{
		$contexts = $this->resolver()->contextsFor($this->rel('blocks.latte'));

		$expectedChain = [$this->rel('deep-root.latte'), $this->rel('importer.latte')];
		$withChain = null;
		foreach ($contexts as $context) {
			if ($context->getChain() === $expectedChain) {
				$withChain = $context;
			}
		}

		self::assertNotNull($withChain);
	}

	public function testIncluderOfUnknownTemplateTypeFileGetsNoUnknownTypeDiagnostic(): void
	{
		// orisaiNette.latte.unknownType belongs solely to the file that DECLARES the bad templateType, reported
		// when THAT file's own DeclarationInjector runs; a consumer only reaching the declaration
		// through ContextResolver/DeclaredVarsResolver must never re-report it (see
		// DeclaredVarsResolver::forFile()'s degrade-to-no-vars catch).
		self::assertSame(
			['orisaiNette.latte.unknownType'],
			$this->diagnosticIdsFor('unknown-templatetype-target.latte'),
		);
		self::assertNotContains(
			'orisaiNette.latte.unknownType',
			$this->diagnosticIdsFor('unknown-templatetype-includer.latte'),
		);
	}

	public function testUndeclaredVarNarrowedFromStore(): void
	{
		$dir = $this->isolatedDir('narrow-undeclared');
		FileSystem::write($dir . '/includer.latte', "{varType stdClass|null \$x}\n{include 'target.latte'}\n");
		FileSystem::write($dir . '/target.latte', "hello\n");

		try {
			$store = $this->emptyStore($dir);
			$includerContext = $this->isolatedResolver($dir, $store)->contextsFor('includer.latte')[0];
			$this->seedSoleSiteEntry($store, $dir, 'includer.latte', $includerContext, ['x' => 'stdClass'], []);

			$targetContexts = $this->isolatedResolver($dir, $store)->contextsFor('target.latte');

			self::assertSame('stdClass', $targetContexts[0]->getVars()['x']);
			self::assertSame(
				'captured:includer.latte#2',
				$targetContexts[0]->getProvenance()['x'],
				'the store overlay must relabel provenance too, not just the type',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDeclaredVarNotOverriddenByStorePerVariablePin(): void
	{
		$dir = $this->isolatedDir('narrow-declared-pin');
		FileSystem::write($dir . '/includer.latte', "{varType stdClass|null \$x}\n{include 'target.latte'}\n");
		FileSystem::write($dir . '/target.latte', "{varType stdClass \$x}\nhello\n");

		try {
			$store = $this->emptyStore($dir);
			$includerContext = $this->isolatedResolver($dir, $store)->contextsFor('includer.latte')[0];
			// A deliberately wrong captured type: even an entry that disagrees with the target's
			// own declaration must never win - declarations are pinned per variable by construction
			// (the overlay runs strictly before the declared-target merge in buildEdgeContext).
			$this->seedSoleSiteEntry($store, $dir, 'includer.latte', $includerContext, ['x' => 'array'], []);

			$targetContexts = $this->isolatedResolver($dir, $store)->contextsFor('target.latte');

			self::assertSame('stdClass', $targetContexts[0]->getVars()['x']);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testParametersTargetBodyUnchangedByStore(): void
	{
		$dir = $this->isolatedDir('narrow-parameters');
		FileSystem::write(
			$dir . '/includer.latte',
			"{varType string \$y}\n{include 'target.latte', name => \$y . '!'}\n",
		);
		FileSystem::write($dir . '/target.latte', "{parameters string \$name}\n{\$name}\n");

		try {
			$store = $this->emptyStore($dir);
			$includerContext = $this->isolatedResolver($dir, $store)->contextsFor('includer.latte')[0];
			$this->seedSoleSiteEntry(
				$store,
				$dir,
				'includer.latte',
				$includerContext,
				[],
				['name' => 'non-falsy-string'],
			);

			$targetContexts = $this->isolatedResolver($dir, $store)->contextsFor('target.latte');

			// {parameters}'s own declared type wins - the captured arg never reaches the body as an
			// override, exactly as it never did for a plain {varType} declaration (see
			// testDeclaredVarNotOverriddenByStorePerVariablePin above).
			self::assertSame('string', $targetContexts[0]->getVars()['name']);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testCapturedArgOverridesArgTyperMixedForCompoundExpression(): void
	{
		$dir = $this->isolatedDir('narrow-compound-arg');
		FileSystem::write(
			$dir . '/includer.latte',
			"{varType string \$y}\n{include 'target.latte', name => \$y . '!'}\n",
		);
		FileSystem::write($dir . '/target.latte', "hello\n");

		try {
			$store = $this->emptyStore($dir);
			$resolverBefore = $this->isolatedResolver($dir, $store);
			$includerContext = $resolverBefore->contextsFor('includer.latte')[0];
			// Baseline: $y . '!' is a compound expression (not a single token), so ArgTyper
			// classifies it 'mixed' - confirm that BEFORE seeding the store, to prove what follows
			// is really an override rather than a coincidence.
			self::assertSame('mixed', $resolverBefore->contextsFor('target.latte')[0]->getVars()['name']);

			$this->seedSoleSiteEntry(
				$store,
				$dir,
				'includer.latte',
				$includerContext,
				[],
				['name' => 'non-falsy-string'],
			);

			$targetContexts = $this->isolatedResolver($dir, $store)->contextsFor('target.latte');

			self::assertSame('non-falsy-string', $targetContexts[0]->getVars()['name']);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testShaMismatchSliceIsIgnored(): void
	{
		$dir = $this->isolatedDir('narrow-sha-mismatch');
		FileSystem::write($dir . '/includer.latte', "{varType stdClass|null \$x}\n{include 'target.latte'}\n");
		FileSystem::write($dir . '/target.latte', "hello\n");

		try {
			$store = $this->emptyStore($dir);
			$includerContext = $this->isolatedResolver($dir, $store)->contextsFor('includer.latte')[0];
			$this->seedSoleSiteEntry(
				$store,
				$dir,
				'includer.latte',
				$includerContext,
				['x' => 'stdClass'],
				[],
				'0000000000000000000000000000000000dead',
			);

			$targetContexts = $this->isolatedResolver($dir, $store)->contextsFor('target.latte');

			self::assertSame('stdClass|null', $targetContexts[0]->getVars()['x']);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testGarbageTypeStringInStoreDoesNotCrashResolver(): void
	{
		$dir = $this->isolatedDir('narrow-garbage');
		FileSystem::write($dir . '/includer.latte', "{varType stdClass|null \$x}\n{include 'target.latte'}\n");
		FileSystem::write($dir . '/target.latte', "hello\n");

		try {
			$store = $this->emptyStore($dir);
			$includerContext = $this->isolatedResolver($dir, $store)->contextsFor('includer.latte')[0];
			$this->seedSoleSiteEntry(
				$store,
				$dir,
				'includer.latte',
				$includerContext,
				['x' => '~~~not a valid type~~~'],
				[],
			);

			// ContextResolver never parses the captured string - it is a raw, opaque replacement.
			// The garbage-string degradation itself happens downstream, where the type STRING is
			// finally turned into a Type object (verified separately: DeclarationInjector's
			// PHPDoc-emission path degrades via PHPStan's own tolerant @param tag parser, never a
			// crash).
			$targetContexts = $this->isolatedResolver($dir, $store)->contextsFor('target.latte');

			self::assertSame('~~~not a valid type~~~', $targetContexts[0]->getVars()['x']);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testOpenContextUnaffectedWithoutCapture(): void
	{
		// Parity check: an empty store must reproduce testExpandOpensContextFillingUnprovidedDeclaredVarsAsMixed's
		// result exactly, proving the overlay is a true no-op absent any capture.
		$contexts = $this->resolverWithStore($this->emptyStore($this->treeDir()))
			->contextsFor($this->rel('expand-target.latte'));

		self::assertCount(1, $contexts);
		self::assertSame('int', $contexts[0]->getVars()['known']);
		self::assertSame('mixed', $contexts[0]->getVars()['other']);
	}

	public function testOpenContextArgOverlaidWhenCaptureExists(): void
	{
		$dir = $this->isolatedDir('narrow-open-capture');
		FileSystem::write(
			$dir . '/includer.latte',
			"{varType string \$y}\n{include 'target.latte', name => \$y . '!', (expand) \$rest}\n",
		);
		FileSystem::write($dir . '/target.latte', "{varType int \$known}\nhello\n");

		try {
			$store = $this->emptyStore($dir);
			$includerContext = $this->isolatedResolver($dir, $store)->contextsFor('includer.latte')[0];
			$this->seedSoleSiteEntry(
				$store,
				$dir,
				'includer.latte',
				$includerContext,
				[],
				['name' => 'non-falsy-string'],
			);

			$targetContexts = $this->isolatedResolver($dir, $store)->contextsFor('target.latte');
			$vars = $targetContexts[0]->getVars();

			self::assertSame('non-falsy-string', $vars['name'], 'captured arg must overlay namedKeys under $open too');
			// 'known' is declared but never named at the call site - the $open branch's own
			// declared-overlay semantics (fall back to 'mixed' when not in namedKeys) stay untouched.
			self::assertSame('mixed', $vars['known']);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testStoreKeyRoundTripsThroughInjectorsOwnCanonicalHash(): void
	{
		$dir = $this->isolatedDir('narrow-roundtrip');
		FileSystem::write($dir . '/includer.latte', "{varType stdClass|null \$x}\n{include 'target.latte'}\n");
		FileSystem::write($dir . '/target.latte', "hello\n");

		try {
			$source = FileSystem::read($dir . '/includer.latte');
			$compiled = (new LatteCompiler())->compile($source, 'LatteTpl_roundtrip_test');
			$declarations = (new DeclarationScanner())->scan($source);
			$contexts = PipelineFactory::createContextResolver($dir)->contextsFor('includer.latte');

			$dump = PipelineFactory::create($dir)->dump($compiled, $declarations, $contexts, 'includer.latte');

			// The anchor EdgeAnchorInjector actually emits, not a hand-derived guess - proves
			// ContextResolver's consume-side key construction (includerRel#latteLine#rawTarget#
			// context->canonicalHash()) is byte-identical to what the injector embeds at capture time.
			if (preg_match(
				'~Helpers::edgeScope\(\'(includer\.latte#\d+#target\.latte#[0-9a-f]{40})\'~',
				$dump,
				$m,
			) !== 1) {
				self::fail('fixture must produce a real anchor: ' . $dump);
			}

			$store = new SiteScopeStore($dir . '/roundtrip-store.php');
			$sha = (string) sha1_file($dir . '/includer.latte');
			$store->replaceForIncluders(
				['includer.latte'],
				[$m[1] => ['sha' => $sha, 'vars' => ['x' => 'stdClass'], 'args' => []]],
			);

			$targetContexts = PipelineFactory::createContextResolver($dir, $store)->contextsFor('target.latte');

			self::assertSame('stdClass', $targetContexts[0]->getVars()['x']);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Real subprocess spawns (mirrors LatteSiteScopeCaptureIntegrationTest), not RuleTestCase: only a
	// spawn from the real repo root exercises the collector against genuinely narrowed PHPStan types
	// and the writer's own store-file bootstrap gate. Run 2 uses a FRESH tmpDir/result-cache (never
	// run 1's) so this test isolates the overlay's narrowing of a COLD analysis from the separate
	// mechanism (folding the store slice hash into LATTE_EDGE_FINGERPRINT so PHPStan's own result
	// cache invalidates on a store change) that ResultCacheInvalidationTest exercises instead.
	public function testEndToEndNarrowingRemovesNullabilityErrorAfterRealCapture(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$run1Dir = sys_get_temp_dir() . '/latte-narrowing-e2e-run1-' . getmypid() . '-' . uniqid('', true);
		$run2Dir = sys_get_temp_dir() . '/latte-narrowing-e2e-run2-' . getmypid() . '-' . uniqid('', true);
		FileSystem::createDir($run1Dir);
		FileSystem::createDir($run2Dir);

		try {
			$storeDir = $run1Dir . '/store';
			SiteScopeStore::bootstrap($storeDir, []);

			$run1 = $this->spawnPhpstan($projectRoot, $this->writeE2eWrapperConfig($run1Dir, $storeDir));
			self::assertStringContainsString(
				'Includes/Fixtures/NarrowingConsume/narrowing-target.latte:1:Cannot call method getMessage() on '
				. 'Exception|null. [identifier=method.nonObject]',
				$run1->getOutput(),
				'run 1 (empty store) must report the wide-type nullability error: '
				. $run1->getOutput() . $run1->getErrorOutput(),
			);

			$run2 = $this->spawnPhpstan($projectRoot, $this->writeE2eWrapperConfig($run2Dir, $storeDir));
			self::assertSame(
				'',
				trim($run2->getOutput()),
				'run 2 (store captured by run 1) must narrow $x to Exception and report zero errors: '
				. $run2->getOutput() . $run2->getErrorOutput(),
			);
		} finally {
			FileSystem::delete($run1Dir);
			FileSystem::delete($run2Dir);
		}
	}

	private function spawnPhpstan(string $projectRoot, string $configPath): Process
	{
		$process = new Process(
			[
				PHP_BINARY,
				$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=raw',
				'-v',
				'-c',
				$configPath,
			],
			$projectRoot,
		);
		$process->setTimeout(120.0);
		$process->run();

		return $process;
	}

	private function writeE2eWrapperConfig(string $scratchDir, string $storeDir): string
	{
		$configPath = $scratchDir . '/wrapper.neon';
		FileSystem::write(
			$configPath,
			Neon::encode(
				[
					'includes' => [__DIR__ . '/../Integration/Fixtures/integration.neon'],
					'parameters' => [
						'tmpDir' => $scratchDir,
						'fileExtensions' => ['php', 'latte'],
						'orisaiNette' => [
							'latte' => [
								'enabled' => true,
								'narrowing' => ['storePath' => $storeDir],
							],
						],
						'paths!' => [dirname(__DIR__, 3) . '/Unit/Latte/Includes/Fixtures/NarrowingConsume'],
					],
				],
				true,
			),
		);

		return $configPath;
	}

	private function isolatedDir(string $prefix): string
	{
		return sys_get_temp_dir() . '/latte-context-resolver-' . $prefix . '-' . getmypid() . '-' . uniqid('', true);
	}

	private function isolatedResolver(string $dir, SiteScopeStore $store): ContextResolver
	{
		$universe = new LatteUniverse([$dir], $dir);
		$index = new TemplateEdgeIndex($universe, new TemplateFactExtractor());

		return new ContextResolver($index, new DeclarationScanner(), $universe, new CapturedOverlay($store, true));
	}

	/**
	 * @param array<string, string> $vars
	 * @param array<string, string> $args
	 */
	private function seedSoleSiteEntry(
		SiteScopeStore $store,
		string $dir,
		string $includerBasename,
		TemplateContext $includerContext,
		array $vars,
		array $args,
		?string $sha = null
	): void
	{
		$universe = new LatteUniverse([$dir], $dir);
		$index = new TemplateEdgeIndex($universe, new TemplateFactExtractor());
		$sites = $index->outgoingSites($includerBasename);
		self::assertNotSame([], $sites, 'fixture must declare at least one outgoing site');
		$site = $sites[0];

		$key = SiteScopeStore::key(
			$includerBasename,
			$site->getLatteLine(),
			$site->getRawTarget(),
			$includerContext->canonicalHash(),
		);
		$actualSha = $sha ?? (string) sha1_file($dir . '/' . $includerBasename);

		$store->replaceForIncluders(
			[$includerBasename],
			[$key => ['sha' => $actualSha, 'vars' => $vars, 'args' => $args]],
		);
	}

	/**
	 * @return array<string>
	 */
	private function diagnosticIdsFor(string $basename): array
	{
		$source = FileSystem::read($this->treeDir() . '/' . $basename);
		$compiled = (new LatteCompiler())->compile($source, 'LatteTpl_test');
		$declarations = (new DeclarationScanner())->scan($source);
		$contexts = $this->resolver()->contextsFor($this->rel($basename));

		$pipeline = PipelineFactory::create();
		$pipeline->process($compiled, $declarations, $contexts);

		return array_map(static fn (Diagnostic $d): string => $d->getIdentifier(), $pipeline->getDiagnostics());
	}

	private function resolver(): ContextResolver
	{
		return $this->resolverWithStore($this->emptyStore($this->treeDir()));
	}

	private function resolverWithStore(SiteScopeStore $store): ContextResolver
	{
		$universe = new LatteUniverse([$this->treeDir()], dirname(__DIR__, 3));
		$index = new TemplateEdgeIndex($universe, new TemplateFactExtractor());

		return new ContextResolver($index, new DeclarationScanner(), $universe, new CapturedOverlay($store, true));
	}

	private function emptyStore(string $dir): SiteScopeStore
	{
		return new SiteScopeStore($dir . '/__absent_sitescope_store__');
	}

	private function treeDir(): string
	{
		return dirname(__DIR__, 3) . '/Unit/Latte/Includes/Fixtures/tree';
	}

	private function rel(string $basename): string
	{
		return ProjectRelativePath::relativize(dirname(__DIR__, 3), $this->treeDir() . '/' . $basename);
	}

}
