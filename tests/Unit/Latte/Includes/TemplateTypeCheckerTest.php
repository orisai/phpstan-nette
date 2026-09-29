<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\Discovery\FormulaVocabulary;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\TemplateTypeChecker;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryAbstractViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryLegacyPathControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoverySetFileOpaquePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryTwoTemplateControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryVendorPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DynamicViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\FixtureLegacyPathControlBase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SubModule\DiscoveryDeepPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingAgreeExactPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingBaseTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingChildTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingConventionDynamicControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingDefaultsSilentPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingNonQualifyingService;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingRuntimeNarrowerPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingUnrelatedTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\FixtureTemplateTypeContainer;
use function array_keys;
use function array_map;
use function array_merge;
use function array_reverse;
use function getmypid;
use function is_int;
use function realpath;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use const SORT_STRING;

final class TemplateTypeCheckerTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const PairingFixtureDir = __DIR__ . '/../Bridge/Pairing/Fixtures/App';

	private const DiscoveryFixtureRoot = __DIR__ . '/../Bridge/Fixtures';

	private const MappingLoaderFile = __DIR__ . '/../Bridge/Fixtures/presenter-mapping-container-loader.php';

	/**
	 * @group latte2
	 */
	public function testExactDeclarationIsSilent(): void
	{
		$this->assertPairingDiagnostics(
			'{templateType ' . PairingBaseTemplateReplica::class . "}\nbody\n",
			[PairingAgreeExactPresenter::class],
			[],
		);
	}

	/**
	 * @group latte2
	 */
	// The owner's standing ruling: a declaration may WIDEN - a renderer pairing a subtype of the
	// declared class still satisfies every member the template body reads.
	public function testWiderDeclarationIsSilent(): void
	{
		$this->assertPairingDiagnostics(
			"{templateType Nette\\Bridges\\ApplicationLatte\\Template}\nbody\n",
			[PairingAgreeExactPresenter::class],
			[],
		);
	}

	/**
	 * @group latte2
	 */
	public function testNarrowerDeclarationIsReported(): void
	{
		$this->assertPairingDiagnostics(
			'{templateType ' . PairingChildTemplateReplica::class . "}\nbody\n",
			[PairingAgreeExactPresenter::class],
			[
				[
					TemplateTypeChecker::MISMATCH_IDENTIFIER,
					'Template declares {templateType ' . PairingChildTemplateReplica::class . '} but renderer '
					. PairingAgreeExactPresenter::class . ' pairs ' . PairingBaseTemplateReplica::class . '.',
					1,
				],
			],
		);
	}

	/**
	 * @group latte2
	 */
	public function testUnrelatedDeclarationIsReported(): void
	{
		$this->assertPairingDiagnostics(
			"body\n{templateType " . PairingUnrelatedTemplateReplica::class . "}\n",
			[PairingAgreeExactPresenter::class],
			[
				[
					TemplateTypeChecker::MISMATCH_IDENTIFIER,
					'Template declares {templateType ' . PairingUnrelatedTemplateReplica::class . '} but renderer '
					. PairingAgreeExactPresenter::class . ' pairs ' . PairingBaseTemplateReplica::class . '.',
					2,
				],
			],
		);
	}

	/**
	 * @group latte2
	 */
	// All-renderers discipline: every linked renderer is judged separately, so a template shared by
	// a satisfying and a violating renderer reports exactly the violating one.
	public function testMultiRendererReportsOnlyTheViolatingRenderer(): void
	{
		$this->assertPairingDiagnostics(
			'{templateType ' . PairingChildTemplateReplica::class . "}\nbody\n",
			[PairingAgreeExactPresenter::class, PairingRuntimeNarrowerPresenter::class],
			[
				[
					TemplateTypeChecker::MISMATCH_IDENTIFIER,
					'Template declares {templateType ' . PairingChildTemplateReplica::class . '} but renderer '
					. PairingAgreeExactPresenter::class . ' pairs ' . PairingBaseTemplateReplica::class . '.',
					1,
				],
			],
		);
	}

	/**
	 * @group latte2
	 */
	// The *dynamic* marker is never a class name: an opaque verdict says nothing about the pairing,
	// so the check stays OPEN rather than reporting against a marker string.
	public function testOpaqueVerdictIsSkipped(): void
	{
		$this->assertPairingDiagnostics(
			'{templateType ' . PairingUnrelatedTemplateReplica::class . "}\nbody\n",
			[PairingConventionDynamicControl::class],
			[],
		);
	}

	/**
	 * @group latte2
	 */
	public function testNonQualifyingRendererIsSkipped(): void
	{
		$this->assertPairingDiagnostics(
			'{templateType ' . PairingUnrelatedTemplateReplica::class . "}\nbody\n",
			[PairingNonQualifyingService::class],
			[],
		);
	}

	/**
	 * @group latte2
	 */
	// orisaiNette.latte.unknownType already reports a bad {templateType} once, at its declaring file - comparing
	// against a class reflection cannot resolve would only duplicate it with a guessed verdict.
	public function testUnresolvableTemplateTypeClassIsSkipped(): void
	{
		$this->assertPairingDiagnostics(
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Nope\\NoSuchTemplate}\nbody\n",
			[PairingAgreeExactPresenter::class],
			[],
		);
	}

	/**
	 * @group latte2
	 */
	public function testTemplateWithoutAnyLinkedRendererIsSilent(): void
	{
		$this->assertPairingDiagnostics(
			'{templateType ' . PairingUnrelatedTemplateReplica::class . "}\nbody\n",
			[],
			[],
		);
	}

	/**
	 * @group latte2
	 */
	// === orisaiNette.latte.templateTypeRequired ===
	public function testFloorRendererWithoutTemplateTypeIsDormantWhileTheFlagIsOff(): void
	{
		$this->assertPairingDiagnostics("body\n", [PairingDefaultsSilentPresenter::class], []);
	}

	/**
	 * @group latte2
	 */
	public function testFloorRendererWithoutTemplateTypeIsReportedWhenTheFlagIsOn(): void
	{
		$this->assertPairingDiagnostics(
			"body\n",
			[PairingDefaultsSilentPresenter::class],
			[
				[
					TemplateTypeChecker::REQUIRED_IDENTIFIER,
					'Template has no {templateType} and renderer ' . PairingDefaultsSilentPresenter::class
					. ' pairs the default template class.',
					1,
				],
			],
			true,
		);
	}

	/**
	 * @group latte2
	 */
	// The strict flag requires a {templateType} only where a PHP renderer's pairing verdict resolves
	// to the default/bare floor. An include-only template has no renderer, so it has no verdict, so
	// there is nothing for the requirement to key on. Skipped BY CONSTRUCTION - the check only ever
	// runs per linked renderer - and pinned here so no future re-seeding of the reportable set can
	// walk it back.
	public function testIncludeOnlyTemplateIsNeverAskedForATemplateTypeEvenWhenTheFlagIsOn(): void
	{
		$this->assertPairingDiagnostics("body\n", [], [], true);
	}

	/**
	 * @group latte2
	 */
	public function testNonFloorRendererWithoutTemplateTypeIsSilentWhenTheFlagIsOn(): void
	{
		$this->assertPairingDiagnostics("body\n", [PairingAgreeExactPresenter::class], [], true);
	}

	/**
	 * @group latte2
	 */
	public function testDeclaredTemplateTypeSatisfiesTheStrictFlag(): void
	{
		$this->assertPairingDiagnostics(
			"{templateType Nette\\Application\\UI\\Template}\nbody\n",
			[PairingDefaultsSilentPresenter::class],
			[],
			true,
		);
	}

	// === orisaiNette.latte.templateMissing ===

	public function testViewWhoseCandidatesAllFailExistenceIsReported(): void
	{
		$this->assertDiscoveryDiagnostics(
			['page.latte' => [DiscoveryVendorPresenter::class]],
			'page.latte',
			[
				[
					TemplateTypeChecker::MISSING_IDENTIFIER,
					'No template file found for ' . DiscoveryVendorPresenter::class . '::detail (tried: '
					. 'App/templates/DiscoveryVendor/detail.latte, App/templates/DiscoveryVendor.detail.latte).',
					1,
				],
			],
		);
	}

	// One renderer, several linked templates: the finding belongs to the renderer, not to any one of
	// its templates, so it anchors on the renderer's lexicographically first linked template only.
	public function testMissingViewIsReportedOnceOnTheFirstLinkedTemplate(): void
	{
		$records = [
			'a-page.latte' => [DiscoveryVendorPresenter::class],
			'z-page.latte' => [DiscoveryVendorPresenter::class],
		];

		$this->assertDiscoveryDiagnostics($records, 'z-page.latte', []);
		self::assertCount(1, $this->discoveryDiagnostics($records, 'a-page.latte'));
	}

	// An action<View>-only view proves nothing about template resolution: the action may redirect,
	// forward, sendJson or terminate long before sendTemplate() consults the formulas. Its formula
	// candidates all failing existence is the NORMAL shape of such a view, not a runtime 500.
	public function testActionOnlyViewWithoutARenderHookIsNeverReportedMissing(): void
	{
		$this->assertDiscoveryDiagnostics(['page.latte' => [DiscoveryDeepPresenter::class]], 'page.latte', []);
	}

	// The render side held to the same standard as the action side: a render<View> that always
	// terminates never returns to the dispatch, so its existence proves no resolution either. Same
	// fixture and same records as testViewWhoseCandidatesAllFailExistenceIsReported, which reports -
	// the single collected method key is what makes the difference.
	public function testTerminatingRenderHookIsNoEvidenceOfResolution(): void
	{
		$this->assertDiscoveryDiagnostics(
			['page.latte' => [DiscoveryVendorPresenter::class]],
			'page.latte',
			[],
			[DiscoveryVendorPresenter::class . '::renderDetail'],
		);
	}

	// An abstract renderer is never the class that runs - Nette's PresenterFactory rejects it
	// outright - so its unresolvable views are not runtime failures of anything.
	public function testAbstractRendererIsNeverReportedMissing(): void
	{
		$this->assertDiscoveryDiagnostics(
			['page.latte' => [DiscoveryAbstractViewPresenter::class]],
			'page.latte',
			[],
		);
	}

	// === orisaiNette.latte.orphanTemplate: existing-but-unchosen candidates ===

	// The store records CHOSEN candidates only, which answers the wrong question for reachability:
	// an existing but unchosen candidate (a conditional write, say) is still reachable. Liveness
	// therefore seeds from the facts' EXISTING candidates too, independent of which ones are chosen.
	public function testExistingButUnchosenCandidatesAreLiveRoots(): void
	{
		$templates = [
			'App/discoveryTwoTemplatePrimary.latte' => "<p>primary</p>\n",
			'App/discoveryTwoTemplateTable.latte' => "<p>table</p>\n",
		];
		$dir = $this->corpus($templates);

		try {
			$checker = $this->discoveryChecker($dir, [], $dir . '/App', [DiscoveryTwoTemplateControl::class]);

			foreach (array_keys($templates) as $rel) {
				self::assertSame(
					[],
					$this->tuples($this->aggregate($checker, $dir, array_keys($templates), $rel)),
					$rel,
				);
			}
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	// The SETTER half of the same convention channel: $this->file is written from the presenter's
	// component factory, so no per-class walk can see it, and the receiver's own convention
	// directory is where the named template lands. The last assertion is what keeps this pin
	// non-vacuous - the very same template is orphaned when the call site is not collected.
	public function testConventionNameWrittenFromOutsideTheClassIsALiveRoot(): void
	{
		$templates = [
			'App/overridden.latte' => "<p>overridden</p>\n",
			'App/stranger.latte' => "<p>stranger</p>\n",
		];
		$dir = $this->corpus($templates);

		try {
			$checker = $this->discoveryChecker(
				$dir,
				// checkAggregate() is inert on a store carrying no class->file link at all (see its
				// own precondition), so this pin needs one - to a template neither subject analyses.
				['App/unrelated.latte' => ['Scratch\\NeverAnalysed']],
				$dir . '/App',
				[],
				[FixtureLegacyPathControlBase::class => FormulaVocabulary::DIRNAME_LCFIRST],
			);

			$analysed = array_keys($templates);
			$sites = [['class' => DiscoveryLegacyPathControl::class, 'name' => 'overridden']];

			self::assertSame(
				[],
				$this->tuples($this->aggregate($checker, $dir, $analysed, 'App/overridden.latte', [], $sites)),
			);
			self::assertCount(1, $this->aggregate($checker, $dir, $analysed, 'App/stranger.latte', [], $sites));
			self::assertCount(1, $this->aggregate($checker, $dir, $analysed, 'App/overridden.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testOpaqueDiscoveryIsNeverReportedMissing(): void
	{
		$this->assertDiscoveryDiagnostics(['page.latte' => [DiscoverySetFileOpaquePresenter::class]], 'page.latte', []);
	}

	public function testOpenViewSetIsNeverReportedMissing(): void
	{
		$this->assertDiscoveryDiagnostics(['page.latte' => [DynamicViewPresenter::class]], 'page.latte', []);
	}

	/**
	 * @group latte2
	 */
	// === orisaiNette.latte.orphanTemplate ===
	public function testTemplateCarryingADiscoveryRecordIsLive(): void
	{
		$this->assertOrphans(
			['app/page.latte' => "body\n"],
			['app/page.latte' => [PairingAgreeExactPresenter::class]],
			'app/page.latte',
			[],
		);
	}

	/**
	 * @group latte2
	 */
	public function testTemplateIncludedFromALiveTemplateIsLive(): void
	{
		$this->assertOrphans(
			['app/page.latte' => "{include 'partial.latte'}\n", 'app/partial.latte' => "body\n"],
			['app/page.latte' => [PairingAgreeExactPresenter::class]],
			'app/partial.latte',
			[],
		);
	}

	/**
	 * @group latte2
	 */
	public function testTransitivelyIncludedTemplateIsLive(): void
	{
		$this->assertOrphans(
			[
				'app/page.latte' => "{include 'partial.latte'}\n",
				'app/partial.latte' => "{include 'deep.latte'}\n",
				'app/deep.latte' => "body\n",
			],
			['app/page.latte' => [PairingAgreeExactPresenter::class]],
			'app/deep.latte',
			[],
		);
	}

	/**
	 * @group latte2
	 */
	// An auto-layout ancestor is named by no tag in the extending template - liveness must flow
	// through the discovery-derived edge exactly as it flows through a written {include}.
	public function testAutoLayoutReachedLayoutIsLive(): void
	{
		$this->assertOrphans(
			['app/page.latte' => "{block content}x{/block}\n", 'app/@layout.latte' => "{block content}y{/block}\n"],
			[
				'app/page.latte' => [PairingAgreeExactPresenter::class],
				'app/@layout.latte' => [PairingAgreeExactPresenter::class => CandidatePath::KIND_LAYOUT],
			],
			'app/@layout.latte',
			[],
		);
	}

	/**
	 * @group latte2
	 */
	public function testUnreachableTemplateIsReportedWithoutATip(): void
	{
		$diagnostics = $this->orphanDiagnostics(
			['app/page.latte' => "body\n", 'app/dead.latte' => "body\n"],
			['app/page.latte' => [PairingAgreeExactPresenter::class]],
			'app/dead.latte',
		);

		self::assertCount(1, $diagnostics);
		self::assertSame(TemplateTypeChecker::ORPHAN_IDENTIFIER, $diagnostics[0]->getIdentifier());
		self::assertSame(
			'No analysable render, include or layout path reaches this template file.',
			$diagnostics[0]->getMessage(),
		);
		self::assertSame(1, $diagnostics[0]->getLatteLine());
		self::assertNull($diagnostics[0]->getTip());
	}

	/**
	 * @group latte2
	 */
	// The transitive half: a partial with real incoming edges is still orphaned when every includer
	// is orphaned itself. The tip names them (dead-code-detector's transitive-tip precedent); the
	// message alone stays sufficient, because tips never participate in baseline matching.
	public function testOrphanIncludedOnlyByOrphansCarriesATipNamingThem(): void
	{
		$diagnostics = $this->orphanDiagnostics(
			[
				'app/page.latte' => "body\n",
				'app/deadOne.latte' => "{include 'partial.latte'}\n",
				'app/deadTwo.latte' => "{include 'partial.latte'}\n",
				'app/partial.latte' => "body\n",
			],
			['app/page.latte' => [PairingAgreeExactPresenter::class]],
			'app/partial.latte',
		);

		self::assertCount(1, $diagnostics);
		self::assertSame(
			'Included only from templates that are themselves unreachable: app/deadOne.latte, app/deadTwo.latte.',
			$diagnostics[0]->getTip(),
		);
	}

	/**
	 * @group latte2
	 */
	// Layout cycles exist in the real corpus; the visited set is what makes the fixpoint terminate.
	public function testMutuallyIncludingOrphansTerminateAndAreBothReported(): void
	{
		$templates = [
			'app/page.latte' => "body\n",
			'app/cycleOne.latte' => "{include 'cycleTwo.latte'}\n",
			'app/cycleTwo.latte' => "{include 'cycleOne.latte'}\n",
		];
		$records = ['app/page.latte' => [PairingAgreeExactPresenter::class]];

		self::assertCount(1, $this->orphanDiagnostics($templates, $records, 'app/cycleOne.latte'));
		self::assertCount(1, $this->orphanDiagnostics($templates, $records, 'app/cycleTwo.latte'));
	}

	/**
	 * @group latte2
	 */
	// The same cycle reached FROM a live root must stay silent - termination must not cost liveness.
	public function testLiveCycleIsNotReported(): void
	{
		$templates = [
			'app/page.latte' => "{include 'cycleOne.latte'}\n",
			'app/cycleOne.latte' => "{include 'cycleTwo.latte'}\n",
			'app/cycleTwo.latte' => "{include 'cycleOne.latte'}\n",
		];
		$records = ['app/page.latte' => [PairingAgreeExactPresenter::class]];

		self::assertSame([], $this->orphanDiagnostics($templates, $records, 'app/cycleOne.latte'));
		self::assertSame([], $this->orphanDiagnostics($templates, $records, 'app/cycleTwo.latte'));
	}

	/**
	 * @group latte2
	 */
	// Structural scoping, never a name list: a template outside the configured app root is out of
	// the diagnostic's domain whatever it is called or wherever the universe found it.
	public function testTemplateOutsideTheAppRootIsNeverReported(): void
	{
		$this->assertOrphans(
			['app/page.latte' => "body\n", 'fixtures/probe.latte' => "body\n"],
			['app/page.latte' => [PairingAgreeExactPresenter::class]],
			'fixtures/probe.latte',
			[],
		);
	}

	/**
	 * @group latte2
	 */
	// The store canonicalizes its records on write AND on read, so permuting THEM can never reach
	// the fixpoint - the one input order nothing upstream flattens is the analysed-file list, which
	// arrives from PHPStan's merged collected data in whatever order the workers finished in. Both
	// halves are asserted: the same finding set whatever the input order, AND a totally ordered
	// output, which is the half checkAggregate() itself owns.
	public function testAggregateOutputIsIndependentOfCollectedInputOrder(): void
	{
		$templates = [
			'app/page.latte' => "{include 'partial.latte'}\n",
			'app/partial.latte' => "body\n",
			'app/other.latte' => "body\n",
			'app/dead.latte' => "{include 'partial.latte'}\n",
			'app/deadTwo.latte' => "body\n",
		];
		$records = [
			'app/other.latte' => [PairingRuntimeNarrowerPresenter::class],
			'app/page.latte' => [PairingAgreeExactPresenter::class],
		];

		$dir = $this->corpus($templates);

		try {
			$checker = $this->pairingChecker($dir, $records, $dir . '/app');
			$analysed = array_keys($templates);

			$forward = $this->findingTuples($checker->checkAggregate($analysed, [], [], []));
			$reversed = $this->findingTuples($checker->checkAggregate(array_reverse($analysed), [], [], []));
			$interleaved = $this->findingTuples($checker->checkAggregate($this->interleave($analysed), [], [], []));

			self::assertNotSame([], $forward);
			self::assertSame($forward, $reversed);
			self::assertSame($forward, $interleaved);

			$files = [];
			foreach ($forward as $tuple) {
				$files[] = $tuple[0];
			}

			$sorted = $files;
			sort($sorted, SORT_STRING);
			self::assertSame($sorted, $files);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	// === dormancy ===
	public function testAnalysisFlagOffIsFullyDormant(): void
	{
		$dir = $this->corpus(['app/page.latte' => "body\n", 'app/dead.latte' => "body\n"]);

		try {
			$checker = $this->pairingChecker(
				$dir,
				['app/page.latte' => [PairingDefaultsSilentPresenter::class]],
				$dir . '/app',
				false,
				true,
				true,
			);

			self::assertSame([], $checker->checkAggregate(
				['app/dead.latte', 'app/page.latte'],
				[$this->declaration('app/dead.latte'), $this->declaration('app/page.latte')],
				[],
				[],
			));
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @group latte2
	 */
	// Without the store there are no live roots at all, so an orphan check would flag every single
	// app template - all four identifiers depend on store links and stay dormant with it off.
	public function testDiscoveryStoreFlagOffIsFullyDormant(): void
	{
		$dir = $this->corpus(['app/page.latte' => "body\n", 'app/dead.latte' => "body\n"]);

		try {
			$checker = $this->pairingChecker(
				$dir,
				['app/page.latte' => [PairingDefaultsSilentPresenter::class]],
				$dir . '/app',
				true,
				false,
				true,
			);

			self::assertSame([], $checker->checkAggregate(
				['app/dead.latte', 'app/page.latte'],
				[$this->declaration('app/dead.latte'), $this->declaration('app/page.latte')],
				[],
				[],
			));
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $rendererClasses
	 * @param list<array{string, string, int}> $expected
	 */
	private function assertPairingDiagnostics(
		string $source,
		array $rendererClasses,
		array $expected,
		bool $templateTypeRequired = false
	): void
	{
		$dir = $this->corpus(['page.latte' => $source]);

		try {
			// checkAggregate() is inert on a store carrying no class->file link at all (see its own
			// precondition), so a template with no renderer of its own still needs one to exist -
			// to a template this subject never analyses, so the pin stays about the subject.
			$records = $rendererClasses === []
				? ['unrelated.latte' => ['Scratch\\NeverAnalysed']]
				: ['page.latte' => $rendererClasses];
			$checker = $this->pairingChecker(
				$dir,
				$records,
				// No template lives under this path, so the reachability half contributes nothing.
				$dir . '/__no_app_scope__',
				true,
				true,
				$templateTypeRequired,
			);

			self::assertSame($expected, $this->tuples($this->aggregate(
				$checker,
				$dir,
				['page.latte'],
				'page.latte',
				[$this->declaration('page.latte', $source)],
			)));
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param array<string, list<string>> $records
	 * @param list<array{string, string, int}> $expected
	 * @param list<string> $terminatingRenderMethods
	 */
	private function assertDiscoveryDiagnostics(
		array $records,
		string $rel,
		array $expected,
		array $terminatingRenderMethods = []
	): void
	{
		self::assertSame(
			$expected,
			$this->tuples($this->discoveryDiagnostics($records, $rel, $terminatingRenderMethods)),
		);
	}

	/**
	 * @param array<string, list<string>> $records
	 * @param list<string> $terminatingRenderMethods
	 * @return list<Diagnostic>
	 */
	private function discoveryDiagnostics(array $records, string $rel, array $terminatingRenderMethods = []): array
	{
		$templates = [];
		foreach ($records as $templateRel => $classes) {
			$templates[$templateRel] = "body\n";
		}

		$dir = $this->corpus($templates);

		try {
			return $this->aggregate(
				$this->discoveryChecker($dir, $records, $dir . '/__no_app_scope__'),
				$dir,
				array_keys($templates),
				$rel,
				[],
				[],
				$terminatingRenderMethods,
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param array<string, string> $templates
	 * @param array<string, list<string>|array<string, string>> $records
	 * @param list<array{string, string, int}> $expected
	 */
	private function assertOrphans(array $templates, array $records, string $rel, array $expected): void
	{
		self::assertSame($expected, $this->tuples($this->orphanDiagnostics($templates, $records, $rel)));
	}

	/**
	 * @param array<string, string> $templates
	 * @param array<string, list<string>|array<string, string>> $records
	 * @return list<Diagnostic>
	 */
	private function orphanDiagnostics(array $templates, array $records, string $rel): array
	{
		$dir = $this->corpus($templates);

		try {
			return $this->aggregate(
				$this->pairingChecker($dir, $records, $dir . '/app'),
				$dir,
				array_keys($templates),
				$rel,
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $analysedTemplates
	 * @param list<array{path: string, class: string|null, line: int}> $templateTypeDeclarations
	 * @param list<array{class: string, name: string}> $conventionNameSites
	 * @param list<string> $terminatingRenderMethods
	 * @return list<Diagnostic>
	 */
	private function aggregate(
		TemplateTypeChecker $checker,
		string $dir,
		array $analysedTemplates,
		string $rel,
		array $templateTypeDeclarations = [],
		array $conventionNameSites = [],
		array $terminatingRenderMethods = []
	): array
	{
		$diagnostics = [];
		foreach (
			$checker->checkAggregate(
				$analysedTemplates,
				$templateTypeDeclarations,
				$conventionNameSites,
				$terminatingRenderMethods,
			)
			as $finding
		) {
			if ($finding['file'] !== $dir . '/' . $rel) {
				continue;
			}

			$diagnostics[] = $finding['diagnostic'];
		}

		return $diagnostics;
	}

	/**
	 * @param array<string, list<string>|array<string, string>> $records
	 */
	private function pairingChecker(
		string $dir,
		array $records,
		string $appRootPath,
		bool $enabled = true,
		bool $discoveryStoreEnabled = true,
		bool $templateTypeRequired = false
	): TemplateTypeChecker
	{
		$appRoot = realpath(self::PairingFixtureDir);
		self::assertNotFalse($appRoot);

		return $this->checker(
			$dir,
			$records,
			$appRootPath,
			$this->recordSource($dir, $appRoot, $appRoot, null, []),
			$enabled,
			$discoveryStoreEnabled,
			$templateTypeRequired,
		);
	}

	/**
	 * @param array<string, list<string>|array<string, string>> $records
	 * @param list<string> $indexedClasses
	 * @param array<string, string|array<string, string>> $formulas
	 */
	private function discoveryChecker(
		string $dir,
		array $records,
		string $appRootPath,
		array $indexedClasses = [],
		array $formulas = []
	): TemplateTypeChecker
	{
		$fixturesRoot = realpath(self::DiscoveryFixtureRoot);
		self::assertNotFalse($fixturesRoot);
		$appRoot = realpath(self::DiscoveryFixtureRoot . '/App');
		self::assertNotFalse($appRoot);

		return $this->checker(
			$dir,
			$records,
			$appRootPath,
			$this->recordSource($dir, $appRoot, $fixturesRoot, self::MappingLoaderFile, $formulas),
			true,
			true,
			false,
			$indexedClasses,
		);
	}

	/**
	 * @param array<string, list<string>|array<string, string>> $records
	 * @param list<string> $indexedClasses
	 */
	private function checker(
		string $dir,
		array $records,
		string $appRootPath,
		DiscoveryRecordSource $recordSource,
		bool $enabled,
		bool $discoveryStoreEnabled,
		bool $templateTypeRequired,
		array $indexedClasses = []
	): TemplateTypeChecker
	{
		$store = new DiscoveryStore($dir . '/store');
		$store->replaceWith($this->storeRecords($records), $indexedClasses, []);

		$reflectionProvider = self::createReflectionProvider();
		$universe = new LatteUniverse([$dir], $dir);
		$index = new TemplateEdgeIndex(
			$universe,
			TestAdapter::accessor(),
			null,
			$store,
			$discoveryStoreEnabled,
		);

		return new TemplateTypeChecker(
			new FixtureTemplateTypeContainer(
				$recordSource,
				new PairingJudge($reflectionProvider),
				$reflectionProvider,
			),
			$store,
			$index,
			$universe,
			[$appRootPath],
			$enabled,
			$discoveryStoreEnabled,
			$templateTypeRequired,
		);
	}

	/**
	 * @param array<string, list<string>|array<string, string>> $records
	 * @return array<string, list<array{class: string, view: string|null, kind: string, certainty: string}>>
	 */
	private function storeRecords(array $records): array
	{
		$storeRecords = [];
		foreach ($records as $rel => $classes) {
			$entries = [];
			foreach ($classes as $key => $value) {
				$className = is_int($key) ? $value : $key;
				$kind = is_int($key) ? CandidatePath::KIND_FORMULA : $value;
				$entries[] = [
					'class' => $className,
					'view' => $kind === CandidatePath::KIND_LAYOUT ? null : 'default',
					'kind' => $kind,
					'certainty' => Certainty::HAPPENS,
				];
			}

			$storeRecords[$rel] = $entries;
		}

		return $storeRecords;
	}

	/**
	 * @param array<string, string|array<string, string>> $formulas
	 */
	private function recordSource(
		string $dir,
		string $appRoot,
		string $projectRoot,
		?string $mappingLoaderFile,
		array $formulas
	): DiscoveryRecordSource
	{
		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$templateFactoryDefault = new TemplateFactoryDefaultResolver(null);
		$discoveryResolver = new DiscoveryResolver($mappingLoaderFile, $formulas, $projectRoot);

		return new DiscoveryRecordSource(
			new PhpFactsCache(
				new LatteAnalysisCache($dir . '/cache'),
				$templateFactoryDefault,
				$discoveryResolver,
				[],
			),
			new PhpRenderWalk(
				self::createReflectionProvider(),
				$parser,
				[$appRoot],
				$templateFactoryDefault,
				$discoveryResolver,
			),
		);
	}

	/**
	 * @param array<string, string> $templates
	 */
	private function corpus(array $templates): string
	{
		$dir = $this->scratchDir();
		foreach ($templates as $rel => $source) {
			FileSystem::write($dir . '/' . $rel, $source);
		}

		return $dir;
	}

	/**
	 * @return array{path: string, class: string|null, line: int}
	 */
	private function declaration(string $rel, string $source = "body\n"): array
	{
		$declarations = (new DeclarationScanner())->scan($source);

		return [
			'path' => $rel,
			'class' => $declarations->getTemplateTypeClass(),
			'line' => $declarations->getTemplateTypeLine() ?? 1,
		];
	}

	/**
	 * @param list<Diagnostic> $diagnostics
	 * @return list<array{string, string, int}>
	 */
	private function tuples(array $diagnostics): array
	{
		return array_map(
			static fn (Diagnostic $diagnostic): array => [
				$diagnostic->getIdentifier(),
				$diagnostic->getMessage(),
				$diagnostic->getLatteLine(),
			],
			$diagnostics,
		);
	}

	/**
	 * @param list<array{file: string, diagnostic: Diagnostic}> $findings
	 * @return list<array{string, string, string, int}>
	 */
	private function findingTuples(array $findings): array
	{
		return array_map(
			static fn (array $finding): array => [
				$finding['file'],
				$finding['diagnostic']->getIdentifier(),
				$finding['diagnostic']->getMessage(),
				$finding['diagnostic']->getLatteLine(),
			],
			$findings,
		);
	}

	/**
	 * @param list<string> $items
	 * @return list<string>
	 */
	private function interleave(array $items): array
	{
		$odd = [];
		$even = [];
		foreach ($items as $position => $item) {
			if ($position % 2 === 0) {
				$even[] = $item;
			} else {
				$odd[] = $item;
			}
		}

		return array_merge($odd, $even);
	}

	private function scratchDir(): string
	{
		return sys_get_temp_dir() . '/latte-templatetype-test-' . getmypid() . '-' . uniqid('', true);
	}

}
