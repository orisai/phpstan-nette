<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\LatteForms\ControlReference;
use OriPhpstan\Nette\LatteForms\FormMacroCollector;
use OriPhpstan\Nette\LatteForms\FormSite;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function basename;
use function dirname;
use function getmypid;
use function glob;
use function implode;
use function sha1;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

final class FormMacroCollectorTest extends BaseTestCase
{

	private const FIXTURE_DIR = 'tests/Unit/LatteForms/Fixtures';

	public function testMacroFormOpenerCarriesEveryMacroReferenceKind(): void
	{
		$sites = $this->sitesFor('macro-form.latte');

		self::assertCount(1, $sites);
		self::assertSame('registrationForm', $sites[0]->getFormName());
		self::assertSame(1, $sites[0]->getLine());
		self::assertSame(
			[
				[ControlReference::KIND_LABEL, 'email', '', 2],
				[ControlReference::KIND_INPUT, 'email', '', 3],
				[ControlReference::KIND_LABEL, 'password', '', 4],
				[ControlReference::KIND_INPUT, 'password', '', 5],
			],
			$this->tuples($sites[0]),
		);
	}

	public function testFormNameAttributeOpensScopeAndEveryOtherTagIsAReference(): void
	{
		$sites = $this->sitesFor('attr-form.latte');

		self::assertCount(1, $sites);
		self::assertSame('attrForm', $sites[0]->getFormName());
		self::assertSame(1, $sites[0]->getLine());
		self::assertSame(
			[
				[ControlReference::KIND_NAME_ATTR, 'email', '', 2],
				[ControlReference::KIND_NAME_ATTR, 'email', '', 3],
				[ControlReference::KIND_NAME_ATTR, 'country', '', 4],
				[ControlReference::KIND_NAME_ATTR, 'note', '', 5],
				[ControlReference::KIND_NAME_ATTR, 'send', '', 6],
			],
			$this->tuples($sites[0]),
		);
	}

	public function testSelfClosingFormAttributeScopeEndsImmediately(): void
	{
		$sites = $this->sitesFor('attr-form-self-closing.latte');

		self::assertCount(1, $sites);
		self::assertSame('emptyForm', $sites[0]->getFormName());
		self::assertSame([], $this->tuples($sites[0]));
	}

	public function testFormTagMatchIsCaseInsensitive(): void
	{
		$sites = $this->sitesFor('attr-form-uppercase.latte');

		self::assertCount(1, $sites);
		self::assertSame('upperForm', $sites[0]->getFormName());
		self::assertSame(
			[[ControlReference::KIND_NAME_ATTR, 'upperControl', '', 2]],
			$this->tuples($sites[0]),
		);
	}

	public function testMacroContainerNestingComposesContainerPaths(): void
	{
		$sites = $this->sitesFor('containers.latte');

		self::assertCount(1, $sites);
		self::assertSame('orderForm', $sites[0]->getFormName());
		self::assertSame(
			[
				[ControlReference::KIND_INPUT, 'note', '', 2],
				[ControlReference::KIND_CONTAINER, 'address', '', 3],
				[ControlReference::KIND_INPUT, 'street', 'address', 4],
				[ControlReference::KIND_CONTAINER, 'geo', 'address', 5],
				[ControlReference::KIND_INPUT, 'lat', 'address/geo', 6],
				[ControlReference::KIND_INPUT, 'total', '', 9],
			],
			$this->tuples($sites[0]),
		);
	}

	public function testAttributeContainerScopeIsBoundedByItsHtmlElement(): void
	{
		$sites = $this->sitesFor('container-attr.latte');

		self::assertCount(1, $sites);
		self::assertSame('attrContainerForm', $sites[0]->getFormName());
		self::assertSame(
			[
				[ControlReference::KIND_CONTAINER, 'question', '', 2],
				[ControlReference::KIND_NAME_ATTR, 'text', 'question', 3],
				[ControlReference::KIND_NAME_ATTR, 'afterFieldset', '', 5],
			],
			$this->tuples($sites[0]),
		);
	}

	public function testDynamicArgumentsYieldNullNamesAndSkipOnlyTheDynamicContainerBody(): void
	{
		$sites = $this->sitesFor('dynamic.latte');

		self::assertCount(1, $sites);
		self::assertNull($sites[0]->getFormName());
		self::assertSame(1, $sites[0]->getLine());
		self::assertSame(
			[
				[ControlReference::KIND_INPUT, null, '', 2],
				[ControlReference::KIND_INPUT, 'staticControl', '', 3],
				[ControlReference::KIND_CONTAINER, null, '', 4],
				[ControlReference::KIND_INPUT, 'afterDynamicContainer', '', 7],
			],
			$this->tuples($sites[0]),
		);
	}

	public function testReferencesOutsideAnyFormScopeAreDropped(): void
	{
		$sites = $this->sitesFor('outside-form.latte');

		self::assertCount(1, $sites);
		self::assertSame('realForm', $sites[0]->getFormName());
		self::assertSame(4, $sites[0]->getLine());
		self::assertSame(
			[[ControlReference::KIND_INPUT, 'realControl', '', 5]],
			$this->tuples($sites[0]),
		);
	}

	public function testNestedFormScopesAttachReferencesToTheInnermostOne(): void
	{
		$sites = $this->sitesFor('nested-forms.latte');

		self::assertCount(2, $sites);
		self::assertSame('outerForm', $sites[0]->getFormName());
		self::assertSame(1, $sites[0]->getLine());
		self::assertSame(
			[
				[ControlReference::KIND_INPUT, 'outerControl', '', 2],
				[ControlReference::KIND_INPUT, 'outerAfter', '', 6],
			],
			$this->tuples($sites[0]),
		);
		self::assertSame('innerForm', $sites[1]->getFormName());
		self::assertSame(3, $sites[1]->getLine());
		self::assertSame(
			[[ControlReference::KIND_INPUT, 'innerControl', '', 4]],
			$this->tuples($sites[1]),
		);
	}

	public function testFormContextOpensItsOwnScope(): void
	{
		$sites = $this->sitesFor('form-context.latte');

		self::assertCount(2, $sites);
		self::assertSame('outerCtxForm', $sites[0]->getFormName());
		self::assertSame(
			[
				[ControlReference::KIND_INPUT, 'outerCtxControl', '', 2],
				[ControlReference::KIND_INPUT, 'backOnOuter', '', 6],
			],
			$this->tuples($sites[0]),
		);
		self::assertSame('otherForm', $sites[1]->getFormName());
		self::assertSame(3, $sites[1]->getLine());
		self::assertSame(
			[[ControlReference::KIND_INPUT, 'otherControl', '', 4]],
			$this->tuples($sites[1]),
		);
	}

	public function testControlPartsAreDroppedWhileDashedNamesStayRaw(): void
	{
		$sites = $this->sitesFor('parts-and-paths.latte');

		self::assertCount(1, $sites);
		self::assertSame(
			[
				[ControlReference::KIND_INPUT, 'radios', '', 2],
				[ControlReference::KIND_LABEL, 'radios', '', 3],
				[ControlReference::KIND_INPUT, 'address-street', '', 4],
				[ControlReference::KIND_NAME_ATTR, 'address-city', '', 5],
				[ControlReference::KIND_CONTAINER, 'nested-box', '', 6],
				[ControlReference::KIND_INPUT, 'inner', 'nested-box', 7],
			],
			$this->tuples($sites[0]),
		);
	}

	public function testMacroScopesSurviveTheCloseOfAnHtmlElementOpenedBeforeThem(): void
	{
		$sites = $this->sitesFor('interleaved-scopes.latte');

		self::assertCount(2, $sites);
		self::assertSame('interleavedForm', $sites[0]->getFormName());
		self::assertSame(
			[[ControlReference::KIND_INPUT, 'afterDiv', '', 2]],
			$this->tuples($sites[0]),
		);
		self::assertSame('containerInterleave', $sites[1]->getFormName());
		self::assertSame(
			[
				[ControlReference::KIND_CONTAINER, 'box', '', 5],
				[ControlReference::KIND_INPUT, 'inBox', 'box', 6],
			],
			$this->tuples($sites[1]),
		);
	}

	public function testInputErrorIsCollectedOnlyWhenItNamesAControl(): void
	{
		$sites = $this->sitesFor('input-error.latte');

		self::assertCount(1, $sites);
		self::assertSame('errorForm', $sites[0]->getFormName());
		self::assertSame(
			[
				[ControlReference::KIND_INPUT_ERROR, 'email', '', 2],
				[ControlReference::KIND_CONTAINER, 'address', '', 3],
				[ControlReference::KIND_INPUT_ERROR, 'street', 'address', 4],
				[ControlReference::KIND_INPUT_ERROR, 'nested-city', '', 6],
				[ControlReference::KIND_INPUT_ERROR, null, '', 7],
				[ControlReference::KIND_INPUT_ERROR, null, '', 8],
				[ControlReference::KIND_INPUT, 'email', '', 9],
			],
			$this->tuples($sites[0]),
		);
	}

	public function testBareClosingTagsCloseTheNearestOpenBody(): void
	{
		$sites = $this->sitesFor('bare-closer.latte');

		self::assertCount(1, $sites);
		self::assertSame('bareForm', $sites[0]->getFormName());
		self::assertSame(
			[
				[ControlReference::KIND_INPUT, 'insideIf', '', 3],
				[ControlReference::KIND_INPUT, 'afterIf', '', 5],
			],
			$this->tuples($sites[0]),
		);
	}

	public function testCommentsAndDoctypeAreNotElements(): void
	{
		$sites = $this->sitesFor('html-noise.latte');

		self::assertCount(1, $sites);
		self::assertSame('noiseForm', $sites[0]->getFormName());
		self::assertSame(3, $sites[0]->getLine());
		self::assertSame(
			[[ControlReference::KIND_NAME_ATTR, 'realControl', '', 5]],
			$this->tuples($sites[0]),
		);
	}

	// The cache is shared across runs and outlives any one shape of these classes, so the payload
	// must be plain arrays: a serialized object graph would be revived into whatever the classes
	// look like on the next run.
	public function testCachedSitesAreStoredAsPlainArraysAndRebuildAcrossInstances(): void
	{
		$root = $this->projectRoot();
		$relPath = self::FIXTURE_DIR . '/attr-form-uppercase.latte';
		$directory = sys_get_temp_dir() . '/latteforms-collector-test-' . getmypid() . '-' . uniqid('', true);
		$cache = new LatteAnalysisCache($directory, 'testv1');
		$universe = new LatteUniverse([$root . '/' . self::FIXTURE_DIR], $root);

		$sites = (new FormMacroCollector($universe, $cache))->sitesFor($relPath);
		self::assertCount(1, $sites);

		self::assertSame(
			[
				'sites' => [
					[
						'formName' => 'upperForm',
						'line' => 1,
						'references' => [
							[
								'kind' => ControlReference::KIND_NAME_ATTR,
								'name' => 'upperControl',
								'containerPath' => [],
								'line' => 2,
								'guarded' => false,
							],
						],
					],
				],
			],
			$cache->readContentAddressed(sha1(FileSystem::read($root . '/' . $relPath)), 'latteforms-macros-v2'),
		);

		$reloaded = (new FormMacroCollector($universe, new LatteAnalysisCache($directory, 'testv1')))
			->sitesFor($relPath);
		self::assertCount(1, $reloaded);
		self::assertSame('upperForm', $reloaded[0]->getFormName());
		self::assertSame(
			[[ControlReference::KIND_NAME_ATTR, 'upperControl', '', 2]],
			$this->tuples($reloaded[0]),
		);

		$cache->clear();
	}

	public function testUnknownTemplateYieldsNoSites(): void
	{
		self::assertSame([], $this->collector()->sitesFor(self::FIXTURE_DIR . '/does-not-exist.latte'));
	}

	public function testEveryFixtureIsAValidLatteTemplate(): void
	{
		$files = glob($this->projectRoot() . '/' . self::FIXTURE_DIR . '/*.latte');
		self::assertNotFalse($files);
		self::assertNotCount(0, $files);

		$compiler = new LatteCompiler();
		foreach ($files as $file) {
			$source = FileSystem::read($file);
			$result = $compiler->compile($source, 'LatteFormsFixture' . substr(sha1($source), 0, 8));
			self::assertNotNull($result->getPhpSource(), basename($file) . ' must compile');
		}
	}

	public function testExistenceGuardsMarkOnlyTheReferencesTheyName(): void
	{
		$sites = $this->sitesFor('guards.latte');

		self::assertCount(1, $sites);
		self::assertSame(
			[
				['before', '', true],
				['after', '', true],
				['block', '', true],
				['sibling', '', false],
				['wrapped', '', true],
				['notAComponentGuard', '', false],
				['opaque', '', true],
				['address', '', false],
				['street', 'address', true],
				['city', 'address', false],
				['plain', '', false],
			],
			$this->guardTuples($sites[0]),
		);
	}

	private function collector(): FormMacroCollector
	{
		$root = $this->projectRoot();

		return new FormMacroCollector(new LatteUniverse([$root . '/' . self::FIXTURE_DIR], $root));
	}

	/**
	 * @return list<FormSite>
	 */
	private function sitesFor(string $fixture): array
	{
		return $this->collector()->sitesFor(self::FIXTURE_DIR . '/' . $fixture);
	}

	/**
	 * @return list<array{string, string|null, string, int}>
	 */
	private function tuples(FormSite $site): array
	{
		$tuples = [];
		foreach ($site->getReferences() as $reference) {
			$tuples[] = [
				$reference->getKind(),
				$reference->getName(),
				implode('/', $reference->getContainerPath()),
				$reference->getLine(),
			];
		}

		return $tuples;
	}

	/**
	 * @return list<array{string|null, string, bool}>
	 */
	private function guardTuples(FormSite $site): array
	{
		$tuples = [];
		foreach ($site->getReferences() as $reference) {
			$tuples[] = [
				$reference->getName(),
				implode('/', $reference->getContainerPath()),
				$reference->isGuarded(),
			];
		}

		return $tuples;
	}

	private function projectRoot(): string
	{
		return dirname(__DIR__, 3);
	}

}
