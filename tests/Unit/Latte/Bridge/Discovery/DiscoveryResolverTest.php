<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Discovery;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryFact;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\TemplateDirectoryListing;
use OriPhpstan\Nette\Latte\Bridge\MutationFact;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\SetFileFact;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryFallbackDerivedControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryFallbackMissingControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryFallbackSharedControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryFileViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryFlatPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryInheritedBasePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryInheritedChildPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryInheritedParentPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryLegacyPathControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryLocatorPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryMethodlessPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryPropertyNameControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryPropertyNameNullControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoverySetFileActionPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoverySetFileBeforeRenderPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoverySetFileConditionalPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoverySetFileOpaquePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryTemplatesPathControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryTwoTemplateControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryUnassignedPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryVendorPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\FixtureFallbackControlBase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\FixtureFallbackMissingControlBase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\FixtureLegacyPathControlBase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\FixturePresenterTemplateLocator;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\FixturePropertyNameControlBase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\FixtureTemplatesPathControlBase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\HeirModule\DiscoveryInheritedHeirPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SubModule\DiscoveryDeepPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnrelatedServiceFixture;
use function array_keys;
use function array_map;
use function dirname;
use function realpath;

/**
 * @phpstan-import-type SetFileEntry from DiscoveryResolver
 */
final class DiscoveryResolverTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const MappingLoaderFile = __DIR__ . '/../Fixtures/presenter-mapping-container-loader.php';

	private const AssignedFormulas = [
		FixturePresenterTemplateLocator::class => 'samedir-single',
		FixtureLegacyPathControlBase::class => 'dirname-lcfirst',
		FixtureTemplatesPathControlBase::class => 'dirname-templates-lcfirst',
		FixtureFallbackControlBase::class => [
			'formula' => 'dirname-templates-lcfirst-fallback',
			'sharedFallback' => '@fixtureShared.latte',
		],
		FixtureFallbackMissingControlBase::class => [
			'formula' => 'dirname-templates-lcfirst-fallback',
			'sharedFallback' => '@fixtureMissing.latte',
		],
		FixturePropertyNameControlBase::class => [
			'formula' => 'dirname-property-lcfirst',
			'nameProperty' => 'layout',
		],
	];

	public function testVendorTwoCandidateProducesMappingDrivenPerViewCandidates(): void
	{
		$discovery = $this->discoveryFor(DiscoveryVendorPresenter::class);

		self::assertSame(['default', 'detail'], array_keys($discovery->getViewCandidates()));
		self::assertSame(
			[
				['App/templates/DiscoveryVendor/default.latte', true, true, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoveryVendor.default.latte', false, false, CandidatePath::KIND_FORMULA],
			],
			self::tuples($discovery->getViewCandidates()['default']),
		);
		self::assertSame(
			[
				['App/templates/DiscoveryVendor/detail.latte', false, false, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoveryVendor.detail.latte', false, false, CandidatePath::KIND_FORMULA],
			],
			self::tuples($discovery->getViewCandidates()['detail']),
		);
		self::assertSame([], $discovery->getOpaques());
	}

	public function testVendorLayoutWalkAscendsDirectoriesPerModuleDepth(): void
	{
		$discovery = $this->discoveryFor(DiscoveryVendorPresenter::class);

		self::assertSame(
			[
				['App/templates/DiscoveryVendor/@layout.latte', false, false, CandidatePath::KIND_LAYOUT],
				['App/templates/DiscoveryVendor.@layout.latte', false, false, CandidatePath::KIND_LAYOUT],
				['App/templates/@layout.latte', false, false, CandidatePath::KIND_LAYOUT],
				['templates/@layout.latte', false, false, CandidatePath::KIND_LAYOUT],
			],
			self::tuples($discovery->getLayoutCandidates()),
		);
	}

	public function testDirnameAscentBranchAndDeepModuleLayoutWalk(): void
	{
		$discovery = $this->discoveryFor(DiscoveryDeepPresenter::class);

		// App/SubModule has no templates/ subdir, so the vendor formula ascends to App/.
		self::assertSame(
			[
				['App/templates/DiscoveryDeep/detail.latte', false, false, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoveryDeep.detail.latte', false, false, CandidatePath::KIND_FORMULA],
			],
			self::tuples($discovery->getViewCandidates()['detail']),
		);

		// Two module parts (Fixture:Sub) walk one directory higher than a single-part module,
		// escaping the project root into an absolute path.
		self::assertSame(
			[
				['App/templates/DiscoveryDeep/@layout.latte', false, false, CandidatePath::KIND_LAYOUT],
				['App/templates/DiscoveryDeep.@layout.latte', false, false, CandidatePath::KIND_LAYOUT],
				['App/templates/@layout.latte', false, false, CandidatePath::KIND_LAYOUT],
				['templates/@layout.latte', false, false, CandidatePath::KIND_LAYOUT],
				[dirname(self::fixturesRoot()) . '/templates/@layout.latte', false, false, CandidatePath::KIND_LAYOUT],
			],
			self::tuples($discovery->getLayoutCandidates()),
		);
	}

	public function testSamedirSingleAssignedByTraitIdentityProducesSingleCandidate(): void
	{
		$discovery = $this->discoveryFor(DiscoveryLocatorPresenter::class);

		self::assertSame(
			[
				['App/DiscoveryLocator.default.latte', true, true, CandidatePath::KIND_FORMULA],
			],
			self::tuples($discovery->getViewCandidates()['default']),
		);
		self::assertSame([], $discovery->getOpaques());
	}

	public function testTraitDeclaredOverrideResolvesToTheTraitIdentityNotTheUsingClass(): void
	{
		// Assigning by the using class must NOT match: the resolved identity is the trait's FQCN.
		$discovery = $this->discoveryFor(
			DiscoveryLocatorPresenter::class,
			self::MappingLoaderFile,
			[DiscoveryLocatorPresenter::class => 'samedir-single'],
		);

		self::assertSame(['default' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString(FixturePresenterTemplateLocator::class, $discovery->getOpaques()[0]['reason']);
	}

	public function testDirnameLcfirstConventionOnControlDescendant(): void
	{
		$discovery = $this->discoveryFor(DiscoveryLegacyPathControl::class);

		self::assertSame(
			[
				'' => [
					['App/discoveryLegacyPathControl.latte', false, true, CandidatePath::KIND_CONVENTION],
				],
			],
			['' => self::tuples($discovery->getViewCandidates()[''])],
		);
		self::assertSame([], $discovery->getLayoutCandidates());
		self::assertSame([], $discovery->getOpaques());
	}

	public function testDirnameTemplatesLcfirstConventionOnControlDescendant(): void
	{
		$discovery = $this->discoveryFor(DiscoveryTemplatesPathControl::class);

		self::assertSame(
			[
				['App/templates/discoveryTemplatesPathControl.latte', false, true, CandidatePath::KIND_CONVENTION],
			],
			self::tuples($discovery->getViewCandidates()['']),
		);
	}

	public function testFallbackFormulaChoosesTheSharedFallbackWhenTheDerivedPathIsMissing(): void
	{
		$discovery = $this->discoveryFor(DiscoveryFallbackSharedControl::class);

		self::assertSame(
			[
				['App/templates/discoveryFallbackSharedControl.latte', false, false, CandidatePath::KIND_CONVENTION],
				['App/templates/@fixtureShared.latte', true, true, CandidatePath::KIND_CONVENTION],
			],
			self::tuples($discovery->getViewCandidates()['']),
		);
		self::assertSame([], $discovery->getOpaques());

		$root = self::fixturesRoot();
		self::assertSame(
			[
				[
					'path' => $root . '/App/templates/@fixtureShared.latte',
					'kind' => DiscoveryFact::PROBE_FILE,
					'result' => true,
				],
				[
					'path' => $root . '/App/templates/discoveryFallbackSharedControl.latte',
					'kind' => DiscoveryFact::PROBE_FILE,
					'result' => false,
				],
			],
			$discovery->getExistenceSet(),
		);
	}

	public function testFallbackFormulaChoosesTheDerivedPathWhenItExists(): void
	{
		$discovery = $this->discoveryFor(DiscoveryFallbackDerivedControl::class);

		self::assertSame(
			[
				['App/templates/discoveryFallbackDerivedControl.latte', true, true, CandidatePath::KIND_CONVENTION],
				['App/templates/@fixtureShared.latte', true, false, CandidatePath::KIND_CONVENTION],
			],
			self::tuples($discovery->getViewCandidates()['']),
		);
	}

	public function testFallbackFormulaDefaultsToTheDerivedPathWhenNeitherExists(): void
	{
		$discovery = $this->discoveryFor(DiscoveryFallbackMissingControl::class);

		self::assertSame(
			[
				['App/templates/discoveryFallbackMissingControl.latte', false, true, CandidatePath::KIND_CONVENTION],
				['App/templates/@fixtureMissing.latte', false, false, CandidatePath::KIND_CONVENTION],
			],
			self::tuples($discovery->getViewCandidates()['']),
		);
	}

	public function testFallbackFormulaAssignedWithoutASharedFallbackYieldsOpaque(): void
	{
		$discovery = $this->discoveryFor(
			DiscoveryFallbackSharedControl::class,
			self::MappingLoaderFile,
			[FixtureFallbackControlBase::class => 'dirname-templates-lcfirst-fallback'],
		);

		self::assertSame(['' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString('sharedFallback', $discovery->getOpaques()[0]['reason']);
	}

	public function testSharedFallbackOnANonFallbackFormulaYieldsOpaque(): void
	{
		$discovery = $this->discoveryFor(
			DiscoveryTemplatesPathControl::class,
			self::MappingLoaderFile,
			[
				FixtureTemplatesPathControlBase::class => [
					'formula' => 'dirname-templates-lcfirst',
					'sharedFallback' => '@fixtureShared.latte',
				],
			],
		);

		self::assertSame(['' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString('does not take a sharedFallback', $discovery->getOpaques()[0]['reason']);
	}

	public function testDirnamePropertyLcfirstNamesTheTemplateAfterThePropertyDefault(): void
	{
		$discovery = $this->discoveryFor(DiscoveryPropertyNameControl::class);

		self::assertSame(
			[
				['App/default.latte', false, true, CandidatePath::KIND_CONVENTION],
			],
			self::tuples($discovery->getViewCandidates()['']),
		);
		self::assertSame([], $discovery->getOpaques());
	}

	// The entry class overrides the inherited default to null while the convention method stays
	// declared by the base: reading the DECLARING class would name this template default.latte.
	public function testTheNamePropertyIsResolvedOffTheEntryClassNotTheDeclaringOne(): void
	{
		$discovery = $this->discoveryFor(DiscoveryPropertyNameNullControl::class);

		self::assertSame(
			[
				['App/app.latte', false, true, CandidatePath::KIND_CONVENTION],
			],
			self::tuples($discovery->getViewCandidates()['']),
		);
		self::assertSame([], $discovery->getOpaques());
	}

	public function testANamePropertyAbsentFromTheEntryClassYieldsOpaque(): void
	{
		$discovery = $this->discoveryFor(
			DiscoveryPropertyNameControl::class,
			self::MappingLoaderFile,
			[
				FixturePropertyNameControlBase::class => [
					'formula' => 'dirname-property-lcfirst',
					'nameProperty' => 'noSuchLayout',
				],
			],
		);

		self::assertSame(['' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString('noSuchLayout', $discovery->getOpaques()[0]['reason']);
	}

	// A typed property with no default reads as null through BetterReflection's
	// getDefaultProperties(), where native reflection omits it - so key presence cannot tell it
	// apart from an explicit null default, which is the value that DERIVES a name.
	public function testANamePropertyDeclaredWithoutADefaultValueYieldsOpaque(): void
	{
		$discovery = $this->discoveryFor(
			DiscoveryPropertyNameControl::class,
			self::MappingLoaderFile,
			[
				FixturePropertyNameControlBase::class => [
					'formula' => 'dirname-property-lcfirst',
					'nameProperty' => 'layoutFile',
				],
			],
		);

		self::assertSame(['' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString(
			'is declared without a default value',
			$discovery->getOpaques()[0]['reason'],
		);
	}

	public function testANamePropertyWhoseDefaultIsNeitherStringNorNullYieldsOpaque(): void
	{
		$discovery = $this->discoveryFor(
			DiscoveryPropertyNameControl::class,
			self::MappingLoaderFile,
			[
				FixturePropertyNameControlBase::class => [
					'formula' => 'dirname-property-lcfirst',
					'nameProperty' => 'layoutNames',
				],
			],
		);

		self::assertSame(['' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString(
			'neither a string nor null',
			$discovery->getOpaques()[0]['reason'],
		);
	}

	public function testANamePropertyOnAFormulaThatDoesNotTakeOneYieldsOpaque(): void
	{
		$discovery = $this->discoveryFor(
			DiscoveryTemplatesPathControl::class,
			self::MappingLoaderFile,
			[
				FixtureTemplatesPathControlBase::class => [
					'formula' => 'dirname-templates-lcfirst',
					'nameProperty' => 'file',
				],
			],
		);

		self::assertSame(['' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString('does not take a nameProperty', $discovery->getOpaques()[0]['reason']);
	}

	public function testANamePropertyOnAViewChannelFormulaYieldsOpaque(): void
	{
		$discovery = $this->discoveryFor(
			DiscoveryLocatorPresenter::class,
			self::MappingLoaderFile,
			[
				FixturePresenterTemplateLocator::class => [
					'formula' => 'samedir-single',
					'nameProperty' => 'layout',
				],
			],
		);

		self::assertSame(['default' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString('does not take a nameProperty', $discovery->getOpaques()[0]['reason']);
	}

	public function testThePropertyFormulaAssignedWithoutANamePropertyYieldsOpaque(): void
	{
		$discovery = $this->discoveryFor(
			DiscoveryPropertyNameControl::class,
			self::MappingLoaderFile,
			[FixturePropertyNameControlBase::class => 'dirname-property-lcfirst'],
		);

		self::assertSame(['' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString('requires a nameProperty', $discovery->getOpaques()[0]['reason']);
	}

	public function testAnUnknownAssignmentKeyStillFailsLoudly(): void
	{
		$discovery = $this->discoveryFor(
			DiscoveryPropertyNameControl::class,
			self::MappingLoaderFile,
			[
				FixturePropertyNameControlBase::class => [
					'formula' => 'dirname-property-lcfirst',
					'namedProperty' => 'layout',
				],
			],
		);

		self::assertSame(['' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString('invalid discovery formula assignment', $discovery->getOpaques()[0]['reason']);
	}

	public function testUnassignedOverrideYieldsOpaqueWithoutFormulaCandidates(): void
	{
		$discovery = $this->discoveryFor(DiscoveryUnassignedPresenter::class);

		self::assertSame(['default' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString(DiscoveryUnassignedPresenter::class, $discovery->getOpaques()[0]['reason']);
		self::assertNull($discovery->getOpaques()[0]['line']);

		// The layout channel is independently vendor-declared, so it stays resolved.
		self::assertCount(4, $discovery->getLayoutCandidates());
	}

	public function testUnknownFormulaNameYieldsOpaque(): void
	{
		$discovery = $this->discoveryFor(
			DiscoveryLocatorPresenter::class,
			self::MappingLoaderFile,
			[FixturePresenterTemplateLocator::class => 'no-such-formula'],
		);

		self::assertSame(['default' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString('no-such-formula', $discovery->getOpaques()[0]['reason']);
	}

	public function testUnmappedPresenterIsOpaqueNeverGuessed(): void
	{
		$discovery = $this->discoveryFor(DiscoveryVendorPresenter::class, null);

		self::assertSame(['default' => [], 'detail' => []], $discovery->getViewCandidates());
		self::assertSame([], $discovery->getLayoutCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertStringContainsString(DiscoveryVendorPresenter::class, $discovery->getOpaques()[0]['reason']);
		self::assertSame([], $discovery->getExistenceSet());
	}

	public function testALoaderReturningABareContainerResolvesTheSameMapping(): void
	{
		self::assertEquals(
			$this->discoveryFor(DiscoverySetFileActionPresenter::class),
			$this->discoveryFor(
				DiscoverySetFileActionPresenter::class,
				__DIR__ . '/../Fixtures/presenter-mapping-bare-container-loader.php',
			),
		);
	}

	public function testEffectiveActionSetFileSuppressesFormulaCandidatesForThatViewOnly(): void
	{
		$discovery = $this->discoveryFor(DiscoverySetFileActionPresenter::class);

		self::assertSame(
			[
				['App/custom-foo.latte', false, true, CandidatePath::KIND_SET_FILE],
			],
			self::tuples($discovery->getViewCandidates()['foo']),
		);
		self::assertSame(
			[
				['App/templates/DiscoverySetFileAction/bar.latte', false, false, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoverySetFileAction.bar.latte', false, false, CandidatePath::KIND_FORMULA],
			],
			self::tuples($discovery->getViewCandidates()['bar']),
		);
	}

	public function testTwoRenderMethodsOfAControlEachChooseTheirOwnSetFile(): void
	{
		$candidates = $this->resolveControlEntries([
			self::controlSetFileEntry('discoveryTwoTemplatePrimary.latte', 10, 'render'),
			self::controlSetFileEntry('discoveryTwoTemplateTable.latte', 20, 'renderShared'),
		]);

		self::assertSame(
			[
				['App/discoveryTwoTemplatePrimary.latte', true, true, CandidatePath::KIND_SET_FILE],
				['App/discoveryTwoTemplateTable.latte', true, true, CandidatePath::KIND_SET_FILE],
			],
			self::tuples($candidates),
		);
	}

	public function testSameMethodSetFilesOfAControlChooseTheLastWrite(): void
	{
		$candidates = $this->resolveControlEntries([
			self::controlSetFileEntry('discoveryTwoTemplatePrimary.latte', 10, 'render'),
			self::controlSetFileEntry('discoveryTwoTemplateTable.latte', 20, 'render'),
		]);

		self::assertSame(
			[
				['App/discoveryTwoTemplatePrimary.latte', true, false, CandidatePath::KIND_SET_FILE],
				['App/discoveryTwoTemplateTable.latte', true, true, CandidatePath::KIND_SET_FILE],
			],
			self::tuples($candidates),
		);
	}

	public function testTheWalkChoosesEveryRenderMethodsTemplateOfAControl(): void
	{
		$discovery = $this->discoveryFor(DiscoveryTwoTemplateControl::class);

		self::assertSame(
			[
				['App/discoveryTwoTemplatePrimary.latte', true, true, CandidatePath::KIND_SET_FILE],
				['App/discoveryTwoTemplateTable.latte', true, true, CandidatePath::KIND_SET_FILE],
			],
			self::tuples($discovery->getViewCandidates()['']),
		);
	}

	public function testEffectiveBeforeRenderSetFileSuppressesClassWideButNotLayout(): void
	{
		$discovery = $this->discoveryFor(DiscoverySetFileBeforeRenderPresenter::class);

		self::assertSame(
			[
				['App/custom-shared.latte', false, true, CandidatePath::KIND_SET_FILE],
			],
			self::tuples($discovery->getViewCandidates()['default']),
		);
		self::assertCount(4, $discovery->getLayoutCandidates());
	}

	public function testConditionalSetFileDoesNotSuppressAndUnionsWithFormulaCandidates(): void
	{
		$discovery = $this->discoveryFor(DiscoverySetFileConditionalPresenter::class);

		self::assertSame(
			[
				['App/custom-conditional.latte', false, false, CandidatePath::KIND_SET_FILE],
				['App/templates/DiscoverySetFileConditional/foo.latte', false, false, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoverySetFileConditional.foo.latte', false, false, CandidatePath::KIND_FORMULA],
			],
			self::tuples($discovery->getViewCandidates()['foo']),
		);
	}

	public function testOpaqueSetFileSuppressesFormulaAndRecordsOpaque(): void
	{
		$discovery = $this->discoveryFor(DiscoverySetFileOpaquePresenter::class);

		self::assertSame(['default' => []], $discovery->getViewCandidates());
		self::assertCount(1, $discovery->getOpaques());
		self::assertNotNull($discovery->getOpaques()[0]['line']);
	}

	public function testTemplateFilesSeedViewsWithoutAnyDispatchMethod(): void
	{
		$discovery = $this->discoveryFor(DiscoveryMethodlessPresenter::class);

		self::assertSame(['add'], array_keys($discovery->getViewCandidates()));
		self::assertSame(
			[
				['App/templates/DiscoveryMethodless/add.latte', true, true, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoveryMethodless.add.latte', false, false, CandidatePath::KIND_FORMULA],
			],
			self::tuples($discovery->getViewCandidates()['add']),
		);
		self::assertSame([], $discovery->getOpaques());
	}

	public function testFileDerivedViewsUnionWithMethodDerivedOnesAndObeyTheVendorViewGrammar(): void
	{
		$discovery = $this->discoveryFor(DiscoveryFileViewPresenter::class);

		// 'default' is method-derived, '404' and 'extra' are file-derived; '@layout.latte' and
		// 'not-a-view.latte' are no view names the vendor would ever dispatch to. The numeric view
		// arrives as an INT array key (PHP's own coercion) - DiscoveryRecords casts it back before
		// the store, whose record validator takes a string or null view only.
		self::assertSame([404, 'default', 'extra'], array_keys($discovery->getViewCandidates()));
		self::assertSame(
			[
				['App/templates/DiscoveryFileView/404.latte', true, true, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoveryFileView.404.latte', false, false, CandidatePath::KIND_FORMULA],
			],
			self::tuples($discovery->getViewCandidates()['404']),
		);
		self::assertSame(
			[
				['App/templates/DiscoveryFileView/extra.latte', true, true, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoveryFileView.extra.latte', false, false, CandidatePath::KIND_FORMULA],
			],
			self::tuples($discovery->getViewCandidates()['extra']),
		);
	}

	public function testTheFlatSecondCandidateDirectoryIsEnumeratedToo(): void
	{
		$discovery = $this->discoveryFor(DiscoveryFlatPresenter::class);

		self::assertSame(['detail'], array_keys($discovery->getViewCandidates()));
		self::assertSame(
			[
				['App/templates/DiscoveryFlat/detail.latte', false, false, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoveryFlat.detail.latte', true, true, CandidatePath::KIND_FORMULA],
			],
			self::tuples($discovery->getViewCandidates()['detail']),
		);
	}

	public function testSamedirSingleFormulaIsInvertedByItsOwnPathAlgebra(): void
	{
		$discovery = $this->discoveryFor(DiscoveryLocatorPresenter::class);

		self::assertSame(['default', 'extra'], array_keys($discovery->getViewCandidates()));
		self::assertSame(
			[
				['App/DiscoveryLocator.extra.latte', true, true, CandidatePath::KIND_FORMULA],
			],
			self::tuples($discovery->getViewCandidates()['extra']),
		);
	}

	public function testAProvenClassWideSetFileKeepsFileEvidenceOutOfTheViewSet(): void
	{
		// templates/DiscoverySetFileBeforeRender/other.latte exists, but beforeRender() overwrites
		// the template file for EVERY view before it is resolved - the formula's own output never
		// reaches the renderer, so a file sitting at its path proves nothing.
		$discovery = $this->discoveryFor(DiscoverySetFileBeforeRenderPresenter::class);

		self::assertSame(['default'], array_keys($discovery->getViewCandidates()));
	}

	public function testInheritedRenderHookResolvesInTheConcreteChildsOwnDirectoryAndName(): void
	{
		$child = $this->discoveryFor(DiscoveryInheritedChildPresenter::class);

		self::assertSame(
			[
				['App/templates/DiscoveryInheritedChild/shared.latte', true, true, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoveryInheritedChild.shared.latte', false, false, CandidatePath::KIND_FORMULA],
			],
			self::tuples($child->getViewCandidates()['shared']),
		);

		// The abstract declarer resolves for its OWN name, and nothing exists there: the child's
		// template is never attributed to the class that declares the hook.
		$base = $this->discoveryFor(DiscoveryInheritedBasePresenter::class);

		self::assertSame(
			[
				['App/templates/DiscoveryInheritedBase/shared.latte', false, false, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoveryInheritedBase.shared.latte', false, false, CandidatePath::KIND_FORMULA],
			],
			self::tuples($base->getViewCandidates()['shared']),
		);
	}

	public function testANonAbstractDeclarerAndItsHeirEachResolveAnIndependentCandidateSet(): void
	{
		$parent = $this->discoveryFor(DiscoveryInheritedParentPresenter::class);

		self::assertSame(
			[
				['App/templates/DiscoveryInheritedParent/shared.latte', true, true, CandidatePath::KIND_FORMULA],
				['App/templates/DiscoveryInheritedParent.shared.latte', false, false, CandidatePath::KIND_FORMULA],
			],
			self::tuples($parent->getViewCandidates()['shared']),
		);

		// Same single declaring method, another directory AND another presenter segment.
		$heir = $this->discoveryFor(DiscoveryInheritedHeirPresenter::class);

		self::assertSame(
			[
				[
					'App/HeirModule/templates/DiscoveryInheritedHeir/shared.latte',
					true,
					true,
					CandidatePath::KIND_FORMULA,
				],
				[
					'App/HeirModule/templates/DiscoveryInheritedHeir.shared.latte',
					false,
					false,
					CandidatePath::KIND_FORMULA,
				],
			],
			self::tuples($heir->getViewCandidates()['shared']),
		);
	}

	public function testEveryProbeIsRecordedInTheExistenceSet(): void
	{
		$discovery = $this->discoveryFor(DiscoveryVendorPresenter::class);

		$root = self::fixturesRoot();
		$file = DiscoveryFact::PROBE_FILE;
		$listing = DiscoveryFact::PROBE_LISTING;
		self::assertSame(
			[
				['path' => $root . '/App/templates', 'kind' => DiscoveryFact::PROBE_DIR, 'result' => true],
				[
					'path' => $root . '/App/templates',
					'kind' => $listing,
					'result' => TemplateDirectoryListing::digest($root . '/App/templates'),
				],
				['path' => $root . '/App/templates/@layout.latte', 'kind' => $file, 'result' => false],
				[
					'path' => $root . '/App/templates/DiscoveryVendor',
					'kind' => $listing,
					'result' => TemplateDirectoryListing::digest($root . '/App/templates/DiscoveryVendor'),
				],
				['path' => $root . '/App/templates/DiscoveryVendor.@layout.latte', 'kind' => $file, 'result' => false],
				['path' => $root . '/App/templates/DiscoveryVendor.default.latte', 'kind' => $file, 'result' => false],
				['path' => $root . '/App/templates/DiscoveryVendor.detail.latte', 'kind' => $file, 'result' => false],
				['path' => $root . '/App/templates/DiscoveryVendor/@layout.latte', 'kind' => $file, 'result' => false],
				['path' => $root . '/App/templates/DiscoveryVendor/default.latte', 'kind' => $file, 'result' => true],
				['path' => $root . '/App/templates/DiscoveryVendor/detail.latte', 'kind' => $file, 'result' => false],
				['path' => $root . '/templates/@layout.latte', 'kind' => $file, 'result' => false],
			],
			$discovery->getExistenceSet(),
		);
	}

	public function testNonQualifyingClassHasNullDiscovery(): void
	{
		$facts = $this->walk(self::MappingLoaderFile, self::AssignedFormulas)
			->factsFor(UnrelatedServiceFixture::class);

		self::assertNull($facts->getDiscovery());
	}

	/**
	 * @param list<SetFileEntry> $entries
	 * @return list<CandidatePath>
	 */
	private function resolveControlEntries(array $entries): array
	{
		$resolver = new DiscoveryResolver(self::MappingLoaderFile, self::AssignedFormulas, self::fixturesRoot());
		$discovery = $resolver->resolve(
			self::createReflectionProvider()->getClass(DiscoveryTwoTemplateControl::class),
			false,
			[],
			$entries,
		);

		return $discovery->getViewCandidates()[''];
	}

	/**
	 * @return SetFileEntry
	 */
	private static function controlSetFileEntry(string $file, int $line, string $method): array
	{
		return [
			'kind' => SetFileFact::KIND_LITERAL,
			'path' => self::fixturesRoot() . '/App/' . $file,
			'certainty' => Certainty::HAPPENS,
			'line' => $line,
			'phase' => MutationFact::PHASE_RENDER,
			'effectiveness' => MutationFact::EFFECTIVE_YES,
			'scope' => 'open',
			'scopeView' => null,
			'conventionMethod' => null,
			'method' => $method,
		];
	}

	/**
	 * @param array<string, string|array<string, string>> $formulas
	 */
	private function discoveryFor(
		string $className,
		?string $mappingLoaderFile = self::MappingLoaderFile,
		array $formulas = self::AssignedFormulas
	): DiscoveryFact
	{
		$facts = $this->walk($mappingLoaderFile, $formulas)->factsFor($className);
		$discovery = $facts->getDiscovery();
		self::assertNotNull($discovery);

		return $discovery;
	}

	/**
	 * @param array<string, string|array<string, string>> $formulas
	 */
	private function walk(?string $mappingLoaderFile, array $formulas): PhpRenderWalk
	{
		$appRoot = realpath(__DIR__ . '/../Fixtures/App');
		self::assertNotFalse($appRoot);

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		return new PhpRenderWalk(
			self::createReflectionProvider(),
			$parser,
			[$appRoot],
			new TemplateFactoryDefaultResolver(null),
			new DiscoveryResolver($mappingLoaderFile, $formulas, self::fixturesRoot()),
		);
	}

	private static function fixturesRoot(): string
	{
		$root = realpath(__DIR__ . '/../Fixtures');
		self::assertNotFalse($root);

		return $root;
	}

	/**
	 * @param list<CandidatePath> $candidates
	 * @return list<array{string, bool, bool, string}>
	 */
	private static function tuples(array $candidates): array
	{
		return array_map(
			static fn (CandidatePath $candidate): array => [
				$candidate->getPath(),
				$candidate->exists(),
				$candidate->isChosen(),
				$candidate->getKind(),
			],
			$candidates,
		);
	}

}
