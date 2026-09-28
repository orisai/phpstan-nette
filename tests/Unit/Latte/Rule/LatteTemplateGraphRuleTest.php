<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Includes\TemplateTypeChecker;
use OriPhpstan\Nette\Latte\Rule\LatteAnalyzedFileMarkerCollector;
use OriPhpstan\Nette\Latte\Rule\LatteTemplateGraphRule;
use OriPhpstan\Nette\Latte\Rule\LatteTerminatingRenderCollector;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Parser\Parser;
use PHPStan\Rules\FileRuleError;
use PHPStan\Rules\FixableNodeRuleError;
use PHPStan\Rules\LineRuleError;
use PHPStan\Rules\TipRuleError;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\MockObject\Stub;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryVendorPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingAgreeExactPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\FixtureTemplateTypeContainer;
use function getmypid;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

// The aggregation seam itself: the reportable set comes from collected data, and each finding is
// attributed back to an individual .latte path with ->file() (shipmonk's DeadCodeRule precedent) -
// the property the 186 committed baseline entries depend on.
final class LatteTemplateGraphRuleTest extends PHPStanTestCase
{

	private const PairingFixtureDir = __DIR__ . '/../Bridge/Pairing/Fixtures/App';

	private const DiscoveryFixtureRoot = __DIR__ . '/../Bridge/Fixtures';

	private const MappingLoaderFile = __DIR__ . '/../Bridge/Fixtures/presenter-mapping-container-loader.php';

	public function testOrphanIsAttributedToTheTemplateFileItself(): void
	{
		$dir = $this->corpus();

		try {
			$errors = $this->rule($dir, true, true)->processNode(
				$this->collectedDataNode(['app/page.latte', 'app/dead.latte', 'app/partial.latte']),
				$this->scope(),
			);

			self::assertCount(2, $errors);

			$dead = $errors[0];
			self::assertSame(TemplateTypeChecker::ORPHAN_IDENTIFIER, $dead->getIdentifier());
			self::assertSame(
				'No analysable render, include or layout path reaches this template file.',
				$dead->getMessage(),
			);
			self::assertInstanceOf(FileRuleError::class, $dead);
			self::assertSame($dir . '/app/dead.latte', $dead->getFile());
			self::assertInstanceOf(LineRuleError::class, $dead);
			self::assertSame(1, $dead->getLine());

			$partial = $errors[1];
			self::assertInstanceOf(FileRuleError::class, $partial);
			self::assertSame($dir . '/app/partial.latte', $partial->getFile());
			self::assertInstanceOf(TipRuleError::class, $partial);
			self::assertSame(
				'Included only from templates that are themselves unreachable: app/dead.latte.',
				$partial->getTip(),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// NO FIXER, EVER (see TemplateTypeChecker's own constraint note): this check under-detects
	// usage, so an auto-fix would delete files that are genuinely rendered.
	public function testReportedErrorsCarryNoFixPayload(): void
	{
		$dir = $this->corpus();

		try {
			$errors = $this->rule($dir, true, true)->processNode(
				$this->collectedDataNode(['app/page.latte', 'app/dead.latte', 'app/partial.latte']),
				$this->scope(),
			);

			self::assertNotSame([], $errors);
			foreach ($errors as $error) {
				self::assertNotInstanceOf(FixableNodeRuleError::class, $error);
			}
		} finally {
			FileSystem::delete($dir);
		}
	}

	// A template nobody asked PHPStan to analyse gains no finding, whatever the graph says about it.
	public function testUncollectedTemplatesAreNeverReported(): void
	{
		$dir = $this->corpus();

		try {
			self::assertSame(
				[],
				$this->rule($dir, true, true)->processNode($this->collectedDataNode([]), $this->scope()),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The collector -> checker link, which is the only place the terminating-render keys travel: the
	// rule harvests them from collected data and hands them to checkAggregate, where they suppress
	// orisaiNette.latte.templateMissing. Same corpus and same store on both runs - one collected key of
	// difference. The suppression direction is the only one allowed: these keys must never reach the
	// orphan half, where a terminating renderer would ORPHAN its template instead.
	public function testTerminatingRenderKeysFromCollectedDataSuppressTheMissingFinding(): void
	{
		$dir = $this->discoveryCorpus();

		try {
			$rule = $this->discoveryRule($dir);

			$reported = $rule->processNode($this->collectedDataNode(['page.latte']), $this->scope());
			self::assertCount(1, $reported);
			self::assertSame(TemplateTypeChecker::MISSING_IDENTIFIER, $reported[0]->getIdentifier());

			self::assertSame(
				[],
				$rule->processNode(
					$this->collectedDataNode(
						['page.latte'],
						[DiscoveryVendorPresenter::class . '::renderDetail'],
					),
					$this->scope(),
				),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// With no store at all the liveness fixpoint has no roots, and every analysed template would be
	// reported as an orphan - a dead verdict on demonstrably live files, which is the first thing a
	// consumer whose very first run has not derived the index yet would hit.
	public function testAbsentStoreDirectoryReportsNothingRatherThanEveryTemplate(): void
	{
		$dir = $this->corpus();

		try {
			$storeDir = $dir . '/store';
			self::assertSame(
				[],
				$this->ruleWithStore($dir, new DiscoveryStore($storeDir), true, true)->processNode(
					$this->collectedDataNode(['app/page.latte', 'app/dead.latte', 'app/partial.latte']),
					$this->scope(),
				),
			);
			self::assertDirectoryDoesNotExist($storeDir);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The same precondition stated on the LINK SET rather than on the directory: a store whose
	// per-template files exist but carry no records yet is exactly the state this rule observes on a
	// corpus with no renderers at all, and it must cost one run of silence, never a corpus-wide
	// false-orphan storm.
	public function testBootstrappedButUnwrittenStoreReportsNothingRatherThanEveryTemplate(): void
	{
		$dir = $this->corpus();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, ['app/page.latte', 'app/dead.latte', 'app/partial.latte']);

			self::assertSame(
				[],
				$this->ruleWithStore($dir, new DiscoveryStore($storeDir), true, true)->processNode(
					$this->collectedDataNode(['app/page.latte', 'app/dead.latte', 'app/partial.latte']),
					$this->scope(),
				),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @return array<string, array{bool, bool}>
	 */
	public static function dormancyProvider(): array
	{
		return [
			'analysis off' => [false, true],
			'discovery store off' => [true, false],
		];
	}

	/**
	 * @dataProvider dormancyProvider
	 */
	public function testFlagOffIsFullyDormant(bool $enabled, bool $discoveryStoreEnabled): void
	{
		$dir = $this->corpus();

		try {
			self::assertSame(
				[],
				$this->rule($dir, $enabled, $discoveryStoreEnabled)->processNode(
					$this->collectedDataNode(['app/page.latte', 'app/dead.latte', 'app/partial.latte']),
					$this->scope(),
				),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $markerRels
	 * @param list<string> $terminatingRenderMethods
	 */
	private function collectedDataNode(array $markerRels, array $terminatingRenderMethods = []): CollectedDataNode
	{
		$data = [];
		if ($markerRels !== []) {
			$data['/project/any.latte'] = [LatteAnalyzedFileMarkerCollector::class => $markerRels];
		}

		if ($terminatingRenderMethods !== []) {
			$data['/project/any.php'] = [
				LatteTerminatingRenderCollector::class => $terminatingRenderMethods,
			];
		}

		return new CollectedDataNode($data, false);
	}

	private function rule(string $dir, bool $enabled, bool $discoveryStoreEnabled): LatteTemplateGraphRule
	{
		$store = new DiscoveryStore($dir . '/store');
		$store->replaceWith(
			[
				'app/page.latte' => [
					[
						'class' => PairingAgreeExactPresenter::class,
						'view' => 'default',
						'kind' => CandidatePath::KIND_FORMULA,
						'certainty' => Certainty::HAPPENS,
					],
				],
			],
			[],
			[],
		);

		return $this->ruleWithStore($dir, $store, $enabled, $discoveryStoreEnabled);
	}

	private function ruleWithStore(
		string $dir,
		DiscoveryStore $store,
		bool $enabled,
		bool $discoveryStoreEnabled
	): LatteTemplateGraphRule
	{
		$reflectionProvider = self::createReflectionProvider();
		$universe = new LatteUniverse([$dir], $dir);

		$checker = new TemplateTypeChecker(
			new FixtureTemplateTypeContainer(
				$this->recordSource($dir),
				new PairingJudge($reflectionProvider),
				$reflectionProvider,
			),
			$store,
			new TemplateEdgeIndex($universe, new TemplateFactExtractor(), null, $store, $discoveryStoreEnabled),
			$universe,
			[$dir . '/app'],
			$enabled,
			$discoveryStoreEnabled,
			false,
		);

		return new LatteTemplateGraphRule(
			TestGuard::latte($enabled, false, $enabled && $discoveryStoreEnabled),
			$checker,
		);
	}

	private function discoveryRule(string $dir): LatteTemplateGraphRule
	{
		$projectRoot = realpath(self::DiscoveryFixtureRoot);
		self::assertNotFalse($projectRoot);

		$store = new DiscoveryStore($dir . '/store');
		$store->replaceWith(
			[
				'page.latte' => [
					[
						'class' => DiscoveryVendorPresenter::class,
						'view' => 'default',
						'kind' => CandidatePath::KIND_FORMULA,
						'certainty' => Certainty::HAPPENS,
					],
				],
			],
			[],
			[],
		);

		$reflectionProvider = self::createReflectionProvider();
		$universe = new LatteUniverse([$dir], $dir);

		$checker = new TemplateTypeChecker(
			new FixtureTemplateTypeContainer(
				$this->recordSource(
					$dir,
					self::DiscoveryFixtureRoot . '/App',
					$projectRoot,
					self::MappingLoaderFile,
				),
				new PairingJudge($reflectionProvider),
				$reflectionProvider,
			),
			$store,
			new TemplateEdgeIndex($universe, new TemplateFactExtractor(), null, $store, true),
			$universe,
			// No template lives under this path, so the orphan half contributes nothing here.
			[$dir . '/__no_app_scope__'],
			true,
			true,
			false,
		);

		return new LatteTemplateGraphRule(TestGuard::latte(true, false, true), $checker);
	}

	private function recordSource(
		string $dir,
		?string $appRootDir = null,
		?string $projectRoot = null,
		?string $mappingLoaderFile = null
	): DiscoveryRecordSource
	{
		$appRoot = realpath($appRootDir ?? self::PairingFixtureDir);
		self::assertNotFalse($appRoot);

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$templateFactoryDefault = new TemplateFactoryDefaultResolver(null);
		$discoveryResolver = new DiscoveryResolver($mappingLoaderFile, [], $projectRoot ?? $appRoot);

		return new DiscoveryRecordSource(
			new PhpFactsCache(new LatteAnalysisCache($dir . '/cache'), $templateFactoryDefault, $discoveryResolver),
			new PhpRenderWalk(
				self::createReflectionProvider(),
				$parser,
				[$appRoot],
				$templateFactoryDefault,
				$discoveryResolver,
			),
		);
	}

	private function discoveryCorpus(): string
	{
		$dir = sys_get_temp_dir() . '/latte-graphrule-missing-test-' . getmypid() . '-' . uniqid('', true);
		FileSystem::write($dir . '/page.latte', "body\n");

		return $dir;
	}

	private function corpus(): string
	{
		$dir = sys_get_temp_dir() . '/latte-graphrule-test-' . getmypid() . '-' . uniqid('', true);
		FileSystem::write($dir . '/app/page.latte', "body\n");
		FileSystem::write($dir . '/app/dead.latte', "{include 'partial.latte'}\n");
		FileSystem::write($dir . '/app/partial.latte', "body\n");

		return $dir;
	}

	/**
	 * @return Scope&CollectedDataEmitter&NodeCallbackInvoker
	 */
	private function scope(): Scope
	{
		/** @var Scope&CollectedDataEmitter&NodeCallbackInvoker&Stub $scope */
		$scope = $this->createStub(Scope::class);

		return $scope;
	}

}
