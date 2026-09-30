<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\RuntimeParity;

use Latte\Engine;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionMethod;
use ReflectionProperty;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\ProcessParamsQualificationFixture;
use function array_key_exists;
use function array_keys;
use function get_object_vars;
use function in_array;
use function restore_error_handler;
use function set_error_handler;
use function strtolower;
use const PHP_VERSION_ID;

// Qualification-parity tier: each assertion below is a PAIR - what the installed Latte's REAL
// render-time registration actually registers off ProcessParamsQualificationFixture, immediately
// followed by what TemplateTypeCustoms - the analysis-side model - decides for the identical
// fixture. A mismatch here means the MODEL is wrong, never the probe.
final class ProcessParamsQualificationParityTest extends BaseTestCase
{

	public function testPublicInstanceMethodWithFilterDocTagQualifiesWhereTheLineReadsDocTags(): void
	{
		$this->assertParity('filter', 'docFilter', self::readsDocTags());
	}

	public function testPublicInstanceMethodWithFunctionDocTagQualifiesWhereTheLineReadsDocTags(): void
	{
		$this->assertParity('function', 'docFunction', self::readsDocTags());
	}

	// getMethods(ReflectionMethod::IS_PUBLIC) filters on the public bit alone - it does not exclude
	// static, on the vendor side or on ours ([$params, $method->name] and $params->$name(...) are
	// legal PHP callables even for a static method).
	public function testPublicStaticMethodWithFilterDocTagQualifiesWhereTheLineReadsDocTags(): void
	{
		$this->assertParity('filter', 'docStaticFilter', self::readsDocTags());
	}

	// Private is the only non-public visibility this fixture pins directly (a `protected` member on
	// a `final` class is a separate project-wide rule, app.finalClass.protectedMember - pointless
	// to fight for one probe method); IS_PUBLIC excludes both visibilities identically, on the
	// vendor side and on ours, so private-only coverage already proves "non-public never qualifies".
	public function testPrivateMethodWithFilterDocTagNeverQualifiesOnEitherSide(): void
	{
		$this->assertParity('filter', 'docPrivateFilter', false);
	}

	public function testUntaggedPublicMethodNeverQualifiesOnEitherSide(): void
	{
		$this->assertParity('filter', 'untaggedPublic', false);
		$this->assertParity('function', 'untaggedPublic', false);
	}

	// Vendor's condition is a bare `strpos((string) $method->getDocComment(), '@filter')` truthy
	// check - a plain substring search, not an exact-tag match - so a docblock merely CONTAINING
	// '@filter' as a substring (e.g. '@filterish') qualifies too. Surprising, real, and worth
	// pinning explicitly rather than assuming a maintainer's mental model of "the tag" is exact.
	public function testDocCommentSubstringMatchQualifiesWhereTheLineReadsDocTags(): void
	{
		$this->assertParity('filter', 'substringBug', self::readsDocTags());
	}

	// The vendor runs two independent `if` statements, never elseif - a single method carrying both
	// tags registers as BOTH a filter and a function.
	public function testMethodWithBothTagsQualifiesAsBothFilterAndFunctionWhereTheLineReadsDocTags(): void
	{
		$this->assertParity('filter', 'bothTags', self::readsDocTags());
		$this->assertParity('function', 'bothTags', self::readsDocTags());
	}

	// #[TemplateFilter] alone (no docblock tag): Latte 2 gates its getAttributes() call on
	// PHP_VERSION_ID >= 80000, and on PHP 7.4 the '#[...]' line parses as a comment; Latte 3 runs on
	// PHP 8 only. The model's own gate is the PhpVersion it is constructed with, here the running one.
	public function testAttributeOnlyMethodQualifiesExactlyWhenTheRuntimeReadsAttributes(): void
	{
		$this->assertParity('filter', 'attrOnlyFilter', PHP_VERSION_ID >= 80000);
	}

	private function assertParity(string $kind, string $method, bool $expected): void
	{
		[$filters, $functions] = $this->realRegistrations();
		$real = $kind === 'filter' ? $filters : $functions;
		self::assertSame($expected, in_array(strtolower($method), $real, true), "runtime registration of $method");

		$model = $kind === 'filter'
			? $this->model()->filtersFor(ProcessParamsQualificationFixture::class)
			: $this->model()->functionsFor(ProcessParamsQualificationFixture::class);
		self::assertSame($expected, array_key_exists(strtolower($method), $model), "model entry for $method");
	}

	// Latte 2 and 3.0 read the docblock tags; 3.1's Helpers::inspectParamsClass() reads attributes only.
	private static function readsDocTags(): bool
	{
		return InstalledVersionsGuard::latteLine() !== ShapeFamily::LATTE_31;
	}

	// What the installed line's own render path registers off the fixture: Latte 2 and 3.0 run the
	// private Engine::processParams() (3.0 raising a deprecation notice per docblock tag), 3.1
	// Helpers::resolveParams(). Names are compared lowercased - Latte 3 keeps the registered case.

	/**
	 * @return array{list<string>, list<string>}
	 */
	private function realRegistrations(): array
	{
		$engine = new Engine();
		$params = new ProcessParamsQualificationFixture();

		set_error_handler(static fn (): bool => true);
		try {
			if (InstalledVersionsGuard::latteLine() === ShapeFamily::LATTE_31) {
				(new ReflectionMethod('Latte\Helpers', 'resolveParams'))->invoke(null, $engine, $params);
			} else {
				$method = new ReflectionMethod($engine, 'processParams');
				$method->setAccessible(true);
				$method->invoke($engine, $params);
			}
		} finally {
			restore_error_handler();
		}

		if (InstalledVersionsGuard::latteMajor() === 2) {
			$functionsProperty = new ReflectionProperty($engine, 'functions');
			$functionsProperty->setAccessible(true);
			$functions = get_object_vars($functionsProperty->getValue($engine));
		} else {
			/** @var array<string, callable(mixed...): mixed> $functions */
			$functions = (new ReflectionMethod($engine, 'getFunctions'))->invoke($engine);
		}

		return [self::lowerKeys($engine->getFilters()), self::lowerKeys($functions)];
	}

	/**
	 * @param array<int|string, mixed> $map
	 * @return list<string>
	 */
	private static function lowerKeys(array $map): array
	{
		$names = [];
		foreach (array_keys($map) as $name) {
			$names[] = strtolower((string) $name);
		}

		return $names;
	}

	private function model(): TemplateTypeCustoms
	{
		/** @var Parser $parser */
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionSimpleParser');

		return new TemplateTypeCustoms(new PhpVersion(PHP_VERSION_ID), $parser, TestAdapter::factory());
	}

}
