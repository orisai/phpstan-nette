<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

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
use OriPhpstan\Nette\Latte\Rule\LattePairingRule;
use PHPStan\Parser\Parser;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingBaseTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingChildTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingUnrelatedTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingVanishedCachePresenter;
use function getmypid;
use function is_dir;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @extends RuleTestCase<LattePairingRule>
 */
final class LattePairingRuleTest extends RuleTestCase
{

	use VersionGroupGate;

	private const FixtureDir = __DIR__ . '/../Bridge/Pairing/Fixtures/App';

	// Deliberately a string literal: the point is a class name reflection cannot resolve, the
	// shape cache-loaded facts produce after a template class is deleted or renamed.
	private const VanishedTemplateClass = 'Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Pairing\\Fixtures\\App\\PairingVanishedTemplateReplica';

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

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');
		$reflectionProvider = self::createReflectionProvider();
		$factoryDefault = new TemplateFactoryDefaultResolver(null);
		$discoveryResolver = new DiscoveryResolver(null, [], $appRoot);

		$cache = new PhpFactsCache(
			new LatteAnalysisCache($this->isolatedCacheDir()),
			$factoryDefault,
			$discoveryResolver,
			[],
		);

		// Pre-warmed cache entry naming a class reflection cannot resolve - the state cache-loaded
		// facts reach after a template class is deleted while the presenter's own read-set files
		// are unchanged. Empty sites pin the NO_SITE_LINE fallback to the class declaration line.
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

		return new LattePairingRule(
			TestGuard::latte(),
			new PhpRenderWalk($reflectionProvider, $parser, [$appRoot], $factoryDefault, $discoveryResolver),
			$cache,
			new PairingJudge($reflectionProvider),
		);
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

	public function testNarrowerDeclarationConflictIsReportedOnceNamingBothDeclarationChannels(): void
	{
		$this->analyse(
			[self::FixtureDir . '/PairingNarrowerDeclarationPresenter.php'],
			[
				[
					'Template class pairing conflict: ' . PairingChildTemplateReplica::class
					. ' (phpdoc, genericBinding) vs ' . PairingBaseTemplateReplica::class . ' (new).',
					13,
				],
			],
		);
	}

	public function testNoAncestryConflictIsReportedOnceNamingBothDeclarationChannels(): void
	{
		$this->analyse(
			[self::FixtureDir . '/PairingNoAncestryPresenter.php'],
			[
				[
					'Template class pairing conflict: ' . PairingChildTemplateReplica::class
					. ' (phpdoc, genericBinding) vs ' . PairingUnrelatedTemplateReplica::class . ' (new).',
					13,
				],
			],
		);
	}

	public function testIntraChannelDriftIsReportedAtTheFirstConflictSite(): void
	{
		$this->analyse(
			[self::FixtureDir . '/PairingIntraChannelDriftPresenter.php'],
			[
				[
					'Template class pairing conflict: ' . PairingBaseTemplateReplica::class
					. ' (new) vs ' . PairingUnrelatedTemplateReplica::class . ' (new).',
					16,
				],
			],
		);
	}

	public function testConventionDriftConflictIsReportedOnceNamingBothDeclarationChannels(): void
	{
		$this->analyse(
			[self::FixtureDir . '/PairingConventionDriftControl.php'],
			[
				[
					'Template class pairing conflict: ' . PairingChildTemplateReplica::class
					. ' (phpdoc, genericBinding) vs ' . PairingUnrelatedTemplateReplica::class . ' (convention).',
					13,
				],
			],
		);
	}

	public function testConventionIntraChannelDriftIsReportedAtTheFirstConflictSite(): void
	{
		$this->analyse(
			[self::FixtureDir . '/PairingConventionIntraChannelControl.php'],
			[
				[
					'Template class pairing conflict: ' . PairingBaseTemplateReplica::class
					. ' (convention) vs ' . PairingUnrelatedTemplateReplica::class . ' (convention).',
					20,
				],
			],
		);
	}

	// The *dynamic* marker must surface as an opaque channel, never rendered as a class name in a
	// conflict message - analyse() asserting this exact single error pins both halves.
	public function testDynamicConventionReturnIsOpaqueNotAConflict(): void
	{
		$this->analyse(
			[self::FixtureDir . '/PairingConventionDynamicControl.php'],
			[
				[
					'Template class pairing is opaque in channel convention.',
					19,
				],
			],
		);
	}

	public function testVanishedCachedDeclarationIsOpaqueAnchoredAtTheClassDeclaration(): void
	{
		$this->analyse(
			[self::FixtureDir . '/PairingVanishedCachePresenter.php'],
			[
				[
					'Template class pairing is opaque in channel phpdoc.',
					5,
				],
			],
		);
	}

	public function testConflictErrorsCarryTheIgnorablePairingConflictIdentifier(): void
	{
		$errors = $this->gatherAnalyserErrors([self::FixtureDir . '/PairingNarrowerDeclarationPresenter.php']);

		self::assertCount(1, $errors);
		self::assertSame(LattePairingRule::CONFLICT_IDENTIFIER, $errors[0]->getIdentifier());
		self::assertTrue($errors[0]->canBeIgnored(), 'pairing conflicts must stay baselineable');
	}

	public function testOpaqueErrorsCarryTheIgnorablePairingOpaqueIdentifier(): void
	{
		$errors = $this->gatherAnalyserErrors([self::FixtureDir . '/PairingConventionDynamicControl.php']);

		self::assertCount(1, $errors);
		self::assertSame(LattePairingRule::OPAQUE_IDENTIFIER, $errors[0]->getIdentifier());
		self::assertTrue($errors[0]->canBeIgnored(), 'pairing opaques must stay baselineable');
	}

	public function testAgreeingRuntimeAndDeclarationAreSilent(): void
	{
		$this->analyse([self::FixtureDir . '/PairingAgreeExactPresenter.php'], []);
	}

	public function testRuntimeSubtypeOfDeclarationIsSilent(): void
	{
		$this->analyse([self::FixtureDir . '/PairingRuntimeNarrowerPresenter.php'], []);
	}

	public function testBareDefaultsClassIsSilent(): void
	{
		$this->analyse([self::FixtureDir . '/PairingDefaultsSilentPresenter.php'], []);
	}

	public function testPerSiteSecondaryTemplateIsSilent(): void
	{
		$this->analyse([self::FixtureDir . '/PairingDualChannelControl.php'], []);
	}

	// Gate pin: judge() throws LogicException on non-qualifying facts by ratified contract - this
	// fixture crashes the analysis run if the rule's qualification gate is ever removed.
	public function testNonQualifyingClassProducesNothing(): void
	{
		$this->analyse([self::FixtureDir . '/PairingNonQualifyingService.php'], []);
	}

	private function isolatedCacheDir(): string
	{
		return $this->cacheDir ??= sys_get_temp_dir() . '/latte-pairing-rule-' . getmypid() . '-' . uniqid('', true);
	}

}
