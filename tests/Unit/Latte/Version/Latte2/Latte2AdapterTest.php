<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version\Latte2;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Version\Latte2\Latte2Adapter;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapter;
use OriPhpstan\Nette\Latte\Version\ParsedTemplate;
use OriPhpstan\Nette\LatteForms\FormMacroCollector;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use function dirname;
use function preg_match;
use function sha1;
use function sys_get_temp_dir;
use function uniqid;

final class Latte2AdapterTest extends BaseTestCase
{

	private const FORMS_FIXTURE_DIR = 'tests/Unit/LatteForms/Fixtures';

	protected function setUp(): void
	{
		parent::setUp();
		InstalledVersionsGuard::requireLatteMajor(2);
	}

	public function testCompileIsTheLatteCompilerOutput(): void
	{
		$source = FileSystem::read(dirname(__DIR__, 2) . '/Fixtures/forms-macros.latte');
		$className = TemplateClassName::forPath('fixtures/forms-macros.latte');

		$expected = (new LatteCompiler())->compile($source, $className);
		$actual = $this->adapter()->compile($source, $className);

		self::assertNotNull($expected->getPhpSource());
		self::assertSame($expected->getPhpSource(), $actual->getPhpSource());
		self::assertSame($expected->getClassName(), $actual->getClassName());
		self::assertEquals($expected->getDiagnostics(), $actual->getDiagnostics());
	}

	public function testCompileCacheKeyCarriesTheFamilyAndTheAdapterClass(): void
	{
		$directory = sys_get_temp_dir() . '/latte2-adapter-cache-' . uniqid('', true);
		$cache = new LatteAnalysisCache($directory, 'testv1');
		$source = "{var \$x = 1}{\$x}\n";
		$className = 'LatteTpl_adapter_cache_test';

		try {
			$result = $this->adapter(new LatteCompiler($cache))->compile($source, $className);
			self::assertNotNull($result->getPhpSource());

			$prefix = sha1($source) . '|' . $className . '|' . HarvestedCustoms::empty()->getSaltHash() . '|disabled|';
			self::assertNotNull(
				$cache->readContentAddressed($prefix . '2/macros|' . Latte2Adapter::class, 'latte-compile'),
			);
			self::assertNull($cache->readContentAddressed($prefix, 'latte-compile'));

			$warm = $this->adapter(new LatteCompiler($cache))->compile($source, $className);
			self::assertSame($result->getPhpSource(), $warm->getPhpSource());
		} finally {
			FileSystem::delete($directory);
		}
	}

	public function testExtractFactsJoinsTheThreeScanners(): void
	{
		$root = dirname(__DIR__, 5);
		$relativePath = self::FORMS_FIXTURE_DIR . '/attr-form.latte';
		$source = FileSystem::read($root . '/' . $relativePath);
		$universe = new LatteUniverse([$root . '/' . self::FORMS_FIXTURE_DIR], $root);

		$facts = $this->adapter(null, $universe)->extractFacts($source, new ParsedTemplate($relativePath));

		self::assertEquals((new DeclarationScanner())->scan($source), $facts->getDeclarations());
		self::assertEquals((new TemplateFactExtractor())->extract($source, $relativePath), $facts->getTemplateFacts());
		self::assertEquals((new FormMacroCollector($universe))->sitesFor($relativePath), $facts->getFormSites());
		self::assertNotSame([], $facts->getFormSites());
	}

	public function testHarvestCustomsReadsTheEngineLikeTheHarvester(): void
	{
		$loader = dirname(__DIR__, 2) . '/Customs/Fixtures/engine-loader-gettext.php';
		$engine = (new EngineSource(null, $loader))->resolve();
		self::assertNotNull($engine);

		$harvested = $this->adapter()->harvestCustoms($engine);

		self::assertSame(
			(new CustomsHarvester(new EngineSource(null, $loader)))->harvest()->getSaltHash(),
			$harvested->getSaltHash(),
		);
		self::assertContains('_', $harvested->getMacroNames());
	}

	public function testLineMarkerPatternNamesTheLine(): void
	{
		$pattern = $this->adapter()->lineMarkerPattern();

		self::assertSame(Latte2Adapter::LINE_MARKER_PATTERN, $pattern);
		self::assertSame(1, preg_match($pattern, 'echo $x /* line 12 */;', $m));
		self::assertSame('12', $m['line']);
		self::assertSame(0, preg_match($pattern, 'echo $x /* pos 12:3 */;'));
	}

	public function testFamily(): void
	{
		self::assertSame('2/macros', $this->adapter()->family()->id());
	}

	private function adapter(?LatteCompiler $compiler = null, ?LatteUniverse $universe = null): LatteVersionAdapter
	{
		return new Latte2Adapter(
			$compiler ?? new LatteCompiler(),
			new DeclarationScanner(),
			new TemplateFactExtractor(),
			new FormMacroCollector($universe ?? new LatteUniverse([], '')),
		);
	}

}
