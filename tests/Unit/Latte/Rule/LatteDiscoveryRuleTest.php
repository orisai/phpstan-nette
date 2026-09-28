<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Rule\LatteDiscoveryRule;
use PHPStan\Parser\Parser;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryUnassignedPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryUnmappedAbstractRenderer;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryUnmappedEndpoint;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\FixturePresenterTemplateLocator;
use function getmypid;
use function is_dir;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @extends RuleTestCase<LatteDiscoveryRule>
 */
final class LatteDiscoveryRuleTest extends RuleTestCase
{

	private const FixtureDir = __DIR__ . '/../Bridge/Fixtures/App';

	private const MappingLoaderFile = __DIR__ . '/../Bridge/Fixtures/presenter-mapping-container-loader.php';

	private const AssignedFormulas = [
		FixturePresenterTemplateLocator::class => 'samedir-single',
	];

	private ?string $cacheDir = null;

	protected function tearDown(): void
	{
		parent::tearDown();

		if ($this->cacheDir !== null && is_dir($this->cacheDir)) {
			FileSystem::delete($this->cacheDir);
		}

		$this->cacheDir = null;
	}

	protected function getRule(): Rule
	{
		$appRoot = realpath(self::FixtureDir);
		self::assertNotFalse($appRoot);
		$projectRoot = realpath(__DIR__ . '/../Bridge/Fixtures');
		self::assertNotFalse($projectRoot);

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$factoryDefault = new TemplateFactoryDefaultResolver(null);
		$discoveryResolver = new DiscoveryResolver(self::MappingLoaderFile, self::AssignedFormulas, $projectRoot);

		return new LatteDiscoveryRule(
			TestGuard::latte(true, false, true),
			new PhpRenderWalk(
				self::createReflectionProvider(),
				$parser,
				[$appRoot],
				$factoryDefault,
				$discoveryResolver,
			),
			new PhpFactsCache(new LatteAnalysisCache($this->isolatedCacheDir()), $factoryDefault, $discoveryResolver),
		);
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

	public function testDynamicSetFileArgumentIsReportedAtTheCallSite(): void
	{
		$this->analyse(
			[self::FixtureDir . '/DiscoverySetFileOpaquePresenter.php'],
			[
				[
					'Template file discovery is opaque: setFile argument is not statically resolvable.',
					13,
				],
			],
		);
	}

	// Also the counter-pin of the abstract-silence rule below: this base IS abstract, and its own
	// dynamic setFile site is a renderable contribution, so its hole stays reported.
	public function testOwnFileSetFileSiteOfAnAbstractControlBaseKeepsItsCallLine(): void
	{
		$this->analyse(
			[self::FixtureDir . '/DiscoveryInheritedOpaqueBase.php'],
			[
				[
					'Template file discovery is opaque: setFile argument is not statically resolvable.',
					13,
				],
			],
		);
	}

	// The walk follows calls into ancestors, so the descendant's own facts carry the BASE's site
	// line - a line number that means nothing in the descendant's file. It anchors at the class
	// declaration instead; the base keeps its own call line (test above).
	public function testInheritedSetFileSiteAnchorsAtTheDescendantClassDeclaration(): void
	{
		$this->analyse(
			[self::FixtureDir . '/DiscoveryInheritedOpaqueControl.php'],
			[
				[
					'Template file discovery is opaque: setFile argument is not statically resolvable.',
					5,
				],
			],
		);
	}

	public function testUnassignedLocatorOverrideIsReportedAtTheClassDeclaration(): void
	{
		$this->analyse(
			[self::FixtureDir . '/DiscoveryUnassignedPresenter.php'],
			[
				[
					'Template file discovery is opaque: formatTemplateFiles override declared by '
					. DiscoveryUnassignedPresenter::class . ' has no assigned discovery formula.',
					7,
				],
			],
		);
	}

	// The whole view-set is opaque when the presenter name does not reverse-map - the real corpus's
	// second opaque family. Anchored at the class declaration: the hole has no call site.
	public function testUnresolvedPresenterNameIsReportedAtTheClassDeclaration(): void
	{
		$this->analyse(
			[self::FixtureDir . '/DiscoveryUnmappedEndpoint.php'],
			[
				[
					'Template file discovery is opaque: presenter name unresolved: no mapping reverse-maps class '
					. DiscoveryUnmappedEndpoint::class . '.',
					9,
				],
			],
		);
	}

	// An abstract class is never the class that runs, so an unresolved hole in ITS discovery is
	// worth reporting only when the class contributes a renderable site of its own. Three pins, not
	// a blanket isAbstract() skip.
	public function testAbstractClassWithNothingRenderableOfItsOwnIsSilent(): void
	{
		$this->analyse([self::FixtureDir . '/DiscoveryUnmappedAbstractEndpoint.php'], []);
	}

	public function testAbstractClassDeclaringItsOwnRenderHookStillReportsItsHole(): void
	{
		$this->analyse(
			[self::FixtureDir . '/DiscoveryUnmappedAbstractRenderer.php'],
			[
				[
					'Template file discovery is opaque: presenter name unresolved: no mapping reverse-maps class '
					. DiscoveryUnmappedAbstractRenderer::class . '.',
					9,
				],
			],
		);
	}

	public function testMutationsReachableOnlyFromShutdownAreReportedAtTheirCallLines(): void
	{
		$this->analyse(
			[self::FixtureDir . '/ShutdownOnlyViewPresenter.php'],
			[
				['Call to setView() has no effect at this point of the presenter lifecycle.', 13],
				['Call to changeAction() has no effect at this point of the presenter lifecycle.', 19],
			],
		);
	}

	public function testOpaqueErrorsCarryTheIgnorableFileDiscoveryOpaqueIdentifier(): void
	{
		$errors = $this->gatherAnalyserErrors([self::FixtureDir . '/DiscoverySetFileOpaquePresenter.php']);

		self::assertCount(1, $errors);
		self::assertSame(LatteDiscoveryRule::OPAQUE_IDENTIFIER, $errors[0]->getIdentifier());
		self::assertTrue($errors[0]->canBeIgnored(), 'discovery opaques must stay baselineable');
	}

	public function testIneffectiveMutationErrorsCarryTheIgnorableMutationIdentifier(): void
	{
		$errors = $this->gatherAnalyserErrors([self::FixtureDir . '/ShutdownOnlyViewPresenter.php']);

		self::assertCount(2, $errors);
		self::assertSame(LatteDiscoveryRule::MUTATION_IDENTIFIER, $errors[0]->getIdentifier());
		self::assertTrue($errors[0]->canBeIgnored(), 'ineffective mutations must stay baselineable');
	}

	// setFile has no too-late window in a full-render flow (Task-2 probe), so even a shutdown-phase
	// setFile stays EFFECTIVE_YES - the uniform model yields no diagnostic here.
	public function testEffectiveMutationsIncludingAShutdownSetFileAreSilent(): void
	{
		$this->analyse([self::FixtureDir . '/SetFilePhasePresenter.php'], []);
	}

	// EFFECTIVE_MAYBE is the conservative state (a helper reachable from both an effective and an
	// ineffective phase) - never a diagnostic.
	public function testMaybeEffectiveMutationsProduceNoDiagnostic(): void
	{
		$this->analyse([self::FixtureDir . '/TwoPhaseHelperViewPresenter.php'], []);
	}

	public function testResolvedVendorFormulaDiscoveryIsSilent(): void
	{
		$this->analyse([self::FixtureDir . '/DiscoveryVendorPresenter.php'], []);
	}

	public function testAssignedAppLocatorFormulaIsSilent(): void
	{
		$this->analyse([self::FixtureDir . '/DiscoveryLocatorPresenter.php'], []);
	}

	public function testNonQualifyingClassProducesNothing(): void
	{
		$this->analyse([self::FixtureDir . '/UnrelatedServiceFixture.php'], []);
	}

	private function isolatedCacheDir(): string
	{
		return $this->cacheDir ??= sys_get_temp_dir() . '/latte-discovery-rule-' . getmypid() . '-' . uniqid('', true);
	}

}
