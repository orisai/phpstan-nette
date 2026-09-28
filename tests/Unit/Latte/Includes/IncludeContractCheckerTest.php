<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Compile\ProjectRelativePath;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\CapturedOverlay;
use OriPhpstan\Nette\Latte\Includes\ContextResolver;
use OriPhpstan\Nette\Latte\Includes\IncludeContractChecker;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function dirname;
use function getmypid;
use function sha1_file;
use function sys_get_temp_dir;
use function uniqid;

final class IncludeContractCheckerTest extends PHPStanTestCase
{

	private ?LatteUniverse $universe = null;

	private ?TemplateEdgeIndex $index = null;

	private ?CapturedOverlay $capturedOverlay = null;

	private ?ContextResolver $contextResolver = null;

	private ?IncludeContractChecker $checker = null;

	public function testTypeMismatchReportedAtSite(): void
	{
		$diagnostics = $this->checker()->check($this->rel('other-root.latte'), $this->contextsFor('other-root.latte'));
		$ids = array_map(static fn (Diagnostic $d): string => $d->getIdentifier(), $diagnostics);

		self::assertContains('orisaiNette.latte.includeTypeMismatch', $ids);
	}

	public function testCompatibleEdgeClean(): void
	{
		$ids = array_map(
			static fn (Diagnostic $d): string => $d->getIdentifier(),
			$this->checker()->check($this->rel('root.latte'), $this->contextsFor('root.latte')),
		);

		self::assertNotContains('orisaiNette.latte.includeTypeMismatch', $ids);
		self::assertNotContains('orisaiNette.latte.includeMissingVariable', $ids);
	}

	public function testMissingDeclaredVariableReported(): void
	{
		self::assertContains(
			'orisaiNette.latte.includeMissingVariable',
			$this->idsFor('strict-partial-includer.latte'),
		);
	}

	public function testDefaultSatisfiesMissingCheck(): void
	{
		self::assertNotContains(
			'orisaiNette.latte.includeMissingVariable',
			$this->idsFor('strict-partial-with-default-includer.latte'),
		);
	}

	public function testDynamicUnknownAndCycleDiagnostics(): void
	{
		self::assertContains('orisaiNette.latte.dynamicInclude', $this->idsFor('dynamic.latte'));
		self::assertContains('orisaiNette.latte.unknownInclude', $this->idsFor('missing.latte'));
		self::assertContains(
			'orisaiNette.latte.includeCycle',
			array_merge($this->idsFor('cycle-a.latte'), $this->idsFor('cycle-b.latte')),
		);
	}

	// A depth-cap cut (a straight, non-cyclic chain deeper than ContextResolver::DEPTH_CAP) must
	// never surface as orisaiNette.latte.includeCycle from ANY member of the chain - only a true on-stack
	// cycle cut may (see testDynamicUnknownAndCycleDiagnostics above for that case).
	public function testDeepNonCyclicChainReportsNoIncludeCycleFromAnyMember(): void
	{
		$dir = sys_get_temp_dir() . '/latte-cap-cycle-test-' . getmypid() . '-' . uniqid('', true);

		for ($i = 0; $i < 20; $i++) {
			$content = $i === 19 ? "Root.\n" : "{include 'chain-" . ($i + 1) . ".latte'}\n";
			FileSystem::write($dir . '/chain-' . $i . '.latte', $content);
		}

		try {
			$universe = new LatteUniverse([$dir], $dir);
			$index = new TemplateEdgeIndex($universe, new TemplateFactExtractor());
			$capturedOverlay = new CapturedOverlay(new SiteScopeStore($dir . '/__absent_sitescope_store__'), true);
			$resolver = new ContextResolver(
				$index,
				new DeclarationScanner(),
				$universe,
				$capturedOverlay,
			);
			$checker = new IncludeContractChecker(
				$index,
				new DeclarationScanner(),
				$universe,
				$resolver,
				self::getContainer()->getByType(TypeStringResolver::class),
				$capturedOverlay,
			);

			// Matches how LatteRoutingParser actually drives resolution: the deepest target's
			// incoming-edge walk pulls the recursion through every ancestor, discovering the cut.
			$resolver->contextsFor('chain-19.latte');

			self::assertNotSame([], $resolver->depthCapCuts());
			self::assertSame([], $resolver->cutCycleEdges());

			for ($i = 0; $i < 20; $i++) {
				$rel = 'chain-' . $i . '.latte';
				$ids = array_map(
					static fn (Diagnostic $d): string => $d->getIdentifier(),
					$checker->check($rel, $resolver->contextsFor($rel)),
				);

				self::assertNotContains(
					'orisaiNette.latte.includeCycle',
					$ids,
					"$rel must not report includeCycle for a depth-cap cut",
				);
			}
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDynamicSandboxReportsDynamicInclude(): void
	{
		self::assertContains('orisaiNette.latte.dynamicInclude', $this->idsFor('dynamic-sandbox.latte'));
	}

	public function testDynamicExtendsReportsDynamicExtends(): void
	{
		$ids = $this->idsFor('dynamic-extends.latte');

		self::assertContains('orisaiNette.latte.dynamicExtends', $ids);
		self::assertNotContains('orisaiNette.latte.dynamicInclude', $ids);
	}

	public function testUnknownIncludeMessageDistinguishesMissingFromOutsidePaths(): void
	{
		$missingMessage = $this->unknownIncludeMessageFor('missing.latte');
		self::assertStringContainsString(
			"'Unit/Latte/Includes/Fixtures/tree/nope.latte' does not exist.",
			$missingMessage,
		);
		self::assertStringNotContainsString('outside the analysed paths', $missingMessage);

		$outsideMessage = $this->unknownIncludeMessageFor('exists-outside-paths-includer.latte');
		self::assertStringContainsString(
			"'Unit/Latte/Includes/Fixtures/outside/target.latte' exists but is outside the analysed paths.",
			$outsideMessage,
		);
	}

	private function unknownIncludeMessageFor(string $basename): string
	{
		$diagnostics = $this->checker()->check($this->rel($basename), $this->contextsFor($basename));
		$unknownInclude = array_values(array_filter(
			$diagnostics,
			static fn (Diagnostic $d): bool => $d->getIdentifier() === 'orisaiNette.latte.unknownInclude',
		));

		self::assertCount(1, $unknownInclude, "expected exactly one orisaiNette.latte.unknownInclude for $basename");

		return $unknownInclude[0]->getMessage();
	}

	public function testUnknownBlockReported(): void
	{
		self::assertContains('orisaiNette.latte.unknownBlock', $this->idsFor('unknown-block-includer.latte'));
	}

	// C3d: a file with zero modeled incoming edges cannot be proven standalone (Nette's
	// auto-layout/setFile()/component-callback wiring attaches invisibly), so its own
	// {include #ghost} must stay silent rather than false-close on an unknowable block.
	public function testUnknownBlockSuppressedForZeroIncomingEdgeFile(): void
	{
		$dir = $this->isolatedDir('latte-unknown-block-orphan');
		FileSystem::write($dir . '/orphan.latte', "{include #ghost}\n");

		try {
			self::assertNotContains('orisaiNette.latte.unknownBlock', $this->isolatedIdsFor($dir, 'orphan.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Control for the above: same unresolved block, but with a modeled includer edge - its graph
	// is positively modeled, so the diagnostic must still fire.
	public function testUnknownBlockStillReportedForGraphConnectedFile(): void
	{
		$dir = $this->isolatedDir('latte-unknown-block-connected');
		FileSystem::write($dir . '/connected.latte', "{include #ghost}\n");
		FileSystem::write($dir . '/connected-user.latte', "{include 'connected.latte'}\n");

		try {
			self::assertContains('orisaiNette.latte.unknownBlock', $this->isolatedIdsFor($dir, 'connected.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// C3e: a file with a `@`-prefixed basename cannot be proven standalone even if it has
	// incoming Latte edges, because Nette's auto-layout discovery ({layout '@layout.latte'})
	// attaches extenders invisibly by convention, so its own {include #ghost} must stay silent.
	public function testUnknownBlockSuppressedForAtPrefixedBasename(): void
	{
		$dir = $this->isolatedDir('latte-unknown-block-at-prefix');
		FileSystem::write($dir . '/@layout.latte', "{include #ghost}\n");
		FileSystem::write($dir . '/page.latte', "{layout '@layout.latte'}\n");

		try {
			self::assertNotContains('orisaiNette.latte.unknownBlock', $this->isolatedIdsFor($dir, '@layout.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Control for the above: same unresolved block, but with a regular (non-@) basename and
	// a modeled includer edge - its graph is positively modeled, so the diagnostic must still fire.
	public function testUnknownBlockStillReportedForRegularBasenameWithIncomingEdge(): void
	{
		$dir = $this->isolatedDir('latte-unknown-block-regular-basename');
		FileSystem::write($dir . '/layout.latte', "{include #ghost}\n");
		FileSystem::write($dir . '/page.latte', "{layout 'layout.latte'}\n");

		try {
			self::assertContains('orisaiNette.latte.unknownBlock', $this->isolatedIdsFor($dir, 'layout.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Layout-slot idiom, end to end: layout.latte's {ifset #slot}{include #slot}{/ifset} must not
	// report orisaiNette.latte.unknownBlock once an extender (page.latte) declares {block slot}.
	public function testUnknownBlockNotReportedWhenExtenderDeclaresSlot(): void
	{
		$dir = $this->isolatedDir('latte-slot-checker');
		FileSystem::write($dir . '/layout.latte', "{ifset #slot}{include #slot}{/ifset}\n");
		FileSystem::write($dir . '/page.latte', "{layout 'layout.latte'}\n{block slot}x{/block}\n");

		try {
			self::assertNotContains('orisaiNette.latte.unknownBlock', $this->isolatedIdsFor($dir, 'layout.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Negative control: no extender declares this name, so the layout's own {include #slot} must
	// still be reported.
	public function testUnknownBlockStillReportedWhenNoExtenderDeclaresSlot(): void
	{
		$dir = $this->isolatedDir('latte-slot-checker-negative');
		FileSystem::write($dir . '/layout.latte', "{ifset #slot}{include #slot}{/ifset}\n");
		FileSystem::write($dir . '/page.latte', "{layout 'layout.latte'}\n{block other}x{/block}\n");

		try {
			self::assertContains('orisaiNette.latte.unknownBlock', $this->isolatedIdsFor($dir, 'layout.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testEmbedBlockModeUnknownBlockReportedButReachableBlockClean(): void
	{
		$diagnostics = $this->checker()->check(
			$this->rel('embed-block-includer.latte'),
			$this->contextsFor('embed-block-includer.latte'),
		);
		$unknownBlockDiagnostics = array_values(array_filter(
			$diagnostics,
			static fn (Diagnostic $d): bool => $d->getIdentifier() === 'orisaiNette.latte.unknownBlock',
		));

		self::assertCount(1, $unknownBlockDiagnostics);
		self::assertStringContainsString('missingBlock', $unknownBlockDiagnostics[0]->getMessage());
	}

	public function testMalformedDeclaredTypeSkipsMismatchCheckWithoutCrashing(): void
	{
		$ids = $this->idsFor('malformed-type-includer.latte');

		self::assertNotContains('orisaiNette.latte.includeTypeMismatch', $ids);
		self::assertNotContains('orisaiNette.latte.includeMissingVariable', $ids);
	}

	public function testMaybeAssignabilityIsNotReported(): void
	{
		self::assertNotContains(
			'orisaiNette.latte.includeTypeMismatch',
			$this->idsFor('maybe-mismatch-includer.latte'),
		);
	}

	// The checker must feed narrowed (store-overlaid) provided types into the
	// definite-NO check, not just ContextResolver. maybe-mismatch-includer.latte provides
	// `int|string` (wide) for a declared `int` target - a "maybe", stays silent above. Seeding a
	// captured entry that narrows the SAME arg to `string` (disjoint from the declared `int`) must
	// turn that "maybe" into a definite mismatch - narrowing only ever ADDS true mismatches, never
	// removes or fabricates one.
	public function testNarrowedDisjointProvidedTypeNewlyReportsMismatch(): void
	{
		$scratchDir = $this->isolatedDir('narrowed-mismatch');
		FileSystem::createDir($scratchDir);

		try {
			$rel = $this->rel('maybe-mismatch-includer.latte');
			$context = $this->contextsFor('maybe-mismatch-includer.latte')[0];
			$site = $this->index()->outgoingSites($rel)[0];

			$key = SiteScopeStore::key($rel, $site->getLatteLine(), $site->getRawTarget(), $context->canonicalHash());
			$sha = (string) sha1_file($this->treeDir() . '/maybe-mismatch-includer.latte');

			$store = new SiteScopeStore($scratchDir . '/store.php');
			$store->replaceForIncluders([$rel], [$key => ['sha' => $sha, 'vars' => [], 'args' => ['v' => 'string']]]);

			$capturedOverlay = new CapturedOverlay($store, true);
			$resolver = new ContextResolver(
				$this->index(),
				new DeclarationScanner(),
				$this->universe(),
				$capturedOverlay,
			);
			$checker = new IncludeContractChecker(
				$this->index(),
				new DeclarationScanner(),
				$this->universe(),
				$resolver,
				self::getContainer()->getByType(TypeStringResolver::class),
				$capturedOverlay,
			);

			$ids = array_map(
				static fn (Diagnostic $d): string => $d->getIdentifier(),
				$checker->check($rel, $resolver->contextsFor($rel)),
			);

			self::assertContains('orisaiNette.latte.includeTypeMismatch', $ids);
		} finally {
			FileSystem::delete($scratchDir);
		}
	}

	// ambig-target.latte itself has ZERO outgoing sites, so checking it directly is vacuous - the
	// valve under test (checkDeclaredVar's "count($providedTypes) < count($contexts)") only ever
	// runs against an outgoing site of the file being checked, and only ever gates checkMismatch()
	// (the earlier "$providedTypes === []" branch handles the fully-absent case on its own, before
	// the valve is even reached). ambig-mid.latte is that site's real owner: two incoming contexts
	// (ambig-parent-a passes $maybe as a conflicting string, ambig-parent-b does not pass it at
	// all), so its own {include 'ambig-target.latte'} (no args) must stay silent rather than report
	// a mismatch off the one context that does carry the conflicting type.
	public function testVarMissingInOnlySomeContextsNotReported(): void
	{
		$ids = $this->idsFor('ambig-mid.latte');

		self::assertNotContains('orisaiNette.latte.includeTypeMismatch', $ids);
		self::assertNotContains('orisaiNette.latte.includeMissingVariable', $ids);
	}

	// Control for the above: same shape, but ambig-mid-solo.latte has a SINGLE (non-passing)
	// includer, so $maybe is absent from every one of its (one) contexts - the valve never applies
	// and the earlier "not provided anywhere, no default" branch must still report.
	public function testVarMissingInSingleNonPassingContextIsReported(): void
	{
		self::assertContains('orisaiNette.latte.includeMissingVariable', $this->idsFor('ambig-mid-solo.latte'));
	}

	// Reproduces the checker/resolver per-tag scope drift: ContextResolver::buildEdgeContext
	// merges the includer's topLevelVars for {layout}/{extends} (the child's finished main scope
	// by the time control reaches the layout), but the checker used to compute "provided" via a
	// tag-blind array_merge($context->getVars(), $typed['vars']) that never saw those locals -
	// see EdgeScope, now the single source of per-tag provided-scope logic for both.
	public function testLayoutSiteSeesIncluderTopLevelVars(): void
	{
		self::assertNotContains('orisaiNette.latte.includeMissingVariable', $this->idsFor('layout-drift-child.latte'));
	}

	// Same drift, opposite direction: the old tag-blind checker code merged in the includer's OWN
	// context vars even for {sandbox}, which only ever provides its explicit args - producing a
	// false includeTypeMismatch against a var the target never actually receives from the includer.
	public function testSandboxSiteStaysSilentOnIncluderOnlyTypeConflict(): void
	{
		self::assertNotContains('orisaiNette.latte.includeTypeMismatch', $this->idsFor('sandbox-drift-includer.latte'));
	}

	// === Block input contracts (declared params without a default, union body {varType}s) ===

	public function testBlockBodyVarTypeMismatchReportedAtIncludeSite(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-mismatch');
		FileSystem::write($dir . '/target.latte', "{block item}\n{varType string \$label}\n{\$label}\n{/block}\n");
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n\n{include item, label: 1}\n");

		try {
			self::assertContains('orisaiNette.latte.includeTypeMismatch', $this->isolatedIdsFor($dir, 'user.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testBlockBodyVarTypeMissingReportedAtIncludeSite(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-missing');
		FileSystem::write($dir . '/target.latte', "{block item}\n{varType string \$label}\n{\$label}\n{/block}\n");
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n\n{include item}\n");

		try {
			self::assertContains('orisaiNette.latte.includeMissingVariable', $this->isolatedIdsFor($dir, 'user.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testBlockBodyVarTypeCompatibleStaysClean(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-compatible');
		FileSystem::write($dir . '/target.latte', "{block item}\n{varType string \$label}\n{\$label}\n{/block}\n");
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n\n{include item, label: 'ok'}\n");

		try {
			$ids = $this->isolatedIdsFor($dir, 'user.latte');

			self::assertNotContains('orisaiNette.latte.includeMissingVariable', $ids);
			self::assertNotContains('orisaiNette.latte.includeTypeMismatch', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Same-file, PARAM-LESS block dispatch reaches its body via real Latte's universal
	// get_defined_vars()+extract($ʟ_args) prolog - a {foreach}-scoped loop variable (never a
	// formally declared var anywhere) genuinely reaches such a block at runtime (confirmed via a
	// direct Latte\Engine probe), a channel EdgeScope's own approximation (the includer's
	// FORMALLY-declared vars only) cannot see. Checking a body {varType} against that
	// approximation here would produce a false includeMissingVariable against a value the block
	// genuinely receives - degrade instead (OPEN).
	public function testSameFileParamlessBlockBodyVarTypeDegradesSilently(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-samefile-paramless');
		FileSystem::write(
			$dir . '/page.latte',
			"{block item}\n{varType string \$label}\n{\$label}\n{/block}\n\n{include item}\n",
		);

		try {
			$ids = $this->isolatedIdsFor($dir, 'page.latte');

			self::assertNotContains('orisaiNette.latte.includeMissingVariable', $ids);
			self::assertNotContains('orisaiNette.latte.includeTypeMismatch', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The ambient-reach blind spot only threatens the MISSING dimension (a name absent from
	// EdgeScope's own approximation might still genuinely arrive via get_defined_vars()). It does
	// NOT threaten MISMATCH: checkMismatch() only ever runs when the name was EXPLICITLY seen via
	// ArgTyper - the same visibility channel cross-file blocks use - independent of same-file-ness
	// or ambient reach. A definitely-wrong explicit arg must still be reported here.
	public function testSameFileParamlessBlockBodyVarTypeMismatchReportedWhenArgExplicit(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-samefile-paramless-mismatch');
		FileSystem::write(
			$dir . '/page.latte',
			"{block item}\n{varType string \$label}\n{\$label}\n{/block}\n\n{include item, label: 1}\n",
		);

		try {
			self::assertContains('orisaiNette.latte.includeTypeMismatch', $this->isolatedIdsFor($dir, 'page.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Control for the above: a block WITH its own declared param never receives the
	// get_defined_vars() fallback at all (real Latte only emits it for fully param-less blocks),
	// so an own param is never ambient-reach-ambiguous - same-file dispatch stays checked for it.
	public function testSameFileBlockOwnParamMissingReported(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-samefile-ownparam');
		FileSystem::write(
			$dir . '/page.latte',
			"{define item, string \$label}\n{\$label}\n{/define}\n\n{include item}\n",
		);

		try {
			self::assertContains('orisaiNette.latte.includeMissingVariable', $this->isolatedIdsFor($dir, 'page.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// F2 pin: an imported file's own top-level {varType} must never join its block's contract -
	// the block's own contract is exactly its own params + its own body varTypes, nothing else.
	public function testImportedFileTopLevelVarTypeNeverJoinsBlockContract(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-f2');
		FileSystem::write(
			$dir . '/target.latte',
			"{varType int \$fileLevel}\n\n{block greet}\n{varType string \$name}\n{\$name}\n{/block}\n",
		);
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n\n{include greet, name: 'ok'}\n");

		try {
			$diagnostics = $this->isolatedDiagnosticsFor($dir, 'user.latte');

			// Positive control: the file-level check for $fileLevel DOES still fire (on the
			// {import} line, an unrelated pre-existing pathway) - proving the fixture is wired
			// correctly and this isn't just silence because nothing ran at all.
			self::assertContains(
				'orisaiNette.latte.includeMissingVariable',
				array_map(static fn (Diagnostic $d): string => $d->getIdentifier(), $diagnostics),
			);

			$atIncludeLine = array_values(array_filter(
				$diagnostics,
				static fn (Diagnostic $d): bool => $d->getLatteLine() === 3,
			));
			self::assertSame(
				[],
				$atIncludeLine,
				"the block include site (line 3) must carry zero diagnostics - \$fileLevel must never join greet's contract",
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Two extenders declaring the same slot name with different contracts is genuinely ambiguous
	// - never guess which one applies (OPEN), mirroring the per-context ambiguity valve one
	// dimension over (which defining FILE, not which caller context).
	public function testAmbiguousBlockOriginStaysSilent(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-ambiguous-origin');
		FileSystem::write($dir . '/layout.latte', "{ifset #slot}{include #slot}{/ifset}\n");
		FileSystem::write(
			$dir . '/page-a.latte',
			"{layout 'layout.latte'}\n{block slot}\n{varType string \$x}\n{\$x}\n{/block}\n",
		);
		FileSystem::write($dir . '/page-b.latte', "{layout 'layout.latte'}\n{block slot}ok{/block}\n");

		try {
			$ids = $this->isolatedIdsFor($dir, 'layout.latte');

			self::assertNotContains('orisaiNette.latte.includeMissingVariable', $ids);
			self::assertNotContains('orisaiNette.latte.includeTypeMismatch', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDefaultedBlockParamIsNotRequired(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-default-param');
		FileSystem::write($dir . '/target.latte', "{define greet, string \$name = 'x'}\n{\$name}\n{/define}\n");
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n\n{include greet}\n");

		try {
			self::assertNotContains(
				'orisaiNette.latte.includeMissingVariable',
				$this->isolatedIdsFor($dir, 'user.latte'),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testUnresolvableBlockBodyVarTypeDegradesSilently(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-malformed-type');
		FileSystem::write($dir . '/target.latte', "{block item}\n{varType int| \$bad}\n{\$bad}\n{/block}\n");
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n\n{include item, bad: 1}\n");

		try {
			$ids = $this->isolatedIdsFor($dir, 'user.latte');

			self::assertNotContains('orisaiNette.latte.includeMissingVariable', $ids);
			self::assertNotContains('orisaiNette.latte.includeTypeMismatch', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// A block's own declared param binds POSITIONALLY at the call site; ArgTyper only recognizes
	// `name: expr`/`name => expr` pairs, so a bare positional argument for it is invisible to the
	// provided-scope computation - trusting an unrelated same-named ambient value there would risk
	// a false report, so own-param names are dropped from the contract entirely on such an edge.
	public function testPositionalBlockArgDegradesRatherThanFalselyReportingMissing(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-positional');
		FileSystem::write($dir . '/target.latte', "{define greet, string \$name}\n{\$name}\n{/define}\n");
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n\n{include greet, 'World'}\n");

		try {
			self::assertNotContains(
				'orisaiNette.latte.includeMissingVariable',
				$this->isolatedIdsFor($dir, 'user.latte'),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The positional-drop above only unset()s $paramContract - but blockContract() then merges
	// $paramContract with $bodyVarTypes, and the untyped-own-param + body-{varType} pairing this
	// feature blesses (testBlockUntypedNativeParamIsNotReported) puts the SAME name into both, so
	// the merge silently re-adds the name the guard just dropped. missingExempt stays empty here
	// because $ownParams !== [], so the re-added name falsely reports orisaiNette.latte.includeMissingVariable
	// on a runtime-satisfied positional edge. The drop must apply to the MERGED contract.
	public function testPositionalArgWithBodyVarTypeForOwnParamDoesNotFalselyReportMissingSameFile(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-positional-bodyvartype-samefile');
		FileSystem::write(
			$dir . '/page.latte',
			"{define greet, \$name}\n{varType string \$name}\n{\$name}\n{/define}\n\n{include greet, 'World'}\n",
		);

		try {
			self::assertNotContains(
				'orisaiNette.latte.includeMissingVariable',
				$this->isolatedIdsFor($dir, 'page.latte'),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Cross-file counterpart of the above - same false positive, target block and includer split
	// across two files.
	public function testPositionalArgWithBodyVarTypeForOwnParamDoesNotFalselyReportMissingCrossFile(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-positional-bodyvartype-crossfile');
		FileSystem::write(
			$dir . '/target.latte',
			"{define greet, \$name}\n{varType string \$name}\n{\$name}\n{/define}\n",
		);
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n\n{include greet, 'World'}\n");

		try {
			self::assertNotContains(
				'orisaiNette.latte.includeMissingVariable',
				$this->isolatedIdsFor($dir, 'user.latte'),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Control for both cases above: a NAMED-arg edge to the identical own-param + body-varType
	// block must stay fully contract-checked - the merged-contract drop is scoped to
	// ArgTyper::hasPositionalArgs() sites only, never a blanket exemption for the pairing.
	public function testNamedArgWithBodyVarTypeForOwnParamStillReportsMismatch(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-named-bodyvartype-control');
		FileSystem::write(
			$dir . '/target.latte',
			"{define greet, \$name}\n{varType string \$name}\n{\$name}\n{/define}\n",
		);
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n\n{include greet, name: 123}\n");

		try {
			self::assertContains('orisaiNette.latte.includeTypeMismatch', $this->isolatedIdsFor($dir, 'user.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// All-contexts semantics, one dimension over from the file-edge case
	// (testVarMissingInOnlySomeContextsNotReported): user.latte's own {include item} block edge
	// (cross-file, checkable - not the same-file ambient-reach shape above) is checked against
	// EVERY context user.latte itself can be entered with - parent-a.latte provides $label
	// ambiently, parent-b.latte does not, so the block edge must stay silent rather than guess
	// which caller is "real".
	public function testBlockVarProvidedInOnlySomeIncluderContextsStaysSilent(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-ambig-context');
		FileSystem::write($dir . '/target.latte', "{block item}\n{varType string \$label}\n{\$label}\n{/block}\n");
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n{include item}\n");
		FileSystem::write($dir . '/parent-a.latte', "{varType string \$label}\n{include 'user.latte'}\n");
		FileSystem::write($dir . '/parent-b.latte', "{include 'user.latte'}\n");

		try {
			$ids = $this->isolatedIdsFor($dir, 'user.latte');

			self::assertNotContains('orisaiNette.latte.includeMissingVariable', $ids);
			self::assertNotContains('orisaiNette.latte.includeTypeMismatch', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Control for the above: a SINGLE non-providing includer context has no ambiguity to hide
	// behind, so the missing report must still fire.
	public function testBlockVarMissingInSingleNonProvidingContextIsReported(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-single-context');
		FileSystem::write($dir . '/target.latte', "{block item}\n{varType string \$label}\n{\$label}\n{/block}\n");
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n{include item}\n");
		FileSystem::write($dir . '/parent.latte', "{include 'user.latte'}\n");

		try {
			self::assertContains('orisaiNette.latte.includeMissingVariable', $this->isolatedIdsFor($dir, 'user.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// F4 (carried from declaration consistency): BlockMacros::extractMethod only emits the
	// universal extract($ʟ_args) fallback for a FULLY param-less block - the moment a block
	// declares even one own param, it switches to a positional-only prologue that never extracts
	// an unmatched named arg (CrossFileScopeParityTest::testOwnParamBlockNeverExtractsUnmatchedExtraArg
	// pins this at runtime). `extra` here matches neither $label (the own param) nor anything else
	// bindable - a true "extra" - so it must never count as provided to $extra's own body
	// {varType} contract entry, a DIFFERENT name from the own param. `label: 'hi'` satisfies the
	// own param via a matched named arg so this probe isolates the extra's effect alone.
	public function testUnmatchedNamedExtraNotProvidedToOwnParamBlock(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-f4-extra-not-provided');
		FileSystem::write(
			$dir . '/target.latte',
			"{define greet, string \$label}\n{varType string \$extra}\n{\$label}{\$extra}\n{/define}\n",
		);
		FileSystem::write(
			$dir . '/user.latte',
			"{import 'target.latte'}\n\n{include greet, label: 'hi', extra: 'x'}\n",
		);

		try {
			self::assertContains('orisaiNette.latte.includeMissingVariable', $this->isolatedIdsFor($dir, 'user.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Control for the fix above: the identical edge shape against a PARAM-LESS block - real
	// Latte's get_defined_vars()+extract($ʟ_args) prolog DOES bind an unmatched named arg there
	// (F4's other, already-modeled half), so this must stay clean; the own-param gate must never
	// touch this path.
	public function testUnmatchedNamedExtraStillProvidedToParamlessBlock(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-f4-extra-paramless-control');
		FileSystem::write($dir . '/target.latte', "{block greet}\n{varType string \$extra}\n{\$extra}\n{/block}\n");
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n\n{include greet, extra: 'x'}\n");

		try {
			$ids = $this->isolatedIdsFor($dir, 'user.latte');

			self::assertNotContains('orisaiNette.latte.includeMissingVariable', $ids);
			self::assertNotContains('orisaiNette.latte.includeTypeMismatch', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Control for the fix above: a named arg MATCHING the own param's own name is a matched arg,
	// not an extra - it must still satisfy the own param's contract entry after the fix.
	public function testMatchedNamedArgStillSatisfiesOwnParamAfterExtraFix(): void
	{
		$dir = $this->isolatedDir('latte-block-contract-f4-matched-control');
		FileSystem::write($dir . '/target.latte', "{define greet, string \$label}\n{\$label}\n{/define}\n");
		FileSystem::write($dir . '/user.latte', "{import 'target.latte'}\n\n{include greet, label: 'hi'}\n");

		try {
			self::assertNotContains(
				'orisaiNette.latte.includeMissingVariable',
				$this->isolatedIdsFor($dir, 'user.latte'),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @return list<string>
	 */
	private function idsFor(string $basename): array
	{
		return array_map(
			static fn (Diagnostic $d): string => $d->getIdentifier(),
			$this->checker()->check($this->rel($basename), $this->contextsFor($basename)),
		);
	}

	private function isolatedDir(string $prefix): string
	{
		return sys_get_temp_dir() . '/' . $prefix . '-test-' . getmypid() . '-' . uniqid('', true);
	}

	/**
	 * @return list<string>
	 */
	private function isolatedIdsFor(string $dir, string $basename): array
	{
		return array_map(
			static fn (Diagnostic $d): string => $d->getIdentifier(),
			$this->isolatedDiagnosticsFor($dir, $basename),
		);
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function isolatedDiagnosticsFor(string $dir, string $basename): array
	{
		$universe = new LatteUniverse([$dir], $dir);
		$index = new TemplateEdgeIndex($universe, new TemplateFactExtractor());
		$capturedOverlay = new CapturedOverlay(new SiteScopeStore($dir . '/__absent_sitescope_store__'), true);
		$resolver = new ContextResolver(
			$index,
			new DeclarationScanner(),
			$universe,
			$capturedOverlay,
		);
		$checker = new IncludeContractChecker(
			$index,
			new DeclarationScanner(),
			$universe,
			$resolver,
			self::getContainer()->getByType(TypeStringResolver::class),
			$capturedOverlay,
		);

		return $checker->check($basename, $resolver->contextsFor($basename));
	}

	/**
	 * @return list<TemplateContext>
	 */
	private function contextsFor(string $basename): array
	{
		return $this->resolver()->contextsFor($this->rel($basename));
	}

	private function checker(): IncludeContractChecker
	{
		if ($this->checker === null) {
			$this->checker = new IncludeContractChecker(
				$this->index(),
				new DeclarationScanner(),
				$this->universe(),
				$this->resolver(),
				self::getContainer()->getByType(TypeStringResolver::class),
				$this->capturedOverlay(),
			);
		}

		return $this->checker;
	}

	private function resolver(): ContextResolver
	{
		if ($this->contextResolver === null) {
			$this->contextResolver = new ContextResolver(
				$this->index(),
				new DeclarationScanner(),
				$this->universe(),
				$this->capturedOverlay(),
			);
		}

		return $this->contextResolver;
	}

	private function capturedOverlay(): CapturedOverlay
	{
		if ($this->capturedOverlay === null) {
			$this->capturedOverlay = new CapturedOverlay(
				new SiteScopeStore($this->treeDir() . '/__absent_sitescope_store__'),
				true,
			);
		}

		return $this->capturedOverlay;
	}

	private function index(): TemplateEdgeIndex
	{
		if ($this->index === null) {
			$this->index = new TemplateEdgeIndex($this->universe(), new TemplateFactExtractor());
		}

		return $this->index;
	}

	private function universe(): LatteUniverse
	{
		if ($this->universe === null) {
			$this->universe = new LatteUniverse([$this->treeDir()], dirname(__DIR__, 3));
		}

		return $this->universe;
	}

	private function treeDir(): string
	{
		return __DIR__ . '/Fixtures/tree';
	}

	private function rel(string $basename): string
	{
		return ProjectRelativePath::relativize(dirname(__DIR__, 3), $this->treeDir() . '/' . $basename);
	}

}
