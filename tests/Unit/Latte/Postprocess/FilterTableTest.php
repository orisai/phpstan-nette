<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Postprocess\FilterTable;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use OriPhpstan\Nette\Latte\Version\DefaultCallables;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\ProcessParamsQualificationFixture;
use function strtolower;

// The table over the installed Latte's own stock filters: Latte 2 lists Latte\Runtime\Filters,
// Latte 3 the Latte\Essential\Filters instance CoreExtension holds.
final class FilterTableTest extends BaseTestCase
{

	public function testResolvesEveryDefaultFilter(): void
	{
		$table = self::table();
		foreach (self::defaults()->getFilters() as $name => $callable) {
			self::assertNotNull($table->resolve(strtolower($name)), "filter $name unresolved");
		}
	}

	public function testUnknownFilterIsNull(): void
	{
		self::assertNull(self::table()->resolve('definitelynotafilter'));
	}

	public function testSliceResolvesToTheTypedHelper(): void
	{
		self::assertSame([Helpers::class, 'slice', false], self::table()->resolve('slice'));
	}

	public function testFunctionOnlyDefaultNameIsNotAFilter(): void
	{
		// 'divisibleBy'/'even'/'odd' exist only among the default functions, never the filters.
		// Regression guard: FilterTable must never also register the functions into the same
		// table, or `{$x|divisibleBy}` would silently "resolve" even though that syntax is invalid
		// Latte.
		self::assertNull(self::table()->resolve('divisibleby'));
		self::assertNull(self::table()->resolve('even'));
		self::assertNull(self::table()->resolve('odd'));
	}

	public function testTrimIsContentAware(): void
	{
		$resolved = self::table()->resolve('trim');

		self::assertNotNull($resolved);
		self::assertSame([self::filtersClass(), 'trim', true], $resolved);
	}

	public function testTruncateIsNotContentAware(): void
	{
		$resolved = self::table()->resolve('truncate');

		self::assertNotNull($resolved);
		self::assertSame([self::filtersClass(), 'truncate', false], $resolved);
	}

	public function testDataStreamAliasResolvesToCanonicalMethodCasing(): void
	{
		// resolve() keys are lowercase by contract (callers lowercase before calling, as
		// FilterRewriter does); an un-lowercased lookup misses even though 'datastream' hits.
		self::assertNull(self::table()->resolve('dataStream'));

		$resolved = self::table()->resolve('datastream');
		self::assertNotNull($resolved);
		self::assertSame('dataStream', $resolved[1]);
	}

	public function testTranslateBridgeEntry(): void
	{
		self::assertSame(
			['OriPhpstan\Nette\Latte\Runtime\Helpers', 'translate', false],
			self::table()->resolve('translate'),
		);
	}

	public function testModifyDateBridgeEntry(): void
	{
		self::assertSame(
			['OriPhpstan\Nette\Latte\Runtime\Helpers', 'modifyDate', false],
			self::table()->resolve('modifydate'),
		);
	}

	public function testWebalizeResolvesToStrings(): void
	{
		self::assertSame(
			['Nette\Utils\Strings', 'webalize', false],
			self::table()->resolve('webalize'),
		);
	}

	// Latte 2's Defaults wraps the mbstring-guarded filters in closures; the fallback map names the
	// static method, so the resolution never depends on the guard branch PHP took.
	public function testMbstringGuardedFiltersResolveToTheStaticMethod(): void
	{
		foreach (['capitalize', 'firstupper', 'lower', 'upper'] as $name) {
			$resolved = self::table()->resolve($name);

			self::assertNotNull($resolved, $name);
			self::assertSame(self::filtersClass(), $resolved[0], $name);
			self::assertSame([$name, false], [strtolower($resolved[1]), $resolved[2]], $name);
		}
	}

	/**
	 * @group latte3
	 */
	public function testLatte3InstanceMethodFiltersDispatchThroughAnInstance(): void
	{
		// CoreExtension registers Essential\Filters' locale-aware methods on its own instance.
		foreach (['number', 'bytes', 'localdate', 'sort'] as $name) {
			$resolved = self::table()->resolveForTemplate($name, null, null);

			self::assertNotNull($resolved, $name);
			self::assertSame('Latte\Essential\Filters', $resolved[0], $name);
			self::assertFalse($resolved[3], $name);
			self::assertFalse($resolved[4], $name);
		}

		self::assertSame(
			['Latte\Essential\Filters', 'upper', false, false, true],
			self::table()->resolveForTemplate('upper', null, null),
		);
	}

	/**
	 * @group latte3
	 */
	public function testLatte3CheckUrlIsAKnownFilter(): void
	{
		self::assertSame(['Latte\Essential\Filters', 'checkUrl', false], self::table()->resolve('checkurl'));
	}

	/**
	 * @group latte31
	 */
	public function testLatte31LimitLambdaResolvesToTheTypedHelper(): void
	{
		self::assertSame([Helpers::class, 'limit', false], self::table()->resolve('limit'));
	}

	public function testHarvestedFilterWithStaticCallableIsRegistered(): void
	{
		$harvested = self::harvestedWithFilter('myFilter', [self::class, 'fixtureFilterMethod']);

		self::assertSame(
			[self::class, 'fixtureFilterMethod', false],
			self::table($harvested)->resolve('myfilter'),
		);
	}

	public function testHarvestedFilterWithClosureDegradesToUnknownRatherThanCrashing(): void
	{
		$harvested = self::harvestedWithFilter('myFilter', static fn (string $s = ''): string => $s);

		self::assertNull(self::table($harvested)->resolve('myfilter'));
	}

	// Latte 3: an entry matches only a spelling the engine registers; a spelling differing in case
	// names the registered one.
	public function testCaseSensitiveNamesMatchOnlyARegisteredSpelling(): void
	{
		$table = new FilterTable(
			self::defaults(),
			new HarvestedCustoms(['myFilter' => [self::class, 'fixtureFilterMethod']], [], [], [], [], []),
			true,
		);

		self::assertNotNull($table->resolveForTemplate('firstupper', null, null, 'firstUpper'));
		self::assertNull($table->resolveForTemplate('firstupper', null, null, 'firstupper'));
		self::assertSame('firstUpper', $table->registeredSpelling('firstupper', null, null));
		self::assertNull($table->registeredSpelling('firstUpper', null, null));
		self::assertNotNull($table->resolveForTemplate('modifydate', null, null, 'modifyDate'));
		self::assertSame('modifyDate', $table->registeredSpelling('modifydate', null, null));
		self::assertSame(
			[self::class, 'fixtureFilterMethod', false, false, true],
			$table->resolveForTemplate('myfilter', null, null, 'myFilter'),
		);
		self::assertNull($table->resolveForTemplate('myfilter', null, null, 'MyFilter'));
		self::assertSame('myFilter', $table->registeredSpelling('MyFilter', null, null));
		self::assertNull($table->registeredSpelling('definitelyNotAFilter', null, null));
	}

	public function testCaseSensitivePerTemplateFilterMatchesItsMethodName(): void
	{
		$table = new FilterTable(self::defaults(), null, true);
		$customs = $this->templateTypeCustoms();

		self::assertSame(
			[ProcessParamsQualificationFixture::class, 'docFilter', false, true, false],
			$table->resolveForTemplate('docfilter', ProcessParamsQualificationFixture::class, $customs, 'docFilter'),
		);
		self::assertNull(
			$table->resolveForTemplate('docfilter', ProcessParamsQualificationFixture::class, $customs, 'docfilter'),
		);
		self::assertSame(
			'docFilter',
			$table->registeredSpelling('docfilter', ProcessParamsQualificationFixture::class, $customs),
		);
	}

	// Latte 2 resolves every spelling and never names a registered one.
	public function testCaseInsensitiveNamesMatchEverySpelling(): void
	{
		$table = self::table();

		self::assertSame(
			$table->resolveForTemplate('firstupper', null, null, 'firstUpper'),
			$table->resolveForTemplate('firstupper', null, null, 'FIRSTupper'),
		);
		self::assertNotNull($table->resolveForTemplate('firstupper', null, null, 'FIRSTupper'));
		self::assertNull($table->registeredSpelling('FIRSTupper', null, null));
	}

	public function testBuiltInFilterWinsOverAHarvestedNameCollision(): void
	{
		// A harvested filter sharing a built-in's lowercase name (e.g. an app override of |upper)
		// never shadows the built-in table entry - built-ins register first, registerHarvested()'s
		// isset() dedup skips the harvested one.
		$harvested = self::harvestedWithFilter('upper', [self::class, 'fixtureFilterMethod']);

		self::assertSame(
			[self::filtersClass(), 'upper', false],
			self::table($harvested)->resolve('upper'),
		);
	}

	public function testResolveForTemplateWithoutATemplateTypeClassFallsBackToTheBaseTable(): void
	{
		self::assertSame(
			[self::filtersClass(), 'upper', false, false, true],
			self::table()->resolveForTemplate('upper', null, $this->templateTypeCustoms()),
		);
	}

	public function testResolveForTemplateResolvesAPerTemplateFilterAsInstanceScoped(): void
	{
		$resolved = self::table()->resolveForTemplate(
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
		$resolved = self::table()->resolveForTemplate(
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
		$resolved = self::table()->resolveForTemplate(
			'upper',
			ProcessParamsQualificationFixture::class,
			$this->templateTypeCustoms(),
		);

		self::assertSame([self::filtersClass(), 'upper', false, false, true], $resolved);
	}

	// A DIFFERENT template's declaring class must never see this one's per-template filter -
	// scoping is per-{templateType} class, resolveForTemplate() taking the class name as an
	// explicit argument (rather than any ambient/global state) is what makes that hold.
	public function testResolveForTemplateNeverLeaksAPerTemplateFilterToAnUnrelatedTemplateTypeClass(): void
	{
		self::assertNull(
			self::table()->resolveForTemplate('docfilter', self::class, $this->templateTypeCustoms()),
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

		$resolved = self::table($harvested)->resolveForTemplate(
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

		return new TemplateTypeCustoms(new PhpVersion(70400), $parser, TestAdapter::factoryFor('2.11.7.0'));
	}

	public static function fixtureFilterMethod(string $s = ''): string
	{
		return $s;
	}

	private static function table(?HarvestedCustoms $harvested = null): FilterTable
	{
		return new FilterTable(self::defaults(), $harvested);
	}

	private static function defaults(): DefaultCallables
	{
		return TestAdapter::create()->defaultCallables();
	}

	private static function filtersClass(): string
	{
		return InstalledVersionsGuard::latteMajor() === 2 ? 'Latte\Runtime\Filters' : 'Latte\Essential\Filters';
	}

	/**
	 * @param callable(mixed...): mixed $callable
	 */
	private static function harvestedWithFilter(string $name, callable $callable): HarvestedCustoms
	{
		return new HarvestedCustoms([strtolower($name) => $callable], [], [], [], [], []);
	}

}
