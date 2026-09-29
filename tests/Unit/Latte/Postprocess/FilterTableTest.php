<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use Latte\Runtime\Defaults;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Postprocess\FilterTable;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\ProcessParamsQualificationFixture;
use function strtolower;

/**
 * @group latte2
 */
final class FilterTableTest extends BaseTestCase
{

	public function testResolvesEveryDefaultFilter(): void
	{
		$table = new FilterTable();
		foreach ((new Defaults())->getFilters() as $name => $callable) {
			self::assertNotNull($table->resolve(strtolower($name)), "filter $name unresolved");
		}
	}

	public function testUnknownFilterIsNull(): void
	{
		self::assertNull((new FilterTable())->resolve('definitelynotafilter'));
	}

	public function testSliceResolvesToTheTypedHelper(): void
	{
		self::assertSame([Helpers::class, 'slice', false], (new FilterTable())->resolve('slice'));
	}

	public function testFunctionOnlyDefaultNameIsNotAFilter(): void
	{
		// 'divisibleBy'/'even'/'odd' exist only in Defaults::getFunctions(), never in getFilters().
		// Regression guard: FilterTable must never also register getFunctions() into the same
		// table, or `{$x|divisibleBy}` would silently "resolve" even though that syntax is invalid
		// Latte.
		self::assertNull((new FilterTable())->resolve('divisibleby'));
		self::assertNull((new FilterTable())->resolve('even'));
		self::assertNull((new FilterTable())->resolve('odd'));
	}

	public function testTrimIsContentAware(): void
	{
		$resolved = (new FilterTable())->resolve('trim');

		self::assertNotNull($resolved);
		self::assertSame(['Latte\Runtime\Filters', 'trim', true], $resolved);
	}

	public function testTruncateIsNotContentAware(): void
	{
		$resolved = (new FilterTable())->resolve('truncate');

		self::assertNotNull($resolved);
		self::assertSame(['Latte\Runtime\Filters', 'truncate', false], $resolved);
	}

	public function testDataStreamAliasResolvesToCanonicalMethodCasing(): void
	{
		// resolve() keys are lowercase by contract (callers lowercase before calling, as
		// FilterRewriter does); an un-lowercased lookup misses even though 'datastream' hits.
		self::assertNull((new FilterTable())->resolve('dataStream'));

		$resolved = (new FilterTable())->resolve('datastream');
		self::assertNotNull($resolved);
		self::assertSame('dataStream', $resolved[1]);
	}

	public function testTranslateBridgeEntry(): void
	{
		self::assertSame(
			['OriPhpstan\Nette\Latte\Runtime\Helpers', 'translate', false],
			(new FilterTable())->resolve('translate'),
		);
	}

	public function testModifyDateBridgeEntry(): void
	{
		self::assertSame(
			['OriPhpstan\Nette\Latte\Runtime\Helpers', 'modifyDate', false],
			(new FilterTable())->resolve('modifydate'),
		);
	}

	public function testWebalizeResolvesToStrings(): void
	{
		self::assertSame(
			['Nette\Utils\Strings', 'webalize', false],
			(new FilterTable())->resolve('webalize'),
		);
	}

	public function testHarvestedFilterWithStaticCallableIsRegistered(): void
	{
		$harvested = self::harvestedWithFilter('myFilter', [self::class, 'fixtureFilterMethod']);

		self::assertSame(
			[self::class, 'fixtureFilterMethod', false],
			(new FilterTable($harvested))->resolve('myfilter'),
		);
	}

	public function testHarvestedFilterWithClosureDegradesToUnknownRatherThanCrashing(): void
	{
		$harvested = self::harvestedWithFilter('myFilter', static fn (string $s = ''): string => $s);

		self::assertNull((new FilterTable($harvested))->resolve('myfilter'));
	}

	public function testBuiltInFilterWinsOverAHarvestedNameCollision(): void
	{
		// A harvested filter sharing a built-in's lowercase name (e.g. an app override of |upper)
		// never shadows the built-in table entry - built-ins register first, registerHarvested()'s
		// isset() dedup skips the harvested one.
		$harvested = self::harvestedWithFilter('upper', [self::class, 'fixtureFilterMethod']);

		self::assertSame(
			['Latte\Runtime\Filters', 'upper', false],
			(new FilterTable($harvested))->resolve('upper'),
		);
	}

	public function testResolveForTemplateWithoutATemplateTypeClassFallsBackToTheBaseTable(): void
	{
		self::assertSame(
			['Latte\Runtime\Filters', 'upper', false, false, false],
			(new FilterTable())->resolveForTemplate('upper', null, $this->templateTypeCustoms()),
		);
	}

	public function testResolveForTemplateResolvesAPerTemplateFilterAsInstanceScoped(): void
	{
		$resolved = (new FilterTable())->resolveForTemplate(
			'docfilter',
			ProcessParamsQualificationFixture::class,
			$this->templateTypeCustoms(),
		);

		self::assertSame([ProcessParamsQualificationFixture::class, 'docFilter', false, true, false], $resolved);
	}

	// A per-template STATIC method must resolve as isStatic=true, so
	// FilterRewriter dispatches it as a plain Class::method() StaticCall instead of through
	// Helpers::templateTypeInstance()->method() - PHPStan's own staticMethod.dynamicCall flags the
	// latter shape for a genuinely static method at strict level 8 (proven via a real spawn,
	// TemplateTypeCustomsIntegrationTest::testStaticPerTemplateFilterDispatchesCleanlyThroughARealSpawn).
	public function testResolveForTemplateFlagsAPerTemplateStaticFilterAsStatic(): void
	{
		$resolved = (new FilterTable())->resolveForTemplate(
			'docstaticfilter',
			ProcessParamsQualificationFixture::class,
			$this->templateTypeCustoms(),
		);

		self::assertSame(
			[ProcessParamsQualificationFixture::class, 'docStaticFilter', false, true, true],
			$resolved,
		);
	}

	public function testResolveForTemplateFallsBackToTheBaseTableWhenTheTemplateTypeClassHasNoMatchingFilter(): void
	{
		$resolved = (new FilterTable())->resolveForTemplate(
			'upper',
			ProcessParamsQualificationFixture::class,
			$this->templateTypeCustoms(),
		);

		self::assertSame(['Latte\Runtime\Filters', 'upper', false, false, false], $resolved);
	}

	// A DIFFERENT template's declaring class must never see this one's per-template filter -
	// scoping is per-{templateType} class, resolveForTemplate() taking the class name as an
	// explicit argument (rather than any ambient/global state) is what makes that hold.
	public function testResolveForTemplateNeverLeaksAPerTemplateFilterToAnUnrelatedTemplateTypeClass(): void
	{
		self::assertNull(
			(new FilterTable())->resolveForTemplate('docfilter', self::class, $this->templateTypeCustoms()),
		);
	}

	// A per-template method always wins over a harvested global of the
	// SAME lowercase name, deterministically - correct by construction (verified against vendor
	// Engine::addFilter()/FilterExecutor::add(): registration is last-write-wins, and
	// processParams() runs inside every render() call, i.e. AFTER engine construction/extension
	// registration, so a per-template @filter method always overwrites a harvested filter of the
	// same name for that render). resolveForTemplate() checks the per-template overlay FIRST and
	// returns immediately on a hit, matching that runtime precedence unconditionally, not merely by
	// accident of table population order.
	public function testResolveForTemplatePerTemplateFilterWinsOverAHarvestedNameCollisionDeterministically(): void
	{
		$harvested = self::harvestedWithFilter('docFilter', [self::class, 'fixtureFilterMethod']);

		$resolved = (new FilterTable($harvested))->resolveForTemplate(
			'docfilter',
			ProcessParamsQualificationFixture::class,
			$this->templateTypeCustoms(),
		);

		self::assertSame([ProcessParamsQualificationFixture::class, 'docFilter', false, true, false], $resolved);
	}

	private function templateTypeCustoms(): TemplateTypeCustoms
	{
		/** @var Parser $parser */
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionSimpleParser');

		return new TemplateTypeCustoms(new PhpVersion(70400), $parser);
	}

	public static function fixtureFilterMethod(string $s = ''): string
	{
		return $s;
	}

	/**
	 * @param callable(mixed...): mixed $callable
	 */
	private static function harvestedWithFilter(string $name, callable $callable): HarvestedCustoms
	{
		return new HarvestedCustoms([strtolower($name) => $callable], [], [], [], [], []);
	}

}
