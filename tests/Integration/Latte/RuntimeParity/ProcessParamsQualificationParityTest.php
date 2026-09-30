<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\RuntimeParity;

use Latte\Engine;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionMethod;
use ReflectionProperty;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\ProcessParamsQualificationFixture;
use function array_keys;
use function get_object_vars;
use const PHP_VERSION_ID;

// Qualification-parity tier: each assertion below is a PAIR - what the
// REAL vendor Engine::processParams() (invoked via reflection, since it's private) actually
// registers off ProcessParamsQualificationFixture, immediately followed by what
// TemplateTypeCustoms - the analysis-side model - decides for the identical fixture. A mismatch
// here means the MODEL is wrong, never the probe. Probes Latte 2's Engine::processParams(); 3.0
// keeps it (deprecated doc tags), 3.1 moves attribute scanning into Helpers::resolveParams - re-pinned
// with Task 16.
/**
 * @group latte2
 */
final class ProcessParamsQualificationParityTest extends BaseTestCase
{

	public function testPublicInstanceMethodWithFilterDocTagQualifiesOnBothSides(): void
	{
		[$filters] = $this->realRegistrations();
		self::assertContains('docfilter', $filters);

		self::assertArrayHasKey(
			'docfilter',
			$this->model(PHP_VERSION_ID)->filtersFor(ProcessParamsQualificationFixture::class),
		);
	}

	public function testPublicInstanceMethodWithFunctionDocTagQualifiesOnBothSides(): void
	{
		[, $functions] = $this->realRegistrations();
		self::assertContains('docFunction', $functions);

		self::assertArrayHasKey(
			'docfunction',
			$this->model(PHP_VERSION_ID)->functionsFor(ProcessParamsQualificationFixture::class),
		);
	}

	// getMethods(ReflectionMethod::IS_PUBLIC) filters on the public bit alone - it does not exclude
	// static, on the vendor side or on ours (see Engine.php:539-540: [$params, $method->name] is a
	// legal PHP callable even for a static method, no $this binding required).
	public function testPublicStaticMethodWithFilterDocTagQualifiesOnBothSides(): void
	{
		[$filters] = $this->realRegistrations();
		self::assertContains('docstaticfilter', $filters);

		self::assertArrayHasKey(
			'docstaticfilter',
			$this->model(PHP_VERSION_ID)->filtersFor(ProcessParamsQualificationFixture::class),
		);
	}

	// Private is the only non-public visibility this fixture pins directly (a `protected` member on
	// a `final` class is a separate project-wide rule, app.finalClass.protectedMember - pointless
	// to fight for one probe method); IS_PUBLIC excludes both visibilities identically, on the
	// vendor side and on ours, so private-only coverage already proves "non-public never qualifies".
	public function testPrivateMethodWithFilterDocTagNeverQualifiesOnEitherSide(): void
	{
		[$filters] = $this->realRegistrations();
		self::assertNotContains('docprivatefilter', $filters);

		self::assertArrayNotHasKey(
			'docprivatefilter',
			$this->model(PHP_VERSION_ID)->filtersFor(ProcessParamsQualificationFixture::class),
		);
	}

	public function testUntaggedPublicMethodNeverQualifiesOnEitherSide(): void
	{
		[$filters, $functions] = $this->realRegistrations();
		self::assertNotContains('untaggedpublic', $filters);
		self::assertNotContains('untaggedPublic', $functions);

		$model = $this->model(PHP_VERSION_ID);
		self::assertArrayNotHasKey('untaggedpublic', $model->filtersFor(ProcessParamsQualificationFixture::class));
		self::assertArrayNotHasKey('untaggedpublic', $model->functionsFor(ProcessParamsQualificationFixture::class));
	}

	// Vendor's condition is a bare `strpos((string) $method->getDocComment(), '@filter')` truthy
	// check - a plain substring search, not an exact-tag match - so a docblock merely CONTAINING
	// '@filter' as a substring (e.g. '@filterish') qualifies too. Surprising, real, and worth
	// pinning explicitly rather than assuming a maintainer's mental model of "the tag" is exact.
	public function testDocCommentSubstringMatchQualifiesOnBothSidesEvenWithoutTheExactTag(): void
	{
		[$filters] = $this->realRegistrations();
		self::assertContains('substringbug', $filters);

		self::assertArrayHasKey(
			'substringbug',
			$this->model(PHP_VERSION_ID)->filtersFor(ProcessParamsQualificationFixture::class),
		);
	}

	// The vendor runs two independent `if` statements (Engine.php:539-548), never elseif - a
	// single method carrying both tags registers as BOTH a filter and a function.
	public function testMethodWithBothTagsQualifiesAsBothFilterAndFunctionOnBothSides(): void
	{
		[$filters, $functions] = $this->realRegistrations();
		self::assertContains('bothtags', $filters);
		self::assertContains('bothTags', $functions);

		$model = $this->model(PHP_VERSION_ID);
		self::assertArrayHasKey('bothtags', $model->filtersFor(ProcessParamsQualificationFixture::class));
		self::assertArrayHasKey('bothtags', $model->functionsFor(ProcessParamsQualificationFixture::class));
	}

	// #[TemplateFilter] alone (no docblock tag): PHP_VERSION_ID >= 80000 gates vendor's own
	// getAttributes() call, and on PHP 7.4 the '#[...]' line parses as a comment. The model's own gate
	// is the PhpVersion it is constructed with, here the running one, so both sides must agree.
	public function testAttributeOnlyMethodQualifiesOnBothSidesExactlyWhenTheRuntimeReadsAttributes(): void
	{
		[$filters] = $this->realRegistrations();
		$modelFilters = $this->model(PHP_VERSION_ID)->filtersFor(ProcessParamsQualificationFixture::class);

		if (PHP_VERSION_ID >= 80000) {
			self::assertContains('attronlyfilter', $filters);
			self::assertArrayHasKey('attronlyfilter', $modelFilters);
		} else {
			self::assertNotContains('attronlyfilter', $filters);
			self::assertArrayNotHasKey('attronlyfilter', $modelFilters);
		}
	}

	/**
	 * @return array{list<int|string>, list<string>}
	 */
	private function realRegistrations(): array
	{
		$engine = new Engine();

		$method = new ReflectionMethod($engine, 'processParams');
		$method->setAccessible(true);
		$method->invoke($engine, new ProcessParamsQualificationFixture());

		$filters = array_keys($engine->getFilters());

		$functionsProperty = new ReflectionProperty($engine, 'functions');
		$functionsProperty->setAccessible(true);
		/** @var array<string, callable> $functionMap */
		$functionMap = get_object_vars($functionsProperty->getValue($engine));

		return [$filters, array_keys($functionMap)];
	}

	private function model(int $versionId): TemplateTypeCustoms
	{
		/** @var Parser $parser */
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionSimpleParser');

		return new TemplateTypeCustoms(new PhpVersion($versionId), $parser);
	}

}
