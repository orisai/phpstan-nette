<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use Latte\Runtime\Template as LatteRuntimeTemplate;
use Nette\Application\UI\Control as UiControl;
use Nette\Application\UI\Presenter as UiPresenter;
use Nette\Application\UI\Template as UiTemplate;
use Nette\Application\UI\TemplateFactory as UiTemplateFactory;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Includes\FirstPartyPaths;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\While_;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use ReflectionMethod;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_merge;
use function array_pop;
use function assert;
use function count;
use function dirname;
use function in_array;
use function is_string;
use function lcfirst;
use function ltrim;
use function realpath;
use function spl_object_id;
use function strlen;
use function strncmp;
use function strtolower;
use function substr;

/**
 * @phpstan-type LifecycleState array{presenter: bool, methods: array<int, array{name: string, public: bool, static: bool}>, edges: array<int, list<int>>, mutations: list<array{kind: MutationFact::KIND_SET_VIEW|MutationFact::KIND_SET_ACTION, argument: string|null, line: int, conditional: bool, methodId: int, file: string}>, signalTargets: list<array{name: string, conditional: bool}>, signalTargetIds: list<array{id: int, conditional: bool}>, closures: list<array{node: Closure|ArrowFunction, conditional: bool, file: string, declaringClass: ClassReflection}>, closureIds: list<int>}
 * @phpstan-type ViewEntry array{certainty: Certainty::HAPPENS|Certainty::MAYBE, sites: list<array{file: string, line: int}>, sources: list<MutationFact|string>}
 * @phpstan-type SetFileSeed array{kind: SetFileFact::KIND_*, path: string|null, certainty: Certainty::*, site: array{file: string, line: int}, methodId: int, conventionMethod: string|null}
 */
final class PhpRenderWalk
{

	private const CREATE_TEMPLATE_METHOD = 'createTemplate';

	private const GET_TEMPLATE_METHOD = 'getTemplate';

	private const SET_FILE_METHOD = 'setFile';

	private const RENDER_METHOD = 'render';

	private const FACTORY_STATIC_METHOD = 'create';

	private const TEMPLATE_PROPERTY = 'template';

	private const CONVENTION_HOOK_METHODS = ['getTemplateClass', 'formatTemplateClass'];

	// The only two createTemplate() bodies that reach TemplateFactory::createTemplate() with a
	// control WITHOUT this walk reading them: the vendor's own Control::createTemplate() and the
	// Presenter override of it, both of which hand the factory their `$this`. Every other declaring
	// class is either the factory itself (whose argument is read directly), an app-level override
	// (whose body this walk reads anyway, so the delegating call must add nothing) or unreadable.
	private const VENDOR_CONTROL_CREATE_TEMPLATE_CLASSES = [UiControl::class, UiPresenter::class];

	private const SET_VIEW_METHOD = 'setView';

	private const CHANGE_ACTION_METHOD = 'changeAction';

	private const ACTION_METHOD_PREFIX = 'action';

	private const RENDER_METHOD_PREFIX = 'render';

	private const SIGNAL_METHOD_PREFIX = 'handle';

	// Nette form events firing inside the signal-processing window (between action and
	// beforeRender), whichever method wires them.
	private const SIGNAL_EVENT_PROPERTIES = ['onSuccess', 'onSubmit', 'onError', 'onValidate', 'onClick'];

	private const CTX_STARTUP = 'startup';

	private const CTX_CHECK_REQUIREMENTS = 'checkRequirements';

	private const CTX_ACTION = 'action';

	private const CTX_SIGNAL = 'signal';

	private const CTX_BEFORE_RENDER = 'beforeRender';

	private const CTX_RENDER = 'render';

	private const CTX_AFTER_RENDER = 'afterRender';

	private const CTX_SHUTDOWN = 'shutdown';

	private const CTX_UNKNOWN = 'unknown';

	// checkRequirements shares startup's window: its class-level run precedes startup() and every
	// per-method rerun still precedes sendTemplate resolution. afterRender/shutdown/unknown all
	// map outside the dispatch phases, differing only in view-mutation effectiveness below.
	private const PHASE_BY_CTX = [
		self::CTX_STARTUP => MutationFact::PHASE_STARTUP,
		self::CTX_CHECK_REQUIREMENTS => MutationFact::PHASE_STARTUP,
		self::CTX_ACTION => MutationFact::PHASE_ACTION,
		self::CTX_SIGNAL => MutationFact::PHASE_SIGNAL,
		self::CTX_BEFORE_RENDER => MutationFact::PHASE_BEFORE_RENDER,
		self::CTX_RENDER => MutationFact::PHASE_RENDER,
		self::CTX_AFTER_RENDER => MutationFact::PHASE_OUTSIDE,
		self::CTX_SHUTDOWN => MutationFact::PHASE_OUTSIDE,
		self::CTX_UNKNOWN => MutationFact::PHASE_OUTSIDE,
	];

	// setView/changeAction act through the once-only sendTemplate-time view resolution: effective
	// everywhere before it (afterRender included - the window runs right up to resolution), dead
	// after it (shutdown), unprovable for methods no lifecycle entry reaches. setFile never
	// consults that resolution, so its effectiveness is fixed EFFECTIVE_YES in every phase and
	// this table applies to the view axis only.
	private const VIEW_EFFECTIVENESS_BY_CTX = [
		self::CTX_STARTUP => MutationFact::EFFECTIVE_YES,
		self::CTX_CHECK_REQUIREMENTS => MutationFact::EFFECTIVE_YES,
		self::CTX_ACTION => MutationFact::EFFECTIVE_YES,
		self::CTX_SIGNAL => MutationFact::EFFECTIVE_YES,
		self::CTX_BEFORE_RENDER => MutationFact::EFFECTIVE_YES,
		self::CTX_RENDER => MutationFact::EFFECTIVE_YES,
		self::CTX_AFTER_RENDER => MutationFact::EFFECTIVE_YES,
		self::CTX_SHUTDOWN => MutationFact::EFFECTIVE_NO,
		self::CTX_UNKNOWN => MutationFact::EFFECTIVE_MAYBE,
	];

	private const TEMPLATE_CLASS_CHANNEL_PRECEDENCE = [
		TemplateClassFact::CHANNEL_PHPDOC,
		TemplateClassFact::CHANNEL_CREATE_TEMPLATE,
		TemplateClassFact::CHANNEL_NEW,
		TemplateClassFact::CHANNEL_FACTORY_STATIC,
		TemplateClassFact::CHANNEL_GENERIC_BINDING,
	];

	private ReflectionProvider $reflectionProvider;

	private Parser $parser;

	private FirstPartyPaths $firstPartyPaths;

	private TemplateFactoryDefaultResolver $templateFactoryDefault;

	private DiscoveryResolver $discoveryResolver;

	/** @var array<string, array<int, ClassMethod>> */
	private array $methodNodesByFile = [];

	/**
	 * @param list<string> $firstPartyPaths
	 */
	public function __construct(
		ReflectionProvider $reflectionProvider,
		Parser $parser,
		array $firstPartyPaths,
		TemplateFactoryDefaultResolver $templateFactoryDefault,
		DiscoveryResolver $discoveryResolver
	)
	{
		$this->reflectionProvider = $reflectionProvider;
		$this->parser = $parser;
		$this->templateFactoryDefault = $templateFactoryDefault;
		$this->discoveryResolver = $discoveryResolver;
		$this->firstPartyPaths = new FirstPartyPaths($firstPartyPaths);
	}

	public function factsFor(string $className): PhpRenderFacts
	{
		$className = ltrim($className, '\\');
		if (!$this->reflectionProvider->hasClass($className)) {
			return PhpRenderFacts::empty();
		}

		$classReflection = $this->reflectionProvider->getClass($className);
		$ownFile = $classReflection->getFileName();
		if ($ownFile === null) {
			return PhpRenderFacts::empty();
		}

		if (!$this->qualifies($classReflection, $ownFile)) {
			return new PhpRenderFacts([], [], null, [], [$ownFile]);
		}

		$readSet = [];
		/** @var array<int, true> $visited */
		$visited = [];

		/** @var array<string, array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}> $assignmentBuilder */
		$assignmentBuilder = [];

		/** @var list<SetFileSeed> $setFileBuilder */
		$setFileBuilder = [];

		/** @var LifecycleState $lifecycle */
		$lifecycle = [
			'presenter' => $classReflection->is(UiPresenter::class),
			'methods' => [],
			'edges' => [],
			'mutations' => [],
			'signalTargets' => [],
			'signalTargetIds' => [],
			'closures' => [],
			'closureIds' => [],
		];

		/** @var list<array{fileArgPresent: bool, literalPath: string|null, site: array{file: string, line: int}}> $renderSiteBuilder */
		$renderSiteBuilder = [];

		/** @var list<array{channel: TemplateClassFact::CHANNEL_*, className: string, certainty: Certainty::*, sites: list<int>}> $templateClassCandidates */
		$templateClassCandidates = [];

		/** @var list<PhpRenderFacts::CONTROL_*> $controlShapes */
		$controlShapes = [];

		$phpdocCandidate = $this->phpdocTemplateClassCandidate($classReflection);
		if ($phpdocCandidate !== null) {
			self::recordTemplateClassCandidate(
				$templateClassCandidates,
				TemplateClassFact::CHANNEL_PHPDOC,
				$phpdocCandidate['className'],
				$phpdocCandidate['certainty'],
				null,
			);
		}

		$surfaceCandidate = $this->resolvedSurfaceTemplateClassCandidate($classReflection);
		if ($surfaceCandidate !== null) {
			self::recordTemplateClassCandidate(
				$templateClassCandidates,
				TemplateClassFact::CHANNEL_GENERIC_BINDING,
				$surfaceCandidate['className'],
				$surfaceCandidate['certainty'],
				null,
			);
		}

		foreach ($this->appAncestorChain($classReflection) as $ancestor) {
			$file = $ancestor->getFileName();
			if ($file === null) {
				continue;
			}

			if (!in_array($file, $readSet, true)) {
				$readSet[] = $file;
			}

			foreach ($this->methodNodesByLine($file) as $node) {
				$this->walkMethod(
					$node,
					$classReflection,
					$ancestor,
					$file,
					$readSet,
					$visited,
					$assignmentBuilder,
					$setFileBuilder,
					$renderSiteBuilder,
					$templateClassCandidates,
					$controlShapes,
					$lifecycle,
				);
			}
		}

		$this->drainSignalQueues(
			$classReflection,
			$readSet,
			$visited,
			$assignmentBuilder,
			$setFileBuilder,
			$renderSiteBuilder,
			$templateClassCandidates,
			$controlShapes,
			$lifecycle,
		);

		$contexts = $this->computeMethodContexts($lifecycle);

		$setFileFacts = [];
		$mutationFacts = [];
		$discoveryEntries = [];
		foreach ($setFileBuilder as $found) {
			$phase = self::phaseOf($contexts[$found['methodId']] ?? []);
			$setFileFacts[] = new SetFileFact(
				$found['kind'],
				$found['path'],
				$found['certainty'],
				$found['site'],
				$phase,
				MutationFact::EFFECTIVE_YES,
			);
			$mutationFacts[] = new MutationFact(
				MutationFact::KIND_SET_FILE,
				$phase,
				MutationFact::EFFECTIVE_YES,
				$found['site']['line'],
				$found['path'],
			);

			$scope = self::setFileDiscoveryScope($lifecycle, $found['methodId']);
			$discoveryEntries[] = [
				'kind' => $found['kind'],
				'path' => $found['path'],
				'certainty' => $found['certainty'],
				'line' => $found['site']['line'],
				'phase' => $phase,
				'effectiveness' => MutationFact::EFFECTIVE_YES,
				'scope' => $scope['scope'],
				'scopeView' => $scope['scopeView'],
				'conventionMethod' => $found['conventionMethod'],
				'method' => $lifecycle['methods'][$found['methodId']]['name'] ?? null,
			];
		}

		// PHP coerces numeric-string keys to int, so keys are array-key and the ViewFact name is
		// cast back explicitly (an action404-style view name must stay a string).
		/** @var array<ViewEntry> $viewBuilder */
		$viewBuilder = [];
		if ($lifecycle['presenter']) {
			foreach ($this->methodDerivedViewSeeds($classReflection) as $seed) {
				$viewBuilder[$seed['view']] = self::mergedViewEntry(
					$viewBuilder[$seed['view']] ?? null,
					Certainty::HAPPENS,
					[$seed['site']],
					[$seed['method']],
				);
			}
		}

		$finalized = self::finalizeViewMutations($lifecycle, $contexts, $viewBuilder, $mutationFacts);

		$views = [];
		$viewNames = [];
		foreach ($finalized['views'] as $view => $entry) {
			$views[(string) $view] = new ViewFact(
				(string) $view,
				$entry['certainty'],
				$entry['sites'],
				$entry['sources'],
			);
			$viewNames[] = (string) $view;
		}

		return new PhpRenderFacts(
			$this->finalizeAssignments($assignmentBuilder),
			$setFileFacts,
			$this->finalizeTemplateClass($templateClassCandidates),
			$this->finalizeRenderSites($renderSiteBuilder),
			$readSet,
			self::finalizeTemplateClassCandidates($templateClassCandidates),
			$views,
			$finalized['mutations'],
			$finalized['open'],
			$this->discoveryResolver->resolve($classReflection, $lifecycle['presenter'], $viewNames, $discoveryEntries),
			$this->finalizeCreateTemplateControl($classReflection, $controlShapes),
		);
	}

	// The inherited rung, and the reason a walked call site alone is not the whole answer: a
	// component that never writes createTemplate() anywhere still creates its template through the
	// vendor body it inherits, which passes $this. Recorded only where reflection proves the class
	// really reaches that body - an app-level override replaces it, and then only the override's own
	// walked calls speak. Joined with the observed shapes rather than preferred over them, so the
	// component that ALSO calls $factory->createTemplate() somewhere lands on OTHER and claims
	// nothing: "the renderer is a component, therefore it has a control" is exactly the inference
	// this corpus disproves, and the disagreement is what keeps it from being made.

	/**
	 * @param list<PhpRenderFacts::CONTROL_*> $observed
	 * @return PhpRenderFacts::CONTROL_*|null
	 */
	private function finalizeCreateTemplateControl(ClassReflection $entryClass, array $observed): ?string
	{
		$shapes = $observed;
		if ($this->inheritsVendorCreateTemplate($entryClass)) {
			$shapes[] = PhpRenderFacts::CONTROL_SELF;
		}

		$joined = null;
		foreach ($shapes as $shape) {
			if ($joined === null) {
				$joined = $shape;

				continue;
			}

			if ($joined !== $shape) {
				return PhpRenderFacts::CONTROL_OTHER;
			}
		}

		return $joined;
	}

	private function inheritsVendorCreateTemplate(ClassReflection $entryClass): bool
	{
		if (!$entryClass->is(UiControl::class)) {
			return false;
		}

		$method = $this->nativeMethodOn($entryClass, self::CREATE_TEMPLATE_METHOD);

		return $method !== null && self::isVendorControlCreateTemplate($method->getDeclaringClass()->getName());
	}

	private static function isVendorControlCreateTemplate(string $declaringClassName): bool
	{
		return in_array($declaringClassName, self::VENDOR_CONTROL_CREATE_TEMPLATE_CLASSES, true);
	}

	/**
	 * Suppression scoping consumed by DiscoveryResolver: only a setFile lexically inside a proven
	 * dispatch window gets a provable scope - an action/render body scopes its own view, a
	 * startup/checkRequirements/beforeRender body scopes the whole class. Helpers, signals and
	 * outside phases stay 'open' (their candidates union with formula candidates, never
	 * suppress), because per-class facts cannot prove which dispatch actually reaches them.
	 *
	 * @param LifecycleState $lifecycle
	 * @return array{scope: 'view'|'class'|'open', scopeView: string|null}
	 */
	private static function setFileDiscoveryScope(array $lifecycle, int $methodId): array
	{
		if (!$lifecycle['presenter']) {
			return ['scope' => 'open', 'scopeView' => null];
		}

		$info = $lifecycle['methods'][$methodId] ?? null;
		if ($info === null) {
			return ['scope' => 'open', 'scopeView' => null];
		}

		$ctx = self::directContextOf($info, true);
		if ($ctx === self::CTX_ACTION || $ctx === self::CTX_RENDER) {
			$view = self::dispatchSuffixOf($info['name'], self::ACTION_METHOD_PREFIX)
				?? self::dispatchSuffixOf($info['name'], self::RENDER_METHOD_PREFIX);

			return $view === null
				? ['scope' => 'open', 'scopeView' => null]
				: ['scope' => 'view', 'scopeView' => $view];
		}

		if ($ctx === self::CTX_STARTUP || $ctx === self::CTX_CHECK_REQUIREMENTS || $ctx === self::CTX_BEFORE_RENDER) {
			return ['scope' => 'class', 'scopeView' => null];
		}

		return ['scope' => 'open', 'scopeView' => null];
	}

	private function qualifies(ClassReflection $classReflection, string $ownFile): bool
	{
		if (!$this->isUnderAppRoot($ownFile)) {
			return false;
		}

		return $this->hasTemplateSurface($classReflection)
			|| $this->callsCreateTemplate($classReflection, $ownFile);
	}

	private function hasTemplateSurface(ClassReflection $classReflection): bool
	{
		return $this->isTemplateIshType($this->templatePropertyType($classReflection))
			|| $this->isTemplateIshType($this->methodReturnType($classReflection, self::CREATE_TEMPLATE_METHOD))
			|| $this->isTemplateIshType($this->methodReturnType($classReflection, self::GET_TEMPLATE_METHOD));
	}

	private function templatePropertyType(ClassReflection $classReflection): ?Type
	{
		$class = $classReflection;
		while ($class !== null) {
			$resolvedPhpDoc = $class->getResolvedPhpDoc();
			if ($resolvedPhpDoc !== null) {
				$tag = $resolvedPhpDoc->getPropertyTags()[self::TEMPLATE_PROPERTY] ?? null;
				if ($tag !== null) {
					$type = $tag->getReadableType() ?? $tag->getWritableType();
					if ($type !== null) {
						return $type;
					}
				}
			}

			if ($class->hasNativeProperty(self::TEMPLATE_PROPERTY)) {
				return $class->getNativeProperty(self::TEMPLATE_PROPERTY)->getReadableType();
			}

			$class = $class->getParentClass();
		}

		return null;
	}

	private function isTemplateIshType(?Type $type): bool
	{
		if ($type === null) {
			return false;
		}

		foreach ($type->getObjectClassNames() as $className) {
			if (!$this->reflectionProvider->hasClass($className)) {
				continue;
			}

			$class = $this->reflectionProvider->getClass($className);
			if ($class->is(UiTemplate::class) || $class->is(LatteRuntimeTemplate::class)) {
				return true;
			}
		}

		return false;
	}

	private function callsCreateTemplate(ClassReflection $classReflection, string $file): bool
	{
		foreach ($this->methodNodesByLine($file) as $method) {
			$calls = (new NodeFinder())->find(
				$method,
				static fn (Node $candidate): bool => $candidate instanceof MethodCall || $candidate instanceof StaticCall,
			);

			foreach ($calls as $call) {
				assert($call instanceof MethodCall || $call instanceof StaticCall);
				if ($this->isTrustedCreateTemplateCall($call, $classReflection, $classReflection)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @return list<ClassReflection>
	 */
	private function appAncestorChain(ClassReflection $classReflection): array
	{
		$chain = [$classReflection];
		$current = $classReflection;

		while (true) {
			$parent = $current->getParentClass();
			if ($parent === null) {
				break;
			}

			$parentFile = $parent->getFileName();
			if ($parentFile === null || !$this->isUnderAppRoot($parentFile)) {
				break;
			}

			$chain[] = $parent;
			$current = $parent;
		}

		return $chain;
	}

	/**
	 * @param list<string> $readSet
	 * @param array<int, true> $visited
	 * @param array<string, array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}> $assignmentBuilder
	 * @param list<SetFileSeed> $setFileBuilder
	 * @param list<array{fileArgPresent: bool, literalPath: string|null, site: array{file: string, line: int}}> $renderSiteBuilder
	 * @param list<array{channel: TemplateClassFact::CHANNEL_*, className: string, certainty: Certainty::*, sites: list<int>}> $templateClassCandidates
	 * @param list<PhpRenderFacts::CONTROL_*> $controlShapes
	 * @param LifecycleState $lifecycle
	 */
	private function walkMethod(
		ClassMethod $node,
		ClassReflection $entryClass,
		ClassReflection $declaringClass,
		string $file,
		array &$readSet,
		array &$visited,
		array &$assignmentBuilder,
		array &$setFileBuilder,
		array &$renderSiteBuilder,
		array &$templateClassCandidates,
		array &$controlShapes,
		array &$lifecycle
	): void
	{
		$key = spl_object_id($node);
		if (isset($visited[$key])) {
			return;
		}

		$visited[$key] = true;

		$lifecycle['methods'][$key] = [
			'name' => $node->name->toString(),
			'public' => $node->isPublic(),
			'static' => $node->isStatic(),
		];

		$stmts = $node->stmts;
		if ($stmts === null) {
			return;
		}

		$templateLocals = $this->templateProvenancedLocals($node, $entryClass, $declaringClass);

		$this->extractAssignmentsFromMethod(
			$node,
			$file,
			$entryClass,
			$declaringClass,
			$templateLocals,
			$assignmentBuilder,
		);
		$this->extractSetFileFromMethod(
			$node,
			$key,
			$file,
			$entryClass,
			$declaringClass,
			$templateLocals,
			$setFileBuilder,
		);
		$this->extractRenderSitesFromMethod(
			$node,
			$file,
			$entryClass,
			$declaringClass,
			$templateLocals,
			$renderSiteBuilder,
		);
		$this->extractTemplateClassCandidatesFromMethod($node, $entryClass, $declaringClass, $templateClassCandidates);
		$this->extractLifecycleFromMethod($node, $key, $file, $declaringClass, $lifecycle);

		$calls = (new NodeFinder())->find(
			$stmts,
			static fn (Node $candidate): bool => $candidate instanceof MethodCall || $candidate instanceof StaticCall,
		);

		foreach ($calls as $call) {
			assert($call instanceof MethodCall || $call instanceof StaticCall);

			// Rides the call scan the followCall loop already needs rather than a scan of its own:
			// a createTemplate() call is a MethodCall/StaticCall wherever it sits, so nesting it in
			// an expression (`$this->createTemplate()->renderToString(...)`) hides it from the
			// statement-shaped extractors above but never from this one.
			$shape = $this->createTemplateControlShape($call, $entryClass, $declaringClass);
			if ($shape !== null) {
				$controlShapes[] = $shape;
			}

			$this->followCall(
				$call,
				$key,
				$entryClass,
				$declaringClass,
				$readSet,
				$visited,
				$assignmentBuilder,
				$setFileBuilder,
				$renderSiteBuilder,
				$templateClassCandidates,
				$controlShapes,
				$lifecycle,
			);
		}
	}

	// createTemplate()'s first argument, resolved through whichever of the three shapes the call
	// really is. Null means "this call says nothing", which is NOT the OTHER verdict: a call
	// delegating to an app-level createTemplate() override is silent here precisely because the
	// override's own body is walked in this same pass and answers for it (`parent::createTemplate()`
	// chains resolve for free that way, however many app classes deep the chain runs).

	/**
	 * @param MethodCall|StaticCall $call
	 * @return PhpRenderFacts::CONTROL_*|null
	 */
	private function createTemplateControlShape(
		$call,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): ?string
	{
		if (!$this->isTrustedCreateTemplateCall($call, $entryClass, $declaringClass)) {
			return null;
		}

		// The same receiver resolution the trust boundary itself used - never a second, looser one.
		$receiver = $call instanceof MethodCall
			? $this->classOfReceiverExpr($call->var, $entryClass, $declaringClass)
			: $this->staticCallReceiverClass($call, $entryClass, $declaringClass);

		if ($receiver === null) {
			return PhpRenderFacts::CONTROL_OTHER;
		}

		if ($receiver->is(UiTemplateFactory::class)) {
			// THE factory call: its own first argument is the $control, with no argument meaning
			// no control at all.
			if ($call->args === []) {
				return PhpRenderFacts::CONTROL_NONE;
			}

			$first = $this->firstArgValue($call->args);
			if ($first instanceof Variable && $first->name === 'this') {
				return PhpRenderFacts::CONTROL_SELF;
			}

			// An explicit null is the parameter's own default spelled out - the same no-control
			// call, which `createTemplate(null, SomeTemplate::class)` is the only way to write.
			$literal = $first === null ? null : $this->literalType($first);

			return $literal !== null && $literal->isNull()->yes()
				? PhpRenderFacts::CONTROL_NONE
				: PhpRenderFacts::CONTROL_OTHER;
		}

		$target = $this->nativeMethodOn($receiver, self::CREATE_TEMPLATE_METHOD);
		if ($target === null) {
			return PhpRenderFacts::CONTROL_OTHER;
		}

		if (self::isVendorControlCreateTemplate($target->getDeclaringClass()->getName())) {
			// The vendor body passes ITS OWN $this, so the call is only SELF when the receiver is
			// this very instance - another component's template is another component's control.
			return self::callsOnOwnInstance($call)
				? PhpRenderFacts::CONTROL_SELF
				: PhpRenderFacts::CONTROL_OTHER;
		}

		$targetFile = $target->getFileName();

		// An app-level createTemplate() override ON THIS VERY INSTANCE, which is the only receiver
		// whose body the walk enters (appAncestorChain plus followCall's $this-/self-/parent-/static-
		// bounded following). A foreign object's own createTemplate() is never read, so however
		// app-local its file is, its argument stays unknown.
		return self::callsOnOwnInstance($call) && $targetFile !== false && $this->isUnderAppRoot($targetFile)
			? null
			: PhpRenderFacts::CONTROL_OTHER;
	}

	/**
	 * @param MethodCall|StaticCall $call
	 */
	private static function callsOnOwnInstance($call): bool
	{
		if ($call instanceof MethodCall) {
			return $call->var instanceof Variable && $call->var->name === 'this';
		}

		return $call->class instanceof Name
			&& in_array(strtolower($call->class->toString()), ['self', 'parent', 'static'], true);
	}

	/**
	 * @param MethodCall|StaticCall $call
	 * @param list<string> $readSet
	 * @param array<int, true> $visited
	 * @param array<string, array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}> $assignmentBuilder
	 * @param list<SetFileSeed> $setFileBuilder
	 * @param list<array{fileArgPresent: bool, literalPath: string|null, site: array{file: string, line: int}}> $renderSiteBuilder
	 * @param list<array{channel: TemplateClassFact::CHANNEL_*, className: string, certainty: Certainty::*, sites: list<int>}> $templateClassCandidates
	 * @param list<PhpRenderFacts::CONTROL_*> $controlShapes
	 * @param LifecycleState $lifecycle
	 */
	private function followCall(
		$call,
		int $callerId,
		ClassReflection $entryClass,
		ClassReflection $declaringClass,
		array &$readSet,
		array &$visited,
		array &$assignmentBuilder,
		array &$setFileBuilder,
		array &$renderSiteBuilder,
		array &$templateClassCandidates,
		array &$controlShapes,
		array &$lifecycle
	): void
	{
		$target = $this->resolveCallTarget($call, $entryClass, $declaringClass);
		if ($target === null) {
			return;
		}

		$file = $target->getFileName();
		if ($file === false || !$this->isUnderAppRoot($file)) {
			return;
		}

		if (!in_array($file, $readSet, true)) {
			$readSet[] = $file;
		}

		$startLine = $target->getStartLine();
		if ($startLine === false) {
			return;
		}

		$node = $this->methodNodesByLine($file)[$startLine] ?? null;
		if ($node === null) {
			return;
		}

		// Edges are recorded even for already-visited targets - context propagation needs every
		// caller, not just the first one that walked the body.
		$lifecycle['edges'][$callerId][] = spl_object_id($node);

		$declaringClassName = $target->getDeclaringClass()->getName();
		$newDeclaringClass = $this->reflectionProvider->hasClass($declaringClassName)
			? $this->reflectionProvider->getClass($declaringClassName)
			: $declaringClass;

		$this->walkMethod(
			$node,
			$entryClass,
			$newDeclaringClass,
			$file,
			$readSet,
			$visited,
			$assignmentBuilder,
			$setFileBuilder,
			$renderSiteBuilder,
			$templateClassCandidates,
			$controlShapes,
			$lifecycle,
		);
	}

	/**
	 * @param MethodCall|StaticCall $call
	 */
	private function resolveCallTarget(
		$call,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): ?ReflectionMethod
	{
		if (!$call->name instanceof Identifier) {
			return null;
		}

		$receiver = $this->resolveCallReceiverClass($call, $entryClass, $declaringClass);
		if ($receiver === null) {
			return null;
		}

		return $this->nativeMethodOn($receiver, $call->name->toString());
	}

	/**
	 * @param MethodCall|StaticCall $call
	 */
	private function resolveCallReceiverClass(
		$call,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): ?ClassReflection
	{
		if ($call instanceof MethodCall) {
			// $this-> dispatches dynamically on the most-derived (entry) class, never the class
			// whose body the call textually appears in.
			return $call->var instanceof Variable && $call->var->name === 'this' ? $entryClass : null;
		}

		if (!$call->class instanceof Name) {
			// Explicit-FQCN static calls are out of the bounded intra-class traversal.
			return null;
		}

		$className = $call->class->toString();

		if ($className === 'static') {
			// Late static binding: same fixed point as $this-> for a single-leaf-class walk.
			return $entryClass;
		}

		if ($className === 'self') {
			// Lexically scoped to the class whose source text contains the call, NOT the entry
			// class - an overridden self::/parent:: target must resolve here, never to $entryClass.
			return $declaringClass;
		}

		if ($className === 'parent') {
			return $declaringClass->getParentClass();
		}

		return null;
	}

	private function nativeMethodOn(ClassReflection $class, string $methodName): ?ReflectionMethod
	{
		$native = $class->getNativeReflection();

		return $native->hasMethod($methodName) ? $native->getMethod($methodName) : null;
	}

	private function isTemplateOrigin(Expr $expr, ClassReflection $entryClass, ClassReflection $declaringClass): bool
	{
		if (
			$expr instanceof PropertyFetch
			&& $expr->var instanceof Variable
			&& $expr->var->name === 'this'
			&& $expr->name instanceof Identifier
			&& $expr->name->toString() === self::TEMPLATE_PROPERTY
		) {
			// Never trusted by name alone: a $template property on a class whose declared type is
			// not Template-ish (a mailer service reusing the name) must not count as an origin.
			return $this->isTemplateIshType($this->templatePropertyType($entryClass));
		}

		return $this->isTrustedCreateTemplateCall($expr, $entryClass, $declaringClass)
			|| $this->isTrustedTemplateReturningCall($expr, self::GET_TEMPLATE_METHOD, $entryClass, $declaringClass);
	}

	private function isTrustedCreateTemplateCall(
		Expr $expr,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): bool
	{
		return $this->isTrustedTemplateReturningCall($expr, self::CREATE_TEMPLATE_METHOD, $entryClass, $declaringClass);
	}

	// The one shared trust boundary every receiver-filtered domain (assignments, setFile, render)
	// consumes as-is: a createTemplate()/getTemplate() call is trusted if and only if the receiver
	// class's own method has a Template-ish return type - one uniform rule across ->, self::,
	// parent::, static:: and explicit-FQCN forms, $this-> included (no name-only shortcut: an
	// entry class whose own createTemplate()/getTemplate() returns a non-template is rejected the
	// same as an unrelated service receiver would be). No caller may widen this - only
	// isTemplateOrigin() itself may grow it.
	private function isTrustedTemplateReturningCall(
		Expr $expr,
		string $methodName,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): bool
	{
		if (
			(!$expr instanceof MethodCall && !$expr instanceof StaticCall)
			|| !$expr->name instanceof Identifier
			|| $expr->name->toString() !== $methodName
		) {
			return false;
		}

		$receiver = $expr instanceof MethodCall
			? $this->classOfReceiverExpr($expr->var, $entryClass, $declaringClass)
			: $this->staticCallReceiverClass($expr, $entryClass, $declaringClass);

		return $receiver !== null
			&& $this->isTemplateIshType($this->methodReturnType($receiver, $methodName));
	}

	private function staticCallReceiverClass(
		StaticCall $call,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): ?ClassReflection
	{
		$receiver = $this->resolveCallReceiverClass($call, $entryClass, $declaringClass);
		if ($receiver !== null) {
			return $receiver;
		}

		if (!$call->class instanceof Name) {
			return null;
		}

		$className = $call->class->toString();
		if (in_array(strtolower($className), ['self', 'parent', 'static'], true)) {
			return null;
		}

		$fqcn = ltrim($className, '\\');

		return $this->reflectionProvider->hasClass($fqcn) ? $this->reflectionProvider->getClass($fqcn) : null;
	}

	/**
	 * @return array<string, true>
	 */
	private function templateProvenancedLocals(
		ClassMethod $method,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): array
	{
		$templateLocals = [];
		if ($method->stmts === null) {
			return $templateLocals;
		}

		foreach ((new NodeFinder())->findInstanceOf($method->stmts, Assign::class) as $assign) {
			if (
				$assign->var instanceof Variable
				&& is_string($assign->var->name)
				&& $this->isTemplateOrigin($assign->expr, $entryClass, $declaringClass)
			) {
				$templateLocals[$assign->var->name] = true;
			}
		}

		return $templateLocals;
	}

	/**
	 * @param array<int, Stmt> $stmts
	 * @param callable(Stmt, bool): void $onLeaf
	 */
	private function walkConditionalStructure(array $stmts, bool $conditional, callable $onLeaf): void
	{
		// Only if/switch/loop lower certainty (the plan's set). try/catch/finally are not
		// descended into at all, so a leaf inside one is silently absent, never mis-attributed;
		// closures/arrow functions are likewise never descended into.
		foreach ($stmts as $stmt) {
			if ($stmt instanceof If_) {
				$this->walkConditionalStructure($stmt->stmts, true, $onLeaf);
				foreach ($stmt->elseifs as $elseif) {
					$this->walkConditionalStructure($elseif->stmts, true, $onLeaf);
				}

				if ($stmt->else !== null) {
					$this->walkConditionalStructure($stmt->else->stmts, true, $onLeaf);
				}

				continue;
			}

			if ($stmt instanceof Switch_) {
				foreach ($stmt->cases as $case) {
					$this->walkConditionalStructure($case->stmts, true, $onLeaf);
				}

				continue;
			}

			if ($stmt instanceof For_ || $stmt instanceof Foreach_ || $stmt instanceof While_ || $stmt instanceof Do_) {
				$this->walkConditionalStructure($stmt->stmts, true, $onLeaf);

				continue;
			}

			$onLeaf($stmt, $conditional);
		}
	}

	/**
	 * @param array<string, true> $templateLocals
	 * @param array<string, array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}> $assignmentBuilder
	 */
	private function extractAssignmentsFromMethod(
		ClassMethod $method,
		string $file,
		ClassReflection $entryClass,
		ClassReflection $declaringClass,
		array $templateLocals,
		array &$assignmentBuilder
	): void
	{
		$stmts = $method->stmts;
		if ($stmts === null) {
			return;
		}

		/** @var array<string, array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}> $perMethod */
		$perMethod = [];
		$this->walkConditionalStructure(
			$stmts,
			false,
			function (Stmt $stmt, bool $conditional) use ($file, $entryClass, $declaringClass, $templateLocals, &$perMethod): void {
				if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
					return;
				}

				$this->recordAssignIfQualifying(
					$stmt->expr,
					$conditional,
					$file,
					$entryClass,
					$declaringClass,
					$templateLocals,
					$perMethod,
				);
			},
		);

		foreach ($perMethod as $var => $found) {
			$assignmentBuilder[$var] = self::mergeAcrossMethodsEntry($assignmentBuilder[$var] ?? null, $found);
		}
	}

	/**
	 * @param array<string, true> $templateLocals
	 * @param array<string, array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}> $builder
	 */
	private function recordAssignIfQualifying(
		Assign $assign,
		bool $conditional,
		string $file,
		ClassReflection $entryClass,
		ClassReflection $declaringClass,
		array $templateLocals,
		array &$builder
	): void
	{
		$var = $assign->var;
		if (!$var instanceof PropertyFetch || !$var->name instanceof Identifier) {
			return;
		}

		if (
			!$this->isTemplateOrigin($var->var, $entryClass, $declaringClass)
			&& !$this->isProvenancedLocal($var->var, $templateLocals)
		) {
			return;
		}

		$type = $this->typeOfExpr($assign->expr, $entryClass, $declaringClass) ?? new MixedType();
		$site = ['file' => $file, 'line' => $assign->getStartLine()];
		$name = $var->name->toString();

		$builder[$name] = self::mergeAssignmentEntry($builder[$name] ?? null, $type, $conditional, $site);
	}

	/**
	 * @param array<string, true> $templateLocals
	 */
	private function isProvenancedLocal(Expr $expr, array $templateLocals): bool
	{
		return $expr instanceof Variable && is_string($expr->name) && isset($templateLocals[$expr->name]);
	}

	/**
	 * @param array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}|null $existing
	 * @param array{file: string, line: int} $site
	 * @return array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}
	 */
	private static function mergeAssignmentEntry(?array $existing, Type $type, bool $conditional, array $site): array
	{
		// Pure function (no by-ref array threading): a by-ref parameter typed with this nested
		// array shape trips PHPStan's invariance check once the caller's array is narrowed to
		// non-empty by a prior call in the same loop.
		if ($existing === null) {
			return [
				'type' => $type,
				'certainty' => $conditional ? Certainty::MAYBE : Certainty::HAPPENS,
				'sites' => [$site],
			];
		}

		$sites = $existing['sites'];
		$sites[] = $site;

		if ($conditional) {
			/** @var Certainty::* $certainty */
			$certainty = Certainty::join($existing['certainty'], Certainty::MAYBE);

			return [
				'type' => TypeCombinator::union($existing['type'], $type),
				'certainty' => $certainty,
				'sites' => $sites,
			];
		}

		// A later unconditional write always executes, so it dominates: whatever a prior
		// conditional branch might have produced is moot once this statement is reached.
		return [
			'type' => $type,
			'certainty' => Certainty::HAPPENS,
			'sites' => $sites,
		];
	}

	/**
	 * @param array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}|null $existing
	 * @param array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>} $found
	 * @return array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}
	 */
	private static function mergeAcrossMethodsEntry(?array $existing, array $found): array
	{
		// Unlike mergeAssignmentEntry() (sequential, last-wins within one linear body),
		// contributions from different methods have no execution order relative to each other,
		// so they are always unioned - this is what lets a helper method's assignment carry the
		// helper's OWN conditionality verbatim when it is the first (or only) contributor.
		if ($existing === null) {
			return $found;
		}

		/** @var Certainty::* $certainty */
		$certainty = Certainty::join($existing['certainty'], $found['certainty']);

		return [
			'type' => TypeCombinator::union($existing['type'], $found['type']),
			'certainty' => $certainty,
			'sites' => array_merge($existing['sites'], $found['sites']),
		];
	}

	/**
	 * @param array<string, array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}> $builder
	 * @return array<string, AssignmentFact>
	 */
	private function finalizeAssignments(array $builder): array
	{
		$assignments = [];
		foreach ($builder as $var => $found) {
			$assignments[$var] = new AssignmentFact(
				$found['type']->describe(VerbosityLevel::typeOnly()),
				$found['certainty'],
				$found['sites'],
			);
		}

		return $assignments;
	}

	/**
	 * @param array<string, true> $templateLocals
	 * @param list<SetFileSeed> $builder
	 */
	private function extractSetFileFromMethod(
		ClassMethod $method,
		int $methodId,
		string $file,
		ClassReflection $entryClass,
		ClassReflection $declaringClass,
		array $templateLocals,
		array &$builder
	): void
	{
		$stmts = $method->stmts;
		if ($stmts === null) {
			return;
		}

		$this->walkConditionalStructure(
			$stmts,
			false,
			function (Stmt $stmt, bool $conditional) use ($methodId, $file, $entryClass, $declaringClass, $templateLocals, &$builder): void {
				if (!$stmt instanceof Expression || !$stmt->expr instanceof MethodCall) {
					return;
				}

				$call = $stmt->expr;
				if (!$call->name instanceof Identifier || $call->name->toString() !== self::SET_FILE_METHOD) {
					return;
				}

				// Receiver-type filter: a same-named setFile() on a non-template receiver must
				// never be recorded. Only consumes isTemplateOrigin()'s trust boundary, never
				// widens it.
				if (
					!$this->isTemplateOrigin($call->var, $entryClass, $declaringClass)
					&& !$this->isProvenancedLocal($call->var, $templateLocals)
				) {
					return;
				}

				$classified = $this->classifySetFileArg($this->firstArgValue($call->args), $file);

				$builder[] = [
					'kind' => $classified['kind'],
					'path' => $classified['path'],
					'certainty' => $conditional ? Certainty::MAYBE : Certainty::HAPPENS,
					'site' => ['file' => $file, 'line' => $stmt->getStartLine()],
					'methodId' => $methodId,
					'conventionMethod' => $classified['conventionMethod'],
				];
			},
		);
	}

	/**
	 * @return array{kind: SetFileFact::KIND_*, path: string|null, conventionMethod: string|null}
	 */
	private function classifySetFileArg(?Expr $argExpr, string $file): array
	{
		if ($argExpr === null) {
			return ['kind' => SetFileFact::KIND_OPAQUE, 'path' => null, 'conventionMethod' => null];
		}

		$literalPath = $this->resolveLiteralPath($argExpr, $file);
		if ($literalPath !== null) {
			return ['kind' => SetFileFact::KIND_LITERAL, 'path' => $literalPath, 'conventionMethod' => null];
		}

		// Convention-derivable: every known real convention-derivable site
		// (getTemplateFilePath()) is a zero-external-input call on the class's own $this -
		// a variable or a getter on any other receiver is opaque, since its value cannot be
		// derived from this call site alone. Deliberately NOT isTemplateOrigin()/the receiver-type
		// filter reused - this asks "is the ARGUMENT a self-derived call", not "is the RECEIVER a
		// template", a different (narrower) question that happens to look structurally similar.
		if ($argExpr instanceof MethodCall && $argExpr->var instanceof Variable && $argExpr->var->name === 'this') {
			return [
				'kind' => SetFileFact::KIND_CONVENTION,
				'path' => null,
				'conventionMethod' => $argExpr->name instanceof Identifier ? $argExpr->name->toString() : null,
			];
		}

		return ['kind' => SetFileFact::KIND_OPAQUE, 'path' => null, 'conventionMethod' => null];
	}

	/**
	 * @param array<string, true> $templateLocals
	 * @param list<array{fileArgPresent: bool, literalPath: string|null, site: array{file: string, line: int}}> $builder
	 */
	private function extractRenderSitesFromMethod(
		ClassMethod $method,
		string $file,
		ClassReflection $entryClass,
		ClassReflection $declaringClass,
		array $templateLocals,
		array &$builder
	): void
	{
		$stmts = $method->stmts;
		if ($stmts === null) {
			return;
		}

		$this->walkConditionalStructure(
			$stmts,
			false,
			// $conditional is unused here (not a bug): RenderSiteFact carries no Certainty field
			// per the Task 1 binding interface, so render sites have nothing to downgrade to MAYBE.
			function (Stmt $stmt, bool $conditional) use ($file, $entryClass, $declaringClass, $templateLocals, &$builder): void {
				if (!$stmt instanceof Expression || !$stmt->expr instanceof MethodCall) {
					return;
				}

				$call = $stmt->expr;
				if (!$call->name instanceof Identifier || $call->name->toString() !== self::RENDER_METHOD) {
					return;
				}

				// Same receiver-type filter as setFile: an unrelated ->render() (e.g. rendering a
				// child Nette Control, not the Template) must not be recorded.
				if (
					!$this->isTemplateOrigin($call->var, $entryClass, $declaringClass)
					&& !$this->isProvenancedLocal($call->var, $templateLocals)
				) {
					return;
				}

				$argExpr = $this->firstArgValue($call->args);

				$builder[] = [
					'fileArgPresent' => $argExpr !== null,
					'literalPath' => $argExpr === null ? null : $this->resolveLiteralPath($argExpr, $file),
					'site' => ['file' => $file, 'line' => $stmt->getStartLine()],
				];
			},
		);
	}

	/**
	 * @param list<array{fileArgPresent: bool, literalPath: string|null, site: array{file: string, line: int}}> $builder
	 * @return list<RenderSiteFact>
	 */
	private function finalizeRenderSites(array $builder): array
	{
		$facts = [];
		foreach ($builder as $found) {
			$facts[] = new RenderSiteFact($found['fileArgPresent'], $found['literalPath'], $found['site']);
		}

		return $facts;
	}

	/**
	 * @param list<array{channel: TemplateClassFact::CHANNEL_*, className: string, certainty: Certainty::*, sites: list<int>}> $candidates
	 */
	private function extractTemplateClassCandidatesFromMethod(
		ClassMethod $method,
		ClassReflection $entryClass,
		ClassReflection $declaringClass,
		array &$candidates
	): void
	{
		$stmts = $method->stmts;
		if ($stmts === null) {
			return;
		}

		$isCreateTemplateOverride = $this->isResolvedCreateTemplateOverride($method, $entryClass, $declaringClass);
		$isConventionHook = $this->isResolvedConventionHook($method, $entryClass, $declaringClass);

		$this->walkConditionalStructure(
			$stmts,
			false,
			function (Stmt $stmt, bool $conditional) use ($entryClass, $declaringClass, $isCreateTemplateOverride, $isConventionHook, &$candidates): void {
				$certainty = $conditional ? Certainty::MAYBE : Certainty::HAPPENS;
				$site = $stmt->getStartLine();

				$methodCall = $this->methodCallExprOf($stmt);
				if (
					$methodCall !== null
					&& $this->isTrustedCreateTemplateCall($methodCall, $entryClass, $declaringClass)
				) {
					$className = $this->classConstArgClassName($methodCall->args);
					if ($className !== null) {
						self::recordTemplateClassCandidate(
							$candidates,
							// The own createTemplate override IS the class's creation (spec §2
							// class-level): its trusted factory call pairs the class, never one
							// call site.
							$isCreateTemplateOverride
								? TemplateClassFact::CHANNEL_NEW
								: TemplateClassFact::CHANNEL_CREATE_TEMPLATE,
							$className,
							$certainty,
							$site,
						);
					}
				}

				if ($isConventionHook && $stmt instanceof Return_ && $stmt->expr !== null) {
					$this->recordConventionReturn($stmt, $certainty, $candidates);
				}

				$staticCall = $this->staticCallExprOf($stmt);
				if ($staticCall !== null) {
					$className = $this->factoryStaticClassName($staticCall);
					if ($className !== null) {
						self::recordTemplateClassCandidate(
							$candidates,
							TemplateClassFact::CHANNEL_FACTORY_STATIC,
							$className,
							$certainty,
							$site,
						);
					}
				}

				if (
					$isCreateTemplateOverride
					&& $stmt instanceof Return_
					&& $stmt->expr instanceof New_
					&& $stmt->expr->class instanceof Name
				) {
					$fqcn = ltrim($stmt->expr->class->toString(), '\\');
					if ($this->reflectionProvider->hasClass($fqcn)) {
						self::recordTemplateClassCandidate(
							$candidates,
							TemplateClassFact::CHANNEL_NEW,
							$fqcn,
							$certainty,
							$site,
						);
					}
				}
			},
		);
	}

	private function methodCallExprOf(Stmt $stmt): ?MethodCall
	{
		if ($stmt instanceof Return_) {
			return $stmt->expr instanceof MethodCall ? $stmt->expr : null;
		}

		if (!$stmt instanceof Expression) {
			return null;
		}

		if ($stmt->expr instanceof MethodCall) {
			return $stmt->expr;
		}

		return $stmt->expr instanceof Assign && $stmt->expr->expr instanceof MethodCall ? $stmt->expr->expr : null;
	}

	private function staticCallExprOf(Stmt $stmt): ?StaticCall
	{
		if (!$stmt instanceof Expression) {
			return null;
		}

		if ($stmt->expr instanceof StaticCall) {
			return $stmt->expr;
		}

		return $stmt->expr instanceof Assign && $stmt->expr->expr instanceof StaticCall ? $stmt->expr->expr : null;
	}

	// Same resolvedness bar as the convention hook: only the entry class's RESOLVED
	// createTemplate() is the class's own creation - a dead body's trusted factory call falls
	// back to per-site recording and its return new records nothing.
	private function isResolvedCreateTemplateOverride(
		ClassMethod $method,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): bool
	{
		return $method->name->toString() === self::CREATE_TEMPLATE_METHOD
			&& $this->isResolvedMethodBody($method, $entryClass, $declaringClass);
	}

	// Only the entry class's RESOLVED hook counts: an overridden ancestor body is dead code for
	// this class and must never seed convention candidates or conflicts.
	private function isResolvedConventionHook(
		ClassMethod $method,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): bool
	{
		return in_array($method->name->toString(), self::CONVENTION_HOOK_METHODS, true)
			&& $this->isResolvedMethodBody($method, $entryClass, $declaringClass);
	}

	private function isResolvedMethodBody(
		ClassMethod $method,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): bool
	{
		$resolved = $this->nativeMethodOn($entryClass, $method->name->toString());

		return $resolved !== null
			&& $resolved->getDeclaringClass()->getName() === $declaringClass->getName()
			&& $resolved->getStartLine() === $method->getStartLine();
	}

	/**
	 * @param Certainty::* $certainty
	 * @param list<array{channel: TemplateClassFact::CHANNEL_*, className: string, certainty: Certainty::*, sites: list<int>}> $candidates
	 */
	private function recordConventionReturn(Return_ $stmt, string $certainty, array &$candidates): void
	{
		$expr = $stmt->expr;
		if ($expr === null) {
			return;
		}

		if (
			$expr instanceof ClassConstFetch
			&& $expr->name instanceof Identifier
			&& $expr->name->toString() === 'class'
			&& $expr->class instanceof Name
		) {
			$fqcn = ltrim($expr->class->toString(), '\\');
			if ($this->reflectionProvider->hasClass($fqcn)) {
				self::recordTemplateClassCandidate(
					$candidates,
					TemplateClassFact::CHANNEL_CONVENTION,
					$fqcn,
					$certainty,
					$stmt->getStartLine(),
				);

				return;
			}
		}

		if (
			$expr instanceof MethodCall
			&& $expr->var instanceof Variable
			&& $expr->var->name === 'this'
			&& $expr->name instanceof Identifier
			&& in_array($expr->name->toString(), self::CONVENTION_HOOK_METHODS, true)
		) {
			// Delegation between the hooks (the app's formatTemplateClass() ->
			// getTemplateClass()): the resolved delegate body is walked in the same pass and
			// carries the candidate itself.
			return;
		}

		self::recordTemplateClassCandidate(
			$candidates,
			TemplateClassFact::CHANNEL_CONVENTION,
			TemplateClassFact::DYNAMIC_CLASS_NAME,
			$certainty,
			$stmt->getStartLine(),
		);
	}

	/**
	 * @param array<Arg|VariadicPlaceholder> $args
	 */
	private function classConstArgClassName(array $args): ?string
	{
		foreach ($args as $arg) {
			if (!$arg instanceof Arg || !$arg->value instanceof ClassConstFetch) {
				continue;
			}

			$fetch = $arg->value;
			if (
				!$fetch->name instanceof Identifier
				|| $fetch->name->toString() !== 'class'
				|| !$fetch->class instanceof Name
			) {
				continue;
			}

			$fqcn = ltrim($fetch->class->toString(), '\\');
			if ($this->reflectionProvider->hasClass($fqcn)) {
				return $fqcn;
			}
		}

		return null;
	}

	private function factoryStaticClassName(StaticCall $call): ?string
	{
		if (!$call->name instanceof Identifier || $call->name->toString() !== self::FACTORY_STATIC_METHOD) {
			return null;
		}

		if (!$call->class instanceof Name) {
			return null;
		}

		$className = $call->class->toString();
		if (in_array(strtolower($className), ['self', 'parent', 'static'], true)) {
			return null;
		}

		$fqcn = ltrim($className, '\\');

		return $this->reflectionProvider->hasClass($fqcn) ? $fqcn : null;
	}

	/**
	 * @param array<Arg|VariadicPlaceholder> $args
	 */
	private function firstArgValue(array $args): ?Expr
	{
		$first = $args[0] ?? null;

		return $first instanceof Arg ? $first->value : null;
	}

	/**
	 * @param list<array{channel: TemplateClassFact::CHANNEL_*, className: string, certainty: Certainty::*, sites: list<int>}> $candidates
	 * @param TemplateClassFact::CHANNEL_* $channel
	 * @param Certainty::* $certainty
	 */
	private static function recordTemplateClassCandidate(
		array &$candidates,
		string $channel,
		string $className,
		string $certainty,
		?int $site
	): void
	{
		// One candidate per (channel, className): repeat observations only append their site - the
		// first observation's certainty stands, matching finalizeTemplateClass()'s first-occurrence
		// per-channel pick.
		foreach ($candidates as $index => $candidate) {
			if ($candidate['channel'] === $channel && $candidate['className'] === $className) {
				if ($site !== null) {
					$candidates[$index]['sites'][] = $site;
				}

				return;
			}
		}

		$candidates[] = [
			'channel' => $channel,
			'className' => $className,
			'certainty' => $certainty,
			'sites' => $site === null ? [] : [$site],
		];
	}

	/**
	 * @return array{className: string, certainty: Certainty::*}|null
	 */
	private function phpdocTemplateClassCandidate(ClassReflection $classReflection): ?array
	{
		$resolvedPhpDoc = $classReflection->getResolvedPhpDoc();
		if ($resolvedPhpDoc === null) {
			return null;
		}

		$propertyTag = $resolvedPhpDoc->getPropertyTags()[self::TEMPLATE_PROPERTY] ?? null;
		if ($propertyTag === null) {
			return null;
		}

		$type = $propertyTag->getReadableType() ?? $propertyTag->getWritableType();
		if ($type === null) {
			return null;
		}

		$owner = $this->classReflectionOfType($type);

		return $owner === null ? null : ['className' => $owner->getName(), 'certainty' => Certainty::HAPPENS];
	}

	/**
	 * @return array{className: string, certainty: Certainty::*}|null
	 */
	private function resolvedSurfaceTemplateClassCandidate(ClassReflection $classReflection): ?array
	{
		$surfaceTypes = [
			$this->templatePropertyType($classReflection),
			$this->methodReturnType($classReflection, self::CREATE_TEMPLATE_METHOD),
			$this->methodReturnType($classReflection, self::GET_TEMPLATE_METHOD),
		];

		foreach ($surfaceTypes as $type) {
			$owner = $this->informativeTemplateClassOf($type);
			if ($owner !== null) {
				return ['className' => $owner->getName(), 'certainty' => Certainty::HAPPENS];
			}
		}

		return null;
	}

	private function informativeTemplateClassOf(?Type $type): ?ClassReflection
	{
		if ($type === null) {
			return null;
		}

		$owner = $this->classReflectionOfType($type);
		if ($owner === null || $owner->getName() === UiTemplate::class) {
			return null;
		}

		if (!$owner->is(UiTemplate::class) && !$owner->is(LatteRuntimeTemplate::class)) {
			return null;
		}

		return $owner;
	}

	/**
	 * @param list<array{channel: TemplateClassFact::CHANNEL_*, className: string, certainty: Certainty::*, sites: list<int>}> $candidates
	 */
	private function finalizeTemplateClass(array $candidates): TemplateClassFact
	{
		// The ladder consumes the first-encountered candidate per channel; every further candidate
		// lives only in the candidate list.
		$firstPerChannel = [];
		foreach ($candidates as $candidate) {
			if (!array_key_exists($candidate['channel'], $firstPerChannel)) {
				$firstPerChannel[$candidate['channel']] = $candidate;
			}
		}

		$winner = null;
		foreach (self::TEMPLATE_CLASS_CHANNEL_PRECEDENCE as $channel) {
			if (array_key_exists($channel, $firstPerChannel)) {
				$winner = $firstPerChannel[$channel];

				break;
			}
		}

		if ($winner === null) {
			// The fallback rungs are definitional, not observations, so they never enter the
			// candidate set - a floor that always exists would poison every conflict check below.
			$factoryDefault = $this->templateFactoryDefault->resolve();
			if ($factoryDefault !== null) {
				return new TemplateClassFact(
					$factoryDefault,
					TemplateClassFact::CHANNEL_FACTORY_DEFAULT,
					Certainty::HAPPENS,
				);
			}

			return new TemplateClassFact(
				UiTemplate::class,
				TemplateClassFact::CHANNEL_TEMPLATE_FLOOR,
				Certainty::HAPPENS,
			);
		}

		// Conflicting channels (any two first-per-channel picks disagreeing on the class) keep the
		// higher-precedence channel's pick but downgrade Certainty to MAYBE - SP1 records the
		// ambiguity, SP2 judges truthiness against it. Within-channel disagreement deliberately
		// never touches the primary - the candidate list carries it.
		foreach ($firstPerChannel as $channel => $candidate) {
			if ($channel !== $winner['channel'] && $candidate['className'] !== $winner['className']) {
				return new TemplateClassFact($winner['className'], $winner['channel'], Certainty::MAYBE);
			}
		}

		return new TemplateClassFact($winner['className'], $winner['channel'], $winner['certainty']);
	}

	/**
	 * @param list<array{channel: TemplateClassFact::CHANNEL_*, className: string, certainty: Certainty::*, sites: list<int>}> $candidates
	 * @return list<TemplateClassFact>
	 */
	private static function finalizeTemplateClassCandidates(array $candidates): array
	{
		$facts = [];
		foreach ($candidates as $candidate) {
			$facts[] = new TemplateClassFact(
				$candidate['className'],
				$candidate['channel'],
				$candidate['certainty'],
				$candidate['sites'],
			);
		}

		return $facts;
	}

	/**
	 * @param LifecycleState $lifecycle
	 */
	private function extractLifecycleFromMethod(
		ClassMethod $method,
		int $methodId,
		string $file,
		ClassReflection $declaringClass,
		array &$lifecycle
	): void
	{
		$stmts = $method->stmts;
		if ($stmts === null) {
			return;
		}

		$this->walkConditionalStructure(
			$stmts,
			false,
			function (Stmt $stmt, bool $conditional) use ($methodId, $file, $declaringClass, &$lifecycle): void {
				$this->recordLifecycleLeaf($stmt, $conditional, $methodId, $file, $declaringClass, $lifecycle);
			},
		);
	}

	/**
	 * @param LifecycleState $lifecycle
	 */
	private function recordLifecycleLeaf(
		Stmt $stmt,
		bool $conditional,
		int $methodId,
		string $file,
		ClassReflection $declaringClass,
		array &$lifecycle
	): void
	{
		if ($lifecycle['presenter']) {
			$mutation = $this->viewMutationOfStmt($stmt);
			if ($mutation !== null) {
				$lifecycle['mutations'][] = [
					'kind' => $mutation['kind'],
					'argument' => $mutation['argument'],
					'line' => $mutation['line'],
					'conditional' => $conditional,
					'methodId' => $methodId,
					'file' => $file,
				];

				return;
			}
		}

		$wiring = self::signalWiringOfStmt($stmt);
		if ($wiring === null) {
			return;
		}

		if ($wiring['target'] !== null) {
			$lifecycle['signalTargets'][] = ['name' => $wiring['target'], 'conditional' => $conditional];

			return;
		}

		if ($wiring['closure'] !== null) {
			$lifecycle['closures'][] = [
				'node' => $wiring['closure'],
				'conditional' => $conditional,
				'file' => $file,
				'declaringClass' => $declaringClass,
			];
		}
	}

	/**
	 * @return array{kind: MutationFact::KIND_SET_VIEW|MutationFact::KIND_SET_ACTION, argument: string|null, line: int}|null
	 */
	private function viewMutationOfStmt(Stmt $stmt): ?array
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof MethodCall) {
			return null;
		}

		$call = $stmt->expr;
		if (
			!$call->var instanceof Variable
			|| $call->var->name !== 'this'
			|| !$call->name instanceof Identifier
		) {
			return null;
		}

		$name = $call->name->toString();
		if ($name === self::SET_VIEW_METHOD) {
			$kind = MutationFact::KIND_SET_VIEW;
		} elseif ($name === self::CHANGE_ACTION_METHOD) {
			$kind = MutationFact::KIND_SET_ACTION;
		} else {
			return null;
		}

		$argExpr = $this->firstArgValue($call->args);

		return [
			'kind' => $kind,
			'argument' => $argExpr instanceof String_ ? $argExpr->value : null,
			'line' => $stmt->getStartLine(),
		];
	}

	/**
	 * @return array{target: string|null, closure: Closure|ArrowFunction|null}|null
	 */
	private static function signalWiringOfStmt(Stmt $stmt): ?array
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return null;
		}

		$assign = $stmt->expr;
		$target = $assign->var;
		if (!$target instanceof ArrayDimFetch || $target->dim !== null) {
			return null;
		}

		if (
			!$target->var instanceof PropertyFetch
			|| !$target->var->name instanceof Identifier
			|| !in_array($target->var->name->toString(), self::SIGNAL_EVENT_PROPERTIES, true)
		) {
			return null;
		}

		$handler = $assign->expr;
		if ($handler instanceof Closure || $handler instanceof ArrowFunction) {
			return ['target' => null, 'closure' => $handler];
		}

		if ($handler instanceof Array_ && count($handler->items) === 2) {
			[$object, $methodName] = $handler->items;
			if (
				$object->value instanceof Variable
				&& $object->value->name === 'this'
				&& $methodName->value instanceof String_
			) {
				return ['target' => $methodName->value->value, 'closure' => null];
			}
		}

		return null;
	}

	/**
	 * @param list<string> $readSet
	 * @param array<int, true> $visited
	 * @param array<string, array{type: Type, certainty: Certainty::*, sites: list<array{file: string, line: int}>}> $assignmentBuilder
	 * @param list<SetFileSeed> $setFileBuilder
	 * @param list<array{fileArgPresent: bool, literalPath: string|null, site: array{file: string, line: int}}> $renderSiteBuilder
	 * @param list<array{channel: TemplateClassFact::CHANNEL_*, className: string, certainty: Certainty::*, sites: list<int>}> $templateClassCandidates
	 * @param list<PhpRenderFacts::CONTROL_*> $controlShapes
	 * @param LifecycleState $lifecycle
	 */
	private function drainSignalQueues(
		ClassReflection $entryClass,
		array &$readSet,
		array &$visited,
		array &$assignmentBuilder,
		array &$setFileBuilder,
		array &$renderSiteBuilder,
		array &$templateClassCandidates,
		array &$controlShapes,
		array &$lifecycle
	): void
	{
		// Walking a wired callback method can wire further callbacks, so the queue re-checks its
		// tail until exhausted; closure processing never grows either queue.
		$processedTargets = 0;
		while ($processedTargets < count($lifecycle['signalTargets'])) {
			$target = $lifecycle['signalTargets'][$processedTargets];
			$processedTargets++;

			$method = $this->nativeMethodOn($entryClass, $target['name']);
			if ($method === null) {
				continue;
			}

			$file = $method->getFileName();
			if ($file === false || !$this->isUnderAppRoot($file)) {
				continue;
			}

			$startLine = $method->getStartLine();
			if ($startLine === false) {
				continue;
			}

			$node = $this->methodNodesByLine($file)[$startLine] ?? null;
			if ($node === null) {
				continue;
			}

			$lifecycle['signalTargetIds'][] = ['id' => spl_object_id($node), 'conditional' => $target['conditional']];

			if (isset($visited[spl_object_id($node)])) {
				continue;
			}

			if (!in_array($file, $readSet, true)) {
				$readSet[] = $file;
			}

			$declaringClassName = $method->getDeclaringClass()->getName();
			$declaringClass = $this->reflectionProvider->hasClass($declaringClassName)
				? $this->reflectionProvider->getClass($declaringClassName)
				: $entryClass;

			$this->walkMethod(
				$node,
				$entryClass,
				$declaringClass,
				$file,
				$readSet,
				$visited,
				$assignmentBuilder,
				$setFileBuilder,
				$renderSiteBuilder,
				$templateClassCandidates,
				$controlShapes,
				$lifecycle,
			);
		}

		$processedClosures = 0;
		while ($processedClosures < count($lifecycle['closures'])) {
			$job = $lifecycle['closures'][$processedClosures];
			$processedClosures++;

			$this->processSignalClosure($job, $entryClass, $setFileBuilder, $lifecycle);
		}
	}

	/**
	 * @param array{node: Closure|ArrowFunction, conditional: bool, file: string, declaringClass: ClassReflection} $job
	 * @param list<SetFileSeed> $setFileBuilder
	 * @param LifecycleState $lifecycle
	 */
	private function processSignalClosure(
		array $job,
		ClassReflection $entryClass,
		array &$setFileBuilder,
		array &$lifecycle
	): void
	{
		$node = $job['node'];
		$closureId = spl_object_id($node);
		if (isset($lifecycle['methods'][$closureId])) {
			return;
		}

		$lifecycle['methods'][$closureId] = ['name' => '', 'public' => false, 'static' => false];
		$lifecycle['closureIds'][] = $closureId;

		$stmts = $node instanceof Closure
			? $node->stmts
			: [new Expression($node->expr, $node->expr->getAttributes())];

		$file = $job['file'];
		$declaringClass = $job['declaringClass'];

		$this->walkConditionalStructure(
			$stmts,
			$job['conditional'],
			function (Stmt $stmt, bool $conditional) use ($closureId, $file, $entryClass, $declaringClass, &$setFileBuilder, &$lifecycle): void {
				if ($lifecycle['presenter']) {
					$mutation = $this->viewMutationOfStmt($stmt);
					if ($mutation !== null) {
						$lifecycle['mutations'][] = [
							'kind' => $mutation['kind'],
							'argument' => $mutation['argument'],
							'line' => $mutation['line'],
							'conditional' => $conditional,
							'methodId' => $closureId,
							'file' => $file,
						];

						return;
					}
				}

				if (!$stmt instanceof Expression || !$stmt->expr instanceof MethodCall) {
					return;
				}

				$call = $stmt->expr;
				if (
					!$call->name instanceof Identifier
					|| $call->name->toString() !== self::SET_FILE_METHOD
					// use()-imported locals carry no provenance here; only direct template origins
					// are recognized inside wired callbacks.
					|| !$this->isTemplateOrigin($call->var, $entryClass, $declaringClass)
				) {
					return;
				}

				$classified = $this->classifySetFileArg($this->firstArgValue($call->args), $file);

				$setFileBuilder[] = [
					'kind' => $classified['kind'],
					'path' => $classified['path'],
					'certainty' => $conditional ? Certainty::MAYBE : Certainty::HAPPENS,
					'site' => ['file' => $file, 'line' => $stmt->getStartLine()],
					'methodId' => $closureId,
					'conventionMethod' => $classified['conventionMethod'],
				];
			},
		);

		foreach ($this->boundedCallTargetIds($stmts, $entryClass, $declaringClass) as $targetId) {
			$lifecycle['edges'][$closureId][] = $targetId;
		}
	}

	/**
	 * @param array<int, Stmt> $stmts
	 * @return list<int>
	 */
	private function boundedCallTargetIds(
		array $stmts,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): array
	{
		$ids = [];
		$calls = (new NodeFinder())->find(
			$stmts,
			static fn (Node $candidate): bool => $candidate instanceof MethodCall || $candidate instanceof StaticCall,
		);

		foreach ($calls as $call) {
			/** @var MethodCall|StaticCall $call */
			$target = $this->resolveCallTarget($call, $entryClass, $declaringClass);
			if ($target === null) {
				continue;
			}

			$file = $target->getFileName();
			if ($file === false || !$this->isUnderAppRoot($file)) {
				continue;
			}

			$startLine = $target->getStartLine();
			if ($startLine === false) {
				continue;
			}

			$node = $this->methodNodesByLine($file)[$startLine] ?? null;
			if ($node === null) {
				continue;
			}

			$ids[] = spl_object_id($node);
		}

		return $ids;
	}

	/**
	 * @param LifecycleState $lifecycle
	 * @return array<int, array<self::CTX_*, true>>
	 */
	private function computeMethodContexts(array $lifecycle): array
	{
		/** @var array<int, array<self::CTX_*, true>> $contexts */
		$contexts = [];
		/** @var list<array{0: int, 1: self::CTX_*}> $work */
		$work = [];

		foreach ($lifecycle['methods'] as $id => $info) {
			$ctx = self::directContextOf($info, $lifecycle['presenter']);
			if ($ctx !== null) {
				self::seedContext($contexts, $work, $id, $ctx);
			}
		}

		foreach ($lifecycle['signalTargetIds'] as $target) {
			self::seedContext($contexts, $work, $target['id'], self::CTX_SIGNAL);
			if ($target['conditional']) {
				// Conditionally wired: the signal window applies only when the wiring ran.
				self::seedContext($contexts, $work, $target['id'], self::CTX_UNKNOWN);
			}
		}

		foreach ($lifecycle['closureIds'] as $closureId) {
			self::seedContext($contexts, $work, $closureId, self::CTX_SIGNAL);
		}

		$hasIncoming = [];
		foreach ($lifecycle['edges'] as $callees) {
			foreach ($callees as $callee) {
				$hasIncoming[$callee] = true;
			}
		}

		// No lifecycle classification and no in-class caller: execution timing is unprovable (an
		// externally invoked public helper, or dead code), and that doubt propagates to callees.
		foreach ($lifecycle['methods'] as $id => $info) {
			if (!isset($contexts[$id]) && !isset($hasIncoming[$id])) {
				self::seedContext($contexts, $work, $id, self::CTX_UNKNOWN);
			}
		}

		self::drainContextWork($contexts, $work, $lifecycle['edges']);

		// Public non-dispatch helpers admit external callers at unprovable times, so shutdown-only
		// in-class reachability cannot prove EFFECTIVE_NO; the added doubt reaches callees too.
		foreach ($lifecycle['methods'] as $id => $info) {
			if (
				$info['public']
				&& ($contexts[$id] ?? []) === [self::CTX_SHUTDOWN => true]
				&& self::directContextOf($info, $lifecycle['presenter']) === null
			) {
				self::seedContext($contexts, $work, $id, self::CTX_UNKNOWN);
			}
		}

		self::drainContextWork($contexts, $work, $lifecycle['edges']);

		return $contexts;
	}

	/**
	 * @param array<int, array<self::CTX_*, true>> $contexts
	 * @param list<array{0: int, 1: self::CTX_*}> $work
	 * @param array<int, list<int>> $edges
	 */
	private static function drainContextWork(array &$contexts, array &$work, array $edges): void
	{
		while ($work !== []) {
			[$id, $ctx] = array_pop($work);
			foreach ($edges[$id] ?? [] as $callee) {
				self::seedContext($contexts, $work, $callee, $ctx);
			}
		}
	}

	/**
	 * @param array<int, array<self::CTX_*, true>> $contexts
	 * @param list<array{0: int, 1: self::CTX_*}> $work
	 * @param self::CTX_* $ctx
	 */
	private static function seedContext(array &$contexts, array &$work, int $id, string $ctx): void
	{
		if (isset($contexts[$id][$ctx])) {
			return;
		}

		$contexts[$id][$ctx] = true;
		$work[] = [$id, $ctx];
	}

	/**
	 * @param array{name: string, public: bool, static: bool} $info
	 * @return self::CTX_*|null
	 */
	private static function directContextOf(array $info, bool $presenter): ?string
	{
		$name = $info['name'];

		if ($presenter) {
			if ($name === 'startup') {
				return self::CTX_STARTUP;
			}

			if ($name === 'checkRequirements') {
				return self::CTX_CHECK_REQUIREMENTS;
			}

			if ($name === 'beforeRender') {
				return self::CTX_BEFORE_RENDER;
			}

			if ($name === 'afterRender') {
				return self::CTX_AFTER_RENDER;
			}

			if ($name === 'shutdown') {
				return self::CTX_SHUTDOWN;
			}

			if ($info['public'] && !$info['static']) {
				if (self::dispatchSuffixOf($name, self::ACTION_METHOD_PREFIX) !== null) {
					return self::CTX_ACTION;
				}

				if (self::dispatchSuffixOf($name, self::RENDER_METHOD_PREFIX) !== null) {
					return self::CTX_RENDER;
				}

				if (self::dispatchSuffixOf($name, self::SIGNAL_METHOD_PREFIX) !== null) {
					return self::CTX_SIGNAL;
				}
			}

			return null;
		}

		// Controls carry only the render and signal axes; presenter lifecycle names have no
		// vendor meaning on them.
		if ($info['public'] && !$info['static']) {
			if ($name === self::RENDER_METHOD || self::dispatchSuffixOf($name, self::RENDER_METHOD_PREFIX) !== null) {
				return self::CTX_RENDER;
			}

			if (self::dispatchSuffixOf($name, self::SIGNAL_METHOD_PREFIX) !== null) {
				return self::CTX_SIGNAL;
			}
		}

		return null;
	}

	// Nette dispatch names firstUpper() the suffix, so a lowercase (or absent) follow-up
	// character can never round-trip to this method name.
	private static function dispatchSuffixOf(string $name, string $prefix): ?string
	{
		$prefixLength = strlen($prefix);
		if (strlen($name) <= $prefixLength || strncmp($name, $prefix, $prefixLength) !== 0) {
			return null;
		}

		$first = $name[$prefixLength];
		if (($first < 'A' || $first > 'Z') && ($first < '0' || $first > '9')) {
			return null;
		}

		return lcfirst((string) substr($name, $prefixLength));
	}

	/**
	 * @param array<self::CTX_*, true> $ctxSet
	 * @return MutationFact::PHASE_*
	 */
	private static function phaseOf(array $ctxSet): string
	{
		$phases = [];
		foreach ($ctxSet === [] ? [self::CTX_UNKNOWN] : array_keys($ctxSet) as $ctx) {
			$phases[self::PHASE_BY_CTX[$ctx]] = true;
		}

		// Multiple reaching phases keep the earliest; the effectiveness join carries the doubt.
		foreach (MutationFact::LIFECYCLE_PHASE_ORDER as $phase) {
			if (isset($phases[$phase])) {
				return $phase;
			}
		}

		return MutationFact::PHASE_OUTSIDE;
	}

	/**
	 * @param array<self::CTX_*, true> $ctxSet
	 * @return MutationFact::EFFECTIVE_*
	 */
	private static function viewEffectivenessOf(array $ctxSet): string
	{
		$effects = [];
		foreach ($ctxSet === [] ? [self::CTX_UNKNOWN] : array_keys($ctxSet) as $ctx) {
			$effects[self::VIEW_EFFECTIVENESS_BY_CTX[$ctx]] = true;
		}

		return count($effects) === 1 ? array_key_first($effects) : MutationFact::EFFECTIVE_MAYBE;
	}

	/**
	 * @return list<array{view: string, site: array{file: string, line: int}, method: string}>
	 */
	private function methodDerivedViewSeeds(ClassReflection $classReflection): array
	{
		$seeds = [];
		foreach ($classReflection->getNativeReflection()->getMethods() as $method) {
			if (!$method->isPublic() || $method->isStatic() || $method->isAbstract()) {
				continue;
			}

			$file = $method->getFileName();
			if ($file === false || !$this->isUnderAppRoot($file)) {
				continue;
			}

			$name = $method->getName();
			$view = self::dispatchSuffixOf($name, self::ACTION_METHOD_PREFIX)
				?? self::dispatchSuffixOf($name, self::RENDER_METHOD_PREFIX);
			if ($view === null) {
				continue;
			}

			$startLine = $method->getStartLine();

			$seeds[] = [
				'view' => $view,
				'site' => ['file' => $file, 'line' => $startLine === false ? 0 : $startLine],
				'method' => $name,
			];
		}

		return $seeds;
	}

	/**
	 * @param LifecycleState $lifecycle
	 * @param array<int, array<self::CTX_*, true>> $contexts
	 * @param array<ViewEntry> $viewBuilder
	 * @param list<MutationFact> $mutationFacts
	 * @return array{views: array<ViewEntry>, mutations: list<MutationFact>, open: bool}
	 */
	private static function finalizeViewMutations(
		array $lifecycle,
		array $contexts,
		array $viewBuilder,
		array $mutationFacts
	): array
	{
		$open = false;

		$byMethod = [];
		foreach ($lifecycle['mutations'] as $mutation) {
			$byMethod[$mutation['methodId']][] = $mutation;
		}

		foreach ($byMethod as $methodId => $list) {
			$ctxSet = $contexts[$methodId] ?? [];
			$phase = self::phaseOf($ctxSet);
			$effectiveness = self::viewEffectivenessOf($ctxSet);

			/** @var array<ViewEntry> $pending */
			$pending = [];
			foreach ($list as $mutation) {
				$fact = new MutationFact(
					$mutation['kind'],
					$phase,
					$effectiveness,
					$mutation['line'],
					$mutation['argument'],
				);
				$mutationFacts[] = $fact;

				if ($effectiveness === MutationFact::EFFECTIVE_NO) {
					// Provably past the view resolution: recorded, never part of the view set.
					continue;
				}

				if ($mutation['argument'] === null) {
					$open = true;

					continue;
				}

				if (!$mutation['conditional']) {
					// Last write wins within one linear body: an unconditional write makes every
					// earlier same-method candidate unreachable. A dynamic write never drops
					// earlier names - keeping them plus the open marker is the safe direction.
					$pending = [];
				}

				$certainty = $mutation['conditional'] || $effectiveness === MutationFact::EFFECTIVE_MAYBE
					? Certainty::MAYBE
					: Certainty::HAPPENS;
				$pending[$mutation['argument']] = self::mergedViewEntry(
					$pending[$mutation['argument']] ?? null,
					$certainty,
					[['file' => $mutation['file'], 'line' => $mutation['line']]],
					[$fact],
				);
			}

			foreach ($pending as $view => $entry) {
				$viewBuilder[(string) $view] = self::mergedViewEntry(
					$viewBuilder[(string) $view] ?? null,
					$entry['certainty'],
					$entry['sites'],
					$entry['sources'],
				);
			}
		}

		return ['views' => $viewBuilder, 'mutations' => $mutationFacts, 'open' => $open];
	}

	/**
	 * @param ViewEntry|null $existing
	 * @param Certainty::HAPPENS|Certainty::MAYBE $certainty
	 * @param list<array{file: string, line: int}> $sites
	 * @param list<MutationFact|string> $sources
	 * @return ViewEntry
	 */
	private static function mergedViewEntry(?array $existing, string $certainty, array $sites, array $sources): array
	{
		if ($existing === null) {
			return ['certainty' => $certainty, 'sites' => $sites, 'sources' => $sources];
		}

		// Any definite deriver settles membership - the strongest contribution wins. Unlike write
		// certainty, this answers "is the name in the set", not "does this write always run".
		return [
			'certainty' => $existing['certainty'] === Certainty::HAPPENS ? Certainty::HAPPENS : $certainty,
			'sites' => array_merge($existing['sites'], $sites),
			'sources' => array_merge($existing['sources'], $sources),
		];
	}

	// Reflection only, no Scope. Anything outside these shapes resolves to null, which the
	// caller widens to `mixed` - the OPEN-discipline fallback for unwalkable shapes.
	private function typeOfExpr(Expr $expr, ClassReflection $entryClass, ClassReflection $declaringClass): ?Type
	{
		$literal = $this->literalType($expr);
		if ($literal !== null) {
			return $literal;
		}

		if ($expr instanceof New_ && $expr->class instanceof Name) {
			$fqcn = ltrim($expr->class->toString(), '\\');

			return $this->reflectionProvider->hasClass($fqcn) ? new ObjectType($fqcn) : null;
		}

		if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
			$owner = $this->classOfReceiverExpr($expr->var, $entryClass, $declaringClass);

			return $owner === null ? null : $this->propertyType($owner, $expr->name->toString());
		}

		if ($expr instanceof StaticCall && $expr->name instanceof Identifier) {
			$receiver = $this->resolveCallReceiverClass($expr, $entryClass, $declaringClass);

			return $receiver === null ? null : $this->methodReturnType($receiver, $expr->name->toString());
		}

		if ($expr instanceof MethodCall && $expr->name instanceof Identifier) {
			$receiver = $this->classOfReceiverExpr($expr->var, $entryClass, $declaringClass);

			return $receiver === null ? null : $this->methodReturnType($receiver, $expr->name->toString());
		}

		return null;
	}

	private function literalType(Expr $expr): ?Type
	{
		if ($expr instanceof String_) {
			return new StringType();
		}

		if ($expr instanceof Int_) {
			return new IntegerType();
		}

		if ($expr instanceof Float_) {
			return new FloatType();
		}

		if ($expr instanceof Array_) {
			return new ArrayType(new MixedType(), new MixedType());
		}

		if ($expr instanceof ConstFetch) {
			$name = strtolower(ltrim($expr->name->toString(), '\\'));
			if ($name === 'true' || $name === 'false') {
				return new BooleanType();
			}

			if ($name === 'null') {
				return new NullType();
			}
		}

		return null;
	}

	// General (non-call-following-bounded) receiver resolution for RHS type extraction - never
	// drives call-following or read-set growth, only answers "what class does this belong to".
	private function classOfReceiverExpr(
		Expr $expr,
		ClassReflection $entryClass,
		ClassReflection $declaringClass
	): ?ClassReflection
	{
		if ($expr instanceof Variable && $expr->name === 'this') {
			return $entryClass;
		}

		$type = $this->typeOfExpr($expr, $entryClass, $declaringClass);

		return $type === null ? null : $this->classReflectionOfType($type);
	}

	private function propertyType(ClassReflection $owner, string $property): ?Type
	{
		$class = $owner;
		while ($class !== null) {
			if ($class->hasNativeProperty($property)) {
				return $class->getNativeProperty($property)->getReadableType();
			}

			$class = $class->getParentClass();
		}

		return null;
	}

	private function methodReturnType(ClassReflection $owner, string $method): ?Type
	{
		if (!$owner->hasNativeMethod($method)) {
			return null;
		}

		return $owner->getNativeMethod($method)->getVariants()[0]->getReturnType();
	}

	private function classReflectionOfType(Type $type): ?ClassReflection
	{
		$classNames = $type->getObjectClassNames();
		if (count($classNames) !== 1) {
			return null;
		}

		return $this->reflectionProvider->hasClass($classNames[0])
			? $this->reflectionProvider->getClass($classNames[0])
			: null;
	}

	// __DIR__ resolves lexically to the file the code is textually written in, which is $file
	// here (the currently-walked ancestor/helper's own file) - never the entry class's file.
	private function resolveLiteralPath(Expr $expr, string $file): ?string
	{
		$literal = $this->resolveLiteralStringPart($expr, $file);
		if ($literal === null) {
			return null;
		}

		$real = realpath($literal);

		// A target .latte file need not exist for this to still be a genuine static absolute
		// path - falls back to the concatenated (unresolved) value rather than null, so a
		// moved/missing target is never mistaken for an unresolvable (opaque) argument.
		return $real === false ? $literal : $real;
	}

	private function resolveLiteralStringPart(Expr $expr, string $file): ?string
	{
		if ($expr instanceof String_) {
			return $expr->value;
		}

		if ($expr instanceof Dir) {
			$real = realpath($file);

			return $real === false ? null : dirname($real);
		}

		if ($expr instanceof Concat) {
			$left = $this->resolveLiteralStringPart($expr->left, $file);
			$right = $this->resolveLiteralStringPart($expr->right, $file);

			return $left === null || $right === null ? null : $left . $right;
		}

		return null;
	}

	private function isUnderAppRoot(string $file): bool
	{
		return $this->firstPartyPaths->contains($file);
	}

	/**
	 * @return array<int, ClassMethod>
	 */
	private function methodNodesByLine(string $file): array
	{
		if (array_key_exists($file, $this->methodNodesByFile)) {
			return $this->methodNodesByFile[$file];
		}

		$index = [];
		foreach ((new NodeFinder())->findInstanceOf($this->parser->parseFile($file), ClassMethod::class) as $method) {
			$index[$method->getStartLine()] = $method;
		}

		return $this->methodNodesByFile[$file] = $index;
	}

}
