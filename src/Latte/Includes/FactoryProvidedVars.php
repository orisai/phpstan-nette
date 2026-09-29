<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use LogicException;
use Nette\Application\Attributes\TemplateVariable;
use Nette\Application\UI\Presenter;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\Qualification;
use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Declarations\CommonClassAncestor;
use OriPhpstan\Nette\Latte\Declarations\PropertyTypeResolver;
use PHPStan\DependencyInjection\Container;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use function array_key_exists;
use function array_keys;
use function class_exists;
use function implode;
use function in_array;
use function interface_exists;
use function is_a;
use function ltrim;
use function method_exists;
use function sort;
use function strpos;
use function strtolower;
use const SORT_STRING;

// The variables Nette\Bridges\ApplicationLatte\TemplateFactory::createTemplate() writes onto the
// template object before rendering (3.1: createTemplate(); 3.2: injectDefaultVariables()), resolved
// per TEMPLATE from the renderer classes the discovery store links to it. Nothing about the variables
// themselves is tabulated here: presence and type are read off the INSTALLED resolved template class
// (3.1's DefaultTemplate declares them untyped, so an unwritten one is null; 3.2's types them without
// a default, so an unwritten one is absent), which is why the answer follows the installed
// nette/application line without a version switch.
//
// THE VENDOR CONTRACT, and every condition it puts on a variable being there at all:
//   foreach ($params as $key => $value) {
//       if ($value !== null && property_exists($template, $key)) { $template->$key = $value; }
//   }
// 3.2 wraps the write in try/catch (TypeError), so a value the declared type cannot hold is skipped
// rather than fatal - see writtenValueFits().
//   1. property_exists - only a property the RESOLVED template class declares is ever written, and
//      the property's own declared type is the type the template body sees. Gated below against the
//      full SP1/SP2 resolution ladder, including its factory-default rung.
//   2. $value !== null - a declared property is still not written when the factory's own value is
//      null. Which of the factory's own values CAN be null is not readable from its constructor
//      signature: ?Nette\Security\User and ?Nette\Http\IRequest are nullable because nette/security
//      and nette/http integration are OPTIONAL, not because a wiring that has them can lose them.
//      certaintyOf() therefore asks the compiled container what this application actually wired
//      (TemplateFactoryDefaultResolver::resolveWiring(), the same single container load the
//      factory-default rung above already pays for), and falls back to MAYBE whenever there is no
//      container to ask.
// Nette\Bridges\ApplicationLatte\Template::getParameters() then exports the PUBLIC properties, so
// public-only is the effective gate here even though the vendor's own condition is
// property_exists() (any visibility): a non-public property could never become a template variable,
// and the factory's own `$template->$key = $value` write would fail on one.
//
// THE SECOND AXIS, the control one: createTemplate()'s FIRST ARGUMENT decides `control` and
// `presenter` ($presenter = $control ? $control->getPresenterIfExists() : null), and a standalone
// $factory->createTemplate() call passes no control at all. PhpRenderFacts::getCreateTemplateControl()
// is the walk's joined answer over every path the RENDERER reaches the factory by, so this axis's
// keys are resolved per (template class, renderer) rather than per template class alone. Only SELF
// and NONE say anything; OTHER and the no-observation answer claim nothing, which is what keeps this
// off "the renderer is a component, therefore it has a control" - an inference this corpus disproves,
// since components calling the factory standalone outnumber the ones that do not. Both keys name the
// RENDERER'S OWN IDENTITY, which is why ContextResolver refuses to let either cross an include edge.
//
// `control` IS CLAIMED, and what held it back was a measurement rather than a missing mechanism.
// Typed as the RENDERER class - the only type that is actually right - `{var $form =
// $control['form']}` starts resolving, and the forms bridge then answers every nested replicator
// access behind it. That used to produce a false-positive CLASS on correct code (26 `Offset int does
// not exist on Kdyby\Replicator\Container`, 9 `Form component 'x' does not exist`, plus undefined
// members on an unresolved Nette\ComponentModel\IComponent), which is the direction this project
// ranks worse than a missed detection. Every one of those was closed at ITS OWN source rather than
// by widening the type back here: the bare-leaf replicator type, the existence clause for a
// value-less control, the second FormShapeProjector copy, the catalog stub's dropped @property tags,
// and the replicator own-child the component walk reaches mid-chain. A whole-corpus cold run now
// measures ZERO of both classes and zero `formShape.*` AMONG THE 189 - not zero in the corpus, which
// keeps one pre-existing `orisaiNette.forms.partiallyUnknown` this change never touches.
//
// What the key costs and buys, whole-corpus and both measured: 73 baselined `Undefined variable:
// $control` entries across 23 templates go away (of a pre-existing 148 across 37 - the 75 that
// remain are the edge-local drop on twelve included advancedProfileParts partials plus the two
// legacy datagrids, whose renderers land on OTHER), and 189 findings arrive, all baselined. They are
// 46 `Nette\Utils\Html|string` (the vendor's own untyped BaseControl::getControl() docblock, reached
// through {input}/{label}), 131 provably-redundant checks of which 122 are inside the
// `is_object(...) ? ... : ...` and `if ($_label = ...)` that Nette's own FormMacros GENERATES for
// every {input}/{label}/n:name - no developer wrote THOSE, and they only become provable once the
// expression types - while the other 9 ARE author-written and one of them, getOptions('id'), is a
// genuine app defect (docs/phpstan-latte.md lists all nine); and 12 residual: 5 calls to count() on
// the vendor's uncountable getContainers() iterator, a live app off-by-one the owner chose to keep,
// and 7 on the Nette\ComponentModel\IComponent member floor an honestly-open container shape
// degrades to on the TYPE axis. That floor is not this key's doing: the baseline already carried 213
// member accesses on it across 42 paths before `control` existed, on templates that never mention
// it (the wider 276-across-49 count that reads naturally as "the floor" also sweeps in 60
// offsetAccess findings and 12 .php paths, so it is not the comparable number).
//
// NOT GATED ON THE CREATION CHANNEL, ON PURPOSE - TemplateClassFact::CHANNEL_NEW does NOT mean
// "manually constructed": PhpRenderWalk records it both for a `return new X` inside a resolved
// createTemplate() override AND for a TRUSTED FACTORY CALL inside that same override (see the
// $isCreateTemplateOverride ternary in extractTemplateClassCandidatesFromMethod()), so refusing
// factory variables on that channel would deny them to factory-created templates - a false
// variable.undefined on correct code. Manual construction is instead handled where it actually
// changes the answer: certaintyOf() only calls a variable definitely-present when the property's
// own DEFAULT is non-null, which holds whether or not the factory ever touched it.
final class FactoryProvidedVars
{

	public const RECORD_SOURCE_SERVICE_NAME = 'latteDiscoveryRecordSource';

	public const PAIRING_JUDGE_SERVICE_NAME = 'lattePairingJudge';

	private const VAR_CONTROL = 'control';

	private const VAR_PRESENTER = 'presenter';

	private const VARIABLES = ['user', 'baseUrl', 'basePath', 'flashes', self::VAR_CONTROL, self::VAR_PRESENTER];

	// The keys the createTemplate() control argument decides, as opposed to the wired-dependency
	// keys below - the two axes never overlap, and every key belongs to exactly one of them.
	private const FROM_CONTROL_ARGUMENT = [self::VAR_CONTROL, self::VAR_PRESENTER];

	// The control axis's keys, published for the ONE consumer that has to know they name the
	// RENDERER'S OWN IDENTITY rather than something ambient: ContextResolver drops them when a
	// context crosses an include edge - see its own edge-local comment for the measurement that
	// forced it.
	public const RENDERER_IDENTITY_VARIABLES = [self::VAR_CONTROL, self::VAR_PRESENTER];

	// The keys whose factory-side value is never null WITHOUT asking any container: $flashes falls
	// back to [] (the presenter's flash session is consulted only to fill it), so the $value !== null

	private const NEVER_NULL_FROM_FACTORY = ['flashes'];

	// The factory's non-object values, for the type clause of the write.
	private const WRITTEN_VALUE_TYPES = ['baseUrl' => 'string', 'basePath' => 'string', 'flashes' => 'array'];

	// The keys whose factory-side value is exactly one wired TemplateFactory dependency, or derived
	// from one: $user IS the wired user, while $baseUrl (and the $baseUrl-derived $basePath) is a
	// non-empty string whenever an httpRequest is wired and null when it is not. The flag says
	// whether the variable's VALUE is that dependency itself, which is what lets the wired object's

	private const FROM_DEPENDENCY = [
		'user' => [TemplateFactoryDefaultResolver::KEY_USER, true],
		'baseUrl' => [TemplateFactoryDefaultResolver::KEY_HTTP_REQUEST, false],
		'basePath' => [TemplateFactoryDefaultResolver::KEY_HTTP_REQUEST, false],
	];

	private Container $container;

	private DiscoveryStore $store;

	private TemplateFactoryDefaultResolver $templateFactoryDefault;

	private bool $enabled;

	private ?DiscoveryRecordSource $recordSource = null;

	private ?PairingJudge $judge = null;

	/** @var array<string, array{vars: array<string, array{certainty: Certainty::*, type: string, class: string}>, classes: list<string>}> */
	private array $memo = [];

	// Keyed by template class AND renderer: the wired-dependency keys are the same answer for every
	// renderer, but the control-argument keys are not.
	/** @var array<string, array<string, array{certainty: Certainty::*, type: string, class: string}>> */
	private array $byRenderer = [];

	/** @var array<string, array<string, array{certainty: Certainty::*, type: string, class: string}>> */
	private array $templateVariables = [];

	public function __construct(
		Container $container,
		DiscoveryStore $store,
		TemplateFactoryDefaultResolver $templateFactoryDefault,
		bool $enabled
	)
	{
		$this->container = $container;
		$this->store = $store;
		$this->templateFactoryDefault = $templateFactoryDefault;
		$this->enabled = $enabled;
	}

	/**
	 * @return array<string, array{certainty: Certainty::*, type: string, class: string}>
	 */
	public function resolveFor(string $projectRelativePath): array
	{
		return $this->resolved($projectRelativePath)['vars'];
	}

	// The scope layer itself: MAYBE materializes as a nullable type, which is not an approximation
	// but the runtime's own answer - an untyped property the factory skipped keeps its implicit
	// null and Template::getParameters() exports it, so the variable exists AND is null. That is
	// also the only encoding that silences variable.undefined without making isset($x) always true
	// (isset() IS "defined and not null"), which is the direction that would invent findings.

	/**
	 * @return array<string, string>
	 */
	public function typesFor(string $projectRelativePath): array
	{
		$types = [];
		foreach ($this->resolveFor($projectRelativePath) as $name => $entry) {
			$types[$name] = $entry['certainty'] === Certainty::HAPPENS
				? $entry['type']
				: self::nullable($entry['type']);
		}

		return $types;
	}

	/**
	 * @return array<string, string>
	 */
	public function provenanceFor(string $projectRelativePath): array
	{
		$provenance = [];
		foreach ($this->resolveFor($projectRelativePath) as $name => $entry) {
			$provenance[$name] = 'factory:' . $entry['class'];
		}

		return $provenance;
	}

	// The template classes this file's scope is derived from - LatteRoutingParser refs them so a
	// property ADDED TO or REMOVED FROM one of them (a signature-level edit) reanalyses this
	// template on the same warm run. Without the ref the paired class is not a dependency of the
	// template at all (TemplateTypeChecker's class doc documents that same hole for the
	// {templateType} identifiers).

	/**
	 * @return list<string>
	 */
	public function templateClassesFor(string $projectRelativePath): array
	{
		return $this->resolved($projectRelativePath)['classes'];
	}

	/**
	 * @return array{vars: array<string, array{certainty: Certainty::*, type: string, class: string}>, classes: list<string>}
	 */
	private function resolved(string $projectRelativePath): array
	{
		if (array_key_exists($projectRelativePath, $this->memo)) {
			return $this->memo[$projectRelativePath];
		}

		return $this->memo[$projectRelativePath] = $this->resolve($projectRelativePath);
	}

	/**
	 * @return array{vars: array<string, array{certainty: Certainty::*, type: string, class: string}>, classes: list<string>}
	 */
	private function resolve(string $projectRelativePath): array
	{
		$nothing = ['vars' => [], 'classes' => []];

		if (!$this->enabled) {
			return $nothing;
		}

		$classNames = [];
		foreach ($this->store->recordsForTemplate($projectRelativePath) as $record) {
			$classNames[$record['class']] = true;
		}

		if ($classNames === []) {
			return $nothing;
		}

		$names = array_keys($classNames);
		sort($names, SORT_STRING);

		$templateClasses = [];
		$resolved = null;
		foreach ($names as $className) {
			$templateClass = $this->templateClassFor($className);

			// OPEN, never false-close: one renderer whose template class is unresolvable (opaque,
			// dynamic, or the bare Template floor) means this template can be rendered with a class
			// whose declared properties are unknown, so no variable may be claimed for it at all.
			if ($templateClass === null) {
				return $nothing;
			}

			$templateClasses[$templateClass] = true;
			$templateVariables = $this->templateVariablesOf($className);
			foreach ($templateVariables as $entry) {
				$templateClasses[$entry['class']] = true;
			}

			$vars = $this->varsOfRenderer($templateClass, $className) + $templateVariables;
			$resolved = $resolved === null ? $vars : self::intersect($resolved, $vars);
		}

		// The class list stands even when the intersection came out empty: those classes are still
		// this template's scope inputs, and a property ADDED to one of them is exactly the edit the
		// ref emitted off this list has to carry.
		$classes = array_keys($templateClasses);
		sort($classes, SORT_STRING);

		return ['vars' => $resolved ?? [], 'classes' => $classes];
	}

	private function templateClassFor(string $rendererClassName): ?string
	{
		$facts = $this->recordSource()->factsFor($rendererClassName);

		// Shared qualification gate: judge() throws on non-qualifying facts by ratified contract,
		// and a non-qualifying class has no resolved template class to gate properties on.
		if (!Qualification::qualifies($facts)) {
			return null;
		}

		$verdict = $this->judge()->judge($facts);

		// These two clauses are REDUNDANT WITH EACH OTHER on every renderer this source can reach
		// today, and neither has an individually discriminating fixture as a result: the walk never
		// records a class name reflection cannot resolve (phpdocTemplateClassCandidate() and the
		// resolved-surface candidates all go through a ClassReflection), so *dynamic* is the only
		// class-level candidate PairingJudge ever marks opaque - and when it is the primary, both
		// clauses fire. Proven jointly: neutralising BOTH lets the dynamic renderer through.
		// Kept as a pair rather than collapsed, because the two say different things - "this verdict
		// names something unreadable" and "this verdict resolved nothing at all" - and only the
		// first survives a channel that starts recording unresolved names (PairingJudge already
		// models that case; see its own opaque test).
		if (
			$verdict->getOpaques() !== []
			|| $verdict->getPrimaryClass() === TemplateClassFact::DYNAMIC_CLASS_NAME
			// The bare interface floor is the ladder's "nobody named a class" answer, not a class
			// whose properties may be read - silence, never a guess. The factory-default rung above
			// it IS a real class (the configured TemplateFactory's own default) and does resolve.
			|| $verdict->getPrimaryChannel() === TemplateClassFact::CHANNEL_TEMPLATE_FLOOR
		) {
			return null;
		}

		$primary = $verdict->getPrimaryClass();

		// A $template property's declared type says what the property HOLDS; it never decides what
		// the factory instantiates. Nette derives that from formatTemplateClass(), whose
		// checkTemplateClass() returns null when the conventional <Name>Template does not exist, and
		// the factory then falls back to its own configured class (createTemplate(): $class ??
		// $this->templateClass). So a declaration that is a STRICT SUPERTYPE of the configured class
		// is merely SATISFIED by it - the object really constructed is the configured one, and its
		// properties are the ones the template body sees. A declaration naming something the
		// configured class does not satisfy stands: that is a genuine override, not a widening.
		$configured = $this->templateFactoryDefault->resolve();

		return $configured !== null && $configured !== $primary && is_a($configured, $primary, true)
			? $configured
			: $primary;
	}

	/**
	 * @return array<string, array{certainty: Certainty::*, type: string, class: string}>
	 */
	private function varsOfRenderer(string $templateClass, string $rendererClass): array
	{
		$key = $templateClass . '|' . $rendererClass;
		if (array_key_exists($key, $this->byRenderer)) {
			return $this->byRenderer[$key];
		}

		return $this->byRenderer[$key] = $this->readTemplateClass($templateClass, $rendererClass);
	}

	/**
	 * @return array<string, array{certainty: Certainty::*, type: string, class: string}>
	 */
	private function readTemplateClass(string $templateClass, string $rendererClass): array
	{
		// class_exists() rather than the reflection provider, matching DeclaredVarsResolver's own
		// property read: the declared TYPE has to come from PropertyTypeResolver so this surface and
		// the {templateType} surface can never drift on how a property's @var resolves. An interface
		// (the Template floor is one) answers false here too.
		if (!class_exists($templateClass)) {
			return [];
		}

		try {
			$properties = PropertyTypeResolver::resolveAllPublic($templateClass);
		} catch (ReflectionException $e) {
			return [];
		}

		$defaults = (new ReflectionClass($templateClass))->getDefaultProperties();

		$vars = [];
		foreach (self::VARIABLES as $name) {
			if (!array_key_exists($name, $properties)) {
				continue;
			}

			$fromControlArgument = in_array($name, self::FROM_CONTROL_ARGUMENT, true);
			$certainty = $fromControlArgument
				? $this->controlArgumentCertaintyOf($name, $rendererClass)
				: $this->certaintyOf($name, $defaults[$name] ?? null);

			// OPEN, never false-close: the control axis answers null when the walk could not tell
			// which shape the createTemplate() call had, and an unknown shape claims nothing.
			if ($certainty === null) {
				continue;
			}

			// getParameters() exports a public property only when it isInitialized(), and a TYPED
			// property with no default never is until something writes it. 3.1 spells these
			// untyped - implicitly null, therefore always exported, which is what makes the
			// nullable-when-unwired encoding above the runtime's own answer - while 3.2 types them,
			// and then an unwired dependency (or a factory call that passes no control) means the
			// variable is not merely null, it is ABSENT. Native getDefaultProperties() is exactly
			// the isInitialized() predicate: it omits a typed property that has no default and
			// lists every other one, untyped ones included. The certainty IS the "something writes
			// it" half on both axes - definitely-present is exactly the case where the factory's
			// own write happens, and the write only initializes the property when its value fits.
			if (
				!array_key_exists($name, $defaults)
				&& (
					$certainty !== Certainty::HAPPENS
					|| !$this->writtenValueFits($templateClass, $name, $rendererClass)
				)
			) {
				continue;
			}

			$vars[$name] = [
				'certainty' => $certainty,
				'type' => $fromControlArgument
					? $this->controlArgumentTypeOf($rendererClass, $properties[$name], $certainty)
					: $this->typeOf($name, $properties[$name]),
				'class' => $templateClass,
			];
		}

		return $vars;
	}

	// THE CONTROL AXIS. $control IS createTemplate()'s first argument and $presenter is that
	// argument's own getPresenterIfExists(), so the walk's joined shape decides both:
	//   SELF - the renderer's own instance reaches the factory, so $control is definitely written
	//     and IS the renderer: the argument is $this, and no hierarchy question arises on it.
	//     $presenter follows only where getPresenterIfExists() cannot answer null, which on the
	//     vendor source is exactly Presenter's own final override returning $this; for a plain
	//     Control it is the runtime attachment state, which no static fact here can prove.
	//   NONE - nothing is written, which is NOT the same as nothing being there: 3.1 spells both
	//     properties untyped, so they keep their implicit null and getParameters() exports them.
	//     MAYBE is that exact answer (the variable exists and is null); the isInitialized gate
	//     above turns it into absence for a typed property (3.2), where the runtime agrees.
	//   OTHER / no observation - claim nothing.

	/**
	 * @return Certainty::*|null
	 */
	private function controlArgumentCertaintyOf(string $name, string $rendererClass): ?string
	{
		$shape = $this->recordSource()->factsFor($rendererClass)->getCreateTemplateControl();

		if ($shape === PhpRenderFacts::CONTROL_NONE) {
			return Certainty::MAYBE;
		}

		if ($shape !== PhpRenderFacts::CONTROL_SELF) {
			return null;
		}

		// SELF settles `control` on its own: the argument IS the renderer, so the property is written
		// and the class it is written with is known outright. `presenter` is one hop further and the
		// hop can fail - Presenter::getPresenterIfExists() is a final override returning $this, so a
		// presenter that hands the factory itself definitely has one, while a plain control's answer
		// is its runtime attachment state, which no walked fact can prove.
		return $name === self::VAR_CONTROL || is_a($rendererClass, Presenter::class, true)
			? Certainty::HAPPENS
			: null;
	}

	// REFINE, never contradict, the same rule typeOf() applies to the wired dependency: the object
	// written is the RENDERER itself on both keys ($control is it, and a presenter's own
	// getPresenterIfExists() returns $this), so the renderer's class replaces the template class's
	// declared @var only where that @var admits it. A merely-MAYBE key is never refined: there the
	// factory's write is exactly what did not happen.
	private function controlArgumentTypeOf(string $rendererClass, string $declaredType, string $certainty): string
	{
		if ($certainty !== Certainty::HAPPENS) {
			return $declaredType;
		}

		return self::admits($declaredType, $rendererClass)
			? '\\' . $rendererClass
			: $declaredType;
	}

	/**
	 * @param mixed $default
	 * @return Certainty::*
	 */
	private function certaintyOf(string $name, $default): string
	{
		$dependency = self::FROM_DEPENDENCY[$name] ?? null;
		if ($dependency !== null) {
			return $this->wiredClassOf($dependency[0]) !== null ? Certainty::HAPPENS : Certainty::MAYBE;
		}

		// The container-free half: the factory's own value must never be null (condition 2 of the
		// contract), AND the property must carry a non-null default of its own, so a template that
		// never went through the factory at all still hands the body a value rather than the
		// property's implicit null.
		return in_array($name, self::NEVER_NULL_FROM_FACTORY, true) && $default !== null
			? Certainty::HAPPENS
			: Certainty::MAYBE;
	}

	// REFINE, never contradict: the wired object's concrete class replaces the template class's own
	// @var only when that @var ADMITS it (the corpus case - the vendor DefaultTemplate spells
	// Nette\Security\User fully qualified and the container wires an application subclass of it,
	// whose extra members the templates actually use). Anything else leaves the declaration
	// standing: the property is still written, so availability is unaffected, but overruling a
	// template class's own declared type on the strength of a container read is a claim this source
	// has no business making.
	//
	// The class_exists() half carries more weight than it looks: PropertyTypeResolver hands back the
	// RAW @var string, and an imported short name - the only form this project's own coding standard
	// permits its classes to write - never resolves, so a project template class declaring its own
	// $user is refused refinement by that clause rather than by is_a().
	private function typeOf(string $name, string $declaredType): string
	{
		$dependency = self::FROM_DEPENDENCY[$name] ?? null;
		if ($dependency === null || $dependency[1] === false) {
			return $declaredType;
		}

		$wired = $this->wiredClassOf($dependency[0]);
		if ($wired === null) {
			return $declaredType;
		}

		return self::admits($declaredType, $wired)
			? '\\' . $wired
			: $declaredType;
	}

	// An interface counts: 3.2 declares $presenter as Nette\Application\IPresenter.
	private static function admits(string $declaredType, string $class): bool
	{
		$declaredClass = ltrim($declaredType, '\\');

		return (class_exists($declaredClass) || interface_exists($declaredClass))
			&& is_a($class, $declaredClass, true);
	}

	// The 3.2 TypeError catch, for the one case it decides presence: a typed property with no default
	// stays uninitialized when the factory's value does not fit it. On 3.1 the same write is fatal, so
	// the template never renders and claiming nothing is equally right. An unknown value (no wiring
	// read, or a class native reflection cannot load) or a type shape not modelled here counts as
	// fitting.

	/**
	 * @param class-string $templateClass
	 */
	private function writtenValueFits(string $templateClass, string $name, string $rendererClass): bool
	{
		$valueType = in_array($name, self::FROM_CONTROL_ARGUMENT, true)
			? $rendererClass
			: self::WRITTEN_VALUE_TYPES[$name] ?? $this->wiredClassOf(TemplateFactoryDefaultResolver::KEY_USER);

		if (
			$valueType === null
			|| (!in_array($valueType, self::WRITTEN_VALUE_TYPES, true) && !class_exists($valueType))
		) {
			return true;
		}

		return self::nativeTypeAccepts((new ReflectionProperty($templateClass, $name))->getType(), $valueType);
	}

	private static function nativeTypeAccepts(?ReflectionType $type, string $valueType): bool
	{
		if ($type === null) {
			return true;
		}

		$members = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
		$isObject = $valueType !== 'string' && $valueType !== 'array';
		foreach ($members as $member) {
			if (!$member instanceof ReflectionNamedType) {
				return true;
			}

			$typeName = $member->getName();
			if (
				$typeName === 'mixed'
				|| $typeName === $valueType
				|| ($valueType === 'array' && $typeName === 'iterable')
				|| ($isObject && $typeName === 'object')
				|| ($isObject && !$member->isBuiltin() && is_a($valueType, $typeName, true))
			) {
				return true;
			}
		}

		return false;
	}

	// Nette\Application\UI\Presenter::sendTemplate() (3.2+) copies onto the template every property
	// ComponentReflection::getTemplateVariables() lists: the renderer's own and inherited properties
	// carrying #[TemplateVariable] (a non-public one throws there, a static one is not readable off
	// $this) - presenters only, a plain Control never sends its template that way. The vendor also
	// requires the property to be initialized; that is runtime state, so it is claimed as present.

	/**
	 * @return array<string, array{certainty: Certainty::*, type: string, class: string}>
	 */
	private function templateVariablesOf(string $rendererClass): array
	{
		if (array_key_exists($rendererClass, $this->templateVariables)) {
			return $this->templateVariables[$rendererClass];
		}

		$vars = [];
		if (class_exists(TemplateVariable::class) && is_a($rendererClass, Presenter::class, true)) {
			foreach ((new ReflectionClass($rendererClass))->getProperties() as $property) {
				if (
					!$property->isPublic()
					|| $property->isStatic()
					|| !method_exists($property, 'getAttributes')
					|| $property->getAttributes(TemplateVariable::class) === []
				) {
					continue;
				}

				$vars[$property->getName()] = [
					'certainty' => Certainty::HAPPENS,
					'type' => PropertyTypeResolver::resolve($property),
					'class' => $property->getDeclaringClass()->getName(),
				];
			}
		}

		return $this->templateVariables[$rendererClass] = $vars;
	}

	private function wiredClassOf(string $key): ?string
	{
		$wiring = $this->templateFactoryDefault->resolveWiring();

		return $wiring === null ? null : ($wiring[$key] ?? null);
	}

	/**
	 * @param array<string, array{certainty: Certainty::*, type: string, class: string}> $a
	 * @param array<string, array{certainty: Certainty::*, type: string, class: string}> $b
	 * @return array<string, array{certainty: Certainty::*, type: string, class: string}>
	 */
	private static function intersect(array $a, array $b): array
	{
		// All-renderers discipline: a variable survives only where EVERY renderer's template class
		// declares it, because the template is rendered by each of them in turn and a variable one
		// of them never provides is genuinely undefined on that path.
		$intersected = [];
		foreach ($a as $name => $entry) {
			if (!array_key_exists($name, $b)) {
				continue;
			}

			$other = $b[$name];
			$classes = [$entry['class'] => true, $other['class'] => true];
			$classNames = array_keys($classes);
			sort($classNames, SORT_STRING);

			// Each side is independently a sound bound: refinement only ever narrows within what a
			// renderer's OWN template class's declared @var admits, and the two sides can resolve to
			// different template classes with different declarations entirely - there is no single
			// shared declaration to fall back on. Any common supertype of two independently sound
			// bounds is itself sound, which is why the deepest common CLASS ancestor is a safe answer.
			// Common ancestors of a set form a chain under single inheritance, so this pairwise fold
			// is associative and the answer does not depend on renderer order, which the fingerprint
			// this feeds requires.
			$intersected[$name] = [
				'certainty' => Certainty::join($entry['certainty'], $other['certainty']),
				'type' => CommonClassAncestor::of($entry['type'], $other['type']),
				'class' => implode('+', $classNames),
			];
		}

		return $intersected;
	}

	private static function nullable(string $type): string
	{
		$lower = strtolower($type);
		if ($lower === 'mixed' || $lower === 'null' || strpos($type, '?') === 0) {
			return $type;
		}

		// A member carrying its own top-level `:` or `(` (a callable shape) re-parses with different
		// precedence once joined by a bare `|` - DeclarationInjector::parenthesizeUnionMember()'s
		// own rule, applied to the one union this class builds.
		$member = strpos($type, ':') === false && strpos($type, '(') === false ? $type : '(' . $type . ')';

		return $member . '|null';
	}

	private function recordSource(): DiscoveryRecordSource
	{
		if ($this->recordSource === null) {
			$recordSource = $this->container->getService(self::RECORD_SOURCE_SERVICE_NAME);
			if (!$recordSource instanceof DiscoveryRecordSource) {
				throw new LogicException(
					self::RECORD_SOURCE_SERVICE_NAME . ' must be a DiscoveryRecordSource service.',
				);
			}

			$this->recordSource = $recordSource;
		}

		return $this->recordSource;
	}

	private function judge(): PairingJudge
	{
		if ($this->judge === null) {
			$judge = $this->container->getService(self::PAIRING_JUDGE_SERVICE_NAME);
			if (!$judge instanceof PairingJudge) {
				throw new LogicException(self::PAIRING_JUDGE_SERVICE_NAME . ' must be a PairingJudge service.');
			}

			$this->judge = $judge;
		}

		return $this->judge;
	}

}
