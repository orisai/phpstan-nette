<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Includes\ProviderAvailabilityChecker;
use OriPhpstan\Nette\Latte\Postprocess\ProviderMacroScanner;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Discovery\Fixtures\FixtureRecordSourceContainer;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsControlRenderer;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsDisagreeingControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsStandaloneControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsVendorDefaultPresenter;
use function array_map;
use function getmypid;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

// The guard's OWN axis is entirely PhpRenderFacts::getCreateTemplateControl() - already exercised
// exhaustively for the `presenter` variable by FactoryProvidedVarsTest, whose renderer fixtures
// (Fixtures/App/FactoryVars*) this file reuses directly rather than re-deriving equivalent classes:
// the CONTROL_NONE/CONTROL_SELF/CONTROL_OTHER/no-observation shapes are exactly the same fixture
// domain either consumer reads. This file is only about the all-renderers GUARD built on top of that
// axis - never about the macro-site DETECTION itself, which ProviderMacroScannerTest pins instead.
final class ProviderAvailabilityCheckerTest extends PHPStanTestCase
{

	private const AppFixtureDir = __DIR__ . '/Fixtures/App';

	private const TemplateRel = 'page.latte';

	private const CONTROL_SITE = ['macro' => ProviderMacroScanner::MACRO_CONTROL, 'provider' => ProviderMacroScanner::PROVIDER_UI_CONTROL, 'line' => 3];

	// THE REPORTING CASE: every renderer this template's store record links to proves CONTROL_NONE,
	// so a uiControl-requiring macro is claimed - the exact scenario the earlier `presenter` row
	// (testStandaloneFactoryRendererProvidesThePresenterAsNull) also rests on.
	public function testReportsWhenTheOnlyLinkedRendererProvesControlNone(): void
	{
		$diagnostics = $this->check([FactoryVarsStandaloneControl::class], [self::CONTROL_SITE]);

		self::assertCount(1, $diagnostics);
		self::assertSame(ProviderAvailabilityChecker::IDENTIFIER, $diagnostics[0]->getIdentifier());
		self::assertSame(3, $diagnostics[0]->getLatteLine());
		self::assertStringContainsString('{control}', $diagnostics[0]->getMessage());
		self::assertStringContainsString('uiControl', $diagnostics[0]->getMessage());
		self::assertStringContainsString('standalone', $diagnostics[0]->getMessage());
	}

	// SILENT CASE 1/4: no store records at all - OPEN, never false-close, matching
	// FactoryProvidedVars::resolve()'s own "$classNames === [] -> $nothing" clause.
	public function testSilentWhenNoStoreRecordsExist(): void
	{
		self::assertSame([], $this->check([], [self::CONTROL_SITE]));
	}

	// SILENT CASE 2/4: the linked renderer's OWN instance really does reach the factory
	// (FactoryVarsControlRenderer inherits the vendor Control::createTemplate() body, which passes
	// $this) - CONTROL_SELF, never CONTROL_NONE, so nothing is claimed however plainly a uiControl
	// macro appears in the template.
	public function testSilentWhenTheOnlyLinkedRendererProvesControlSelf(): void
	{
		self::assertSame([], $this->check([FactoryVarsControlRenderer::class], [self::CONTROL_SITE]));
	}

	// ... and the SAME condition, no finer one, for a provider {plink}/{ifCurrent} need instead of
	// {control}/{form} - this checker does not special-case "control present but its presenter may
	// still be null" (a detached FactoryVarsControlRenderer's own runtime state, which
	// FactoryProvidedVars' `presenter` row already declines to answer for controls); CONTROL_SELF
	// silences it exactly the same way.
	public function testSilentForAPresenterMacroWhenTheOnlyLinkedRendererProvesControlSelf(): void
	{
		$plinkSite = ['macro' => ProviderMacroScanner::MACRO_PLINK, 'provider' => ProviderMacroScanner::PROVIDER_UI_PRESENTER, 'line' => 3];

		self::assertSame([], $this->check([FactoryVarsControlRenderer::class], [$plinkSite]));
	}

	// SILENT CASE 3/4: two creation paths on the same class (the inherited vendor body AND a
	// standalone factory call) - CONTROL_OTHER, which claims nothing rather than guessing which path
	// a given template took.
	public function testSilentWhenTheOnlyLinkedRendererProvesControlOther(): void
	{
		self::assertSame([], $this->check([FactoryVarsDisagreeingControl::class], [self::CONTROL_SITE]));
	}

	// SILENT CASE 4/4: the renderer qualifies (its own template surface resolves) but records NO
	// createTemplate() call site at all - "no observation", deliberately kept distinguishable from
	// CONTROL_OTHER (both deny the claim, but only OTHER says a call site was actually seen).
	public function testSilentWhenTheOnlyLinkedRendererHasNoObservedCreateTemplateCall(): void
	{
		self::assertSame([], $this->check([FactoryVarsVendorDefaultPresenter::class], [self::CONTROL_SITE]));
	}

	// The all-renderers discipline itself: ONE non-NONE renderer among several poisons the whole
	// claim, exactly like FactoryProvidedVars::intersect()'s own all-or-nothing fold - a resolver
	// that merely picked the "best" renderer would still report here.
	public function testSilentWhenOnlyOneOfSeveralLinkedRenderersProvesControlNone(): void
	{
		$renderers = [FactoryVarsStandaloneControl::class, FactoryVarsControlRenderer::class];

		self::assertSame([], $this->check($renderers, [self::CONTROL_SITE]));
	}

	// A template with no provider-requiring macro at all: silent regardless of how certainly every
	// renderer proves CONTROL_NONE - there is nothing here for the guard to claim about.
	public function testSilentWhenTheTemplateHasNoProviderMacroSites(): void
	{
		self::assertSame([], $this->check([FactoryVarsStandaloneControl::class], []));
	}

	public function testDisabledFlagStaysSilentEvenWhenEveryConditionOtherwiseReports(): void
	{
		self::assertSame([], $this->check([FactoryVarsStandaloneControl::class], [self::CONTROL_SITE], false));
	}

	/**
	 * @param list<string> $rendererClasses
	 * @param list<array{macro: string, provider: string, line: int}> $sites
	 * @return list<Diagnostic>
	 */
	private function check(array $rendererClasses, array $sites, bool $enabled = true): array
	{
		$dir = $this->scratchDir();

		try {
			$container = new FixtureRecordSourceContainer($this->recordSource($dir));
			$checker = new ProviderAvailabilityChecker($container, $this->store($dir, $rendererClasses), $enabled);

			return $checker->check(self::TemplateRel, $sites);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $rendererClasses
	 */
	private function store(string $dir, array $rendererClasses): DiscoveryStore
	{
		$store = new DiscoveryStore($dir . '/store');
		$store->replaceWith(
			$rendererClasses === []
				? []
				: [
					self::TemplateRel => array_map(
						static fn (string $className): array => [
							'class' => $className,
							'view' => 'default',
							'kind' => CandidatePath::KIND_FORMULA,
							'certainty' => Certainty::HAPPENS,
						],
						$rendererClasses,
					),
				],
			$rendererClasses,
			[],
		);

		return $store;
	}

	private function recordSource(string $dir): DiscoveryRecordSource
	{
		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$appRoot = realpath(self::AppFixtureDir);
		self::assertNotFalse($appRoot);

		$templateFactoryDefault = new TemplateFactoryDefaultResolver(null);
		$discoveryResolver = new DiscoveryResolver(null, [], $appRoot);

		return new DiscoveryRecordSource(
			new PhpFactsCache(new LatteAnalysisCache($dir . '/cache'), $templateFactoryDefault, $discoveryResolver, []),
			new PhpRenderWalk(
				self::createReflectionProvider(),
				$parser,
				[$appRoot],
				$templateFactoryDefault,
				$discoveryResolver,
			),
		);
	}

	private function scratchDir(): string
	{
		return sys_get_temp_dir() . '/latte-provider-availability-' . getmypid() . '-' . uniqid('', true);
	}

}
