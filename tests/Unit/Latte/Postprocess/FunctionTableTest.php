<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use Latte\Runtime\Defaults;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Postprocess\FunctionTable;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\ProcessParamsQualificationFixture;
use function strtolower;

/**
 * @group latte2
 */
final class FunctionTableTest extends BaseTestCase
{

	public function testResolvesEveryDefaultFunction(): void
	{
		$table = new FunctionTable();
		foreach ((new Defaults())->getFunctions() as $name => $callable) {
			self::assertNotNull($table->resolve(strtolower($name)), "function $name unresolved");
		}
	}

	public function testUnknownFunctionIsNull(): void
	{
		self::assertNull((new FunctionTable())->resolve('definitelynotafunction'));
	}

	public function testFilterOnlyDefaultNameIsNotAFunction(): void
	{
		// 'batch'/'trim'/'upper' exist only in Defaults::getFilters(), never in getFunctions().
		self::assertNull((new FunctionTable())->resolve('batch'));
		self::assertNull((new FunctionTable())->resolve('trim'));
	}

	public function testClampResolvesToFiltersClamp(): void
	{
		self::assertSame(
			['Latte\Runtime\Filters', 'clamp', false],
			(new FunctionTable())->resolve('clamp'),
		);
	}

	public function testHarvestedFunctionWithStaticCallableIsRegistered(): void
	{
		$harvested = self::harvestedWithFunction('myFunction', [self::class, 'fixtureFunctionMethod']);

		self::assertSame(
			[self::class, 'fixtureFunctionMethod', false],
			(new FunctionTable($harvested))->resolve('myfunction'),
		);
	}

	public function testHarvestedFunctionWithClosureDegradesToUnknownRatherThanCrashing(): void
	{
		$harvested = self::harvestedWithFunction('myFunction', static fn (int $n = 0): int => $n);

		self::assertNull((new FunctionTable($harvested))->resolve('myfunction'));
	}

	public function testBuiltInFunctionWinsOverAHarvestedNameCollision(): void
	{
		$harvested = self::harvestedWithFunction('clamp', [self::class, 'fixtureFunctionMethod']);

		self::assertSame(
			['Latte\Runtime\Filters', 'clamp', false],
			(new FunctionTable($harvested))->resolve('clamp'),
		);
	}

	public function testResolveForTemplateResolvesAPerTemplateFunctionAsInstanceScoped(): void
	{
		$resolved = (new FunctionTable())->resolveForTemplate(
			'docfunction',
			ProcessParamsQualificationFixture::class,
			$this->templateTypeCustoms(),
		);

		self::assertSame([ProcessParamsQualificationFixture::class, 'docFunction', false, true, false], $resolved);
	}

	public function testResolveForTemplateFallsBackToTheBaseTableWhenTheTemplateTypeClassHasNoMatchingFunction(): void
	{
		$resolved = (new FunctionTable())->resolveForTemplate(
			'clamp',
			ProcessParamsQualificationFixture::class,
			$this->templateTypeCustoms(),
		);

		self::assertSame(['Latte\Runtime\Filters', 'clamp', false, false, false], $resolved);
	}

	// See FilterTableTest's identical-in-spirit test - the same last-write-wins runtime precedence
	// (Engine::addFunction() overwrites, processParams() runs after registration) applies to
	// functions too.
	public function testResolveForTemplatePerTemplateFunctionWinsOverAHarvestedNameCollisionDeterministically(): void
	{
		$harvested = self::harvestedWithFunction('docFunction', [self::class, 'fixtureFunctionMethod']);

		$resolved = (new FunctionTable($harvested))->resolveForTemplate(
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

		return new TemplateTypeCustoms(new PhpVersion(70400), $parser);
	}

	public static function fixtureFunctionMethod(int $n = 0): int
	{
		return $n;
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
