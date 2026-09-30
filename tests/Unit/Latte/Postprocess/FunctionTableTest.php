<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Postprocess\FunctionTable;
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

final class FunctionTableTest extends BaseTestCase
{

	public function testResolvesEveryDefaultFunction(): void
	{
		$table = self::table();
		foreach (self::defaults()->getFunctions() as $name => $callable) {
			self::assertNotNull($table->resolve(strtolower($name)), "function $name unresolved");
		}
	}

	public function testUnknownFunctionIsNull(): void
	{
		self::assertNull(self::table()->resolve('definitelynotafunction'));
	}

	public function testFilterOnlyDefaultNameIsNotAFunction(): void
	{
		// 'batch'/'trim'/'upper' exist only among the default filters, never the functions.
		self::assertNull(self::table()->resolve('batch'));
		self::assertNull(self::table()->resolve('trim'));
	}

	public function testClampResolvesToFiltersClamp(): void
	{
		self::assertSame(
			[self::filtersClass(), 'clamp', false],
			self::table()->resolve('clamp'),
		);
	}

	/**
	 * @group latte3
	 */
	public function testLatte3BlockLambdasResolveToTheHelpers(): void
	{
		// hasBlock()/hasTemplate() are inline lambdas taking the template first; the helpers take
		// the name the rewriter leaves after dropping that argument.
		self::assertSame([Helpers::class, 'hasBlock', false], self::table()->resolve('hasblock'));
		self::assertSame([Helpers::class, 'hasTemplate', false], self::table()->resolve('hastemplate'));
	}

	public function testHarvestedFunctionWithStaticCallableIsRegistered(): void
	{
		$harvested = self::harvestedWithFunction('myFunction', [self::class, 'fixtureFunctionMethod']);

		self::assertSame(
			[self::class, 'fixtureFunctionMethod', false],
			self::table($harvested)->resolve('myfunction'),
		);
	}

	// An anonymous closure has no declaration to reference: known, untyped, never argument-checked.
	public function testHarvestedAnonymousClosureFunctionIsKnownButUntyped(): void
	{
		$harvested = self::harvestedWithFunction('myFunction', static fn (int $n = 0): int => $n);

		self::assertSame([Helpers::class, 'untypedFunction', false], self::table($harvested)->resolve('myfunction'));
	}

	// Latte 3: an entry matches only a spelling the engine registers; a spelling differing in case
	// names the registered one.
	public function testCaseSensitiveNamesMatchOnlyARegisteredSpelling(): void
	{
		$table = new FunctionTable(
			self::defaults(),
			self::harvestedWithFunction('myFunction', [self::class, 'fixtureFunctionMethod']),
			true,
		);

		self::assertSame(
			[self::class, 'fixtureFunctionMethod', false, false, true],
			$table->resolveForTemplate('myfunction', null, null, 'myFunction'),
		);
		self::assertNull($table->resolveForTemplate('myfunction', null, null, 'MyFunction'));
		self::assertSame('myFunction', $table->registeredSpelling('MyFunction', null, null));
		self::assertNull($table->registeredSpelling('myFunction', null, null));
		self::assertNull($table->registeredSpelling('definitelyNotAFunction', null, null));
	}

	public function testCaseSensitivePerTemplateFunctionMatchesItsMethodName(): void
	{
		$table = new FunctionTable(self::defaults(), null, true);
		$customs = $this->templateTypeCustoms();

		self::assertSame(
			[ProcessParamsQualificationFixture::class, 'docFunction', false, true, false],
			$table->resolveForTemplate(
				'docfunction',
				ProcessParamsQualificationFixture::class,
				$customs,
				'docFunction',
			),
		);
		self::assertNull(
			$table->resolveForTemplate(
				'docfunction',
				ProcessParamsQualificationFixture::class,
				$customs,
				'DocFunction',
			),
		);
		self::assertSame(
			'docFunction',
			$table->registeredSpelling('DocFunction', ProcessParamsQualificationFixture::class, $customs),
		);
	}

	// Latte 2 resolves every spelling and never names a registered one.
	public function testCaseInsensitiveNamesMatchEverySpelling(): void
	{
		$table = self::table(self::harvestedWithFunction('myFunction', [self::class, 'fixtureFunctionMethod']));

		self::assertSame(
			[self::class, 'fixtureFunctionMethod', false, false, true],
			$table->resolveForTemplate('myfunction', null, null, 'MYfunction'),
		);
		self::assertNull($table->registeredSpelling('MYfunction', null, null));
	}

	public function testBuiltInFunctionWinsOverAHarvestedNameCollision(): void
	{
		$harvested = self::harvestedWithFunction('clamp', [self::class, 'fixtureFunctionMethod']);

		self::assertSame(
			[self::filtersClass(), 'clamp', false],
			self::table($harvested)->resolve('clamp'),
		);
	}

	public function testResolveForTemplateResolvesAPerTemplateFunctionAsInstanceScoped(): void
	{
		$resolved = self::table()->resolveForTemplate(
			'docfunction',
			ProcessParamsQualificationFixture::class,
			$this->templateTypeCustoms(),
		);

		self::assertSame([ProcessParamsQualificationFixture::class, 'docFunction', false, true, false], $resolved);
	}

	public function testResolveForTemplateFallsBackToTheBaseTableWhenTheTemplateTypeClassHasNoMatchingFunction(): void
	{
		$resolved = self::table()->resolveForTemplate(
			'clamp',
			ProcessParamsQualificationFixture::class,
			$this->templateTypeCustoms(),
		);

		self::assertSame([self::filtersClass(), 'clamp', false, false, true], $resolved);
	}

	// See FilterTableTest's identical-in-spirit test - the same last-write-wins runtime precedence
	// (Engine::addFunction() overwrites, processParams() runs after registration) applies to
	// functions too.
	public function testResolveForTemplatePerTemplateFunctionWinsOverAHarvestedNameCollisionDeterministically(): void
	{
		$harvested = self::harvestedWithFunction('docFunction', [self::class, 'fixtureFunctionMethod']);

		$resolved = self::table($harvested)->resolveForTemplate(
			'docfunction',
			ProcessParamsQualificationFixture::class,
			$this->templateTypeCustoms(),
		);

		self::assertSame([ProcessParamsQualificationFixture::class, 'docFunction', false, true, false], $resolved);
	}

	private function templateTypeCustoms(): TemplateTypeCustoms
	{
		/** @var Parser $parser */
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionSimpleParser');

		return new TemplateTypeCustoms(new PhpVersion(70400), $parser, TestAdapter::factoryFor('2.11.7.0'));
	}

	public static function fixtureFunctionMethod(int $n = 0): int
	{
		return $n;
	}

	private static function table(?HarvestedCustoms $harvested = null): FunctionTable
	{
		return new FunctionTable(self::defaults(), $harvested);
	}

	private static function defaults(): DefaultCallables
	{
		return TestAdapter::create()->defaultCallables();
	}

	private static function filtersClass(): string
	{
		return InstalledVersionsGuard::latteMajor() === 2 ? 'Latte\Runtime\Filters' : 'Latte\Essential\Filters';
	}

	// Engine::addFunction() keeps the exact spelling as-is (unlike filters, whose harvested keys
	// are already lowercased by Engine::getFilters()) - the key here is deliberately NOT
	// lowercased, matching real harvest shape (CustomsHarvester::readFunctions()).

	/**
	 * @param callable(mixed...): mixed $callable
	 */
	private static function harvestedWithFunction(string $name, callable $callable): HarvestedCustoms
	{
		return new HarvestedCustoms([], [$name => $callable], [], [], [], []);
	}

}
