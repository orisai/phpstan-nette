<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Latte3;

use Latte\Essential\TranslatorExtension;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Bridges\CacheLatte\CacheExtension;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Postprocess\FilterTable;
use OriPhpstan\Nette\Latte\Postprocess\FunctionTable;
use OriPhpstan\Nette\Latte\Version\Latte3\Latte3Compiler;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Latte3\Fixtures\FixtureExtension;
use function array_map;
use function class_exists;
use function get_class;
use function strpos;

/**
 * @group latte3
 */
final class Latte3EngineReaderTest extends BaseTestCase
{

	private const EngineLoaderFile = __DIR__ . '/Fixtures/engine-loader.php';

	private const StrictTypesEngineLoaderFile = __DIR__ . '/Fixtures/engine-loader-strict-types.php';

	public function testExtensionTagsIncludingDerivedAttributesAreHarvested(): void
	{
		$harvested = $this->harvest(self::EngineLoaderFile);

		self::assertTrue($harvested->isExtensionHarvest());
		foreach (['hello', 'n:hello', 'n:inner-hello', 'n:tag-hello'] as $name) {
			self::assertContains($name, $harvested->getMacroNames(), $name);
		}

		self::assertContains('n:if', $harvested->getMacroNames());
		self::assertContains('var', $harvested->getMacroNames());
		self::assertNotContains('n:var', $harvested->getMacroNames());
	}

	public function testFiltersFunctionsAndProvidersAreHarvested(): void
	{
		$harvested = $this->harvest(self::EngineLoaderFile);

		self::assertArrayHasKey('shout', $harvested->getFilters());
		self::assertSame('a!', ($harvested->getFilters()['shout'])('a'));
		self::assertArrayHasKey('twice', $harvested->getFunctions());
		self::assertArrayHasKey('greet', $harvested->getFunctions());
		self::assertSame(['foo' => 'stdClass'], $harvested->getProviderTypes());
		self::assertSame('greet', $harvested->getFunctionOriginalNames()['greet'] ?? null);
	}

	// Neither loader registers the UI or translator extension; nette/application adds both only at
	// render time, so the harvest adds them after the project's own.
	public function testMissingRuntimeExtensionsAreAddedAfterTheProjectOnes(): void
	{
		$classes = array_map(
			static fn (object $extension): string => get_class($extension),
			$this->harvest(self::EngineLoaderFile)->getExtensions(),
		);

		self::assertSame(
			[FixtureExtension::class, UIExtension::class, TranslatorExtension::class],
			[$classes[2], $classes[3], $classes[4]],
		);
		self::assertContains('link', $this->harvest(self::EngineLoaderFile)->getMacroNames());
		self::assertContains('_', $this->harvest(self::EngineLoaderFile)->getMacroNames());
	}

	public function testUserTagCompilesThroughTheHarvestedExtension(): void
	{
		$compiled = TestAdapter::create($this->harvester(self::EngineLoaderFile))
			->compile("{hello}x{/hello}\n<b n:hello>y</b>\n", 'LatteTpl_hello', 'hello.latte');

		self::assertSame([], $compiled->getResult()->getDiagnostics());
		self::assertStringContainsString('hello x', (string) $compiled->getResult()->getPhpSource());
	}

	public function testUserTagIsUnknownWithoutAHarvest(): void
	{
		$compiled = TestAdapter::create()->compile("{hello}x{/hello}\n", 'LatteTpl_hello', 'hello.latte');

		self::assertSame(
			['orisai.nette.latte.unknownMacro'],
			array_map(
				static fn ($diagnostic): string => $diagnostic->getIdentifier(),
				$compiled->getResult()->getDiagnostics(),
			),
		);
	}

	public function testHarvestedFunctionsCompileThroughTheFunctionExecutor(): void
	{
		$code = (string) TestAdapter::create($this->harvester(self::EngineLoaderFile))
			->compile("{twice(2)}{greet('a')}\n", 'LatteTpl_functions', 'functions.latte')
			->getResult()
			->getPhpSource();

		self::assertStringContainsString('($this->global->fn->twice)($this, 2)', $code);
		self::assertStringContainsString('($this->global->fn->greet)($this, \'a\')', $code);
	}

	public function testStrictTypesFollowTheHarvestedEngine(): void
	{
		$harvested = TestAdapter::create($this->harvester(self::StrictTypesEngineLoaderFile))
			->compile("{\$a}\n", 'LatteTpl_strict', 'strict.latte');
		$fixed = TestAdapter::create()->compile("{\$a}\n", 'LatteTpl_strict', 'strict.latte');

		self::assertNotFalse(strpos((string) $harvested->getResult()->getPhpSource(), 'declare(strict_types=1)'));
		self::assertFalse(strpos((string) $fixed->getResult()->getPhpSource(), 'declare(strict_types=1)'));
	}

	// Extension callables are instance-bound first-class callables; they resolve to the declaring
	// method with an instance dispatch, and only the Template-typed function keeps the template.
	public function testHarvestedCallablesResolveToTheirMethods(): void
	{
		$harvested = $this->harvest(self::EngineLoaderFile);
		$defaults = TestAdapter::create()->defaultCallables();
		$filters = new FilterTable($defaults, $harvested);
		$functions = new FunctionTable($defaults, $harvested);

		self::assertSame(
			[FixtureExtension::class, 'shout', false, false, false],
			$filters->resolveForTemplate('shout', null, null),
		);
		self::assertSame(
			[FixtureExtension::class, 'twice', false, false, false],
			$functions->resolveForTemplate('twice', null, null),
		);
		self::assertFalse($functions->receivesTemplate('twice'));
		self::assertTrue($functions->receivesTemplate('greet'));
		self::assertFalse($functions->receivesTemplate('greetmaybe'));
		self::assertFalse($functions->receivesTemplate('hasblock'));
		self::assertSame(['', 'trim', false, false, true], $filters->resolveForTemplate('trimmed', null, null));
	}

	// The fixed set's CacheExtension exists only with nette/caching installed.
	public function testFixedSetSaltFollowsTheCacheBridge(): void
	{
		self::assertSame(
			'fixed-set' . (class_exists(CacheExtension::class) ? '|cache' : ''),
			(new Latte3Compiler())->engineSalt(),
		);
		self::assertNotSame(
			(new Latte3Compiler())->engineSalt(),
			(new Latte3Compiler($this->harvester(self::EngineLoaderFile)))->engineSalt(),
		);
	}

	public function testSaltFollowsTheExtensionsAndFeatures(): void
	{
		$fixture = $this->harvest(self::EngineLoaderFile);
		$strict = $this->harvest(self::StrictTypesEngineLoaderFile);

		self::assertSame($fixture->getSaltHash(), $this->harvest(self::EngineLoaderFile)->getSaltHash());
		self::assertNotSame($fixture->getSaltHash(), $strict->getSaltHash());
		self::assertNotSame(HarvestedCustoms::empty()->getSaltHash(), $strict->getSaltHash());
		$features = $strict->getFeatures();
		self::assertTrue($features['StrictTypes'] ?? $features['strictTypes'] ?? false);
	}

	private function harvest(string $engineLoaderFile): HarvestedCustoms
	{
		return $this->harvester($engineLoaderFile)->harvest();
	}

	private function harvester(string $engineLoaderFile): CustomsHarvester
	{
		return TestAdapter::harvester(new EngineSource(null, $engineLoaderFile));
	}

}
