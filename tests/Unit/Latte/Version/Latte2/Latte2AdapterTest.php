<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version\Latte2;

use Latte\Runtime\Defaults;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Version\Latte2\FormSiteScanner;
use OriPhpstan\Nette\Latte\Version\Latte2\Latte2Adapter;
use OriPhpstan\Nette\Latte\Version\Latte2\Latte2EngineReader;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapter;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use function array_keys;
use function dirname;
use function preg_match;
use function sha1;
use function sys_get_temp_dir;
use function uniqid;

final class Latte2AdapterTest extends BaseTestCase
{

	private const FORMS_FIXTURE_REL = 'tests/Unit/LatteForms/Fixtures/attr-form.latte';

	protected function setUp(): void
	{
		parent::setUp();
		InstalledVersionsGuard::requireLatteMajor(2);
	}

	public function testCompileIsTheLatteCompilerOutputPlusTheFacts(): void
	{
		$relativePath = 'fixtures/forms-macros.latte';
		$source = FileSystem::read(dirname(__DIR__, 2) . '/Fixtures/forms-macros.latte');
		$className = TemplateClassName::forPath($relativePath);

		$expected = (new LatteCompiler())->compile($source, $className);
		$adapter = $this->adapter();
		$compiled = $adapter->compile($source, $className, $relativePath);

		self::assertNotNull($expected->getPhpSource());
		self::assertSame($expected->getPhpSource(), $compiled->getResult()->getPhpSource());
		self::assertSame($expected->getClassName(), $compiled->getResult()->getClassName());
		self::assertEquals($expected->getDiagnostics(), $compiled->getResult()->getDiagnostics());
		$facts = $adapter->extractFacts($source, $relativePath);
		self::assertEquals($facts->getDeclarations(), $compiled->getFacts()->getDeclarations());
		self::assertEquals($facts->getTemplateFacts(), $compiled->getFacts()->getTemplateFacts());
		self::assertEquals($facts->getFormSites(), $compiled->getFacts()->getFormSites());
	}

	public function testCompileCacheKeyCarriesTheFamilyAndTheAdapterClass(): void
	{
		$directory = sys_get_temp_dir() . '/latte2-adapter-cache-' . uniqid('', true);
		$cache = new LatteAnalysisCache($directory, 'testv1');
		$source = "{var \$x = 1}{\$x}\n";
		$className = 'LatteTpl_adapter_cache_test';

		try {
			$result = $this->adapter(new LatteCompiler($cache))->compile($source, $className, 'a.latte')->getResult();
			self::assertNotNull($result->getPhpSource());

			$prefix = sha1($source) . '|' . $className . '|' . HarvestedCustoms::empty()->getSaltHash() . '|disabled|';
			self::assertNotNull(
				$cache->readContentAddressed($prefix . '2/macros|' . Latte2Adapter::class, 'latte-compile'),
			);
			self::assertNull($cache->readContentAddressed($prefix, 'latte-compile'));

			$warm = $this->adapter(new LatteCompiler($cache))->compile($source, $className, 'a.latte')->getResult();
			self::assertSame($result->getPhpSource(), $warm->getPhpSource());
		} finally {
			FileSystem::delete($directory);
		}
	}

	public function testExtractFactsJoinsTheThreeScanners(): void
	{
		$source = FileSystem::read(dirname(__DIR__, 5) . '/' . self::FORMS_FIXTURE_REL);

		$facts = $this->adapter()->extractFacts($source, self::FORMS_FIXTURE_REL);

		self::assertEquals((new DeclarationScanner())->scan($source), $facts->getDeclarations());
		self::assertEquals(
			(new TemplateFactExtractor())->extract($source, self::FORMS_FIXTURE_REL),
			$facts->getTemplateFacts(),
		);
		self::assertEquals((new FormSiteScanner())->scan($source), $facts->getFormSites());
		self::assertNotSame([], $facts->getFormSites());
	}

	public function testEngineReaderReadsWhatTheHarvesterHarvests(): void
	{
		$loader = dirname(__DIR__, 2) . '/Customs/Fixtures/engine-loader-gettext.php';
		$engine = (new EngineSource(null, $loader))->resolve();
		self::assertNotNull($engine);

		$harvested = (new Latte2EngineReader())->read($engine);

		self::assertSame(
			TestAdapter::harvester(new EngineSource(null, $loader))->harvest()->getSaltHash(),
			$harvested->getSaltHash(),
		);
		self::assertContains('_', $harvested->getMacroNames());
	}

	public function testLineMarkerPatternNamesTheLine(): void
	{
		$pattern = $this->adapter()->lineMarkerPattern();

		self::assertSame(ShapeFamily::LINE_MARKER_PATTERN_LINE, $pattern);
		self::assertSame(1, preg_match($pattern, 'echo $x /* line 12 */;', $m));
		self::assertSame('12', $m['line']);
		self::assertSame(0, preg_match($pattern, 'echo $x /* pos 12:3 */;'));
	}

	public function testFamily(): void
	{
		self::assertSame('2/macros', $this->adapter()->family()->id());
	}

	public function testDefaultCallablesAreLattesOwnDefaultsWithTheGuardedEntriesMapped(): void
	{
		$defaults = $this->adapter()->defaultCallables();

		self::assertSame(array_keys((new Defaults())->getFilters()), array_keys($defaults->getFilters()));
		self::assertSame(array_keys((new Defaults())->getFunctions()), array_keys($defaults->getFunctions()));
		self::assertSame(['Latte\Runtime\Filters', 'upper'], $defaults->filterFallback('upper'));
		self::assertSame(['Nette\Utils\Strings', 'webalize'], $defaults->filterFallback('webalize'));
		self::assertNull($defaults->filterFallback('trim'));
		self::assertNull($defaults->functionFallback('clamp'));
	}

	private function adapter(?LatteCompiler $compiler = null): LatteVersionAdapter
	{
		return new Latte2Adapter(
			$compiler ?? new LatteCompiler(),
			new DeclarationScanner(),
			new TemplateFactExtractor(),
			new FormSiteScanner(),
		);
	}

}
