<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\CapturedOverlay;
use OriPhpstan\Nette\Latte\Includes\ContextResolver;
use OriPhpstan\Nette\Latte\Includes\FactoryProvidedVars;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Parser\LatteRoutingParser;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsAncestorBasePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsAncestorLeftPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsAncestorRightPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsControlRenderer;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsDisagreeingControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsDisagreeingPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsDynamicControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsFlashlessPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsFloorPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsFullPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsMistypedUserPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsNarrowedPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsNarrowedTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsNoUserPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsNoUserTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsPresenterRenderer;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsShortNamePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsStandaloneControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsTypedControlPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsTypedControlStandalone;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsTypedUserPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsUserReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsUserService;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsVendorDefaultPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\FixtureTemplateTypeContainer;
use function array_keys;
use function array_map;
use function getmypid;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

// TemplateFactory's own injected variables, resolved per template. The rows below pin both halves
// of the vendor contract (property_exists against the RESOLVED template class, and the value-null
// condition that decides certainty), the fallback rungs of the resolution ladder, and the control
// axis that createTemplate()'s recorded first argument decides - including the one asymmetry
// between its two keys, that SELF settles `control` outright while `presenter` still has to be a
// presenter.
final class FactoryProvidedVarsTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const AppFixtureDir = __DIR__ . '/Fixtures/App';

	private const FactoryDefaultLoaderFile = __DIR__ . '/Fixtures/factory-vars-container-loader.php';

	private const WiredLoaderFile = __DIR__ . '/Fixtures/factory-vars-wired-container-loader.php';

	private const TemplateRel = 'page.latte';

	// The vendor's own DefaultTemplate, reached through the renderer's @property-read declaration,
	// each key with the type DefaultTemplate itself declares. On 3.1 the unwired keys are untyped
	// properties holding their implicit null; on 3.2+ they are typed and uninitialized, so absent.
	public function testVendorDefaultTemplateProvidesTheFactoryVariables(): void
	{
		self::assertSame(
			self::typedDefaultTemplate()
				? ['flashes' => '\stdClass[]']
				: [
					'user' => 'Nette\Security\User|null',
					'baseUrl' => 'string|null',
					'basePath' => 'string|null',
					'flashes' => '\stdClass[]',
				],
			$this->typesFor([FactoryVarsVendorDefaultPresenter::class]),
		);
	}

	public function testUnwiredUserOfADetachedControlIsNullableOnUntypedDefaultTemplate(): void
	{
		InstalledVersionsGuard::requireNetteLine('nette/application', '~3.1.0');

		self::assertSame(
			'Nette\Security\User|null',
			$this->typesFor([FactoryVarsControlRenderer::class], self::FactoryDefaultLoaderFile)['user'],
		);
	}

	/**
	 * @group nette32
	 */
	public function testUnwiredUserOfADetachedControlIsUndefinedOnTypedDefaultTemplate(): void
	{
		self::assertArrayNotHasKey(
			'user',
			$this->typesFor([FactoryVarsControlRenderer::class], self::FactoryDefaultLoaderFile),
		);
	}

	/**
	 * @group nette32
	 */
	public function testWiredUserOfADetachedControlIsNonNullOnTypedDefaultTemplate(): void
	{
		$vars = $this->resolveFor([FactoryVarsControlRenderer::class], self::WiredLoaderFile);

		self::assertSame(Certainty::HAPPENS, $vars['user']['certainty']);
		self::assertSame(
			'\\' . FactoryVarsUserService::class,
			$this->typesFor([FactoryVarsControlRenderer::class], self::WiredLoaderFile)['user'],
		);
	}

	// THE CONTROL AXIS, and its floor: this renderer is not a component and never calls
	// createTemplate() anywhere, so the walk recorded no argument at all and the axis claims
	// nothing - the four dependency-side keys above are unaffected.
	public function testRendererWithNoCreateTemplatePathClaimsNeitherControlNorPresenter(): void
	{
		$names = array_keys($this->typesFor([FactoryVarsVendorDefaultPresenter::class]));

		self::assertNotContains('control', $names);
		self::assertNotContains('presenter', $names);
	}

	// A PLAIN COMPONENT on the inherited vendor createTemplate(), and THE ROW THAT SEPARATES THE TWO
	// KEYS: the factory is handed this very instance, so $control is definitely written and IS this
	// class - no hierarchy question arises on an argument that is $this. $presenter is STILL not
	// claimed, because a detached control's getPresenterIfExists() returns null and whether the
	// control is attached is runtime state no walked fact can prove. Modelling it as nullable would
	// clear variable.undefined at the price of a nullability error on every $presenter->... a
	// rendered control actually reaches - the direction this project ranks worse than a missed
	// detection. Applying the presenter check to `control` too would take $control away here.
	public function testPlainComponentRendererClaimsItsOwnControlButNoPresenter(): void
	{
		$vars = $this->resolveFor([FactoryVarsControlRenderer::class]);

		self::assertSame(Certainty::HAPPENS, $vars['control']['certainty']);
		self::assertSame(
			'\\' . FactoryVarsControlRenderer::class,
			$this->typesFor([FactoryVarsControlRenderer::class])['control'],
		);
		self::assertArrayNotHasKey('presenter', $vars);
	}

	// THE SOUND HALF: Presenter::getPresenterIfExists() is a final override returning $this, so a
	// presenter handing the factory itself definitely has one, and it IS the renderer - refined from
	// the template class's declared Nette\Application\UI\Presenter to the renderer's own class,
	// which is what makes the variable worth having rather than merely defined.
	public function testPresenterRendererProvidesItselfAsThePresenter(): void
	{
		$vars = $this->resolveFor([FactoryVarsPresenterRenderer::class]);

		self::assertSame(Certainty::HAPPENS, $vars['presenter']['certainty']);
		self::assertSame(
			'\\' . FactoryVarsPresenterRenderer::class,
			$this->typesFor([FactoryVarsPresenterRenderer::class])['presenter'],
		);
	}

	// NO CONTROL PASSED is not the same as no variable: the vendor spells the property untyped, so
	// it keeps its implicit null and Template::getParameters() exports it. The variable exists and
	// is null, which is exactly the maybe-defined encoding - never a claim that isset($presenter)
	// holds. Both keys of the axis answer that way, and neither is refined: a merely-MAYBE key is
	// exactly the one the factory's own write did not happen for, so the declared @var stands.
	public function testStandaloneFactoryRendererProvidesTheControlAxisAsNull(): void
	{
		$vars = $this->resolveFor([FactoryVarsStandaloneControl::class]);
		$types = $this->typesFor([FactoryVarsStandaloneControl::class]);

		if (self::typedDefaultTemplate()) {
			self::assertArrayNotHasKey('presenter', $vars);
			self::assertArrayNotHasKey('control', $vars);

			return;
		}

		self::assertSame(Certainty::MAYBE, $vars['presenter']['certainty']);
		self::assertSame('Nette\Application\UI\Presenter|null', $types['presenter']);
		self::assertSame(Certainty::MAYBE, $vars['control']['certainty']);
		self::assertSame('Nette\Application\UI\Control|null', $types['control']);
	}

	// A component that creates templates BOTH ways answers neither shape - the walk's OTHER verdict,
	// and the row that keeps "the renderer is a component, therefore it has a presenter" from being
	// the rule. The four dependency-side keys are untouched by it.
	public function testRendererCreatingTemplatesBothWaysClaimsNothingFromTheControlAxis(): void
	{
		self::assertSame(
			self::typedDefaultTemplate() ? ['flashes'] : ['user', 'baseUrl', 'basePath', 'flashes'],
			array_keys($this->typesFor([FactoryVarsDisagreeingControl::class])),
		);
	}

	// ... and being a PRESENTER does not rescue it: the vendor's own getPresenterIfExists() answer
	// only applies to the control the factory actually received, and this renderer's argument is
	// unknown. Both keys go, `control` included - the argument's SHAPE is what is missing here, and
	// no amount of knowing what class the renderer is substitutes for it.
	public function testDisagreeingPresenterRendererClaimsNeitherControlAxisKey(): void
	{
		$vars = $this->resolveFor([FactoryVarsDisagreeingPresenter::class]);

		self::assertArrayNotHasKey('presenter', $vars);
		self::assertArrayNotHasKey('control', $vars);
	}

	// The isInitialized gate on the control axis: a NATIVELY TYPED property with no default is never
	// exported until something writes it, and a factory that got no control never does - so it is
	// ABSENT rather than null. Same discipline the dependency axis applies to $user, and it holds
	// for both keys: the MAYBE that a NONE argument produces is precisely what the gate turns into
	// absence.
	public function testTypedControlAxisPropertiesAreAbsentWhenNoControlIsPassed(): void
	{
		$vars = $this->resolveFor([FactoryVarsTypedControlStandalone::class]);

		self::assertArrayNotHasKey('presenter', $vars);
		self::assertArrayNotHasKey('control', $vars);
	}

	// ... and the same typed properties ARE provided once a control really is passed, because then
	// the factory's own write initialises them. Without this half the row above would pass for a
	// source that simply never resolves that template class. The declared types stand unrefined: a
	// nullable native type is not a class name the refinement rule can read.
	public function testTypedControlAxisPropertiesAreProvidedOnceAControlIsPassed(): void
	{
		$vars = $this->resolveFor([FactoryVarsTypedControlPresenter::class]);

		self::assertSame(Certainty::HAPPENS, $vars['presenter']['certainty']);
		self::assertSame('Nette\Application\UI\Presenter|null', $vars['presenter']['type']);
		self::assertSame(Certainty::HAPPENS, $vars['control']['certainty']);
		self::assertSame('Nette\Application\UI\Control|null', $vars['control']['type']);
	}

	// `control` is decided by the very same recorded argument, and on a presenter renderer the two
	// keys coincide: the argument is $this, so $control IS the presenter too - refined to the
	// renderer's own class off the template class's declared Nette\Application\UI\Control, which the
	// renderer satisfies.
	public function testPresenterRendererProvidesItselfAsTheControlAsWell(): void
	{
		$vars = $this->resolveFor([FactoryVarsPresenterRenderer::class]);

		self::assertSame(Certainty::HAPPENS, $vars['control']['certainty']);
		self::assertSame(
			'\\' . FactoryVarsPresenterRenderer::class,
			$this->typesFor([FactoryVarsPresenterRenderer::class])['control'],
		);
	}

	// THE property_exists GATE: the resolved template class declares no $user, so $user is not a
	// variable of that template however unconditionally the factory would have set it.
	public function testTemplateClassNotDeclaringAPropertyNeverProvidesThatVariable(): void
	{
		self::assertSame(
			[
				'baseUrl' => 'string|null',
				'basePath' => 'string|null',
				'flashes' => 'array<stdClass>',
			],
			$this->typesFor([FactoryVarsNoUserPresenter::class]),
		);
	}

	// THE FLOOR: the renderer names no template class, and with no configured factory default the
	// ladder answers with the bare Template interface - "nobody named a class", never a class whose
	// properties may be read.
	//
	// The SCOPE-CLASS half is what makes this row bite the floor gate itself: the vars half alone
	// passes for an unrelated reason (the floor's answer is an INTERFACE, which class_exists()
	// rejects anyway), so only the class list can tell "the gate refused" apart from "the gate let
	// it through and the property read came back empty" - and letting it through would emit a
	// dependency ref on an interface that declares nothing.
	public function testUnresolvableTemplateClassProvidesNothing(): void
	{
		self::assertSame([], $this->typesFor([FactoryVarsFloorPresenter::class]));
		self::assertSame([], $this->classesFor([FactoryVarsFloorPresenter::class]));
	}

	// The SAME renderer, with the factory-default rung live: the configured TemplateFactory's own
	// default template class supplies the properties, so the ladder's fallback rung is what answers
	// the property_exists gate here. All six keys, because this renderer is a vendor Presenter
	// descendant on the inherited createTemplate() - the control axis answers for it too.
	public function testFactoryDefaultRungSuppliesTheTemplateClass(): void
	{
		$vars = $this->resolveFor([FactoryVarsFloorPresenter::class], self::FactoryDefaultLoaderFile);

		self::assertSame(
			['user', 'baseUrl', 'basePath', 'flashes', 'control', 'presenter'],
			array_keys($vars),
		);
		self::assertSame(FactoryVarsTemplateReplica::class, $vars['user']['class']);
	}

	// The declared type comes from the RESOLVED class, never from the vendor's own DefaultTemplate -
	// which is what makes this a different answer from the DefaultTemplate row's Nette\Security\User.
	public function testDeclaredTypeComesFromTheResolvedTemplateClass(): void
	{
		self::assertSame(
			['user' => 'FactoryVarsNarrowedUserReplica|null'],
			$this->typesFor([FactoryVarsNarrowedPresenter::class]),
		);
	}

	// PropertyTypeResolver hands back the raw @var string, so an unqualified docblock name reaches
	// the template scope unqualified - a pre-existing limitation of the shared property surface (the
	// {templateType} channel resolves types the same way), pinned here because this source is the
	// first consumer to meet it on a VENDOR-declared property set. The vendor's own DefaultTemplate
	// writes its @var tags fully qualified, so the four variables it provides are unaffected.
	public function testShortDocblockNameIsKeptVerbatim(): void
	{
		self::assertSame(
			['user' => 'FactoryVarsUserReplica|null'],
			$this->typesFor([FactoryVarsShortNamePresenter::class]),
		);
	}

	// Certainty, both directions of the one variable the factory never leaves null: $flashes is
	// definitely present only when the property's own default is non-null too, because a template
	// built outside the factory keeps that default and nothing else.
	public function testFlashesIsDefiniteOnlyWithANonNullPropertyDefault(): void
	{
		self::assertSame(
			Certainty::HAPPENS,
			$this->resolveFor([FactoryVarsFullPresenter::class])['flashes']['certainty'],
		);
		self::assertSame(
			Certainty::MAYBE,
			$this->resolveFor([FactoryVarsFlashlessPresenter::class])['flashes']['certainty'],
		);
		self::assertSame(
			['flashes' => 'array<stdClass>|null'],
			$this->typesFor([FactoryVarsFlashlessPresenter::class]),
		);
	}

	// THE WIRED CONTAINER, which is what decides the vendor's `$value !== null` condition: with both
	// optional TemplateFactory dependencies supplied, all four variables are DEFINITELY present, and
	// $user carries the WIRED object's own class rather than the vendor supertype the template class
	// declares. Definite is the load-bearing half - a maybe-defined variable would be modelled as a
	// nullable one, and a nullable $user makes every `$user->isInRole(...)` in the corpus a
	// nullability error the wiring proves impossible.
	public function testWiredContainerMakesTheFactoryVariablesDefinitelyAvailable(): void
	{
		$vars = $this->resolveFor([FactoryVarsVendorDefaultPresenter::class], self::WiredLoaderFile);

		self::assertSame(Certainty::HAPPENS, $vars['user']['certainty']);
		self::assertSame(Certainty::HAPPENS, $vars['baseUrl']['certainty']);
		self::assertSame(Certainty::HAPPENS, $vars['basePath']['certainty']);
		self::assertSame(
			[
				'user' => '\\' . FactoryVarsUserService::class,
				'baseUrl' => 'string',
				'basePath' => 'string',
				'flashes' => '\stdClass[]',
			],
			$this->typesFor([FactoryVarsVendorDefaultPresenter::class], self::WiredLoaderFile),
		);
	}

	// REFINE, never contradict: the wired class replaces the declared one only where the declaration
	// ADMITS it. Here it does not - an imported short name does not resolve to a class at all, which is
	// the shape every project template class's @var has under this project's own coding standard - so
	// the declaration stands, while availability, which the declaration has no say in, is still
	// definite. The admitting direction is the vendor DefaultTemplate row above.
	public function testWiredUserRefinesOnlyASupertypeDeclaration(): void
	{
		$vars = $this->resolveFor([FactoryVarsFullPresenter::class], self::WiredLoaderFile);

		self::assertSame(Certainty::HAPPENS, $vars['user']['certainty']);
		self::assertSame('FactoryVarsUserReplica', $vars['user']['type']);
	}

	// NO CONTAINER, NO CLAIM: with no loader file there is nothing to prove the optional
	// dependencies are wired, so availability falls back to the conservative answer rather than
	// guessing from the vendor's nullable constructor signature.
	public function testAbsentContainerKeepsTheContainerBackedVariablesConservative(): void
	{
		$vars = $this->resolveFor([FactoryVarsVendorDefaultPresenter::class]);

		if (self::typedDefaultTemplate()) {
			self::assertSame(['flashes'], array_keys($vars));

			return;
		}

		self::assertSame(Certainty::MAYBE, $vars['user']['certainty']);
		self::assertSame(Certainty::MAYBE, $vars['baseUrl']['certainty']);
		self::assertSame(Certainty::MAYBE, $vars['basePath']['certainty']);
	}

	// A NATIVELY TYPED property with no default - how newer nette/application versions spell these -
	// is never isInitialized(), so getParameters() does not export it and the variable is ABSENT
	// rather than null when the dependency is unwired. Providing a nullable something here would
	// claim a variable the runtime never hands the body.
	public function testTypedPropertyWithNoDefaultIsAbsentRatherThanNullWhenUnwired(): void
	{
		self::assertArrayNotHasKey(
			'user',
			$this->resolveFor([FactoryVarsTypedUserPresenter::class], self::FactoryDefaultLoaderFile),
		);
	}

	// ... and the same property IS provided once the container wires the dependency, because then
	// the factory writes it and getParameters() exports it. Without this half the row above would
	// pass for a source that simply never resolves that template class.
	public function testTypedPropertyWithNoDefaultIsProvidedOnceTheDependencyIsWired(): void
	{
		$vars = $this->resolveFor([FactoryVarsTypedUserPresenter::class], self::WiredLoaderFile);

		self::assertArrayHasKey('user', $vars);
		self::assertSame(Certainty::HAPPENS, $vars['user']['certainty']);
	}

	// The write's type clause: a wired value the typed property cannot hold never initializes it.
	public function testTypedPropertyTheWiredValueDoesNotFitIsAbsent(): void
	{
		self::assertArrayNotHasKey(
			'user',
			$this->resolveFor([FactoryVarsMistypedUserPresenter::class], self::WiredLoaderFile),
		);
	}

	// The other half of that gate, and a genuinely different verdict: the container IS there and
	// wires neither dependency, which is positive evidence of absence rather than missing evidence.
	// Both land on the conservative answer, and neither may be confused with the wired one.
	public function testContainerWiringNeitherDependencyKeepsThemConservative(): void
	{
		$vars = $this->resolveFor([FactoryVarsVendorDefaultPresenter::class], self::FactoryDefaultLoaderFile);
		$types = $this->typesFor([FactoryVarsVendorDefaultPresenter::class], self::FactoryDefaultLoaderFile);

		if (self::typedDefaultTemplate()) {
			self::assertSame(['flashes' => '\stdClass[]'], $types);

			return;
		}

		self::assertSame(Certainty::MAYBE, $vars['user']['certainty']);
		self::assertSame(Certainty::MAYBE, $vars['baseUrl']['certainty']);
		self::assertSame(
			[
				'user' => 'Nette\Security\User|null',
				'baseUrl' => 'string|null',
				'basePath' => 'string|null',
				'flashes' => '\stdClass[]',
			],
			$types,
		);
	}

	// A dynamic convention hook resolves no class name at all - OPEN, so nothing is claimed. Same
	// scope-class half as the floor row above, and here the stakes are higher: the ladder's dynamic
	// answer is the sentinel *dynamic*, which is not a class name at all and must never reach a
	// channel that emits it as one.
	public function testDynamicTemplateClassProvidesNothing(): void
	{
		self::assertSame([], $this->typesFor([FactoryVarsDynamicControl::class]));
		self::assertSame([], $this->classesFor([FactoryVarsDynamicControl::class]));
	}

	public function testTemplateWithNoStoreRecordProvidesNothing(): void
	{
		self::assertSame([], $this->typesFor([]));
	}

	public function testDisabledFlagProvidesNothing(): void
	{
		self::assertSame([], $this->typesFor([FactoryVarsVendorDefaultPresenter::class], null, false));
	}

	// All-renderers discipline: a variable one linked renderer's template class does not declare is
	// genuinely undefined when that renderer renders the template, so the claim is dropped for both.
	// $flashes survives in BOTH classes but with docblocks that do not match verbatim (the vendor
	// spells \stdClass[], the replica array<stdClass>), so it widens rather than picking one.
	public function testMultiRendererIntersectsTheProvidedVariables(): void
	{
		self::assertSame(
			self::typedDefaultTemplate()
				? ['flashes' => 'mixed']
				: [
					'baseUrl' => 'string|null',
					'basePath' => 'string|null',
					'flashes' => 'mixed',
				],
			$this->typesFor([FactoryVarsVendorDefaultPresenter::class, FactoryVarsNoUserPresenter::class]),
		);
	}

	// Two renderers declaring the same key with imported SHORT class names
	// (FactoryVarsUserReplica / FactoryVarsNarrowedUserReplica): PropertyTypeResolver hands back the
	// raw @var string, so class_exists() on either short name answers false and of() returns mixed
	// through that gate alone, never reaching the ancestor walk - it never even asks whether the two
	// are related. The genuinely-unrelated-classes branch is covered separately by
	// CommonClassAncestorTest::testUnrelatedClassesHaveNoCommonAncestor. The related case is the row
	// below it.
	public function testMultiRendererWidensDisagreeingTypes(): void
	{
		$vars = $this->resolveFor([FactoryVarsFullPresenter::class, FactoryVarsNarrowedPresenter::class]);

		self::assertSame('mixed', $vars['user']['type']);
		self::assertSame(
			FactoryVarsNarrowedTemplateReplica::class . '+' . FactoryVarsTemplateReplica::class,
			$vars['user']['class'],
		);
	}

	// The merge's real corpus shape: one template rendered by several presenters, each refining
	// $presenter to ITSELF, so every pair disagrees. Collapsing to mixed silenced the variable
	// entirely; the deepest common class is the tightest sound answer and is what the shared layout
	// of a whole application resolves to.
	public function testMultiRendererPresenterWidensToTheDeepestCommonAncestor(): void
	{
		$renderers = [
			FactoryVarsAncestorLeftPresenter::class,
			FactoryVarsAncestorRightPresenter::class,
		];

		self::assertSame(
			'\\' . FactoryVarsAncestorBasePresenter::class,
			$this->typesFor($renderers)['presenter'],
		);
	}

	// ... and `control` rides the very same fold, which is why a multi-renderer template gets ONE
	// $control type rather than one per renderer: each renderer refines it to itself, and the
	// pairwise intersect() widens those to their deepest common class ancestor exactly as it does
	// for $presenter. Measured on this project, app/Component/BaseControl/templates/@dataGrid.latte
	// resolves its 15 renderers to a single App\Control\BaseDataGridControl.
	public function testMultiRendererControlWidensToTheDeepestCommonAncestor(): void
	{
		$renderers = [
			FactoryVarsAncestorLeftPresenter::class,
			FactoryVarsAncestorRightPresenter::class,
		];

		self::assertSame(
			'\\' . FactoryVarsAncestorBasePresenter::class,
			$this->typesFor($renderers)['control'],
		);
	}

	// ... and the certainty half is untouched by the widening: both renderers hand the factory
	// themselves, so the variables stay definitely present rather than becoming nullable.
	public function testWidenedControlAxisStaysDefinitelyPresent(): void
	{
		$vars = $this->resolveFor([
			FactoryVarsAncestorLeftPresenter::class,
			FactoryVarsAncestorRightPresenter::class,
		]);

		self::assertSame(Certainty::HAPPENS, $vars['presenter']['certainty']);
		self::assertSame(Certainty::HAPPENS, $vars['control']['certainty']);
	}

	// One unresolvable renderer voids the WHOLE claim - the template can be rendered through it with
	// a template class whose declared properties are unknown. The class list proves it is the
	// all-or-nothing return that answers, not an intersection that happened to come out empty: a
	// resolver that merely skipped the unresolvable renderer would still name the other's class.
	public function testOneUnresolvableRendererVoidsTheClaim(): void
	{
		$renderers = [FactoryVarsVendorDefaultPresenter::class, FactoryVarsFloorPresenter::class];

		self::assertSame([], $this->typesFor($renderers));
		self::assertSame([], $this->classesFor($renderers));
	}

	public function testTemplateClassesForNamesTheResolvedClasses(): void
	{
		$dir = $this->scratchDir();

		try {
			self::assertSame(
				[FactoryVarsNoUserTemplateReplica::class, FactoryVarsTemplateReplica::class],
				$this->source($dir, [FactoryVarsFullPresenter::class, FactoryVarsNoUserPresenter::class])
					->templateClassesFor(self::TemplateRel),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// THE SCOPE SEAM: the root context of a store-linked template carries the factory variables,
	// labelled with the class that declared them.
	public function testContextResolverCarriesTheFactoryVariables(): void
	{
		$dir = $this->corpus([self::TemplateRel => "<p>body</p>\n"]);

		try {
			$contexts = $this->contexts($dir, [FactoryVarsVendorDefaultPresenter::class]);

			self::assertCount(1, $contexts);
			self::assertSame(
				self::typedDefaultTemplate()
					? ['flashes' => '\stdClass[]']
					: [
						'basePath' => 'string|null',
						'baseUrl' => 'string|null',
						'flashes' => '\stdClass[]',
						'user' => 'Nette\Security\User|null',
					],
				$contexts[0]->getVars(),
			);
			self::assertSame(
				'factory:' . DefaultTemplate::class,
				$contexts[0]->getProvenance()[self::typedDefaultTemplate() ? 'flashes' : 'user'],
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The template's own declaration is stronger than the ambient factory type - the factory layer
	// is the weakest one in the context.
	public function testOwnDeclarationWinsOverTheFactoryType(): void
	{
		$dir = $this->corpus([
			self::TemplateRel => '{varType ' . FactoryVarsUserReplica::class . " \$user}\nbody\n",
		]);

		try {
			$vars = $this->contexts($dir, [FactoryVarsVendorDefaultPresenter::class])[0]->getVars();

			self::assertSame(FactoryVarsUserReplica::class, $vars['user']);
			if (self::typedDefaultTemplate()) {
				self::assertArrayNotHasKey('baseUrl', $vars);
			} else {
				self::assertSame('string|null', $vars['baseUrl']);
			}
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Latte 2 hands an included file the includer's whole param set, and the edge machinery already
	// models that - so the partial inherits the factory variables from its includer's context.
	public function testIncludedPartialInheritsTheFactoryVariables(): void
	{
		$dir = $this->corpus([
			self::TemplateRel => "{include 'partial.latte'}\n",
			'partial.latte' => "body\n",
		]);

		try {
			$contexts = $this->contexts($dir, [FactoryVarsVendorDefaultPresenter::class], 'partial.latte');

			self::assertCount(1, $contexts);
			self::assertSame('\stdClass[]', $contexts[0]->getVars()['flashes']);
			if (!self::typedDefaultTemplate()) {
				self::assertSame('Nette\Security\User|null', $contexts[0]->getVars()['user']);
			}
		} finally {
			FileSystem::delete($dir);
		}
	}

	// EDGE-LOCAL, and the one place the control axis parts company with the ambient variables:
	// $control and $presenter name the RENDERER'S OWN IDENTITY, so an includer's value describes the
	// includer. Both are dropped when a context crosses an include edge while $user rides it as
	// before - the partial here is linked to no renderer of its own, so it ends up saying nothing
	// about either rather than repeating its includer's. Keeping them would be one context per
	// includer for every shared partial: measured on this project, app/templates/@layout.latte went
	// from 4 contexts to 41, multiplying its findings and its baseline counts by ten.
	public function testIncludedPartialDoesNotInheritTheControlAxisVariables(): void
	{
		$dir = $this->corpus([
			self::TemplateRel => "{include 'partial.latte'}\n",
			'partial.latte' => "body\n",
		]);

		try {
			$includer = $this->contexts($dir, [FactoryVarsPresenterRenderer::class])[0]->getVars();
			self::assertArrayHasKey('presenter', $includer);
			self::assertArrayHasKey('control', $includer);

			$vars = $this->contexts($dir, [FactoryVarsPresenterRenderer::class], 'partial.latte')[0]->getVars();

			self::assertArrayNotHasKey('presenter', $vars);
			self::assertArrayNotHasKey('control', $vars);
			self::assertSame('\stdClass[]', $vars['flashes']);
			if (!self::typedDefaultTemplate()) {
				self::assertSame('Nette\Security\User|null', $vars['user']);
			}
		} finally {
			FileSystem::delete($dir);
		}
	}

	// THE INVALIDATION CHANNEL. The template class the scope is derived from is not a dependency of
	// the template by anything PHPStan can see on its own - the link runs through the discovery
	// store, not through any symbol the .latte file mentions. The routing parser therefore emits it
	// on the same ref channel a declared {templateType} rides, so ADDING a property to (or removing
	// one from) that class reanalyses this template on a warm run instead of serving a scope
	// computed from a class that has since changed shape.
	public function testRoutingParserRefsTheFactoryProvidedTemplateClass(): void
	{
		$dir = $this->corpus([self::TemplateRel => "<p>body</p>\n"]);

		try {
			$stmts = $this->parse($dir, [FactoryVarsVendorDefaultPresenter::class]);

			self::assertTrue(
				$this->refsClass($stmts, DefaultTemplate::class),
				'the resolved template class must ride the templateType ref channel',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// The library default (no source wired at all) leaves every context byte-identical.
	public function testResolverWithoutTheSourceIsUnchanged(): void
	{
		$dir = $this->corpus([self::TemplateRel => "body\n"]);

		try {
			$index = $this->index($dir, [FactoryVarsVendorDefaultPresenter::class]);

			self::assertEquals(
				$this->resolver($dir, $index, null)->contextsFor(self::TemplateRel),
				(new ContextResolver(
					$index,
					new DeclarationScanner(),
					$this->universe($dir),
					$this->capturedOverlay($dir),
				))->contextsFor(self::TemplateRel),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $rendererClasses
	 * @return array<string, string>
	 */
	private function typesFor(array $rendererClasses, ?string $loaderFile = null, bool $enabled = true): array
	{
		$dir = $this->scratchDir();

		try {
			return $this->source($dir, $rendererClasses, $loaderFile, $enabled)->typesFor(self::TemplateRel);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $rendererClasses
	 * @return array<Stmt>
	 */
	private function parse(string $dir, array $rendererClasses): array
	{
		$root = $dir . '/templates';
		$contextResolver = $this->resolver($dir, $this->index($dir, $rendererClasses), null);

		return (new LatteRoutingParser(
			$this->createMock(Parser::class),
			new LatteCompiler(),
			new DeclarationScanner(),
			PipelineFactory::create($root, null, false),
			$contextResolver,
			PipelineFactory::createIncludeContractChecker($root, $contextResolver, null, false),
			PipelineFactory::createDeclarationConsistencyChecker($root),
			PipelineFactory::createTemplateEdgeIndex($root),
			PipelineFactory::createSiteScopeStore($root),
			PipelineFactory::createRichAttributeDecorator(),
			$root,
			true,
			false,
			null,
			$this->source($dir, $rendererClasses),
		))->parseFile($root . '/' . self::TemplateRel);
	}

	/**
	 * @param array<Stmt> $stmts
	 */
	private function refsClass(array $stmts, string $className): bool
	{
		foreach ((new NodeFinder())->findInstanceOf($stmts, StaticCall::class) as $call) {
			if (
				!$call->class instanceof Name
				|| $call->class->toString() !== Helpers::class
				|| !$call->name instanceof Identifier
				|| $call->name->toString() !== 'analyzed'
				|| !isset($call->args[0])
				|| !$call->args[0] instanceof Arg
				|| !$call->args[0]->value instanceof ClassConstFetch
				|| !$call->args[0]->value->class instanceof Name
			) {
				continue;
			}

			if ($call->args[0]->value->class->toString() === $className) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string> $rendererClasses
	 * @return list<string>
	 */
	private function classesFor(array $rendererClasses, ?string $loaderFile = null): array
	{
		$dir = $this->scratchDir();

		try {
			return $this->source($dir, $rendererClasses, $loaderFile)->templateClassesFor(self::TemplateRel);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $rendererClasses
	 * @return array<string, array{certainty: string, type: string, class: string}>
	 */
	private function resolveFor(array $rendererClasses, ?string $loaderFile = null): array
	{
		$dir = $this->scratchDir();

		try {
			return $this->source($dir, $rendererClasses, $loaderFile)->resolveFor(self::TemplateRel);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $rendererClasses
	 * @return list<TemplateContext>
	 */
	private function contexts(string $dir, array $rendererClasses, string $rel = self::TemplateRel): array
	{
		$index = $this->index($dir, $rendererClasses);

		return $this->resolver($dir, $index, $this->source($dir, $rendererClasses))->contextsFor($rel);
	}

	/**
	 * @param list<string> $rendererClasses
	 */
	private function index(string $dir, array $rendererClasses): TemplateEdgeIndex
	{
		return new TemplateEdgeIndex(
			$this->universe($dir),
			new TemplateFactExtractor(),
			null,
			$this->store($dir, $rendererClasses),
			true,
		);
	}

	private function resolver(string $dir, TemplateEdgeIndex $index, ?FactoryProvidedVars $source): ContextResolver
	{
		return new ContextResolver(
			$index,
			new DeclarationScanner(),
			$this->universe($dir),
			$this->capturedOverlay($dir),
			false,
			$source,
		);
	}

	/**
	 * @param list<string> $rendererClasses
	 */
	private function source(
		string $dir,
		array $rendererClasses,
		?string $loaderFile = null,
		bool $enabled = true
	): FactoryProvidedVars
	{
		// ONE resolver instance for both consumers, exactly like the container wiring: the
		// factory-default rung and the wiring read must come off the same single container load.
		$templateFactoryDefault = new TemplateFactoryDefaultResolver($loaderFile);

		return new FactoryProvidedVars(
			new FixtureTemplateTypeContainer(
				$this->recordSource($dir, $templateFactoryDefault),
				new PairingJudge(self::createReflectionProvider()),
				self::createReflectionProvider(),
			),
			$this->store($dir, $rendererClasses),
			$templateFactoryDefault,
			$enabled,
		);
	}

	/**
	 * @param list<string> $rendererClasses
	 */
	private function store(string $dir, array $rendererClasses): DiscoveryStore
	{
		$store = new DiscoveryStore($dir . '/store');
		$store->replaceWith(
			$rendererClasses === []
				? []
				: [
					self::TemplateRel => array_map(
						static fn (string $className): array => [
							'class' => $className,
							'view' => 'default',
							'kind' => CandidatePath::KIND_FORMULA,
							'certainty' => Certainty::HAPPENS,
						],
						$rendererClasses,
					),
				],
			$rendererClasses,
			[],
		);

		return $store;
	}

	private function recordSource(
		string $dir,
		TemplateFactoryDefaultResolver $templateFactoryDefault
	): DiscoveryRecordSource
	{
		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$appRoot = realpath(self::AppFixtureDir);
		self::assertNotFalse($appRoot);

		$discoveryResolver = new DiscoveryResolver(null, [], $appRoot);

		return new DiscoveryRecordSource(
			new PhpFactsCache(
				new LatteAnalysisCache($dir . '/cache'),
				$templateFactoryDefault,
				$discoveryResolver,
				[],
			),
			new PhpRenderWalk(
				self::createReflectionProvider(),
				$parser,
				[$appRoot],
				$templateFactoryDefault,
				$discoveryResolver,
			),
		);
	}

	/**
	 * @param array<string, string> $templates
	 */
	private function corpus(array $templates): string
	{
		$dir = $this->scratchDir();
		foreach ($templates as $basename => $source) {
			FileSystem::write($dir . '/templates/' . $basename, $source);
		}

		return $dir;
	}

	private function capturedOverlay(string $dir): CapturedOverlay
	{
		return new CapturedOverlay(new SiteScopeStore($dir . '/__absent_sitescope_store__'), true);
	}

	private function universe(string $dir): LatteUniverse
	{
		return new LatteUniverse([$dir . '/templates'], $dir . '/templates');
	}

	private static function typedDefaultTemplate(): bool
	{
		return InstalledVersionsGuard::satisfies('nette/application', '>=3.2');
	}

	private function scratchDir(): string
	{
		return sys_get_temp_dir() . '/latte-factory-vars-' . getmypid() . '-' . uniqid('', true);
	}

}
