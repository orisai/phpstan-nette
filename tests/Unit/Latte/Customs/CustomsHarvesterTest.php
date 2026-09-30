<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs;

use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Version\Latte2\LatteCompiler;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureMacroSet;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\InvocationCounter;
use function array_keys;
use function ob_get_clean;
use function ob_start;

// The macro-set cases stay Latte 2 (Latte3EngineReaderTest covers the extension harvest); the
// resolution, memoization and degradation cases run on every line with that line's loader.
final class CustomsHarvesterTest extends BaseTestCase
{

	private const ContainerLoaderFile = __DIR__ . '/Fixtures/container-loader.php';

	private const ContainerLoaderNoFactoryFile = __DIR__ . '/Fixtures/container-loader-no-factory.php';

	private const EngineLoaderFile = __DIR__ . '/Fixtures/engine-loader.php';

	private const EngineLoaderCountingFile = __DIR__ . '/Fixtures/engine-loader-counting.php';

	private const EngineLoaderThrowingFile = __DIR__ . '/Fixtures/engine-loader-throwing.php';

	private const EngineLoaderNonEngineFile = __DIR__ . '/Fixtures/engine-loader-non-engine.php';

	private const EngineLoaderEnumerationThrowsFile = __DIR__ . '/Fixtures/engine-loader-enumeration-throws.php';

	private const EngineLoaderTriggerErrorFile = __DIR__ . '/Fixtures/engine-loader-trigger-error.php';

	private const MissingFile = __DIR__ . '/Fixtures/does-not-exist.php';

	private const Latte3EngineLoaderFile = __DIR__ . '/Latte3/Fixtures/engine-loader.php';

	private const Latte3EngineLoaderEnumerationThrowsFile = __DIR__ . '/Latte3/Fixtures/engine-loader-enumeration-throws.php';

	/**
	 * @group latte2
	 */
	public function testEnumeratesFiltersFunctionsMacrosViaContainerLoader(): void
	{
		$harvested = $this->harvest(self::ContainerLoaderFile, null);

		self::assertArrayHasKey('fixturefilter', $harvested->getFilters());
		self::assertSame('x', ($harvested->getFilters()['fixturefilter'])('x'));

		self::assertArrayHasKey('fixtureFunction', $harvested->getFunctions());
		self::assertSame(5, ($harvested->getFunctions()['fixtureFunction'])(5));

		self::assertContains('fixtureMacro', $harvested->getMacroNames());
		self::assertContainsFixtureMacroSet($harvested);

		self::assertSame('fixtureFilter', $harvested->getFilterOriginalNames()['fixturefilter'] ?? null);
		self::assertSame('fixtureFunction', $harvested->getFunctionOriginalNames()['fixturefunction'] ?? null);
	}

	/**
	 * @group latte2
	 */
	public function testEnumeratesFiltersFunctionsMacrosViaEngineLoaderFile(): void
	{
		$harvested = $this->harvest(null, self::EngineLoaderFile);

		self::assertArrayHasKey('fixturefilter', $harvested->getFilters());
		self::assertArrayHasKey('fixtureFunction', $harvested->getFunctions());
		self::assertContains('fixtureMacro', $harvested->getMacroNames());
		self::assertContainsFixtureMacroSet($harvested);

		self::assertSame('fixtureFilter', $harvested->getFilterOriginalNames()['fixturefilter'] ?? null);
		self::assertSame('fixtureFunction', $harvested->getFunctionOriginalNames()['fixturefunction'] ?? null);
	}

	/**
	 * @group latte2
	 */
	public function testContainerLoaderTakesPrecedenceOverEngineLoaderFile(): void
	{
		InvocationCounter::$count = 0;

		$harvested = $this->harvest(self::ContainerLoaderFile, self::EngineLoaderCountingFile);

		self::assertSame(0, InvocationCounter::$count);
		self::assertArrayHasKey('fixturefilter', $harvested->getFilters());
	}

	public function testMemoizationEngineLoaderFileRequiredOnce(): void
	{
		InvocationCounter::$count = 0;

		$harvester = $this->harvester(null, self::EngineLoaderCountingFile);
		$harvester->harvest();
		$harvester->harvest();
		$harvester->harvest();

		self::assertSame(1, InvocationCounter::$count);
	}

	public function testDeterminismAcrossTwoHarvests(): void
	{
		$first = $this->harvest(null, self::engineLoaderFile());
		$second = $this->harvest(null, self::engineLoaderFile());

		self::assertNotSame([], $first->getFilters());

		self::assertSame(array_keys($first->getFilters()), array_keys($second->getFilters()));
		self::assertSame(array_keys($first->getFunctions()), array_keys($second->getFunctions()));
		self::assertSame($first->getMacroNames(), $second->getMacroNames());
		self::assertSame($first->getSaltHash(), $second->getSaltHash());
	}

	/**
	 * @group latte2
	 */
	public function testSaltHashDiffersFromEmptyHarvest(): void
	{
		$harvested = $this->harvest(null, self::EngineLoaderFile);

		self::assertNotSame(HarvestedCustoms::empty()->getSaltHash(), $harvested->getSaltHash());
	}

	/**
	 * @group latte2
	 */
	public function testSaltHashDiffersWhenTheSameMacroNameIsProvidedByADifferentClass(): void
	{
		// Both loaders register the identical filter/function (same shared callable class) and the
		// same macro tag name ('fixtureMacro') - the ONLY difference is which class provides that
		// macro. A macro-set CODE change with an unchanged tag name must still change the salt, or
		// neither PHPStan's result cache nor LatteCompiler's compile cache ever notices the codegen
		// changed.
		$base = $this->harvest(null, __DIR__ . '/Fixtures/engine-loader-macro-identity-base.php');
		$alternate = $this->harvest(null, __DIR__ . '/Fixtures/engine-loader-alternate-macro-class.php');

		self::assertSame(array_keys($base->getFilters()), array_keys($alternate->getFilters()));
		self::assertSame(array_keys($base->getFunctions()), array_keys($alternate->getFunctions()));
		self::assertSame($base->getMacroNames(), $alternate->getMacroNames());
		self::assertNotSame($base->getSaltHash(), $alternate->getSaltHash());
	}

	public function testMissingEngineLoaderFileDegradesToEmptyWithoutException(): void
	{
		$harvested = $this->harvest(null, self::MissingFile);

		self::assertEmptyHarvest($harvested);
	}

	public function testEngineLoaderThrowingDegradesToEmptyWithoutException(): void
	{
		$harvested = $this->harvest(null, self::EngineLoaderThrowingFile);

		self::assertEmptyHarvest($harvested);
	}

	public function testEngineLoaderReturningNonEngineDegradesToEmptyWithoutException(): void
	{
		$harvested = $this->harvest(null, self::EngineLoaderNonEngineFile);

		self::assertEmptyHarvest($harvested);
	}

	public function testContainerWithoutFactoryDegradesToEmptyWithoutException(): void
	{
		$harvested = $this->harvest(self::ContainerLoaderNoFactoryFile, null);

		self::assertEmptyHarvest($harvested);
	}

	public function testNoSourceConfiguredDegradesToEmptyWithoutException(): void
	{
		$harvested = $this->harvest(null, null);

		self::assertEmptyHarvest($harvested);
	}

	public function testEnumerationStageFailureDegradesToEmptyWithoutException(): void
	{
		// Resolution succeeds (a real Engine comes back); the failure happens later, inside the
		// reader itself (a Latte 2 onCompile[] handler, a Latte 3 extension's getTags() throwing) - a
		// different failure stage than every other test in this class, which all fail during
		// EngineSource::resolve().
		$harvested = $this->harvest(
			null,
			InstalledVersionsGuard::latteMajor() === 2
				? self::EngineLoaderEnumerationThrowsFile
				: self::Latte3EngineLoaderEnumerationThrowsFile,
		);

		self::assertEmptyHarvest($harvested);
	}

	/**
	 * @group latte2
	 */
	public function testOnCompileTriggerErrorDuringHarvestIsContainedAndHarvestStillSucceeds(): void
	{
		// If VendorErrorContainment ever stopped wrapping CustomsHarvester::doHarvest(), PHPUnit's
		// own error-to-exception handler would convert one of the fixture's trigger_error() calls
		// into a thrown Error before harvest() returns - reaching the assertions below is already
		// half the regression proof. ob_start()/ob_get_clean() is the other half: it proves nothing
		// reached stdout either, independent of which handler would have caught it.
		ob_start();
		$harvested = $this->harvest(null, self::EngineLoaderTriggerErrorFile);
		$output = ob_get_clean();

		self::assertSame('', $output, 'trigger_error() output during harvest must never leak to stdout');
		self::assertArrayHasKey('fixturefilter', $harvested->getFilters());
		self::assertArrayHasKey('fixtureFunction', $harvested->getFunctions());
		self::assertContains('fixtureMacro', $harvested->getMacroNames());
	}

	/**
	 * @group latte2
	 */
	public function testHarvestTriggerErrorNeverBecomesALatteDeprecatedDiagnosticDuringCompile(): void
	{
		// orisaiNette.latte.deprecated is compile-scoped: it only exists inside LatteCompiler::doCompile()'s
		// own VendorErrorContainment window around $compiler->compile(). Harvest-time
		// trigger_error()s are contained by a wholly separate window (CustomsHarvester::doHarvest())
		// that has no Diagnostic sink at all - even though CaseMismatchScanner's lazy harvest() call
		// happens during this very compile(), a fresh (not yet memoized) harvester's fixture
		// deprecation must never surface as a orisaiNette.latte.deprecated finding on the compiled template.
		$harvester = $this->harvester(null, self::EngineLoaderTriggerErrorFile);
		$compiler = new LatteCompiler(null, $harvester);

		$result = $compiler->compile("{if \$show}<b>{\$name}</b>{/if}\n", 'LatteTpl_test_harvest_trigger_error');

		self::assertNotNull($result->getPhpSource());
		self::assertSame([], $result->getDiagnostics());
	}

	/**
	 * @group latte2
	 */
	public function testContainerLoaderReturningABareContainerIsHarvested(): void
	{
		$harvested = $this->harvest(__DIR__ . '/Fixtures/container-loader-bare.php', null);

		self::assertArrayHasKey('fixturefilter', $harvested->getFilters());
		self::assertContains('fixtureMacro', $harvested->getMacroNames());
	}

	private static function engineLoaderFile(): string
	{
		return InstalledVersionsGuard::latteMajor() === 2 ? self::EngineLoaderFile : self::Latte3EngineLoaderFile;
	}

	private function harvest(?string $containerLoaderFile, ?string $latteEngineLoaderFile): HarvestedCustoms
	{
		return $this->harvester($containerLoaderFile, $latteEngineLoaderFile)->harvest();
	}

	private function harvester(?string $containerLoaderFile, ?string $latteEngineLoaderFile): CustomsHarvester
	{
		return TestAdapter::harvester(new EngineSource($containerLoaderFile, $latteEngineLoaderFile));
	}

	public function testHasConfiguredSourceIsFalseWithNeitherLoaderSet(): void
	{
		self::assertFalse($this->harvester(null, null)->hasConfiguredSource());
	}

	public function testHasConfiguredSourceIsTrueWithAContainerLoaderEvenIfItYieldsNoFactory(): void
	{
		self::assertTrue($this->harvester(self::ContainerLoaderNoFactoryFile, null)->hasConfiguredSource());
	}

	public function testHasConfiguredSourceIsTrueWithAnEngineLoaderEvenIfItThrows(): void
	{
		self::assertTrue($this->harvester(null, self::EngineLoaderThrowingFile)->hasConfiguredSource());
	}

	public function testAmbiguousBuiltInSpellingsAreDroppedFromTheOriginalNameMap(): void
	{
		// Defaults (Latte 3: CoreExtension) deliberately registers 'dataStream'/'datastream' (and
		// other pairs) as two equally-valid spellings of the same filter - buildOriginalNameMap() must
		// not guess a single "canonical" one (either guess would false-positive a case-mismatch
		// diagnostic against real app template usage of the other, correctly-registered spelling).
		$harvested = $this->harvest(null, self::engineLoaderFile());

		self::assertArrayHasKey('upper', $harvested->getFilterOriginalNames());

		self::assertArrayNotHasKey('datastream', $harvested->getFilterOriginalNames());
		self::assertArrayNotHasKey('striptags', $harvested->getFilterOriginalNames());
	}

	private static function assertEmptyHarvest(HarvestedCustoms $harvested): void
	{
		self::assertSame([], $harvested->getFilters());
		self::assertSame([], $harvested->getFunctions());
		self::assertSame([], $harvested->getMacroSets());
		self::assertSame([], $harvested->getMacroNames());
		self::assertSame([], $harvested->getFilterOriginalNames());
		self::assertSame([], $harvested->getFunctionOriginalNames());
		self::assertSame(HarvestedCustoms::empty()->getSaltHash(), $harvested->getSaltHash());
	}

	private static function assertContainsFixtureMacroSet(HarvestedCustoms $harvested): void
	{
		foreach ($harvested->getMacroSets() as $macroSet) {
			if ($macroSet instanceof FixtureMacroSet) {
				return;
			}
		}

		self::fail('Expected a harvested macro set instance of FixtureMacroSet.');
	}

}
