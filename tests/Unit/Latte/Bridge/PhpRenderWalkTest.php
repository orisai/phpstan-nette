<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge;

use Nette\Application\UI\Template as UiTemplate;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use Nette\Bridges\ApplicationLatte\Template as BridgeTemplate;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\MutationFact;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\SetFileFact;
use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\BoundaryChildPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\BoundaryMiddleFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ConditionalAfterUnconditionalPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ConditionalCertaintyPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ControlArgumentDisagreeingControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ControlArgumentExplicitThisControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ControlArgumentForeignControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ControlArgumentForeignReceiverControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ControlArgumentNoneChainLeaf;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ControlArgumentOverrideFactoryControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ControlArgumentSelfChainLeaf;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ControlArgumentStandaloneFactory;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ControlSetFilePhaseFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\CycleHelperTrait;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\CycleTerminationPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DynamicViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\FormCallbackViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\GenericBaseControlDescendantFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\GenericBindingBareExtendsFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\GenericBindingUnresolvableArgFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\GetTemplateOnlySurfaceFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\HelperConditionalityPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\HelperFollowingPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\HelperSetterTrait;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LegacyBaseControlDescendantFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LexicalCallEntryPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LexicalScopeBaseFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LexicalScopeEntryPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LexicalScopeMiddleFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LexicalTraitTargetTrait;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LifecycleViewsPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LiteralAssignmentPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LiteralAssignmentTarget;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\LoopBodyCertaintyPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\MailerLikeFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\MethodReturnRhsEntity;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\MethodReturnRhsPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\NamespacedBaseControlDescendantFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\NonTemplateIshGetTemplateFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\NonTemplateIshOwnCreateTemplatePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\NonTemplateIshOwnGetTemplatePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\OverwriteOrderViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ParentCreateTemplateConventionFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\PlainVendorControlFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\PropertyRhsPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\PropertyRhsSource;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\PublicShutdownHelperViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ReassignmentPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\RenderSiteLiteralPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\RenderSiteNoArgPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\RenderSiteNonLiteralArgPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\RenderSiteUnrelatedReceiverPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SamplePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SetFileConditionalPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SetFileConventionPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SetFileOpaquePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SetFilePhasePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SetFileProvenancedLocalPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SetFileReceiverFilterPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\ShutdownOnlyViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassAgreementFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassChannelTargetFour;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassChannelTargetOne;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassChannelTargetThree;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassChannelTargetTwo;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassConditionalCreateTemplatePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassConflictFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassCreateTemplateArgPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassFactoryStaticPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassMultiCandidatePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassNewChannelPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassPhpdocOverridePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassPhpdocUnionTypePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateClassRepeatedObservationPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateFactoryProvenanceReceiverPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TemplateIshBuilderReceiverFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TrustedStaticCreateTemplateReceiverPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\TwoPhaseHelperViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnionAcrossBranchesPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnrelatedAncestorFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnrelatedCreateTemplateReceiverAncestorFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnrelatedCreateTemplateReceiverFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnrelatedGetTemplateReceiverPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnrelatedNameOnlyPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnrelatedProvenanceReceiverPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnrelatedServiceFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnrelatedStaticCreateTemplateReceiverPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\UnresolvableRhsPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\VendorSurfaceDescendantFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\FixtureFactoryDefaultTemplate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Outside\BoundaryOutsideFixture;
use function array_column;
use function array_key_exists;
use function array_keys;
use function array_map;
use function dirname;
use function explode;
use function realpath;
use function sort;

final class PhpRenderWalkTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const FactoryDefaultContainerLoaderFile = __DIR__ . '/Fixtures/factory-default-container-loader.php';

	private const FactoryDefaultContainerLoaderNoFactoryFile = __DIR__ . '/Fixtures/factory-default-container-loader-no-factory.php';

	private function walk(?string $containerLoaderFile = null): PhpRenderWalk
	{
		$appRoot = realpath(__DIR__ . '/Fixtures/App');
		self::assertNotFalse($appRoot);

		return new PhpRenderWalk(
			self::createReflectionProvider(),
			self::getRichParser(),
			[$appRoot],
			new TemplateFactoryDefaultResolver($containerLoaderFile),
			new DiscoveryResolver(null, [], dirname($appRoot)),
		);
	}

	private static function getRichParser(): Parser
	{
		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		return $parser;
	}

	public function testUnresolvableClassReturnsEmpty(): void
	{
		$facts = $this->walk()->factsFor('Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Fixtures\\App\\DoesNotExist');

		self::assertSame([], $facts->getReadSet());
		self::assertSame([], $facts->getAssignments());
		self::assertNull($facts->getTemplateClass());
	}

	public function testPresenterFixtureQualifies(): void
	{
		$facts = $this->walk()->factsFor(SamplePresenter::class);

		self::assertSame([$this->fileOf(SamplePresenter::class)], $facts->getReadSet());
	}

	public function testLegacySurfaceBaseControlDescendantQualifiesStructurally(): void
	{
		$facts = $this->walk()->factsFor(LegacyBaseControlDescendantFixture::class);

		self::assertSame([$this->fileOf(LegacyBaseControlDescendantFixture::class)], $facts->getReadSet());
	}

	public function testNamespacedSurfaceBaseControlDescendantQualifiesStructurally(): void
	{
		$facts = $this->walk()->factsFor(NamespacedBaseControlDescendantFixture::class);

		self::assertSame([$this->fileOf(NamespacedBaseControlDescendantFixture::class)], $facts->getReadSet());
		// A non-qualifying class yields the identical read-set but a null templateClass - only
		// the resolved surface discriminates.
		$templateClass = $facts->getTemplateClass();
		self::assertNotNull($templateClass);
		self::assertSame(BridgeTemplate::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_GENERIC_BINDING, $templateClass->getChannel());
	}

	public function testGenericSurfaceBaseControlDescendantQualifiesStructurally(): void
	{
		$facts = $this->walk()->factsFor(GenericBaseControlDescendantFixture::class);

		self::assertSame([$this->fileOf(GenericBaseControlDescendantFixture::class)], $facts->getReadSet());
	}

	// The StatusBar gap: a plain vendor-Control descendant with no app base, no *Presenter name
	// and no factory call must qualify purely via the vendor reflection surface.
	public function testPlainVendorControlDescendantQualifiesStructurallyWithZeroConfig(): void
	{
		$facts = $this->walk()->factsFor(PlainVendorControlFixture::class);

		self::assertSame([$this->fileOf(PlainVendorControlFixture::class)], $facts->getReadSet());
		self::assertArrayHasKey('statusText', $facts->getAssignments());
		self::assertCount(1, $facts->getRenderSites());
	}

	// Subsumes the deleted *Presenter suffix rule: a vendor-Presenter descendant with no
	// template-related override qualifies from the vendor surface alone.
	public function testVendorPresenterDescendantWithNoOverrideQualifiesStructurally(): void
	{
		$facts = $this->walk()->factsFor(VendorSurfaceDescendantFixture::class);

		self::assertSame([$this->fileOf(VendorSurfaceDescendantFixture::class)], $facts->getReadSet());
		self::assertArrayHasKey('fromVendorSurface', $facts->getAssignments());
	}

	// The other half of the suffix-rule subsumption: the name alone must not qualify, and a
	// non-Template-ish $template property must not be trusted as an origin.
	public function testPresenterClassNameSuffixAloneDoesNotQualify(): void
	{
		$facts = $this->walk()->factsFor(UnrelatedNameOnlyPresenter::class);

		self::assertSame([$this->fileOf(UnrelatedNameOnlyPresenter::class)], $facts->getReadSet());
		self::assertSame([], $facts->getAssignments());
		self::assertNull($facts->getTemplateClass());
	}

	// Decisive getTemplate() rung pin: the fixture's only surface is the native getTemplate()
	// return type, so both qualification and the resolved template class must come from it -
	// no property or createTemplate() rung can short-circuit.
	public function testGetTemplateOnlySurfaceQualifiesAndResolvesItsReturnType(): void
	{
		$facts = $this->walk()->factsFor(GetTemplateOnlySurfaceFixture::class);

		$templateClass = $facts->getTemplateClass();
		self::assertNotNull($templateClass);
		self::assertSame(DefaultTemplate::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_GENERIC_BINDING, $templateClass->getChannel());
		self::assertSame(Certainty::HAPPENS, $templateClass->getCertainty());
	}

	public function testNonTemplateIshGetTemplateReturnTypeDoesNotQualify(): void
	{
		$facts = $this->walk()->factsFor(NonTemplateIshGetTemplateFixture::class);

		self::assertSame([$this->fileOf(NonTemplateIshGetTemplateFixture::class)], $facts->getReadSet());
		self::assertNull($facts->getTemplateClass());
	}

	// getTemplate() as a trusted template origin: a local provenanced from the trusted call yields
	// assignment facts (structure-decided certainty, same as every other origin) and a render site.
	public function testGetTemplateProvenancedLocalYieldsAssignmentsAndARenderSite(): void
	{
		$facts = $this->walk()->factsFor(GetTemplateOnlySurfaceFixture::class);
		$assignments = $facts->getAssignments();

		self::assertArrayHasKey('heading', $assignments);
		self::assertSame('string', $assignments['heading']->getTypeString());
		self::assertSame(Certainty::HAPPENS, $assignments['heading']->getCertainty());

		self::assertArrayHasKey('subtitle', $assignments);
		self::assertSame(Certainty::MAYBE, $assignments['subtitle']->getCertainty());

		self::assertCount(1, $facts->getRenderSites());
	}

	// Negative-pin family extension for getTemplate: an unrelated class's own same-named static
	// getTemplate() returning void must not be trusted as an origin.
	public function testExplicitFqcnStaticGetTemplateIsNotTrusted(): void
	{
		$facts = $this->walk()->factsFor(UnrelatedGetTemplateReceiverPresenter::class);

		self::assertArrayNotHasKey('subject', $facts->getAssignments());
	}

	public function testOwnGetTemplateWithNonTemplateIshReturnTypeIsNotProvenanceTracked(): void
	{
		$facts = $this->walk()->factsFor(NonTemplateIshOwnGetTemplatePresenter::class);

		self::assertArrayNotHasKey('boom', $facts->getAssignments());
	}

	public function testCreateTemplateCallerQualifies(): void
	{
		$facts = $this->walk()->factsFor(MailerLikeFixture::class);

		self::assertSame([$this->fileOf(MailerLikeFixture::class)], $facts->getReadSet());
	}

	// THE CONTROL ARGUMENT. What createTemplate() is handed decides the factory's own $control (and
	// $presenter through it), and the rows below are the whole discrimination: the inherited vendor
	// body, the standalone factory call, the explicit argument in both directions, a delegation
	// chain resolved through app-level overrides, and the disagreement that voids the answer.
	public function testInheritedVendorCreateTemplatePassesTheComponentItself(): void
	{
		self::assertSame(
			PhpRenderFacts::CONTROL_SELF,
			$this->walk()->factsFor(PlainVendorControlFixture::class)->getCreateTemplateControl(),
		);
	}

	public function testStandaloneFactoryCallPassesNoControl(): void
	{
		self::assertSame(
			PhpRenderFacts::CONTROL_NONE,
			$this->walk()->factsFor(ControlArgumentStandaloneFactory::class)->getCreateTemplateControl(),
		);
	}

	// A COMPONENT that still passes no control: the override replaced the vendor body, so the
	// inherited rung must be off and only the walked call may speak.
	public function testComponentOverridingCreateTemplateWithAStandaloneCallPassesNoControl(): void
	{
		self::assertSame(
			PhpRenderFacts::CONTROL_NONE,
			$this->walk()->factsFor(ControlArgumentOverrideFactoryControl::class)->getCreateTemplateControl(),
		);
	}

	public function testExplicitThisArgumentIsTheComponentItself(): void
	{
		self::assertSame(
			PhpRenderFacts::CONTROL_SELF,
			$this->walk()->factsFor(ControlArgumentExplicitThisControl::class)->getCreateTemplateControl(),
		);
	}

	// An explicit null is the parameter default written out - the same no-control call.
	public function testExplicitNullArgumentPassesNoControl(): void
	{
		self::assertSame(
			PhpRenderFacts::CONTROL_NONE,
			$this->walk()->factsFor(TemplateClassCreateTemplateArgPresenter::class)->getCreateTemplateControl(),
		);
	}

	public function testForeignControlArgumentResolvesToNeitherShape(): void
	{
		self::assertSame(
			PhpRenderFacts::CONTROL_OTHER,
			$this->walk()->factsFor(ControlArgumentForeignControl::class)->getCreateTemplateControl(),
		);
	}

	// The SAME vendor body as the inherited rung, invoked on another component's instance: that
	// body passes its own $this, so the control is that other object and this renderer may claim
	// nothing. The receiver is the entire difference between this row and the inherited one.
	public function testVendorCreateTemplateOnAForeignReceiverIsNotThisComponent(): void
	{
		self::assertSame(
			PhpRenderFacts::CONTROL_OTHER,
			$this->walk()->factsFor(ControlArgumentForeignReceiverControl::class)->getCreateTemplateControl(),
		);
	}

	// Two app-level overrides deep, each delegating to its parent, before the vendor body answers -
	// the shape has to survive the chain rather than stop at the first override it meets.
	public function testDelegationChainResolvesToTheVendorBodyItEndsIn(): void
	{
		self::assertSame(
			PhpRenderFacts::CONTROL_SELF,
			$this->walk()->factsFor(ControlArgumentSelfChainLeaf::class)->getCreateTemplateControl(),
		);
	}

	// ... and the same chain ending in a base that bypasses the vendor body answers NONE, which is
	// the half that proves the chain is really followed rather than defaulted to the component rung.
	public function testDelegationChainEndingInAStandaloneCallPassesNoControl(): void
	{
		self::assertSame(
			PhpRenderFacts::CONTROL_NONE,
			$this->walk()->factsFor(ControlArgumentNoneChainLeaf::class)->getCreateTemplateControl(),
		);
	}

	// A class creating templates BOTH ways answers neither: which shape a given template came from
	// is not a per-class fact, so the per-class fact must refuse to pick.
	public function testDisagreeingCallSitesVoidTheAnswer(): void
	{
		self::assertSame(
			PhpRenderFacts::CONTROL_OTHER,
			$this->walk()->factsFor(ControlArgumentDisagreeingControl::class)->getCreateTemplateControl(),
		);
	}

	// A trusted createTemplate() on a FOREIGN service - app-local, but never a receiver this walk
	// enters, so its argument stays unknown. The silent verdict is reserved for the one receiver
	// whose body really is read: this very instance's own override.
	public function testForeignServiceCreateTemplateBodyIsNeverReadForItsArgument(): void
	{
		self::assertSame(
			PhpRenderFacts::CONTROL_OTHER,
			$this->walk()->factsFor(TemplateIshBuilderReceiverFixture::class)->getCreateTemplateControl(),
		);
	}

	// No createTemplate() path at all - not a component, and no call anywhere. Distinct from OTHER
	// on purpose: both deny the variables, but only one of them saw something.
	public function testNoCreateTemplatePathLeavesTheArgumentUnrecorded(): void
	{
		self::assertNull($this->walk()->factsFor(GetTemplateOnlySurfaceFixture::class)->getCreateTemplateControl());
	}

	// A receiver that is not a TemplateFactory but whose createTemplate() returns a real template
	// is trusted by the return-type boundary - both for qualification and provenance tracking.
	public function testTemplateIshReturningServiceReceiverCreateTemplateIsTrusted(): void
	{
		$facts = $this->walk()->factsFor(TemplateIshBuilderReceiverFixture::class);

		self::assertArrayHasKey('subject', $facts->getAssignments());
		self::assertSame('string', $facts->getAssignments()['subject']->getTypeString());
	}

	// The trust boundary is the receiver's createTemplate() return type - a coincidentally
	// same-named createTemplate() with a non-template return must not expand the walked set.
	public function testUnrelatedReceiverCreateTemplateDoesNotQualify(): void
	{
		$facts = $this->walk()->factsFor(UnrelatedCreateTemplateReceiverFixture::class);

		// If the unpinned (any-receiver) match were still in effect, this class would qualify and
		// its ancestor's file would be pulled into the read set too - it must not be.
		self::assertSame([$this->fileOf(UnrelatedCreateTemplateReceiverFixture::class)], $facts->getReadSet());
		self::assertNotContains(
			$this->fileOf(UnrelatedCreateTemplateReceiverAncestorFixture::class),
			$facts->getReadSet(),
		);
	}

	public function testUnrelatedServiceDoesNotQualifyAndSkipsWalkingItsAncestor(): void
	{
		$facts = $this->walk()->factsFor(UnrelatedServiceFixture::class);

		// Non-qualifying: own file is still pinned, but the ancestor is never even looked at,
		// unlike a qualifying class's boundary chain.
		self::assertSame([$this->fileOf(UnrelatedServiceFixture::class)], $facts->getReadSet());
		self::assertNotContains($this->fileOf(UnrelatedAncestorFixture::class), $facts->getReadSet());
	}

	public function testOwnFileIsAlwaysInReadSetForBothQualifyingAndNonQualifyingClasses(): void
	{
		$qualifying = $this->walk()->factsFor(SamplePresenter::class);
		self::assertContains($this->fileOf(SamplePresenter::class), $qualifying->getReadSet());

		$nonQualifying = $this->walk()->factsFor(UnrelatedServiceFixture::class);
		self::assertContains($this->fileOf(UnrelatedServiceFixture::class), $nonQualifying->getReadSet());
	}

	public function testAncestorMethodBodiesAreWalkedButNonAppAncestorIsNot(): void
	{
		$facts = $this->walk()->factsFor(BoundaryChildPresenter::class);

		self::assertContains($this->fileOf(BoundaryChildPresenter::class), $facts->getReadSet());
		self::assertContains($this->fileOf(BoundaryMiddleFixture::class), $facts->getReadSet());
		self::assertNotContains($this->fileOf(BoundaryOutsideFixture::class), $facts->getReadSet());
	}

	public function testHelperMethodFollowingCrossesIntoTheDeclaringTraitFile(): void
	{
		$facts = $this->walk()->factsFor(HelperFollowingPresenter::class);

		self::assertContains($this->fileOf(HelperFollowingPresenter::class), $facts->getReadSet());
		self::assertContains($this->fileOf(HelperSetterTrait::class), $facts->getReadSet());
	}

	public function testProvenanceTracksLocalAssignedFromTemplateAndIgnoresUnrelatedReceiver(): void
	{
		$source = <<<'PHP'
<?php
class ProvenanceSnippetFixture
{
	public function render(): void
	{
		$tpl = $this->template;
		$tpl->x = 1;
		$unrelated = new UnrelatedThing();
		$unrelated->y = 2;
	}
}
PHP;

		$stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
		$methods = (new NodeFinder())->findInstanceOf($stmts, ClassMethod::class);
		self::assertCount(1, $methods);

		$method = new ReflectionMethod(PhpRenderWalk::class, 'templateProvenancedLocals');
		$method->setAccessible(true);

		$stub = self::createReflectionProvider()->getClass(SamplePresenter::class);

		/** @var array<string, true> $locals */
		$locals = $method->invoke($this->walk(), $methods[0], $stub, $stub);

		self::assertTrue(array_key_exists('tpl', $locals));
		self::assertFalse(array_key_exists('unrelated', $locals));
	}

	public function testProvenanceTracksLocalAssignedFromCreateTemplateCall(): void
	{
		$source = <<<'PHP'
<?php
class ProvenanceCreateTemplateSnippetFixture
{
	public function render(): void
	{
		$tpl = $this->createTemplate();
		$tpl->x = 1;
	}
}
PHP;

		$stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
		$methods = (new NodeFinder())->findInstanceOf($stmts, ClassMethod::class);
		self::assertCount(1, $methods);

		$method = new ReflectionMethod(PhpRenderWalk::class, 'templateProvenancedLocals');
		$method->setAccessible(true);

		$stub = self::createReflectionProvider()->getClass(SamplePresenter::class);

		/** @var array<string, true> $locals */
		$locals = $method->invoke($this->walk(), $methods[0], $stub, $stub);

		self::assertTrue(array_key_exists('tpl', $locals));
	}

	// createTemplate()'s receiver must be reflection-confirmed to return a Template-ish type -
	// an unrelated same-named createTemplate() must not provenance-track its result as a
	// template object.
	public function testUnrelatedReceiverCreateTemplateIsNotProvenanceTracked(): void
	{
		$facts = $this->walk()->factsFor(UnrelatedProvenanceReceiverPresenter::class);

		self::assertArrayNotHasKey('subject', $facts->getAssignments());
	}

	public function testTemplateFactoryReceiverCreateTemplateIsStillProvenanceTracked(): void
	{
		$facts = $this->walk()->factsFor(TemplateFactoryProvenanceReceiverPresenter::class);

		self::assertArrayHasKey('subject', $facts->getAssignments());
		self::assertSame('string', $facts->getAssignments()['subject']->getTypeString());
	}

	// The return-type boundary subsumes the old $this->createTemplate() name-only shortcut: the
	// entry class's own createTemplate() returning a non-template must not be trusted either.
	public function testOwnCreateTemplateWithNonTemplateIshReturnTypeIsNotProvenanceTracked(): void
	{
		$facts = $this->walk()->factsFor(NonTemplateIshOwnCreateTemplatePresenter::class);

		self::assertArrayNotHasKey('boom', $facts->getAssignments());
	}

	public function testHelperFollowingTerminatesOnATraitMutualRecursionCycle(): void
	{
		$facts = $this->walk()->factsFor(CycleTerminationPresenter::class);

		self::assertContains($this->fileOf(CycleTerminationPresenter::class), $facts->getReadSet());
		self::assertContains($this->fileOf(CycleHelperTrait::class), $facts->getReadSet());
	}

	// --- self::/parent:: are lexically scoped to the class whose body contains the call (the
	// declaring class currently being walked), while $this->/static:: stay bound to the entry
	// class (dynamic dispatch / late static binding). A three-level chain where each level
	// overrides the same method makes the four forms resolve to three different classes when they
	// are correct, so any conflation is immediately visible.

	public function testResolveCallReceiverClassSelfIsLexicallyScopedToTheDeclaringClass(): void
	{
		$result = $this->invokeResolveCallReceiverClass(
			new StaticCall(new Name('self'), 'target'),
			LexicalScopeEntryPresenter::class,
			LexicalScopeMiddleFixture::class,
		);

		self::assertNotNull($result);
		self::assertSame(LexicalScopeMiddleFixture::class, $result->getName());
	}

	public function testResolveCallReceiverClassParentIsLexicallyScopedToTheDeclaringClassParent(): void
	{
		$result = $this->invokeResolveCallReceiverClass(
			new StaticCall(new Name('parent'), 'target'),
			LexicalScopeEntryPresenter::class,
			LexicalScopeMiddleFixture::class,
		);

		self::assertNotNull($result);
		self::assertSame(LexicalScopeBaseFixture::class, $result->getName());
	}

	public function testResolveCallReceiverClassStaticIsLateStaticBoundToTheEntryClass(): void
	{
		$result = $this->invokeResolveCallReceiverClass(
			new StaticCall(new Name('static'), 'target'),
			LexicalScopeEntryPresenter::class,
			LexicalScopeMiddleFixture::class,
		);

		self::assertNotNull($result);
		self::assertSame(LexicalScopeEntryPresenter::class, $result->getName());
	}

	public function testResolveCallReceiverClassThisIsDynamicallyBoundToTheEntryClass(): void
	{
		$result = $this->invokeResolveCallReceiverClass(
			new MethodCall(new Variable('this'), 'target'),
			LexicalScopeEntryPresenter::class,
			LexicalScopeMiddleFixture::class,
		);

		self::assertNotNull($result);
		self::assertSame(LexicalScopeEntryPresenter::class, $result->getName());
	}

	public function testSelfCallFixCrossesIntoTheBaseClasssOwnTraitInsteadOfTheEntrysOverride(): void
	{
		$facts = $this->walk()->factsFor(LexicalCallEntryPresenter::class);

		// self::traitTarget() is called from LexicalCallBaseFixture's own body, so it must resolve
		// to that class's own (trait-provided) traitTarget(), not the entry class's override -
		// observable here because the trait file is reachable ONLY via call-following.
		self::assertContains($this->fileOf(LexicalTraitTargetTrait::class), $facts->getReadSet());

		$assignment = $facts->getAssignments()['traitVar'];
		$sites = $assignment->getSites();
		self::assertCount(2, $sites);
		self::assertContains($this->fileOf(LexicalTraitTargetTrait::class), array_column($sites, 'file'));
	}

	// --- Assignment extraction domain (Task 3) ---

	public function testLiteralRhsTypesResolveToScalarOrConstructedClassTypeStrings(): void
	{
		$facts = $this->walk()->factsFor(LiteralAssignmentPresenter::class);
		$assignments = $facts->getAssignments();

		self::assertSame('string', $assignments['str']->getTypeString());
		self::assertSame('int', $assignments['num']->getTypeString());
		self::assertSame('float', $assignments['flt']->getTypeString());
		self::assertSame('bool', $assignments['flag']->getTypeString());
		self::assertSame('null', $assignments['nil']->getTypeString());
		self::assertSame('array', $assignments['arr']->getTypeString());
		self::assertSame(LiteralAssignmentTarget::class, $assignments['obj']->getTypeString());

		foreach ($assignments as $assignment) {
			self::assertSame(Certainty::HAPPENS, $assignment->getCertainty());
		}
	}

	public function testMethodReturnRhsResolvesReflectedNullableReturnType(): void
	{
		$facts = $this->walk()->factsFor(MethodReturnRhsPresenter::class);

		$assignment = $facts->getAssignments()['found'];
		self::assertSame(MethodReturnRhsEntity::class . '|null', $assignment->getTypeString());
		self::assertSame(Certainty::HAPPENS, $assignment->getCertainty());
	}

	public function testPropertyRhsResolvesDeclaredPropertyType(): void
	{
		$facts = $this->walk()->factsFor(PropertyRhsPresenter::class);

		$assignment = $facts->getAssignments()['obj'];
		self::assertSame(PropertyRhsSource::class, $assignment->getTypeString());
	}

	public function testUnresolvableDynamicCallRhsFallsBackToMixed(): void
	{
		$facts = $this->walk()->factsFor(UnresolvableRhsPresenter::class);

		$assignment = $facts->getAssignments()['x'];
		self::assertSame('mixed', $assignment->getTypeString());
	}

	public function testUnconditionalAssignmentIsHappensAndConditionalIsMaybe(): void
	{
		$facts = $this->walk()->factsFor(ConditionalCertaintyPresenter::class);
		$assignments = $facts->getAssignments();

		self::assertSame(Certainty::HAPPENS, $assignments['definite']->getCertainty());
		self::assertSame(Certainty::MAYBE, $assignments['maybe']->getCertainty());
	}

	public function testConditionalAssignmentsInBothBranchesUnionTypesAndStayMaybe(): void
	{
		$facts = $this->walk()->factsFor(UnionAcrossBranchesPresenter::class);

		$assignment = $facts->getAssignments()['x'];
		self::assertSame(Certainty::MAYBE, $assignment->getCertainty());

		$members = explode('|', $assignment->getTypeString());
		sort($members);
		self::assertSame(['int', 'string'], $members);
	}

	public function testReassignmentInLinearBodyLastWinsButAllSitesAreRecorded(): void
	{
		$facts = $this->walk()->factsFor(ReassignmentPresenter::class);

		$assignment = $facts->getAssignments()['x'];
		self::assertSame('int', $assignment->getTypeString());
		self::assertSame(Certainty::HAPPENS, $assignment->getCertainty());
		self::assertCount(2, $assignment->getSites());

		$file = $this->fileOf(ReassignmentPresenter::class);
		self::assertSame([
			['file' => $file, 'line' => 16],
			['file' => $file, 'line' => 17],
		], $assignment->getSites());
	}

	public function testHelperMethodAssignmentCarriesTheHelpersOwnConditionalityNotTheCallSites(): void
	{
		$facts = $this->walk()->factsFor(HelperConditionalityPresenter::class);
		$assignments = $facts->getAssignments();

		// Called only conditionally from actionDefault(), but unconditional at the top of its own
		// body - the helper's own structure wins, not the (conditional) call site.
		self::assertSame(Certainty::HAPPENS, $assignments['fromUnconditionalHelper']->getCertainty());

		// Called unconditionally from actionDefault(), but conditional inside its own body.
		self::assertSame(Certainty::MAYBE, $assignments['fromConditionalHelper']->getCertainty());
	}

	// A later conditional write downgrades certainty from HAPPENS to MAYBE even though the var is
	// unconditionally set earlier in the same linear body - safe (never falsely claims HAPPENS)
	// though imprecise (the var is, in fact, always set); pinning the current behavior.
	public function testConditionalWriteAfterAnEstablishedUnconditionalWriteDowngradesToMaybe(): void
	{
		$facts = $this->walk()->factsFor(ConditionalAfterUnconditionalPresenter::class);

		$assignment = $facts->getAssignments()['x'];
		self::assertSame(Certainty::MAYBE, $assignment->getCertainty());

		$members = explode('|', $assignment->getTypeString());
		sort($members);
		self::assertSame(['int', 'string'], $members);
	}

	public function testForeachBodyAssignmentIsMaybe(): void
	{
		$facts = $this->walk()->factsFor(LoopBodyCertaintyPresenter::class);

		self::assertSame(Certainty::MAYBE, $facts->getAssignments()['fromForeach']->getCertainty());
	}

	public function testWhileBodyAssignmentIsMaybe(): void
	{
		$facts = $this->walk()->factsFor(LoopBodyCertaintyPresenter::class);

		self::assertSame(Certainty::MAYBE, $facts->getAssignments()['fromWhile']->getCertainty());
	}

	// --- setFile domain (Task 4) ---

	// Receiver-type filter pin: a same-named setFile() on an unrelated (non-template) receiver
	// must never be recorded, while the genuine $this->template->setFile() call in the same
	// class still is.
	public function testSetFileReceiverFilterExcludesNonTemplateReceiverButRecordsTheRealOne(): void
	{
		$facts = $this->walk()->factsFor(SetFileReceiverFilterPresenter::class);
		$targets = $facts->getSetFileTargets();

		self::assertCount(1, $targets);
		self::assertSame(SetFileFact::KIND_LITERAL, $targets[0]->getKind());
		self::assertSame(
			dirname($this->fileOf(SetFileReceiverFilterPresenter::class)) . '/real.latte',
			$targets[0]->getPath(),
		);
		self::assertSame(Certainty::HAPPENS, $targets[0]->getCertainty());
	}

	public function testSetFileConventionDerivableSelfMethodCallHasNullPath(): void
	{
		$facts = $this->walk()->factsFor(SetFileConventionPresenter::class);
		$targets = $facts->getSetFileTargets();

		self::assertCount(1, $targets);
		self::assertSame(SetFileFact::KIND_CONVENTION, $targets[0]->getKind());
		self::assertNull($targets[0]->getPath());
	}

	public function testSetFileOpaqueVariableIndirectionHasNullPath(): void
	{
		$facts = $this->walk()->factsFor(SetFileOpaquePresenter::class);
		$targets = $facts->getSetFileTargets();

		self::assertCount(1, $targets);
		self::assertSame(SetFileFact::KIND_OPAQUE, $targets[0]->getKind());
		self::assertNull($targets[0]->getPath());
	}

	public function testSetFileInsideConditionalIsMaybeCertainty(): void
	{
		$facts = $this->walk()->factsFor(SetFileConditionalPresenter::class);
		$targets = $facts->getSetFileTargets();

		self::assertCount(1, $targets);
		self::assertSame(SetFileFact::KIND_LITERAL, $targets[0]->getKind());
		self::assertSame(Certainty::MAYBE, $targets[0]->getCertainty());
	}

	public function testSetFileOnAProvenancedLocalIsStillRecorded(): void
	{
		$facts = $this->walk()->factsFor(SetFileProvenancedLocalPresenter::class);
		$targets = $facts->getSetFileTargets();

		self::assertCount(1, $targets);
		self::assertSame(SetFileFact::KIND_LITERAL, $targets[0]->getKind());
		self::assertSame(
			dirname($this->fileOf(SetFileProvenancedLocalPresenter::class)) . '/via-local.latte',
			$targets[0]->getPath(),
		);
	}

	// --- render sites domain (Task 4) ---

	public function testRenderSiteLiteralArgResolvesTheNewLiteralPathField(): void
	{
		$facts = $this->walk()->factsFor(RenderSiteLiteralPresenter::class);
		$sites = $facts->getRenderSites();

		self::assertCount(1, $sites);
		self::assertTrue($sites[0]->hasFileArg());
		self::assertSame(
			dirname($this->fileOf(RenderSiteLiteralPresenter::class)) . '/foo.latte',
			$sites[0]->getLiteralPath(),
		);
	}

	public function testRenderSiteWithNoArgHasFileArgPresentFalseAndNullLiteralPath(): void
	{
		$facts = $this->walk()->factsFor(RenderSiteNoArgPresenter::class);
		$sites = $facts->getRenderSites();

		self::assertCount(1, $sites);
		self::assertFalse($sites[0]->hasFileArg());
		self::assertNull($sites[0]->getLiteralPath());
	}

	public function testRenderSiteWithNonLiteralArgHasFileArgPresentButNullLiteralPath(): void
	{
		$facts = $this->walk()->factsFor(RenderSiteNonLiteralArgPresenter::class);
		$sites = $facts->getRenderSites();

		self::assertCount(1, $sites);
		self::assertTrue($sites[0]->hasFileArg());
		self::assertNull($sites[0]->getLiteralPath());
	}

	// Same receiver-type filter as setFile: rendering an unrelated child (a Nette Control, not
	// the Template) must not be recorded, while the genuine template render() call still is.
	public function testRenderSiteUnrelatedReceiverIsNotRecorded(): void
	{
		$facts = $this->walk()->factsFor(RenderSiteUnrelatedReceiverPresenter::class);

		self::assertCount(1, $facts->getRenderSites());
	}

	// --- template-class channels domain (Task 4) ---

	public function testGenericBindingChannelResolvesTheExtendsTagGenericArgument(): void
	{
		$facts = $this->walk()->factsFor(GenericBaseControlDescendantFixture::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(DefaultTemplate::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_GENERIC_BINDING, $templateClass->getChannel());
		self::assertSame(Certainty::HAPPENS, $templateClass->getCertainty());
	}

	// The reflection ladder resolves through INHERITED surface members - the base's own
	// @property-read/createTemplate() surface, not just the entry class's phpdoc.
	public function testInheritedSurfaceResolvesTemplateClassThroughTheReflectionLadder(): void
	{
		$facts = $this->walk()->factsFor(LegacyBaseControlDescendantFixture::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(BridgeTemplate::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_GENERIC_BINDING, $templateClass->getChannel());
		self::assertSame(Certainty::HAPPENS, $templateClass->getCertainty());
	}

	// Fallback rung: a vendor-only surface resolves to nothing more specific than the Template
	// interface, so the container-configured TemplateFactory default takes over when available.
	public function testUnresolvedSurfaceResolvesToTheContainerConfiguredTemplateFactoryDefault(): void
	{
		$facts = $this->walk(self::FactoryDefaultContainerLoaderFile)
			->factsFor(VendorSurfaceDescendantFixture::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(FixtureFactoryDefaultTemplate::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_FACTORY_DEFAULT, $templateClass->getChannel());
		self::assertSame(Certainty::HAPPENS, $templateClass->getCertainty());
	}

	public function testUnresolvedSurfaceWithoutAContainerFallsToTheTemplateInterfaceFloor(): void
	{
		$facts = $this->walk()->factsFor(VendorSurfaceDescendantFixture::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(UiTemplate::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_TEMPLATE_FLOOR, $templateClass->getChannel());
		self::assertSame(Certainty::HAPPENS, $templateClass->getCertainty());
	}

	public function testContainerWithoutABridgeTemplateFactoryFallsToTheTemplateInterfaceFloor(): void
	{
		$facts = $this->walk(self::FactoryDefaultContainerLoaderNoFactoryFile)
			->factsFor(VendorSurfaceDescendantFixture::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(UiTemplate::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_TEMPLATE_FLOOR, $templateClass->getChannel());
	}

	public function testCreateTemplateArgChannelReadsTheExplicitClassConstArgument(): void
	{
		$facts = $this->walk()->factsFor(TemplateClassCreateTemplateArgPresenter::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassChannelTargetOne::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_CREATE_TEMPLATE, $templateClass->getChannel());
		self::assertSame(Certainty::HAPPENS, $templateClass->getCertainty());
	}

	public function testConditionalCreateTemplateArgIsMaybeCertainty(): void
	{
		$facts = $this->walk()->factsFor(TemplateClassConditionalCreateTemplatePresenter::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassFact::CHANNEL_CREATE_TEMPLATE, $templateClass->getChannel());
		self::assertSame(Certainty::MAYBE, $templateClass->getCertainty());
	}

	public function testNewChannelReadsTheReturnedNewExpressionInACreateTemplateOverride(): void
	{
		$facts = $this->walk()->factsFor(TemplateClassNewChannelPresenter::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassChannelTargetTwo::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $templateClass->getChannel());
		self::assertSame(Certainty::HAPPENS, $templateClass->getCertainty());
	}

	public function testFactoryStaticChannelReadsTheExplicitStaticCreateCall(): void
	{
		$facts = $this->walk()->factsFor(TemplateClassFactoryStaticPresenter::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassChannelTargetThree::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_FACTORY_STATIC, $templateClass->getChannel());
		self::assertSame(Certainty::HAPPENS, $templateClass->getCertainty());
	}

	// Precedence pin: phpdoc outranks every other channel, and a disagreeing lower-precedence
	// channel (here, an explicit createTemplate() class arg for a DIFFERENT class) downgrades
	// Certainty to MAYBE rather than being silently ignored.
	public function testPhpdocChannelOutranksCreateTemplateArgAndTheConflictForcesMaybe(): void
	{
		$facts = $this->walk()->factsFor(TemplateClassPhpdocOverridePresenter::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassChannelTargetOne::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_PHPDOC, $templateClass->getChannel());
		self::assertSame(Certainty::MAYBE, $templateClass->getCertainty());
	}

	// Same precedence/conflict pin, one tier down: an explicit createTemplate() class arg
	// outranks the generic BaseControl<T> binding, and disagreement still forces MAYBE.
	public function testCreateTemplateArgOutranksGenericBindingAndTheConflictForcesMaybe(): void
	{
		$facts = $this->walk()->factsFor(TemplateClassConflictFixture::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassChannelTargetTwo::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_CREATE_TEMPLATE, $templateClass->getChannel());
		self::assertSame(Certainty::MAYBE, $templateClass->getCertainty());
	}

	// When every found channel agrees on the same class, there is no ambiguity to flag - the
	// winning (higher-precedence) channel's own natural Certainty is kept, not downgraded.
	public function testAgreeingChannelsKeepTheWinnersNaturalCertaintyWithoutDowngrade(): void
	{
		$facts = $this->walk()->factsFor(TemplateClassAgreementFixture::class);
		$templateClass = $facts->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassChannelTargetOne::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_CREATE_TEMPLATE, $templateClass->getChannel());
		self::assertSame(Certainty::HAPPENS, $templateClass->getCertainty());
	}

	// --- template-class candidate lists ---

	// Two manual-factory classes in the SAME channel: both surface, in walk encounter order,
	// with their sites, while the resolved primary stays the first occurrence.
	public function testEverySameChannelCandidateIsRecordedWithItsSites(): void
	{
		$facts = $this->walk()->factsFor(TemplateClassMultiCandidatePresenter::class);

		$candidates = $facts->getTemplateClassCandidates();
		self::assertCount(2, $candidates);
		self::assertSame(TemplateClassChannelTargetThree::class, $candidates[0]->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_FACTORY_STATIC, $candidates[0]->getChannel());
		self::assertSame(Certainty::HAPPENS, $candidates[0]->getCertainty());
		self::assertSame([12], $candidates[0]->getSites());
		self::assertSame(TemplateClassChannelTargetFour::class, $candidates[1]->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_FACTORY_STATIC, $candidates[1]->getChannel());
		self::assertSame(Certainty::HAPPENS, $candidates[1]->getCertainty());
		self::assertSame([13], $candidates[1]->getSites());

		$templateClass = $facts->getTemplateClass();
		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassChannelTargetThree::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_FACTORY_STATIC, $templateClass->getChannel());
		self::assertSame(Certainty::HAPPENS, $templateClass->getCertainty());
	}

	// The SAME class observed twice in one channel merges into ONE candidate carrying both site
	// lines; the first observation's certainty stands even when a later unconditional sighting
	// is stronger - no upgrade, no last-wins.
	public function testRepeatObservationsOfOneCandidateMergeSitesAndKeepTheFirstCertainty(): void
	{
		$facts = $this->walk()->factsFor(TemplateClassRepeatedObservationPresenter::class);

		$candidates = $facts->getTemplateClassCandidates();
		self::assertCount(1, $candidates);
		self::assertSame(TemplateClassChannelTargetThree::class, $candidates[0]->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_FACTORY_STATIC, $candidates[0]->getChannel());
		self::assertSame(Certainty::MAYBE, $candidates[0]->getCertainty());
		self::assertSame([16, 19], $candidates[0]->getSites());

		$templateClass = $facts->getTemplateClass();
		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassChannelTargetThree::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_FACTORY_STATIC, $templateClass->getChannel());
		self::assertSame(Certainty::MAYBE, $templateClass->getCertainty());
	}

	public function testCandidatesFromDistinctChannelsAreAllPreservedAlongsideTheResolvedPrimary(): void
	{
		$facts = $this->walk()->factsFor(TemplateClassConflictFixture::class);

		$candidates = $facts->getTemplateClassCandidates();
		self::assertCount(3, $candidates);
		self::assertSame(TemplateClassChannelTargetOne::class, $candidates[0]->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_GENERIC_BINDING, $candidates[0]->getChannel());
		self::assertSame(Certainty::HAPPENS, $candidates[0]->getCertainty());
		self::assertSame([], $candidates[0]->getSites());
		self::assertSame(TemplateClassChannelTargetOne::class, $candidates[1]->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_CONVENTION, $candidates[1]->getChannel());
		self::assertSame(Certainty::HAPPENS, $candidates[1]->getCertainty());
		self::assertSame([23], $candidates[1]->getSites());
		self::assertSame(TemplateClassChannelTargetTwo::class, $candidates[2]->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_CREATE_TEMPLATE, $candidates[2]->getChannel());
		self::assertSame(Certainty::HAPPENS, $candidates[2]->getCertainty());
		self::assertSame([28], $candidates[2]->getSites());

		$templateClass = $facts->getTemplateClass();
		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassChannelTargetTwo::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_CREATE_TEMPLATE, $templateClass->getChannel());
		self::assertSame(Certainty::MAYBE, $templateClass->getCertainty());
	}

	public function testFloorResolvedClassHasAnEmptyCandidateList(): void
	{
		$facts = $this->walk()->factsFor(VendorSurfaceDescendantFixture::class);

		$templateClass = $facts->getTemplateClass();
		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassFact::CHANNEL_TEMPLATE_FLOOR, $templateClass->getChannel());
		self::assertSame([], $facts->getTemplateClassCandidates());
	}

	// --- parent::/self::/static::createTemplate() trust boundary (Task 4 review fix) ---

	// Regression pin for the review's Critical-1: mirrors app/Component/BaseControl.php's and
	// app/Control/BaseControl.php's own createTemplate() override verbatim -
	// parent::createTemplate() into a local, then setFile()/dynamic-property writes on that
	// local, must be recognized rather than silently dropped as an untracked receiver.
	public function testParentCreateTemplateStaticCallIsTrustedAndTheRealConventionShapeIsRecorded(): void
	{
		$facts = $this->walk()->factsFor(ParentCreateTemplateConventionFixture::class);

		$targets = $facts->getSetFileTargets();
		self::assertCount(1, $targets);
		self::assertSame(SetFileFact::KIND_CONVENTION, $targets[0]->getKind());

		self::assertArrayHasKey('currency', $facts->getAssignments());
		self::assertSame('string', $facts->getAssignments()['currency']->getTypeString());
	}

	// Positive twin of the negative control below: an explicit-FQCN static createTemplate() on
	// a class whose own createTemplate() returns a real template IS trusted, same as the
	// ->/self::/parent::/static:: forms.
	public function testExplicitFqcnStaticCreateTemplateWithTemplateIshReturnIsTrusted(): void
	{
		$facts = $this->walk()->factsFor(TrustedStaticCreateTemplateReceiverPresenter::class);

		self::assertArrayHasKey('subject', $facts->getAssignments());
		self::assertSame('string', $facts->getAssignments()['subject']->getTypeString());
	}

	// Negative control: an unrelated class's own same-named static createTemplate() returning
	// void must NOT be trusted - the same false-positive shape the return-type boundary exists
	// to exclude, just reached via an explicit-FQCN static call instead of ->.
	public function testExplicitFqcnStaticCreateTemplateIsNotTrusted(): void
	{
		$facts = $this->walk()->factsFor(UnrelatedStaticCreateTemplateReceiverPresenter::class);

		self::assertArrayNotHasKey('subject', $facts->getAssignments());
	}

	/**
	 * @return iterable<string, array{0: StaticCall, 1: bool}>
	 */
	public function provideStaticCreateTemplateTrustCases(): iterable
	{
		yield 'self is trusted' => [new StaticCall(new Name('self'), 'createTemplate'), true];
		yield 'parent is trusted' => [new StaticCall(new Name('parent'), 'createTemplate'), true];
		yield 'static is trusted' => [new StaticCall(new Name('static'), 'createTemplate'), true];
		yield 'unknown FQCN is not trusted' => [new StaticCall(new Name('SomeOtherClass'), 'createTemplate'), false];
		yield 'wrong method name is not trusted' => [new StaticCall(new Name('parent'), 'somethingElse'), false];
	}

	/**
	 * @dataProvider provideStaticCreateTemplateTrustCases
	 */
	public function testIsTrustedCreateTemplateCallLexicalFormsPerTask3Rules(StaticCall $call, bool $expected): void
	{
		$result = $this->invokeIsTrustedCreateTemplateCall(
			$call,
			LexicalScopeEntryPresenter::class,
			LexicalScopeMiddleFixture::class,
		);

		self::assertSame($expected, $result);
	}

	/**
	 * @return iterable<string, array{0: StaticCall, 1: bool}>
	 */
	public function provideStaticGetTemplateTrustCases(): iterable
	{
		yield 'self is trusted' => [new StaticCall(new Name('self'), 'getTemplate'), true];
		yield 'parent is trusted' => [new StaticCall(new Name('parent'), 'getTemplate'), true];
		yield 'static is trusted' => [new StaticCall(new Name('static'), 'getTemplate'), true];
		yield 'unknown FQCN is not trusted' => [new StaticCall(new Name('SomeOtherClass'), 'getTemplate'), false];
		yield 'wrong method name is not trusted' => [new StaticCall(new Name('parent'), 'somethingElse'), false];
	}

	/**
	 * @dataProvider provideStaticGetTemplateTrustCases
	 */
	public function testGetTemplateOriginTrustMirrorsCreateTemplateAcrossLexicalForms(
		StaticCall $call,
		bool $expected
	): void
	{
		$result = $this->invokeIsTemplateOrigin(
			$call,
			LexicalScopeEntryPresenter::class,
			LexicalScopeMiddleFixture::class,
		);

		self::assertSame($expected, $result);
	}

	// --- template-class channel degrade paths (Task 4 review Important-2) ---

	public function testMalformedGenericExtendsWithNoTypeArgumentDegradesToTheFloor(): void
	{
		$templateClass = $this->walk()->factsFor(GenericBindingBareExtendsFixture::class)->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassFact::CHANNEL_TEMPLATE_FLOOR, $templateClass->getChannel());
	}

	public function testGenericExtendsArgumentResolvingToAnUnknownClassDegradesToTheFloor(): void
	{
		$templateClass = $this->walk()->factsFor(GenericBindingUnresolvableArgFixture::class)->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(TemplateClassFact::CHANNEL_TEMPLATE_FLOOR, $templateClass->getChannel());
	}

	public function testPhpdocPropertyTagWithAUnionTypeDegradesToTheFloor(): void
	{
		$templateClass = $this->walk()->factsFor(TemplateClassPhpdocUnionTypePresenter::class)->getTemplateClass();

		self::assertNotNull($templateClass);
		self::assertSame(UiTemplate::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_TEMPLATE_FLOOR, $templateClass->getChannel());
	}

	// --- lifecycle views + mutation facts domain ---

	public function testActionAndRenderMethodNamesAndEffectiveSetViewsYieldTheViewSet(): void
	{
		$facts = $this->walk()->factsFor(LifecycleViewsPresenter::class);
		$views = $facts->getViews();

		self::assertSame(
			['afterR', 'altBefore', 'conditional', 'default', 'detail', 'fromCheck', 'fromStartup', 'other'],
			array_keys($views),
		);
		self::assertFalse($facts->hasOpenViewSet());

		$file = $this->fileOf(LifecycleViewsPresenter::class);

		self::assertSame('default', $views['default']->getName());
		self::assertSame(Certainty::HAPPENS, $views['default']->getCertainty());
		self::assertSame(['actionDefault'], $views['default']->getSources());
		self::assertSame([['file' => $file, 'line' => 22]], $views['default']->getSites());

		self::assertSame(['renderDetail'], $views['detail']->getSources());
		self::assertSame(['actionOther'], $views['other']->getSources());

		self::assertSame(Certainty::MAYBE, $views['conditional']->getCertainty());
		$sources = $views['conditional']->getSources();
		self::assertCount(1, $sources);
		self::assertInstanceOf(MutationFact::class, $sources[0]);
		self::assertSame(MutationFact::KIND_SET_VIEW, $sources[0]->getKind());
		self::assertSame(MutationFact::PHASE_ACTION, $sources[0]->getPhase());
		self::assertSame([['file' => $file, 'line' => 29]], $views['conditional']->getSites());

		self::assertSame(Certainty::HAPPENS, $views['fromStartup']->getCertainty());
		self::assertSame(Certainty::HAPPENS, $views['fromCheck']->getCertainty());
		self::assertSame(Certainty::HAPPENS, $views['altBefore']->getCertainty());
		self::assertSame(Certainty::HAPPENS, $views['afterR']->getCertainty());
	}

	public function testViewMutationsAreLifecycleOrderedAndPhaseTagged(): void
	{
		$facts = $this->walk()->factsFor(LifecycleViewsPresenter::class);

		// checkRequirements shares the startup window: its first run precedes startup() and every
		// rerun stays ahead of sendTemplate resolution; afterRender still lands on the file, so it
		// stays effective even though it maps outside the dispatch phases.
		self::assertSame([
			[MutationFact::KIND_SET_VIEW, MutationFact::PHASE_STARTUP, MutationFact::EFFECTIVE_YES, 14, 'fromStartup'],
			[MutationFact::KIND_SET_VIEW, MutationFact::PHASE_STARTUP, MutationFact::EFFECTIVE_YES, 19, 'fromCheck'],
			[MutationFact::KIND_SET_VIEW, MutationFact::PHASE_ACTION, MutationFact::EFFECTIVE_YES, 29, 'conditional'],
			[MutationFact::KIND_SET_VIEW, MutationFact::PHASE_BEFORE_RENDER, MutationFact::EFFECTIVE_YES, 39, 'altBefore'],
			[MutationFact::KIND_SET_VIEW, MutationFact::PHASE_OUTSIDE, MutationFact::EFFECTIVE_YES, 44, 'afterR'],
		], self::mutationTuples($facts->getMutations()));
	}

	public function testHelperReachableFromTwoPhasesIsEffectiveMaybe(): void
	{
		$facts = $this->walk()->factsFor(TwoPhaseHelperViewPresenter::class);

		$mutations = $facts->getMutations();
		self::assertCount(1, $mutations);
		self::assertSame(MutationFact::KIND_SET_VIEW, $mutations[0]->getKind());
		self::assertSame(MutationFact::PHASE_BEFORE_RENDER, $mutations[0]->getPhase());
		self::assertSame(MutationFact::EFFECTIVE_MAYBE, $mutations[0]->getEffectiveness());
		self::assertSame('contested', $mutations[0]->getArgument());

		$views = $facts->getViews();
		self::assertSame(['contested'], array_keys($views));
		self::assertSame(Certainty::MAYBE, $views['contested']->getCertainty());
	}

	public function testShutdownOnlyReachableViewMutationsAreIneffectiveAndExcludedFromViews(): void
	{
		$facts = $this->walk()->factsFor(ShutdownOnlyViewPresenter::class);

		self::assertSame([], $facts->getViews());
		self::assertFalse($facts->hasOpenViewSet());

		self::assertSame([
			[MutationFact::KIND_SET_VIEW, MutationFact::PHASE_OUTSIDE, MutationFact::EFFECTIVE_NO, 13, 'never'],
			[MutationFact::KIND_SET_ACTION, MutationFact::PHASE_OUTSIDE, MutationFact::EFFECTIVE_NO, 19, 'late'],
		], self::mutationTuples($facts->getMutations()));
	}

	public function testPublicHelperReachableOnlyFromShutdownDegradesToMaybeAndKeepsItsViews(): void
	{
		$facts = $this->walk()->factsFor(PublicShutdownHelperViewPresenter::class);

		// Public visibility admits external callers at unprovable times, so shutdown-only in-class
		// reachability proves nothing - MAYBE, not EFFECTIVE_NO, and the doubt reaches the private
		// callee through the helper's own edge.
		self::assertSame([
			[MutationFact::KIND_SET_VIEW, MutationFact::PHASE_OUTSIDE, MutationFact::EFFECTIVE_MAYBE, 18, 'late'],
			[MutationFact::KIND_SET_ACTION, MutationFact::PHASE_OUTSIDE, MutationFact::EFFECTIVE_MAYBE, 24, 'inner'],
		], self::mutationTuples($facts->getMutations()));

		$views = $facts->getViews();
		self::assertSame(['inner', 'late'], array_keys($views));
		self::assertSame(Certainty::MAYBE, $views['late']->getCertainty());
		self::assertSame(Certainty::MAYBE, $views['inner']->getCertainty());
		self::assertFalse($facts->hasOpenViewSet());
	}

	public function testLaterUnconditionalWriteOverwritesThePendingViewWithinOneMethod(): void
	{
		$facts = $this->walk()->factsFor(OverwriteOrderViewPresenter::class);
		$views = $facts->getViews();

		self::assertSame(['default', 'final', 'switched'], array_keys($views));

		$switchedSources = $views['switched']->getSources();
		self::assertCount(1, $switchedSources);
		self::assertInstanceOf(MutationFact::class, $switchedSources[0]);
		self::assertSame(MutationFact::KIND_SET_ACTION, $switchedSources[0]->getKind());
		self::assertSame(Certainty::HAPPENS, $views['switched']->getCertainty());
		self::assertSame(Certainty::HAPPENS, $views['final']->getCertainty());

		self::assertSame([
			[MutationFact::KIND_SET_VIEW, MutationFact::PHASE_ACTION, MutationFact::EFFECTIVE_YES, 12, 'alt'],
			[MutationFact::KIND_SET_ACTION, MutationFact::PHASE_ACTION, MutationFact::EFFECTIVE_YES, 13, 'switched'],
			[MutationFact::KIND_SET_ACTION, MutationFact::PHASE_SIGNAL, MutationFact::EFFECTIVE_YES, 18, 'other'],
			[MutationFact::KIND_SET_VIEW, MutationFact::PHASE_SIGNAL, MutationFact::EFFECTIVE_YES, 19, 'final'],
		], self::mutationTuples($facts->getMutations()));
	}

	public function testDynamicSetViewArgumentOpensTheViewSet(): void
	{
		$facts = $this->walk()->factsFor(DynamicViewPresenter::class);

		self::assertTrue($facts->hasOpenViewSet());
		self::assertSame(['default'], array_keys($facts->getViews()));

		$mutations = $facts->getMutations();
		self::assertCount(1, $mutations);
		self::assertSame(MutationFact::KIND_SET_VIEW, $mutations[0]->getKind());
		self::assertSame(MutationFact::PHASE_ACTION, $mutations[0]->getPhase());
		self::assertSame(MutationFact::EFFECTIVE_YES, $mutations[0]->getEffectiveness());
		self::assertNull($mutations[0]->getArgument());
	}

	public function testSetFilePhaseTaggingKeepsEveryPhaseEffectiveIncludingShutdown(): void
	{
		$facts = $this->walk()->factsFor(SetFilePhasePresenter::class);
		$dir = dirname($this->fileOf(SetFilePhasePresenter::class));

		$targets = $facts->getSetFileTargets();
		self::assertCount(2, $targets);
		self::assertSame(MutationFact::PHASE_BEFORE_RENDER, $targets[0]->getPhase());
		self::assertSame(MutationFact::EFFECTIVE_YES, $targets[0]->getEffectiveness());
		self::assertSame(MutationFact::PHASE_OUTSIDE, $targets[1]->getPhase());
		// No too-late window for setFile: a shutdown-phase call still rewrites the rendered file.
		self::assertSame(MutationFact::EFFECTIVE_YES, $targets[1]->getEffectiveness());

		self::assertSame([
			[
				MutationFact::KIND_SET_FILE,
				MutationFact::PHASE_BEFORE_RENDER,
				MutationFact::EFFECTIVE_YES,
				13,
				$dir . '/phase-before.latte',
			],
			[
				MutationFact::KIND_SET_FILE,
				MutationFact::PHASE_OUTSIDE,
				MutationFact::EFFECTIVE_YES,
				18,
				$dir . '/phase-shutdown.latte',
			],
		], self::mutationTuples($facts->getMutations()));

		self::assertSame([], $facts->getViews());
	}

	public function testFormCallbackMethodAndClosureAreSignalPhase(): void
	{
		$facts = $this->walk()->factsFor(FormCallbackViewPresenter::class);

		self::assertSame([
			[MutationFact::KIND_SET_VIEW, MutationFact::PHASE_SIGNAL, MutationFact::EFFECTIVE_YES, 16, 'closureSaved'],
			[MutationFact::KIND_SET_VIEW, MutationFact::PHASE_SIGNAL, MutationFact::EFFECTIVE_YES, 24, 'saved'],
		], self::mutationTuples($facts->getMutations()));

		$views = $facts->getViews();
		self::assertSame(['closureSaved', 'saved'], array_keys($views));
		self::assertSame(Certainty::HAPPENS, $views['closureSaved']->getCertainty());
		self::assertSame(Certainty::HAPPENS, $views['saved']->getCertainty());
	}

	public function testControlsHaveNoViewAxisAndGetRenderAndSignalSetFilePhases(): void
	{
		$facts = $this->walk()->factsFor(ControlSetFilePhaseFixture::class);

		self::assertSame([], $facts->getViews());
		self::assertFalse($facts->hasOpenViewSet());

		$targets = $facts->getSetFileTargets();
		self::assertCount(2, $targets);
		self::assertSame(MutationFact::PHASE_RENDER, $targets[0]->getPhase());
		self::assertSame(MutationFact::PHASE_SIGNAL, $targets[1]->getPhase());

		$mutations = $facts->getMutations();
		self::assertCount(2, $mutations);
		foreach ($mutations as $mutation) {
			self::assertSame(MutationFact::KIND_SET_FILE, $mutation->getKind());
			self::assertSame(MutationFact::EFFECTIVE_YES, $mutation->getEffectiveness());
		}
	}

	public function testNonLifecycleReachableSetFileFallsToOutsidePhaseAndStaysEffective(): void
	{
		$facts = $this->walk()->factsFor(SetFileConventionPresenter::class);
		$targets = $facts->getSetFileTargets();

		self::assertCount(1, $targets);
		self::assertSame(MutationFact::PHASE_OUTSIDE, $targets[0]->getPhase());
		self::assertSame(MutationFact::EFFECTIVE_YES, $targets[0]->getEffectiveness());
	}

	/**
	 * @param list<MutationFact> $mutations
	 * @return list<array{0: string, 1: string, 2: string, 3: int, 4: string|null}>
	 */
	private static function mutationTuples(array $mutations): array
	{
		return array_map(
			static fn (MutationFact $mutation): array => [
				$mutation->getKind(),
				$mutation->getPhase(),
				$mutation->getEffectiveness(),
				$mutation->getLine(),
				$mutation->getArgument(),
			],
			$mutations,
		);
	}

	/**
	 * @param class-string $entryClassName
	 * @param class-string $declaringClassName
	 */
	private function invokeIsTrustedCreateTemplateCall(
		StaticCall $call,
		string $entryClassName,
		string $declaringClassName
	): bool
	{
		$reflectionProvider = self::createReflectionProvider();

		$method = new ReflectionMethod(PhpRenderWalk::class, 'isTrustedCreateTemplateCall');
		$method->setAccessible(true);

		/** @var bool $result */
		$result = $method->invoke(
			$this->walk(),
			$call,
			$reflectionProvider->getClass($entryClassName),
			$reflectionProvider->getClass($declaringClassName),
		);

		return $result;
	}

	/**
	 * @param class-string $entryClassName
	 * @param class-string $declaringClassName
	 */
	private function invokeIsTemplateOrigin(
		StaticCall $call,
		string $entryClassName,
		string $declaringClassName
	): bool
	{
		$reflectionProvider = self::createReflectionProvider();

		$method = new ReflectionMethod(PhpRenderWalk::class, 'isTemplateOrigin');
		$method->setAccessible(true);

		/** @var bool $result */
		$result = $method->invoke(
			$this->walk(),
			$call,
			$reflectionProvider->getClass($entryClassName),
			$reflectionProvider->getClass($declaringClassName),
		);

		return $result;
	}

	/**
	 * @param MethodCall|StaticCall $call
	 * @param class-string $entryClassName
	 * @param class-string $declaringClassName
	 */
	private function invokeResolveCallReceiverClass(
		$call,
		string $entryClassName,
		string $declaringClassName
	): ?ClassReflection
	{
		$reflectionProvider = self::createReflectionProvider();

		$method = new ReflectionMethod(PhpRenderWalk::class, 'resolveCallReceiverClass');
		$method->setAccessible(true);

		/** @var ClassReflection|null $result */
		$result = $method->invoke(
			$this->walk(),
			$call,
			$reflectionProvider->getClass($entryClassName),
			$reflectionProvider->getClass($declaringClassName),
		);

		return $result;
	}

	/**
	 * @param class-string $className
	 */
	private function fileOf(string $className): string
	{
		$file = (new ReflectionClass($className))->getFileName();
		self::assertNotFalse($file);

		return $file;
	}

}
