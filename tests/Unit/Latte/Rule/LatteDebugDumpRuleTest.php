<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use Nette\Application\UI\Template as UiTemplate;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\CapturedOverlay;
use OriPhpstan\Nette\Latte\Includes\ContextResolver;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Rule\LatteDebugDumpRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Type;
use PHPUnit\Framework\MockObject\Stub;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ConditionalCertaintyPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryMethodlessPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoverySetFileActionPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoverySetFileOpaquePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryTemplatesPathControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryVendorPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DynamicViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\FixtureTemplatesPathControlBase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\GetTemplateOnlySurfaceFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LifecycleViewsPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\OverwriteOrderViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\RenderSiteLiteralPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\RenderSiteNoArgPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\RenderSiteNonLiteralArgPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SamplePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SetFileConditionalPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SetFileConventionPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SetFileOpaquePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ShutdownOnlyViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassChannelTargetOne;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassChannelTargetThree;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassChannelTargetTwo;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassPhpdocOverridePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassRepeatedObservationPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnrelatedNameOnlyPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingBaseTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingChildTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingConventionDelegatingControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingConventionDynamicControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingDefaultsSilentPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingDualChannelControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingNarrowerDeclarationPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingNonQualifyingService;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingUnrelatedTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingVanishedCachePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureTemplateTypeCustoms;
use function class_exists;
use function dirname;
use function getmypid;
use function implode;
use function is_dir;
use function realpath;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

// Real ContextResolver/TemplateEdgeIndex/LatteUniverse built by hand against a fixture directory
// (ContextResolverTest's own pattern), never RuleTestCase: the rule's constructor deps are cheap
// to build directly, and RuleTestCase's own DIC binds %currentWorkingDirectory% to phpstan's
// internal testing sandbox rather than a real project root (see LatteSiteScopeWriterRuleTest for
// the same reasoning). Scope is stubbed (LatteEdgeScopeCollectorTest's own pattern) since
// getType()/getFunctionName() need no real analysis run to exercise this rule's own formatting.
final class LatteDebugDumpRuleTest extends BaseTestCase
{

	// Deliberately a string literal: the point is a class name reflection cannot resolve, the
	// shape cache-loaded facts produce after a template class is deleted or renamed.
	private const VanishedTemplateClass = 'Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Pairing\\Fixtures\\App\\PairingVanishedTemplateReplica';

	private const MappingLoaderFile = __DIR__ . '/../Bridge/Fixtures/presenter-mapping-container-loader.php';

	private const DiscoveryFormulas = [
		FixtureTemplatesPathControlBase::class => 'dirname-templates-lcfirst',
	];

	public function testDumpIncludersReportsExactZeroEdgeTextForAFileWithNoIncomingEdges(): void
	{
		$dir = $this->isolatedDir('zero-edge');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->rule($dir)->processNode(
				$this->funcCall('OriPhpstan\Nette\Latte\Testing\dumpLatteIncluders'),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame('orisaiNette.latte.debugDump', $errors[0]->getIdentifier());
			self::assertSame(
				'no incoming Latte edges (convention-wired? PHP-side wiring is invisible until phase 3)',
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Bare {do dumpLatteIncluders()} is the documented usage
	// syntax. The compiled Latte class carries no `namespace` statement, so that bare call resolves
	// at the AST level to the plain unqualified name, never the OriPhpstan\Nette\Latte\Testing\...
	// FQN - the form every other test in this file uses. Both must match, or the documented syntax
	// silently no-ops (no error, no hint - the worst failure mode for a debug tool).
	public function testDumpIncludersMatchesTheBareUnqualifiedFunctionName(): void
	{
		$dir = $this->isolatedDir('bare-zero-edge');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->rule($dir)->processNode(
				$this->funcCall('dumpLatteIncluders'),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'no incoming Latte edges (convention-wired? PHP-side wiring is invisible until phase 3)',
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpIncludersListsEachIncomingEdgeWithItsIncluderContextCount(): void
	{
		$dir = $this->isolatedDir('one-edge');
		FileSystem::write($dir . '/includer.latte', "{include 'included.latte'}\n");
		FileSystem::write($dir . '/included.latte', "irrelevant\n");

		try {
			$errors = $this->rule($dir)->processNode(
				$this->funcCall('OriPhpstan\Nette\Latte\Testing\dumpLatteIncluders'),
				$this->scopeForFile($dir . '/included.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame('includer.latte:1 (include) - 1 context(s)', $errors[0]->getMessage());
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpVarOriginReportsChainTypeProvenanceAndUnionPerContext(): void
	{
		$dir = $this->isolatedDir('var-origin');
		FileSystem::write(
			$dir . '/includer-a.latte',
			"{varType Exception \$x}\n{include 'target.latte', x => \$x}\n",
		);
		FileSystem::write(
			$dir . '/includer-b.latte',
			"{varType RuntimeException \$x}\n{include 'target.latte', x => \$x}\n",
		);
		FileSystem::write($dir . '/target.latte', "irrelevant\n");

		try {
			$universe = new LatteUniverse([$dir], $dir);
			$edgeIndex = new TemplateEdgeIndex($universe, new TemplateFactExtractor());
			$resolver = new ContextResolver(
				$edgeIndex,
				new DeclarationScanner(),
				$universe,
				new CapturedOverlay(new SiteScopeStore($dir . '/__absent_store__'), true),
			);
			$rule = new LatteDebugDumpRule(
				TestGuard::latte(),
				$resolver,
				$edgeIndex,
				$universe,
				new DeclarationScanner(),
			);

			$sorted = TemplateContext::sortByHash($resolver->contextsFor('target.latte'));
			self::assertCount(2, $sorted, 'fixture must produce exactly two distinct contexts for $x');

			// Literal, not derived via the same algorithm describeUnion() itself runs (a shared bug
			// in both copies would otherwise go undetected) - order pinned empirically: context-hash
			// sort is deterministic but not alphabetical, matching LatteDebugDumpIntegrationTest's
			// identical two-class fixture.
			$union = 'RuntimeException|Exception across 2 contexts';

			foreach ($sorted as $i => $context) {
				$node = $this->funcCall('OriPhpstan\Nette\Latte\Testing\dumpLatteVarOrigin', [new Variable('x')]);
				$scope = $this->scopeForVarOrigin(
					$dir . '/target.latte',
					'latteMain_ctx' . $i,
					$context->getVars()['x'],
				);

				$errors = $rule->processNode($node, $scope);

				self::assertCount(1, $errors);
				self::assertSame('orisaiNette.latte.debugDump', $errors[0]->getIdentifier());
				self::assertSame(
					sprintf(
						"\$x: %s\nchain: %s\nprovenance: %s\nunion: %s",
						$context->getVars()['x'],
						implode(' -> ', $context->getChain()),
						$context->getProvenance()['x'],
						$union,
					),
					$errors[0]->getMessage(),
				);
			}
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Same bare-form requirement as testDumpIncludersMatchesTheBareUnqualifiedFunctionName above,
	// for {do dumpLatteVarOrigin($x)}.
	public function testDumpVarOriginMatchesTheBareUnqualifiedFunctionName(): void
	{
		$dir = $this->isolatedDir('bare-var-origin');
		FileSystem::write($dir . '/target.latte', "irrelevant\n");

		try {
			$node = $this->funcCall('dumpLatteVarOrigin', [new Variable('x')]);
			$scope = $this->scopeForVarOrigin($dir . '/target.latte', 'latteMain_ctx0', 'stdClass');

			$errors = $this->rule($dir)->processNode($node, $scope);

			self::assertCount(1, $errors);
			self::assertSame(
				"\$x: stdClass\nchain: root (no incoming edges)\nprovenance: default:mixed\nunion: mixed across 1 context",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpVarOriginDegradesToUnknownWhenScopeIsNotAContextClone(): void
	{
		$dir = $this->isolatedDir('var-origin-no-clone');
		FileSystem::write($dir . '/target.latte', "irrelevant\n");

		try {
			$node = $this->funcCall('OriPhpstan\Nette\Latte\Testing\dumpLatteVarOrigin', [new Variable('x')]);
			// 'latteMain' (no _ctxN suffix) - a block/prepare scope, never context-cloned; the rule
			// must still emit ONE valid error rather than crash or silently no-op.
			$scope = $this->scopeForVarOrigin($dir . '/target.latte', 'latteMain', 'stdClass');

			$errors = $this->rule($dir)->processNode($node, $scope);

			self::assertCount(1, $errors);
			self::assertSame(
				"\$x: stdClass\nchain: unknown\nprovenance: unknown\nunion: mixed across 1 context",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpVarOriginNonVariableExpressionUsesAGenericLabel(): void
	{
		$dir = $this->isolatedDir('var-origin-non-variable');
		FileSystem::write($dir . '/target.latte', "irrelevant\n");

		try {
			$node = $this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteVarOrigin',
				[new Expr\ConstFetch(new Name('null'))],
			);
			$scope = $this->scopeForVarOrigin($dir . '/target.latte', 'latteMain_ctx0', 'null');

			$errors = $this->rule($dir)->processNode($node, $scope);

			self::assertCount(1, $errors);
			self::assertSame(
				"expr: null\nchain: root (no incoming edges)\nprovenance: unknown\nunion: null across 1 context",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testProcessNodeIgnoresUnrelatedFunctionCalls(): void
	{
		$dir = $this->isolatedDir('unrelated');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->rule($dir)->processNode(
				$this->funcCall('strlen', [new Variable('x')]),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertSame([], $errors);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testProcessNodeIgnoresCallsOutsideLatteScopedFiles(): void
	{
		$dir = $this->isolatedDir('non-latte');

		try {
			$errors = $this->rule($dir)->processNode(
				$this->funcCall('OriPhpstan\Nette\Latte\Testing\dumpLatteIncluders'),
				$this->scopeForFile('/some/where/Regular.php'),
			);

			self::assertSame([], $errors);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpCustomsReportsNoHarvestSourceConfiguredAndNoTemplateWhenNeitherIsWired(): void
	{
		$dir = $this->isolatedDir('customs-none');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->rule($dir)->processNode(
				$this->funcCall('OriPhpstan\Nette\Latte\Testing\dumpLatteCustoms'),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame('orisaiNette.latte.debugDump', $errors[0]->getIdentifier());
			self::assertSame(
				'global: no harvest source configured (orisaiNette.dic.containerLoader / orisaiNette.latte.engineLoader)'
				. "\ntemplate: (none)\ntemplate filters: (none)\ntemplate functions: (none)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Same bare-form requirement as the includers/varOrigin variants above, for {do
	// dumpLatteCustoms()}.
	public function testDumpCustomsMatchesTheBareUnqualifiedFunctionName(): void
	{
		$dir = $this->isolatedDir('bare-customs');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->rule($dir)->processNode(
				$this->funcCall('dumpLatteCustoms'),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'global: no harvest source configured (orisaiNette.dic.containerLoader / orisaiNette.latte.engineLoader)'
				. "\ntemplate: (none)\ntemplate filters: (none)\ntemplate functions: (none)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// A configured-but-failing engine loader is a materially different state from "nothing wired
	// at all" above (EngineSource::isConfigured() is true - the harvest genuinely ran and came back
	// empty), so the global section reports per-kind "(none)" rather than the no-source line.
	public function testDumpCustomsReportsNoneForEveryGlobalKindWhenTheConfiguredSourceYieldsAnEmptyHarvest(): void
	{
		$dir = $this->isolatedDir('customs-empty-harvest');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$harvester = new CustomsHarvester(new EngineSource(
				null,
				__DIR__ . '/../Customs/Fixtures/engine-loader-throwing.php',
			));
			$universe = new LatteUniverse([$dir], $dir);
			$edgeIndex = new TemplateEdgeIndex($universe, new TemplateFactExtractor());
			$resolver = new ContextResolver(
				$edgeIndex,
				new DeclarationScanner(),
				$universe,
				new CapturedOverlay(new SiteScopeStore($dir . '/__absent_store__'), true),
			);
			$rule = new LatteDebugDumpRule(
				TestGuard::latte(),
				$resolver,
				$edgeIndex,
				$universe,
				new DeclarationScanner(),
				$harvester,
			);

			$errors = $rule->processNode(
				$this->funcCall('OriPhpstan\Nette\Latte\Testing\dumpLatteCustoms'),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				"global filters: (none)\nglobal functions: (none)\nglobal macros: (none)"
				. "\ntemplate: (none)\ntemplate filters: (none)\ntemplate functions: (none)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpCustomsListsPerTemplateEntriesFromTheCurrentTemplatesTemplateTypeWithTheDeclaringClassNamed(): void
	{
		$dir = $this->isolatedDir('customs-per-template');
		$fqcn = FixtureTemplateTypeCustoms::class;
		FileSystem::write($dir . '/target.latte', "{templateType $fqcn}\nHello.\n");

		try {
			$universe = new LatteUniverse([$dir], $dir);
			$edgeIndex = new TemplateEdgeIndex($universe, new TemplateFactExtractor());
			$resolver = new ContextResolver(
				$edgeIndex,
				new DeclarationScanner(),
				$universe,
				new CapturedOverlay(new SiteScopeStore($dir . '/__absent_store__'), true),
			);
			$rule = new LatteDebugDumpRule(
				TestGuard::latte(),
				$resolver,
				$edgeIndex,
				$universe,
				new DeclarationScanner(),
				null,
				$this->templateTypeCustoms(),
			);

			$errors = $rule->processNode(
				$this->funcCall('OriPhpstan\Nette\Latte\Testing\dumpLatteCustoms'),
				$this->scopeForFile($dir . '/target.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'global: no harvest source configured (orisaiNette.dic.containerLoader / orisaiNette.latte.engineLoader)'
				. "\ntemplate: $fqcn"
				. "\ntemplate filters: myTplFilter ($fqcn)"
				. "\ntemplate functions: myTplFunction ($fqcn)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsReportsUnknownClassExplicitly(): void
	{
		$dir = $this->isolatedDir('render-facts-unknown');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch('Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DoesNotExist')],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame('orisaiNette.latte.debugDump', $errors[0]->getIdentifier());
			self::assertSame(
				"class: Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Fixtures\\App\\DoesNotExist\nno render facts: class not found",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Same bare-form requirement as the includers/varOrigin/customs variants above, for {do
	// dumpLatteRenderFacts(\X::class)}.
	public function testDumpRenderFactsMatchesTheBareUnqualifiedFunctionName(): void
	{
		$dir = $this->isolatedDir('bare-render-facts');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'dumpLatteRenderFacts',
					[$this->classConstFetch('Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DoesNotExist')],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				"class: Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Fixtures\\App\\DoesNotExist\nno render facts: class not found",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsReportsNonQualifyingClassExplicitly(): void
	{
		$dir = $this->isolatedDir('render-facts-non-qualifying');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(UnrelatedNameOnlyPresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . UnrelatedNameOnlyPresenter::class
				. "\nno render facts: class does not qualify"
				. ' (no template surface or trusted createTemplate call under the app root)',
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsRendersAssignmentsWithTypeCertaintyAndSites(): void
	{
		$dir = $this->isolatedDir('render-facts-assignments');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");
		$file = (new ReflectionClass(ConditionalCertaintyPresenter::class))->getFileName();

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(ConditionalCertaintyPresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . ConditionalCertaintyPresenter::class
				. "\ntemplate class: " . UiTemplate::class . ' (templateFloor, happens)'
				. "\nassignments:"
				. "\n\$definite: string (happens) @ {$file}:19"
				. "\n\$maybe: string (maybe) @ {$file}:22"
				. "\nsetFile targets: (none)"
				. "\nrender sites: (none)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsRendersSetFileTargetsWithKindAndSite(): void
	{
		$dir = $this->isolatedDir('render-facts-setfile');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");
		$file = (new ReflectionClass(SetFileConventionPresenter::class))->getFileName();

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(SetFileConventionPresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . SetFileConventionPresenter::class
				. "\ntemplate class: " . UiTemplate::class . ' (templateFloor, happens)'
				. "\nassignments: (none)"
				. "\nsetFile targets:"
				. "\nconvention (happens) @ {$file}:16"
				. "\nrender sites: (none)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsRendersRenderSitesWithLiteralPath(): void
	{
		$dir = $this->isolatedDir('render-facts-render-sites');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");
		$file = (new ReflectionClass(RenderSiteLiteralPresenter::class))->getFileName();
		self::assertIsString($file);
		$literal = dirname($file) . '/foo.latte';

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(RenderSiteLiteralPresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . RenderSiteLiteralPresenter::class
				. "\ntemplate class: " . UiTemplate::class . ' (templateFloor, happens)'
				. "\nassignments: (none)"
				. "\nsetFile targets: (none)"
				. "\nrender sites:"
				. "\n{$file}:16 (file: '{$literal}')",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsRendersSetFileLiteralTargetWithTheQuotedPath(): void
	{
		$dir = $this->isolatedDir('render-facts-setfile-literal');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");
		$file = (new ReflectionClass(SetFileConditionalPresenter::class))->getFileName();
		self::assertIsString($file);
		$literal = dirname($file) . '/cond.latte';

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(SetFileConditionalPresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . SetFileConditionalPresenter::class
				. "\ntemplate class: " . UiTemplate::class . ' (templateFloor, happens)'
				. "\nassignments: (none)"
				. "\nsetFile targets:"
				. "\nliteral '{$literal}' (maybe) @ {$file}:20"
				. "\nrender sites: (none)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsRendersSetFileOpaqueTargetWithoutAPath(): void
	{
		$dir = $this->isolatedDir('render-facts-setfile-opaque');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");
		$file = (new ReflectionClass(SetFileOpaquePresenter::class))->getFileName();

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(SetFileOpaquePresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . SetFileOpaquePresenter::class
				. "\ntemplate class: " . UiTemplate::class . ' (templateFloor, happens)'
				. "\nassignments: (none)"
				. "\nsetFile targets:"
				. "\nopaque (happens) @ {$file}:17"
				. "\nrender sites: (none)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsRendersRenderSiteWithNoFileArg(): void
	{
		$dir = $this->isolatedDir('render-facts-render-no-arg');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");
		$file = (new ReflectionClass(RenderSiteNoArgPresenter::class))->getFileName();

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(RenderSiteNoArgPresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . RenderSiteNoArgPresenter::class
				. "\ntemplate class: " . UiTemplate::class . ' (templateFloor, happens)'
				. "\nassignments: (none)"
				. "\nsetFile targets: (none)"
				. "\nrender sites:"
				. "\n{$file}:16 (no file arg)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsRendersRenderSiteWithNonLiteralFileArg(): void
	{
		$dir = $this->isolatedDir('render-facts-render-non-literal');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");
		$file = (new ReflectionClass(RenderSiteNonLiteralArgPresenter::class))->getFileName();

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(RenderSiteNonLiteralArgPresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . RenderSiteNonLiteralArgPresenter::class
				. "\ntemplate class: " . UiTemplate::class . ' (templateFloor, happens)'
				. "\nassignments: (none)"
				. "\nsetFile targets: (none)"
				. "\nrender sites:"
				. "\n{$file}:16 (file arg, non-literal)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsRendersThePhpdocChannelInTheTemplateClassLine(): void
	{
		$dir = $this->isolatedDir('render-facts-phpdoc-channel');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(TemplateClassPhpdocOverridePresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . TemplateClassPhpdocOverridePresenter::class
				. "\ntemplate class: " . TemplateClassChannelTargetOne::class . ' (phpdoc, maybe)'
				. "\ntemplate class candidates:"
				. "\n" . TemplateClassChannelTargetOne::class . ' (phpdoc, happens)'
				. "\n" . TemplateClassChannelTargetOne::class . ' (genericBinding, happens)'
				. "\n" . TemplateClassChannelTargetTwo::class . ' (createTemplate, happens) @ 22'
				. "\nassignments: (none)"
				. "\nsetFile targets: (none)"
				. "\nrender sites: (none)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsRendersTheGenericBindingChannelInTheTemplateClassLine(): void
	{
		$dir = $this->isolatedDir('render-facts-generic-binding-channel');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		$file = (new ReflectionClass(GetTemplateOnlySurfaceFixture::class))->getFileName();

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(GetTemplateOnlySurfaceFixture::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . GetTemplateOnlySurfaceFixture::class
				. "\ntemplate class: " . DefaultTemplate::class . ' (genericBinding, happens)'
				. "\ntemplate class candidates:"
				. "\n" . DefaultTemplate::class . ' (genericBinding, happens)'
				. "\nassignments:"
				. "\n\$heading: string (happens) @ {$file}:25"
				. "\n\$subtitle: string (maybe) @ {$file}:28"
				. "\nsetFile targets: (none)"
				. "\nrender sites:"
				. "\n{$file}:31 (no file arg)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsRendersEveryMergedSiteOnOneCandidateLine(): void
	{
		$dir = $this->isolatedDir('render-facts-candidate-sites');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(TemplateClassRepeatedObservationPresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . TemplateClassRepeatedObservationPresenter::class
				. "\ntemplate class: " . TemplateClassChannelTargetThree::class . ' (factoryStatic, maybe)'
				. "\ntemplate class candidates:"
				. "\n" . TemplateClassChannelTargetThree::class . ' (factoryStatic, maybe) @ 16, 19'
				. "\nassignments: (none)"
				. "\nsetFile targets: (none)"
				. "\nrender sites: (none)",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The facts describe a PHP class, so unlike the template-centric dumps above this one must
	// fire from ANY analyzed context - a developer drops it in the presenter file itself, no
	// scratch template needed.
	public function testDumpRenderFactsFiresOutsideLatteScopedFiles(): void
	{
		$dir = $this->isolatedDir('render-facts-php-context');

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch('Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DoesNotExist')],
				),
				$this->scopeForFile('/some/where/RegularPresenter.php'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				"class: Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Fixtures\\App\\DoesNotExist\nno render facts: class not found",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsRequiresAClassConstantArgument(): void
	{
		$dir = $this->isolatedDir('render-facts-bad-arg');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$rule = $this->ruleWithRenderWalk($dir);
			$scope = $this->scopeForFile($dir . '/lonely.latte');

			foreach ([
				[new String_(SamplePresenter::class)],
				[],
			] as $args) {
				$errors = $rule->processNode(
					$this->funcCall('OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts', $args),
					$scope,
				);

				self::assertCount(1, $errors);
				self::assertSame(
					'dumpLatteRenderFacts() expects a ::class constant argument',
					$errors[0]->getMessage(),
				);
			}
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpRenderFactsReportsWalkNotWiredInsteadOfCrashing(): void
	{
		$dir = $this->isolatedDir('render-facts-no-walk');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->rule($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch(SamplePresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . SamplePresenter::class . "\nno render facts: PhpRenderWalk not wired",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The m6-corrected wording: a class reflection knows but has no source file for (runtime-
	// defined here via eval; native-extension classes without stubs in real runs - phpstorm-stubs-
	// backed built-ins like stdClass DO carry a stub file and stay non-qualifying instead) is a
	// distinct state from an unknown class and must not claim "class not found".
	public function testDumpRenderFactsReportsNoAnalyzableFileForAFilelessClass(): void
	{
		$dir = $this->isolatedDir('render-facts-no-file');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");
		$this->defineFilelessProbeClass();

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts',
					[$this->classConstFetch('LatteBridgeFilelessProbe')],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				"class: LatteBridgeFilelessProbe\nno render facts: class has no analyzable file",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpPairingReportsUnknownClassExplicitly(): void
	{
		$errors = $this->pairingRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLattePairing',
				[$this->classConstFetch('Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\DoesNotExist')],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame('orisaiNette.latte.debugDump', $errors[0]->getIdentifier());
		self::assertSame(
			"class: Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Pairing\\Fixtures\\App\\DoesNotExist\nno pairing: class not found",
			$errors[0]->getMessage(),
		);
	}

	// Same bare-form requirement as the other dump variants, for {do dumpLattePairing(\X::class)} -
	// exercised from a .latte scope, which with the unknown-class pin above also covers both firing
	// contexts (the pairing verdict describes a PHP class, so the dump fires from any of them).
	public function testDumpPairingMatchesTheBareUnqualifiedFunctionName(): void
	{
		$errors = $this->pairingRule()->processNode(
			$this->funcCall(
				'dumpLattePairing',
				[$this->classConstFetch('Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\DoesNotExist')],
			),
			$this->scopeForFile('/some/where/template.latte'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			"class: Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Pairing\\Fixtures\\App\\DoesNotExist\nno pairing: class not found",
			$errors[0]->getMessage(),
		);
	}

	// Same m6-corrected wording as the render-facts variant above, on the pairing surface.
	public function testDumpPairingReportsNoAnalyzableFileForAFilelessClass(): void
	{
		$this->defineFilelessProbeClass();

		$errors = $this->pairingRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLattePairing',
				[$this->classConstFetch('LatteBridgeFilelessProbe')],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			"class: LatteBridgeFilelessProbe\nno pairing: class has no analyzable file",
			$errors[0]->getMessage(),
		);
	}

	// Non-qualifying classes never reach judge() - it throws on non-qualifying facts by ratified
	// contract (same qualification gate as LattePairingRule).
	public function testDumpPairingReportsNonQualifyingClassExplicitly(): void
	{
		$errors = $this->pairingRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLattePairing',
				[$this->classConstFetch(PairingNonQualifyingService::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . PairingNonQualifyingService::class
			. "\nno pairing: class does not qualify"
			. ' (no template surface or trusted createTemplate call under the app root)',
			$errors[0]->getMessage(),
		);
	}

	// The dump renders the VERDICT, not the raw facts: a convention-only class shows its
	// convention-resolved primary where the facts ladder (dumpLatteRenderFacts) would floor-resolve
	// it to the Template interface.
	public function testDumpPairingRendersTheConventionResolvedPrimaryNotTheFactsLadderFloor(): void
	{
		$errors = $this->pairingRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLattePairing',
				[$this->classConstFetch(PairingConventionDelegatingControl::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . PairingConventionDelegatingControl::class
			. "\nprimary: " . PairingBaseTemplateReplica::class . ' (convention, happens)'
			. "\ncandidates:"
			. "\n" . PairingBaseTemplateReplica::class . ' (convention, happens) @ 21'
			. "\nsite pairings: (none)"
			. "\nconflicts: (none)"
			. "\nopaques: (none)",
			$errors[0]->getMessage(),
		);
	}

	public function testDumpPairingRendersCandidateAndMergedConflictLinesNamingBothChannels(): void
	{
		$errors = $this->pairingRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLattePairing',
				[$this->classConstFetch(PairingNarrowerDeclarationPresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . PairingNarrowerDeclarationPresenter::class
			. "\nprimary: " . PairingBaseTemplateReplica::class . ' (new, happens)'
			. "\ncandidates:"
			. "\n" . PairingChildTemplateReplica::class . ' (phpdoc, happens)'
			. "\n" . PairingChildTemplateReplica::class . ' (genericBinding, happens)'
			. "\n" . PairingBaseTemplateReplica::class . ' (new, happens) @ 13'
			. "\nsite pairings: (none)"
			. "\nconflicts:"
			. "\n" . PairingChildTemplateReplica::class . ' (phpdoc, genericBinding) vs '
			. PairingBaseTemplateReplica::class . ' (new)'
			. "\nopaques: (none)",
			$errors[0]->getMessage(),
		);
	}

	public function testDumpPairingRendersSitePairingLines(): void
	{
		$errors = $this->pairingRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLattePairing',
				[$this->classConstFetch(PairingDualChannelControl::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . PairingDualChannelControl::class
			. "\nprimary: " . PairingBaseTemplateReplica::class . ' (phpdoc, happens)'
			. "\ncandidates:"
			. "\n" . PairingBaseTemplateReplica::class . ' (phpdoc, happens)'
			. "\n" . PairingBaseTemplateReplica::class . ' (genericBinding, happens)'
			. "\n" . PairingUnrelatedTemplateReplica::class . ' (createTemplate, happens) @ 22'
			. "\nsite pairings:"
			. "\n" . PairingUnrelatedTemplateReplica::class . ' (createTemplate) @ 22'
			. "\nconflicts: (none)"
			. "\nopaques: (none)",
			$errors[0]->getMessage(),
		);
	}

	// The *dynamic* marker renders verbatim in the primary/candidate slots - by construction never
	// a valid class name - and the opaque line names only the channel, never the marker as a class.
	public function testDumpPairingRendersTheDynamicMarkerAndTheOpaqueSiteLine(): void
	{
		$errors = $this->pairingRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLattePairing',
				[$this->classConstFetch(PairingConventionDynamicControl::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . PairingConventionDynamicControl::class
			. "\nprimary: *dynamic* (convention, happens)"
			. "\ncandidates:"
			. "\n*dynamic* (convention, happens) @ 19"
			. "\nsite pairings: (none)"
			. "\nconflicts: (none)"
			. "\nopaques:"
			. "\nconvention @ 19",
			$errors[0]->getMessage(),
		);
	}

	public function testDumpPairingRendersTheFloorPrimaryWithEverySectionEmpty(): void
	{
		$errors = $this->pairingRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLattePairing',
				[$this->classConstFetch(PairingDefaultsSilentPresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . PairingDefaultsSilentPresenter::class
			. "\nprimary: " . UiTemplate::class . ' (templateFloor, happens)'
			. "\ncandidates: (none)"
			. "\nsite pairings: (none)"
			. "\nconflicts: (none)"
			. "\nopaques: (none)",
			$errors[0]->getMessage(),
		);
	}

	// A site-less opaque (cache-loaded facts naming a since-vanished declaration class) renders the
	// bare channel - no ` @ 0` from the NO_SITE_LINE sentinel.
	public function testDumpPairingRendersASitelessOpaqueChannelBare(): void
	{
		$cacheDir = $this->isolatedDir('pairing-vanished-cache');

		try {
			$factoryDefault = new TemplateFactoryDefaultResolver(null);
			$cache = new PhpFactsCache(
				new LatteAnalysisCache($cacheDir),
				$factoryDefault,
				new DiscoveryResolver(null, [], $cacheDir),
			);
			$vanishedFact = new TemplateClassFact(
				self::VanishedTemplateClass,
				TemplateClassFact::CHANNEL_PHPDOC,
				Certainty::HAPPENS,
				[],
			);
			$cache->remember(
				PairingVanishedCachePresenter::class,
				static fn (): PhpRenderFacts => new PhpRenderFacts([], [], $vanishedFact, [], [], [$vanishedFact]),
			);

			$errors = $this->pairingRule($cache)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLattePairing',
					[$this->classConstFetch(PairingVanishedCachePresenter::class)],
				),
				$this->scopeForFile('/some/where/RegularPresenter.php'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . PairingVanishedCachePresenter::class
				. "\nprimary: " . self::VanishedTemplateClass . ' (phpdoc, happens)'
				. "\ncandidates:"
				. "\n" . self::VanishedTemplateClass . ' (phpdoc, happens)'
				. "\nsite pairings: (none)"
				. "\nconflicts: (none)"
				. "\nopaques:"
				. "\nphpdoc",
				$errors[0]->getMessage(),
			);
		} finally {
			if (is_dir($cacheDir)) {
				FileSystem::delete($cacheDir);
			}
		}
	}

	public function testDumpPairingRequiresAClassConstantArgument(): void
	{
		$rule = $this->pairingRule();
		$scope = $this->scopeForFile('/some/where/RegularPresenter.php');

		foreach ([
			[new String_(SamplePresenter::class)],
			[],
		] as $args) {
			$errors = $rule->processNode(
				$this->funcCall('OriPhpstan\Nette\Latte\Testing\dumpLattePairing', $args),
				$scope,
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'dumpLattePairing() expects a ::class constant argument',
				$errors[0]->getMessage(),
			);
		}
	}

	public function testDumpPairingReportsWalkNotWiredInsteadOfCrashing(): void
	{
		$errors = $this->rule($this->isolatedDir('pairing-no-walk'))->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLattePairing',
				[$this->classConstFetch(SamplePresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . SamplePresenter::class . "\nno pairing: PhpRenderWalk not wired",
			$errors[0]->getMessage(),
		);
	}

	public function testDumpPairingReportsJudgeNotWiredInsteadOfCrashing(): void
	{
		$dir = $this->isolatedDir('pairing-no-judge');
		FileSystem::write($dir . '/lonely.latte', "irrelevant\n");

		try {
			$errors = $this->ruleWithRenderWalk($dir)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLattePairing',
					[$this->classConstFetch(SamplePresenter::class)],
				),
				$this->scopeForFile($dir . '/lonely.latte'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . SamplePresenter::class . "\nno pairing: PairingJudge not wired",
				$errors[0]->getMessage(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDumpDiscoveryReportsUnknownClassExplicitly(): void
	{
		$errors = $this->discoveryRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch('Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DoesNotExist')],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame('orisaiNette.latte.debugDump', $errors[0]->getIdentifier());
		self::assertSame(
			"class: Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Fixtures\\App\\DoesNotExist\nno discovery: class not found",
			$errors[0]->getMessage(),
		);
	}

	// Same bare-form requirement as the other dump variants, for {do dumpLatteDiscovery(\X::class)} -
	// exercised from a .latte scope, which with the unknown-class pin above also covers both firing
	// contexts (discovery describes a PHP class, so the dump fires from any of them).
	public function testDumpDiscoveryMatchesTheBareUnqualifiedFunctionName(): void
	{
		$errors = $this->discoveryRule()->processNode(
			$this->funcCall(
				'dumpLatteDiscovery',
				[$this->classConstFetch('Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DoesNotExist')],
			),
			$this->scopeForFile('/some/where/template.latte'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			"class: Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Fixtures\\App\\DoesNotExist\nno discovery: class not found",
			$errors[0]->getMessage(),
		);
	}

	public function testDumpDiscoveryReportsNonQualifyingClassExplicitly(): void
	{
		$errors = $this->discoveryRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch(UnrelatedNameOnlyPresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . UnrelatedNameOnlyPresenter::class
			. "\nno discovery: class does not qualify"
			. ' (no template surface or trusted createTemplate call under the app root)',
			$errors[0]->getMessage(),
		);
	}

	// Same m6-corrected wording as the render-facts/pairing variants, on the discovery surface.
	public function testDumpDiscoveryReportsNoAnalyzableFileForAFilelessClass(): void
	{
		$this->defineFilelessProbeClass();

		$errors = $this->discoveryRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch('LatteBridgeFilelessProbe')],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			"class: LatteBridgeFilelessProbe\nno discovery: class has no analyzable file",
			$errors[0]->getMessage(),
		);
	}

	public function testDumpDiscoveryRequiresAClassConstantArgument(): void
	{
		$rule = $this->discoveryRule();
		$scope = $this->scopeForFile('/some/where/RegularPresenter.php');

		foreach ([
			[new String_(SamplePresenter::class)],
			[],
		] as $args) {
			$errors = $rule->processNode(
				$this->funcCall('OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery', $args),
				$scope,
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'dumpLatteDiscovery() expects a ::class constant argument',
				$errors[0]->getMessage(),
			);
		}
	}

	public function testDumpDiscoveryReportsWalkNotWiredInsteadOfCrashing(): void
	{
		$errors = $this->rule($this->isolatedDir('discovery-no-walk'))->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch(SamplePresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . SamplePresenter::class . "\nno discovery: PhpRenderWalk not wired",
			$errors[0]->getMessage(),
		);
	}

	// Qualifying facts CAN carry no discovery fact at all - facts written by hand or restored from
	// an envelope whose discovery key was null. The dump says so instead of rendering a dump made
	// entirely of empty sections, which would read as "discovery ran and found nothing".
	public function testDumpDiscoveryReportsFactsCarryingNoDiscoveryFact(): void
	{
		$cacheDir = $this->isolatedDir('discovery-factless');

		try {
			$cache = new PhpFactsCache(
				new LatteAnalysisCache($cacheDir),
				new TemplateFactoryDefaultResolver(null),
				new DiscoveryResolver(null, [], $cacheDir),
			);
			$cache->remember(
				DiscoveryVendorPresenter::class,
				static fn (): PhpRenderFacts => new PhpRenderFacts(
					[],
					[],
					new TemplateClassFact(
						UiTemplate::class,
						TemplateClassFact::CHANNEL_TEMPLATE_FLOOR,
						Certainty::HAPPENS,
						[],
					),
					[],
					[],
				),
			);

			$errors = $this->discoveryRule(self::MappingLoaderFile, $cache)->processNode(
				$this->funcCall(
					'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
					[$this->classConstFetch(DiscoveryVendorPresenter::class)],
				),
				$this->scopeForFile('/some/where/RegularPresenter.php'),
			);

			self::assertCount(1, $errors);
			self::assertSame(
				'class: ' . DiscoveryVendorPresenter::class . "\nno discovery: facts carry no discovery fact",
				$errors[0]->getMessage(),
			);
		} finally {
			if (is_dir($cacheDir)) {
				FileSystem::delete($cacheDir);
			}
		}
	}

	// The full-render pin: method-derived views with their deriving method named, the vendor
	// formula's two per-view candidates with the existing one chosen, and the layout walk.
	public function testDumpDiscoveryRendersViewsFormulaCandidatesAndTheLayoutWalk(): void
	{
		$file = self::fixtureFile(DiscoveryVendorPresenter::class);

		$errors = $this->discoveryRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch(DiscoveryVendorPresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . DiscoveryVendorPresenter::class
			. "\nopen view set: no"
			. "\nviews:"
			. "\ndefault (happens) @ {$file}:10 from actionDefault"
			. "\ndetail (happens) @ {$file}:14 from renderDetail"
			. "\nview candidates:"
			. "\ndefault:"
			. "\nApp/templates/DiscoveryVendor/default.latte (formula, exists, chosen)"
			. "\nApp/templates/DiscoveryVendor.default.latte (formula, missing)"
			. "\ndetail:"
			. "\nApp/templates/DiscoveryVendor/detail.latte (formula, missing)"
			. "\nApp/templates/DiscoveryVendor.detail.latte (formula, missing)"
			. "\nlayout candidates:"
			. "\nApp/templates/DiscoveryVendor/@layout.latte (layout, missing)"
			. "\nApp/templates/DiscoveryVendor.@layout.latte (layout, missing)"
			. "\nApp/templates/@layout.latte (layout, missing)"
			. "\ntemplates/@layout.latte (layout, missing)"
			. "\nopaques: (none)"
			. "\nineffective mutations: (none)",
			$errors[0]->getMessage(),
		);
	}

	// A template file sitting where the formula would put it IS a renderable view even with no
	// action/render method anywhere - the view has no fact site, so it shows up in the candidates
	// while the views section stays empty.
	public function testDumpDiscoveryRendersFileDerivedViewsWithAnEmptyViewsSection(): void
	{
		$errors = $this->discoveryRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch(DiscoveryMethodlessPresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . DiscoveryMethodlessPresenter::class
			. "\nopen view set: no"
			. "\nviews: (none)"
			. "\nview candidates:"
			. "\nadd:"
			. "\nApp/templates/DiscoveryMethodless/add.latte (formula, exists, chosen)"
			. "\nApp/templates/DiscoveryMethodless.add.latte (formula, missing)"
			. "\nlayout candidates:"
			. "\nApp/templates/DiscoveryMethodless/@layout.latte (layout, missing)"
			. "\nApp/templates/DiscoveryMethodless.@layout.latte (layout, missing)"
			. "\nApp/templates/@layout.latte (layout, missing)"
			. "\ntemplates/@layout.latte (layout, missing)"
			. "\nopaques: (none)"
			. "\nineffective mutations: (none)",
			$errors[0]->getMessage(),
		);
	}

	// A proven setFile wins its own view (its candidate is chosen even though the file does not
	// exist - the write happened, whatever comes after) and suppresses the formula for that view
	// only; the untouched view keeps both formula candidates.
	public function testDumpDiscoveryRendersASetFileWinnerBesideAnUnsuppressedFormulaView(): void
	{
		$file = self::fixtureFile(DiscoverySetFileActionPresenter::class);

		$errors = $this->discoveryRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch(DiscoverySetFileActionPresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . DiscoverySetFileActionPresenter::class
			. "\nopen view set: no"
			. "\nviews:"
			. "\nbar (happens) @ {$file}:15 from actionBar"
			. "\nfoo (happens) @ {$file}:10 from actionFoo"
			. "\nview candidates:"
			. "\nbar:"
			. "\nApp/templates/DiscoverySetFileAction/bar.latte (formula, missing)"
			. "\nApp/templates/DiscoverySetFileAction.bar.latte (formula, missing)"
			. "\nfoo:"
			. "\nApp/custom-foo.latte (setFile, missing, chosen)"
			. "\nlayout candidates:"
			. "\nApp/templates/DiscoverySetFileAction/@layout.latte (layout, missing)"
			. "\nApp/templates/DiscoverySetFileAction.@layout.latte (layout, missing)"
			. "\nApp/templates/@layout.latte (layout, missing)"
			. "\ntemplates/@layout.latte (layout, missing)"
			. "\nopaques: (none)"
			. "\nineffective mutations: (none)",
			$errors[0]->getMessage(),
		);
	}

	// A non-derivable setFile argument: an opaque entry carrying its own site line, and a view
	// whose candidate list is empty (the write suppressed the formula, its own path is unknown).
	public function testDumpDiscoveryRendersASiteLineOpaqueAndAnEmptyCandidateList(): void
	{
		$file = self::fixtureFile(DiscoverySetFileOpaquePresenter::class);

		$errors = $this->discoveryRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch(DiscoverySetFileOpaquePresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . DiscoverySetFileOpaquePresenter::class
			. "\nopen view set: no"
			. "\nviews:"
			. "\ndefault (happens) @ {$file}:10 from actionDefault"
			. "\nview candidates:"
			. "\ndefault: (none)"
			. "\nlayout candidates:"
			. "\nApp/templates/DiscoverySetFileOpaque/@layout.latte (layout, missing)"
			. "\nApp/templates/DiscoverySetFileOpaque.@layout.latte (layout, missing)"
			. "\nApp/templates/@layout.latte (layout, missing)"
			. "\ntemplates/@layout.latte (layout, missing)"
			. "\nopaques:"
			. "\nsetFile argument is not statically resolvable @ 13"
			. "\nineffective mutations: (none)",
			$errors[0]->getMessage(),
		);
	}

	// A control has no view axis at all: its convention candidate lands in the viewless bucket, and
	// the layout channel (a presenter-only vendor mechanism) stays empty.
	public function testDumpDiscoveryRendersTheViewlessBucketForAControlConvention(): void
	{
		$errors = $this->discoveryRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch(DiscoveryTemplatesPathControl::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . DiscoveryTemplatesPathControl::class
			. "\nopen view set: no"
			. "\nviews: (none)"
			. "\nview candidates:"
			. "\n(no view):"
			. "\nApp/templates/discoveryTemplatesPathControl.latte (convention, missing, chosen)"
			. "\nlayout candidates: (none)"
			. "\nopaques: (none)"
			. "\nineffective mutations: (none)",
			$errors[0]->getMessage(),
		);
	}

	// A dynamic setView argument opens the view set - the marker every per-view check reads as
	// "the real view set is unknown, not this one" (it is not itself diagnosed anywhere else).
	public function testDumpDiscoveryMarksAnOpenViewSet(): void
	{
		$file = self::fixtureFile(DynamicViewPresenter::class);

		$errors = $this->discoveryRule()->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch(DynamicViewPresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . DynamicViewPresenter::class
			. "\nopen view set: yes"
			. "\nviews:"
			. "\ndefault (happens) @ {$file}:10 from actionDefault"
			. "\nview candidates:"
			. "\ndefault:"
			. "\nApp/templates/DynamicView/default.latte (formula, missing)"
			. "\nApp/templates/DynamicView.default.latte (formula, missing)"
			. "\nlayout candidates:"
			. "\nApp/templates/DynamicView/@layout.latte (layout, missing)"
			. "\nApp/templates/DynamicView.@layout.latte (layout, missing)"
			. "\nApp/templates/@layout.latte (layout, missing)"
			. "\ntemplates/@layout.latte (layout, missing)"
			. "\nopaques: (none)"
			. "\nineffective mutations: (none)",
			$errors[0]->getMessage(),
		);
	}

	// Mutation-derived views name the writing call, not a dispatch method; with no presenter
	// mapping wired the name never reverse-maps, which is the site-less opaque entry.
	public function testDumpDiscoveryRendersMutationDerivedViewsAndASiteLessOpaque(): void
	{
		$file = self::fixtureFile(OverwriteOrderViewPresenter::class);

		$errors = $this->discoveryRule(null)->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch(OverwriteOrderViewPresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . OverwriteOrderViewPresenter::class
			. "\nopen view set: no"
			. "\nviews:"
			. "\ndefault (happens) @ {$file}:10 from actionDefault"
			. "\nfinal (happens) @ {$file}:19 from setView:19"
			. "\nswitched (happens) @ {$file}:13 from changeAction:13"
			. "\nview candidates:"
			. "\ndefault: (none)"
			. "\nfinal: (none)"
			. "\nswitched: (none)"
			. "\nlayout candidates: (none)"
			. "\nopaques:"
			. "\npresenter name unresolved: no mapping reverse-maps class " . OverwriteOrderViewPresenter::class
			. "\nineffective mutations: (none)",
			$errors[0]->getMessage(),
		);
	}

	// The view axis across every effective lifecycle phase, including the one whose write is
	// conditional - the only channel that renders a view as `maybe` rather than `happens`.
	public function testDumpDiscoveryRendersPerViewCertaintyIncludingAConditionalWrite(): void
	{
		$file = self::fixtureFile(LifecycleViewsPresenter::class);

		$errors = $this->discoveryRule(null)->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch(LifecycleViewsPresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . LifecycleViewsPresenter::class
			. "\nopen view set: no"
			. "\nviews:"
			. "\nafterR (happens) @ {$file}:44 from setView:44"
			. "\naltBefore (happens) @ {$file}:39 from setView:39"
			. "\nconditional (maybe) @ {$file}:29 from setView:29"
			. "\ndefault (happens) @ {$file}:22 from actionDefault"
			. "\ndetail (happens) @ {$file}:33 from renderDetail"
			. "\nfromCheck (happens) @ {$file}:19 from setView:19"
			. "\nfromStartup (happens) @ {$file}:14 from setView:14"
			. "\nother (happens) @ {$file}:26 from actionOther"
			. "\nview candidates:"
			. "\nafterR: (none)"
			. "\naltBefore: (none)"
			. "\nconditional: (none)"
			. "\ndefault: (none)"
			. "\ndetail: (none)"
			. "\nfromCheck: (none)"
			. "\nfromStartup: (none)"
			. "\nother: (none)"
			. "\nlayout candidates: (none)"
			. "\nopaques:"
			. "\npresenter name unresolved: no mapping reverse-maps class " . LifecycleViewsPresenter::class
			. "\nineffective mutations: (none)",
			$errors[0]->getMessage(),
		);
	}

	// The provably-too-late writes: recorded as mutations, excluded from the view set (hence the
	// empty views section), and listed with the lifecycle phase that proves them ineffective.
	public function testDumpDiscoveryListsIneffectiveMutationsWithTheirPhase(): void
	{
		$errors = $this->discoveryRule(null)->processNode(
			$this->funcCall(
				'OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery',
				[$this->classConstFetch(ShutdownOnlyViewPresenter::class)],
			),
			$this->scopeForFile('/some/where/RegularPresenter.php'),
		);

		self::assertCount(1, $errors);
		self::assertSame(
			'class: ' . ShutdownOnlyViewPresenter::class
			. "\nopen view set: no"
			. "\nviews: (none)"
			. "\nview candidates: (none)"
			. "\nlayout candidates: (none)"
			. "\nopaques:"
			. "\npresenter name unresolved: no mapping reverse-maps class " . ShutdownOnlyViewPresenter::class
			. "\nineffective mutations:"
			. "\nsetView (outside) @ 13"
			. "\nchangeAction (outside) @ 19",
			$errors[0]->getMessage(),
		);
	}

	// The one deterministic way to make a class reflection resolves (runtime fallback) yet carries
	// no file: phpstorm-stubs give every built-in a stub path, so no real class name works.
	private function defineFilelessProbeClass(): void
	{
		if (!class_exists('LatteBridgeFilelessProbe', false)) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval('class LatteBridgeFilelessProbe {}');
		}
	}

	private function templateTypeCustoms(): TemplateTypeCustoms
	{
		/** @var Parser $parser */
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionSimpleParser');

		return new TemplateTypeCustoms(new PhpVersion(70400), $parser);
	}

	private function rule(string $dir): LatteDebugDumpRule
	{
		$universe = new LatteUniverse([$dir], $dir);
		$edgeIndex = new TemplateEdgeIndex($universe, new TemplateFactExtractor());
		$resolver = new ContextResolver(
			$edgeIndex,
			new DeclarationScanner(),
			$universe,
			new CapturedOverlay(new SiteScopeStore($dir . '/__absent_store__'), true),
		);

		return new LatteDebugDumpRule(TestGuard::latte(), $resolver, $edgeIndex, $universe, new DeclarationScanner());
	}

	private function ruleWithRenderWalk(string $dir): LatteDebugDumpRule
	{
		$universe = new LatteUniverse([$dir], $dir);
		$edgeIndex = new TemplateEdgeIndex($universe, new TemplateFactExtractor());
		$resolver = new ContextResolver(
			$edgeIndex,
			new DeclarationScanner(),
			$universe,
			new CapturedOverlay(new SiteScopeStore($dir . '/__absent_store__'), true),
		);

		return new LatteDebugDumpRule(
			TestGuard::latte(),
			$resolver,
			$edgeIndex,
			$universe,
			new DeclarationScanner(),
			null,
			null,
			$this->renderWalk(),
			null,
			null,
			PHPStanTestCase::createReflectionProvider(),
		);
	}

	private function pairingRule(?PhpFactsCache $renderFactsCache = null): LatteDebugDumpRule
	{
		$dir = $this->isolatedDir('pairing');
		$universe = new LatteUniverse([$dir], $dir);
		$edgeIndex = new TemplateEdgeIndex($universe, new TemplateFactExtractor());
		$resolver = new ContextResolver(
			$edgeIndex,
			new DeclarationScanner(),
			$universe,
			new CapturedOverlay(new SiteScopeStore($dir . '/__absent_store__'), true),
		);
		$reflectionProvider = PHPStanTestCase::createReflectionProvider();
		$appRoot = realpath(__DIR__ . '/../Bridge/Pairing/Fixtures/App');
		self::assertNotFalse($appRoot);

		return new LatteDebugDumpRule(
			TestGuard::latte(),
			$resolver,
			$edgeIndex,
			$universe,
			new DeclarationScanner(),
			null,
			null,
			$this->renderWalk($appRoot),
			$renderFactsCache,
			new PairingJudge($reflectionProvider),
			$reflectionProvider,
		);
	}

	private function discoveryRule(
		?string $mappingLoaderFile = self::MappingLoaderFile,
		?PhpFactsCache $renderFactsCache = null
	): LatteDebugDumpRule
	{
		$dir = $this->isolatedDir('discovery');
		$universe = new LatteUniverse([$dir], $dir);
		$edgeIndex = new TemplateEdgeIndex($universe, new TemplateFactExtractor());
		$resolver = new ContextResolver(
			$edgeIndex,
			new DeclarationScanner(),
			$universe,
			new CapturedOverlay(new SiteScopeStore($dir . '/__absent_store__'), true),
		);
		$appRoot = realpath(__DIR__ . '/../Bridge/Fixtures/App');
		self::assertNotFalse($appRoot);
		$fixturesRoot = realpath(__DIR__ . '/../Bridge/Fixtures');
		self::assertNotFalse($fixturesRoot);
		$reflectionProvider = PHPStanTestCase::createReflectionProvider();

		/** @var Parser $parser */
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionRichParser');

		return new LatteDebugDumpRule(
			TestGuard::latte(),
			$resolver,
			$edgeIndex,
			$universe,
			new DeclarationScanner(),
			null,
			null,
			new PhpRenderWalk(
				$reflectionProvider,
				$parser,
				[$appRoot],
				new TemplateFactoryDefaultResolver(null),
				new DiscoveryResolver($mappingLoaderFile, self::DiscoveryFormulas, $fixturesRoot),
			),
			$renderFactsCache,
			null,
			$reflectionProvider,
		);
	}

	/**
	 * @param class-string $className
	 */
	private static function fixtureFile(string $className): string
	{
		$file = (new ReflectionClass($className))->getFileName();
		self::assertIsString($file);

		return $file;
	}

	private function renderWalk(?string $appRoot = null): PhpRenderWalk
	{
		if ($appRoot === null) {
			$appRoot = realpath(__DIR__ . '/../Bridge/Fixtures/App');
			self::assertNotFalse($appRoot);
		}

		/** @var Parser $parser */
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionRichParser');

		return new PhpRenderWalk(
			PHPStanTestCase::createReflectionProvider(),
			$parser,
			[$appRoot],
			new TemplateFactoryDefaultResolver(null),
			new DiscoveryResolver(null, [], $appRoot),
		);
	}

	private function classConstFetch(string $className): ClassConstFetch
	{
		return new ClassConstFetch(new FullyQualified($className), new Identifier('class'));
	}

	/**
	 * @param list<Expr> $argExprs
	 */
	private function funcCall(string $functionName, array $argExprs = []): FuncCall
	{
		$args = [];
		foreach ($argExprs as $expr) {
			$args[] = new Arg($expr);
		}

		return new FuncCall(new Name($functionName), $args, ['startLine' => 1]);
	}

	// Rule::processNode()'s own interface docblock overrides $scope's type to the full
	// Scope&NodeCallbackInvoker&CollectedDataEmitter intersection (see LatteSiteScopeWriterRuleTest's
	// identical @return+@var pair) - a bare Scope stub satisfies every method this rule actually
	// calls, so the extra interfaces are never exercised at runtime.

	/**
	 * @return Scope&CollectedDataEmitter&NodeCallbackInvoker
	 */
	private function scopeForFile(string $file): Scope
	{
		/** @var Scope&CollectedDataEmitter&NodeCallbackInvoker&Stub $scope */
		$scope = $this->createStub(Scope::class);
		$scope->method('getFile')->willReturn($file);

		return $scope;
	}

	/**
	 * @return Scope&CollectedDataEmitter&NodeCallbackInvoker
	 */
	private function scopeForVarOrigin(string $file, string $functionName, string $typeDescription): Scope
	{
		/** @var Scope&CollectedDataEmitter&NodeCallbackInvoker&Stub $scope */
		$scope = $this->createStub(Scope::class);
		$scope->method('getFile')->willReturn($file);
		$scope->method('getFunctionName')->willReturn($functionName);
		$scope->method('getType')->willReturn($this->describedType($typeDescription));

		return $scope;
	}

	private function describedType(string $description): Type
	{
		$type = $this->createStub(Type::class);
		$type->method('describe')->willReturn($description);

		return $type;
	}

	private function isolatedDir(string $prefix): string
	{
		return sys_get_temp_dir() . '/latte-debug-dump-rule-' . $prefix . '-' . getmypid() . '-' . uniqid('', true);
	}

}
