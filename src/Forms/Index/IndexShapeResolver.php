<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Index;

use Nette\ComponentModel\IComponent;
use Nette\Forms\Container as NetteContainer;
use Nette\Forms\Form as NetteForm;
use OriPhpstan\Nette\Forms\Analyzer\AnalyzerStackFactory;
use OriPhpstan\Nette\Forms\Analyzer\FormShapeAnalyzer;
use OriPhpstan\Nette\Forms\Analyzer\LocalVariableClassTracker;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\NetteEffectiveControlValueTypeResolver;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Component\AnalysedPaths;
use OriPhpstan\Nette\Forms\Component\BuilderChainDetector;
use OriPhpstan\Nette\Forms\Component\ConstructedFormShapeResolver;
use OriPhpstan\Nette\Forms\Component\ConstructorFormShapeResolver;
use OriPhpstan\Nette\Forms\Component\InterproceduralShapeKey;
use OriPhpstan\Nette\Forms\Component\ReturnShapeSupport;
use OriPhpstan\Nette\Forms\Component\TraitAwareMethodLocator;
use OriPhpstan\Nette\Forms\Graph\ClosureScope;
use OriPhpstan\Nette\Forms\Graph\PropertyFormAccess;
use OriPhpstan\Nette\Forms\Inference\EnclosingFunctionLikeLocator;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use ReflectionClass;
use function array_intersect;
use function array_key_exists;
use function array_values;
use function count;
use function in_array;
use function is_int;
use function is_string;
use function ksort;
use function lcfirst;
use function ltrim;
use function spl_object_id;
use function strncmp;
use function substr;
use function ucfirst;

/**
 * The source of truth for interprocedural handler/pass-through param keys, computed on demand from
 * the order-independent RegistrationIndex: each handler site's registered-form shape is recomputed
 * through the existing scope-free walk (FormShapeAnalyzer built by AnalyzerStackFactory, self-framing
 * through FormShapeCache); pass-through edges re-apply the four reflection gates the recognizer
 * coarsened and recurse into the caller key.
 *
 * Multiple return arms within one site join through the analyzer's own return-point fold
 * (FormShape::joinBranch); sites and edges combine across each other with FormShape::merge(). A
 * per-key memo caches both hits and misses (the fallback-storm rule) and a re-entrancy set breaks
 * pass-through cycles (a cycle degrades to null).
 *
 * Resolution is in-memory per process — no persistence of answers. The registered-form walk needs
 * a live analysis Scope (borrowed, as the on-demand ContainerModel resolvers do); it is bound through
 * bindScope() before a resolution session. The factory-call rebind compensation
 * ($form = $this->factory->create()) is applied (factoryAssignShape), resolving the factory
 * through the same interprocedural machinery and clearing REBIND_UNPROVEN on success; the constructor
 * compensation of a `new X()` rebind is applied too. The parent-inherited createComponent
 * compensation ($form = parent::createComponentForm()) is applied (inheritedParentShape), resolving
 * the parent class's builder scope-free and merging its inherited fields under the same sole-rebind and
 * marker discipline; an unresolvable parent stays honestly open, never a silent different-closed shape.
 *
 * classPropertyFormShape adds a third channel beside the handler-param and class-component ones: a form
 * held in an object PROPERTY, whose sources are the class's own binding methods normalised into the
 * walk's variable vocabulary and folded through the same entry. Its openness is decided by what the
 * per-class fold can see rather than by the walk alone — see that method.
 */
final class IndexShapeResolver
{

	private RegistrationIndex $index;

	private FormShapeCache $cache;

	private ReflectionProvider $reflectionProvider;

	private FormShapeAnalyzer $analyzer;

	private EnclosingFunctionLikeLocator $records;

	private TraitAwareMethodLocator $methodLocator;

	private ReturnShapeSupport $support;

	private ConstructedFormShapeResolver $constructedResolver;

	private LocalVariableClassTracker $classTracker;

	private AnalysedPaths $analysedPaths;

	private ?Scope $scope = null;

	/** @var array<string, FormShape|null> */
	private array $memo = [];

	/** @var array<string, true> */
	private array $resolving = [];

	/** @var array<string, string|null> */
	private array $classMemo = [];

	/** @var array<string, bool> */
	private array $mutationMemo = [];

	/** @var array<string, true> */
	private array $resolvingClass = [];

	/** @var array<string, FormShape|null> */
	private array $propertyMemo = [];

	/** @var array<string, true> */
	private array $resolvingProperty = [];

	/**
	 * @param list<string> $analysedPaths
	 */
	public function __construct(
		RegistrationIndex $index,
		FormShapeCache $cache,
		Parser $parser,
		ReflectionProvider $reflectionProvider,
		ControlAnnotationValueTypeReader $catalogReader,
		array $analysedPaths
	)
	{
		$this->index = $index;
		$this->cache = $cache;
		$this->reflectionProvider = $reflectionProvider;
		$this->analyzer = AnalyzerStackFactory::build($catalogReader, $cache, $parser, $analysedPaths);
		$this->records = new EnclosingFunctionLikeLocator();
		$this->methodLocator = new TraitAwareMethodLocator($parser);
		$constructorResolver = new ConstructorFormShapeResolver(
			$reflectionProvider,
			new NetteEffectiveControlValueTypeResolver(
				$catalogReader,
				AnalyzerStackFactory::buildCallees($catalogReader, $cache, $parser),
				$analysedPaths,
			),
			$parser,
			$cache,
		);
		$this->constructedResolver = new ConstructedFormShapeResolver($reflectionProvider, $constructorResolver);
		$this->support = new ReturnShapeSupport($reflectionProvider, $this->constructedResolver);
		$this->classTracker = new LocalVariableClassTracker($reflectionProvider, $cache->recorder());
		$this->analysedPaths = new AnalysedPaths($analysedPaths);
	}

	public function bindScope(Scope $scope): void
	{
		$this->scope = $scope;
	}

	public function resolveMethodParam(string $class, string $method, int $paramIdx): ?FormShape
	{
		$key = InterproceduralShapeKey::forMethodParam($class, $method, $paramIdx);

		// Answering inside an enclosing recorder frame means that frame's shape EMBEDS this answer, whose
		// correctness depends on the key's contributor set: a registration appearing in any file — even
		// one created after the entry was written — changes the answer, which no per-file dependency
		// captures. Record the key's contributor fingerprint into the frame; entry validation refolds the
		// universe (cheap — the fold is blob-served) and invalidates the entry when the set moves, so a
		// brand-new registering file is caught where the recorded files could not see it. Recorded before
		// the memo/cycle short-circuits so a memo hit or a pass-through cycle inside the frame still
		// contributes its fingerprint, and for every key on the pass-through chain (not only the
		// outermost) since any contributor-set move along the chain changes the embedded answer. Empirically
		// dormant: no reader answers a param shape inside a frame (B6 probe: zero embeddings), so this is a
		// sound, zero-cost safety net rather than a live path.
		if ($this->cache->recorder()->hasActiveFrame()) {
			$this->cache->recorder()->recordFingerprint($key, $this->index->fingerprint($key));
		}

		if (array_key_exists($key, $this->memo)) {
			return $this->memo[$key];
		}

		// A pass-through cycle re-entering its own key breaks without memoising the partial null.
		if (isset($this->resolving[$key])) {
			return null;
		}

		$this->resolving[$key] = true;
		try {
			$shape = $this->compute($class, $method, $paramIdx);
		} finally {
			unset($this->resolving[$key]);
		}

		$this->memo[$key] = $shape;

		return $shape;
	}

	/**
	 * The registered form's constructed class for a handler/pass-through param key, resolved scope-free
	 * WITHOUT the shape walk (no analyzeFormValue, no getType() on the reader scope). A caller narrows
	 * the bare handler-param variable to this class BEFORE the offset walk that reflects the builder's
	 * add* returns runs — so the walk reflects them on the constructed class, not on the declared param
	 * type, without recursing into the very shape resolution the narrowed type then feeds. Every
	 * contributing site and edge must resolve to the same class; a single unresolvable or divergent
	 * contributor returns null (the param keeps its declared type), so the narrowed class is always a
	 * proven supertype of every registration's runtime form — never below the truth.
	 */
	public function resolveMethodParamClass(string $class, string $method, int $paramIdx): ?string
	{
		$key = InterproceduralShapeKey::forMethodParam($class, $method, $paramIdx);

		// Mirrors resolveMethodParam's fingerprint recording: this channel answers the same key from
		// the same contributor set, so an active frame embedding this answer needs the same fingerprint
		// dependency to invalidate when a registration enters or leaves any file.
		if ($this->cache->recorder()->hasActiveFrame()) {
			$this->cache->recorder()->recordFingerprint($key, $this->index->fingerprint($key));
		}

		if (array_key_exists($key, $this->classMemo)) {
			return $this->classMemo[$key];
		}

		if (isset($this->resolvingClass[$key])) {
			return null;
		}

		$this->resolvingClass[$key] = true;
		try {
			$resolved = null;
			foreach ($this->index->handlerSites($class, $method, $paramIdx) as $site) {
				$siteClass = $this->registeredFormClass($site['fact']);
				if ($siteClass === null || ($resolved !== null && $resolved !== $siteClass)) {
					return null;
				}

				$resolved = $siteClass;
			}

			foreach ($this->index->passThroughEdgesInto($class, $method, $paramIdx) as $edge) {
				$edgeClass = $this->passThroughClass($edge['fact']);
				if ($edgeClass === null || ($resolved !== null && $resolved !== $edgeClass)) {
					return null;
				}

				$resolved = $edgeClass;
			}
		} finally {
			unset($this->resolvingClass[$key]);
		}

		$this->classMemo[$key] = $resolved;

		return $resolved;
	}

	/**
	 * The constructed Nette-container class the registering method binds the handler on, resolved
	 * scope-free from the form variable's final `new X()` assignment (or, when that is undecidable, the
	 * method's declared Form/Container return). Leading-backslash normalised so sites compare by
	 * identity. Null when the registering method or its form class is unlocatable.
	 */
	private function registeredFormClass(RegistrationFact $fact): ?string
	{
		$registeringClass = $fact->getRegisteringClass();
		$registeringMethod = $fact->getRegisteringMethod();
		if (!$this->reflectionProvider->hasClass($registeringClass)) {
			return null;
		}

		$native = $this->reflectionProvider->getClass($registeringClass)->getNativeReflection();
		if (!$native->hasMethod($registeringMethod)) {
			return null;
		}

		$methodNode = $this->methodLocator->locate($native->getMethod($registeringMethod));
		if ($methodNode === null) {
			return null;
		}

		$constructed = $this->returnedContainerClass($methodNode, $fact->getFormVar(), $registeringClass)
			?? $this->declaredReturnFormClass($registeringClass, $registeringMethod);

		return $constructed === null ? null : ltrim($constructed, '\\');
	}

	/**
	 * The constructed class flowing across a pass-through edge — the caller parameter it forwards,
	 * resolved recursively. A caller origin that is not a plain parameter index (a local-var / class
	 * component site) is left unresolved, so the depending param keeps its declared type rather than
	 * risk a narrowing below an unmodelled origin's runtime class.
	 */
	private function passThroughClass(RegistrationFact $fact): ?string
	{
		$origin = $fact->getCallerOrigin();
		if (!is_int($origin)) {
			return null;
		}

		return $this->resolveMethodParamClass($fact->getCallerClass(), $fact->getCallerMethod(), $origin);
	}

	private function compute(string $class, string $method, int $paramIdx): ?FormShape
	{
		$result = null;

		foreach ($this->index->handlerSites($class, $method, $paramIdx) as $site) {
			$siteShape = $this->registeredFormShape($site['fact']);
			if ($siteShape === null) {
				continue;
			}

			$result = $result === null ? $siteShape : $result->merge($siteShape);
		}

		foreach ($this->index->passThroughEdgesInto($class, $method, $paramIdx) as $edge) {
			$edgeShape = $this->passThroughShape($class, $edge['fact']);
			if ($edgeShape === null) {
				continue;
			}

			$result = $result === null ? $edgeShape : $result->merge($edgeShape);
		}

		return $result;
	}

	private function registeredFormShape(RegistrationFact $fact): ?FormShape
	{
		return $this->registeredFormShapeFor(
			$fact->getRegisteringClass(),
			$fact->getRegisteringMethod(),
			$fact->getFormVar(),
		);
	}

	/**
	 * Resolves the registered-form shape for a registering method: a handler site keys
	 * the returned var explicitly ($expectedFormVar); a createComponent factory (the store's
	 * forClassComponent value that extractThisOffsetShape reads) passes null, so every method-level
	 * returned variable resolves and the arms join exactly as the store's per-arm writes joinBranch —
	 * and when no variable is returned at all, the store's chain / `$this->prop` return arms resolve
	 * through nonVarReturnShape. A factory-call rebind (factoryAssignShape) and a parent-inherited
	 * createComponent rebind (inheritedParentShape) are both compensated, resolving the source
	 * scope-free and clearing REBIND_UNPROVEN once a compensating shape without its own marker
	 * resolves; an unresolvable source keeps the base walk's honest open marker.
	 */
	private function registeredFormShapeFor(
		string $registeringClass,
		string $registeringMethod,
		?string $expectedFormVar
	): ?FormShape
	{
		// The registered-form walk needs a borrowed analysis scope; without one the site degrades to
		// no contribution (a sound miss) rather than a wrong closed shape.
		if ($this->scope === null) {
			return null;
		}

		if (!$this->reflectionProvider->hasClass($registeringClass)) {
			return null;
		}

		$native = $this->reflectionProvider->getClass($registeringClass)->getNativeReflection();
		if (!$native->hasMethod($registeringMethod)) {
			return null;
		}

		$reflectionMethod = $native->getMethod($registeringMethod);
		$file = $this->methodLocator->ownFile($reflectionMethod);
		$methodNode = $this->methodLocator->locate($reflectionMethod);
		if ($file === null || $methodNode === null) {
			return null;
		}

		return $this->methodFormShape($methodNode, $file, $registeringClass, $registeringMethod, $expectedFormVar);
	}

	/**
	 * The fold itself, over a method node the caller has already located — the one walk, shared by
	 * every channel that has a form-bearing value to name. The registered-form channel hands it the
	 * method as parsed; the property channel hands it the same method normalised so `$this->prop`
	 * reads as the named variable (PropertyFormAccess::rewrite), which is what makes a property target
	 * expressible in the $expectedFormVar vocabulary at all.
	 */
	private function methodFormShape(
		ClassMethod $methodNode,
		string $file,
		string $registeringClass,
		string $registeringMethod,
		?string $expectedFormVar
	): ?FormShape
	{
		if ($expectedFormVar !== null) {
			return $this->varReturnShape($methodNode, $file, $registeringClass, $registeringMethod, $expectedFormVar);
		}

		$names = $this->returnedVariableNames($methodNode);
		if ($names === []) {
			return $this->nonVarReturnShape($methodNode, $registeringClass, $registeringMethod);
		}

		$joined = null;
		foreach ($names as $name) {
			$arm = $this->varReturnShape($methodNode, $file, $registeringClass, $registeringMethod, $name);
			if ($arm === null || $arm->getClassName() === null) {
				continue;
			}

			$joined = $joined === null ? $arm : $joined->joinBranch($arm);
		}

		return $joined;
	}

	private function varReturnShape(
		ClassMethod $methodNode,
		string $file,
		string $registeringClass,
		string $registeringMethod,
		string $formVar
	): ?FormShape
	{
		if ($this->scope === null) {
			return null;
		}

		$returnExpr = $this->returnedVariableExpr($methodNode, $formVar);
		if ($returnExpr === null) {
			return null;
		}

		// Scope-free entry-class fallback: the returned form's own container class, resolved from its
		// defining assignment even when it is rebound more than once (which defeats the class tracker's
		// single-assignment resolution). Without it the walk bails closed-empty under a foreign scope,
		// silently narrowing below the store instead of degrading OPEN on the unproven rebind. Null when
		// the returned value is genuinely not a container (an undecidable factory result), so the walk
		// bails exactly as the store's does — never opening a shape the store closes.
		$container = $this->returnedContainerClass($methodNode, $formVar, $registeringClass);

		$shape = $this->analyzer->analyzeFormValue(
			$returnExpr,
			$methodNode,
			$this->records->taggedRecords($methodNode, $this->scope),
			$this->scope,
			$file,
			$registeringClass,
			true,
			$container,
		);

		if ($shape->getClassName() === null) {
			return null;
		}

		$isCreateComponent = strncmp($registeringMethod, 'createComponent', 15) === 0;

		// REBIND_UNPROVEN is itself a lost-field reason, so the marker a factory/parent rebind raised is
		// stripped from the probe the compensation lost-field guards read — otherwise the rebind's own
		// marker would self-block the resolution it exists to be compensated by. Stripped for any resolved
		// source (not only createComponent) so the factory/constructor guards below apply to every method.
		$probeShape = $this->reassignsFromResolvedSource($methodNode, $formVar, $isCreateComponent)
			? $shape->withoutUnknownReason(UnknownReason::REBIND_UNPROVEN)
			: $shape;

		// A parent-inherited createComponent rebind ($form = parent::createComponentForm()) resolves the
		// parent class's builder scope-free through the same registeredFormShapeFor entry the collector's
		// forClassComponent store value is computed by, merging its inherited fields; an unresolvable
		// parent keeps the marker (honest open).
		$parentShape = null;
		if ($isCreateComponent) {
			$parentShape = $this->inheritedParentShape(
				$methodNode,
				$formVar,
				$registeringClass,
				$registeringMethod,
				$probeShape,
			);
			if ($parentShape !== null) {
				$shape = $shape->merge($parentShape);
			}
		}

		// A factory-call rebind ($form = $this->factory->create()) resolves that source through the same
		// interprocedural machinery the store's factoryAssignShape uses (BuilderChainDetector's
		// forFactoryMethod cache, then the remote factory method's new/var returns). Skipped when the
		// method opened the form by pulling a control into a local and mutating it (a lost-field unknown):
		// merging the factory's precise-but-now-incomplete fields would wrongly close them. The guard
		// precedes every resolution channel so the outcome is cache-temperature-independent.
		$factoryShape = $this->hasLostFieldUnknown($probeShape)
			? null
			: $this->factoryAssignShape($methodNode, $formVar, $registeringClass, $this->scope);
		if ($factoryShape !== null) {
			$shape = $shape->merge($factoryShape);
		}

		// A compensating shape that itself carries the marker (its own form came from an unresolved
		// source) must not launder it through the strip — the merged marker stays and the shape is open.
		if (
			($parentShape !== null || $factoryShape !== null)
			&& ($parentShape === null || !$this->carriesRebindMarker($parentShape))
			&& ($factoryShape === null || !$this->carriesRebindMarker($factoryShape))
		) {
			$shape = $shape->withoutUnknownReason(UnknownReason::REBIND_UNPROVEN);
		}

		// Skipped for the same lost-field reason as the factory merge: a constructor's precise fields would
		// wrongly close a shape the method opened by mutating a pulled-in control.
		$constructorShape = $this->hasLostFieldUnknown($probeShape)
			? null
			: $this->support->constructorShape($methodNode, $formVar, $registeringClass);
		if ($constructorShape !== null) {
			$shape = $shape->merge($constructorShape);
		}

		if (ReturnShapeSupport::isClassNameUnresolved($shape->getClassName())) {
			// The declared-component fallback exists for createComponent only: a foreign borrowed scope
			// cannot type a returned non-Container component variable (control, datagrid), which the
			// collector's native scope could — the declared return class restores the hop-bearing top
			// class (equal or wider than the store's runtime class, never narrower fields). A handler
			// site's registering method may declare an unrelated component return, so it keeps the
			// Form/Container-only fallback.
			$declared = $this->declaredReturnFormClass($registeringClass, $registeringMethod)
				?? ($isCreateComponent
					? $this->declaredReturnComponentClass($registeringClass, $registeringMethod)
					: null);
			if ($declared !== null) {
				$shape = $shape->withClassName($declared);

				// Substituting the class means the ORIGIN was never read, and where the walk recorded
				// no child on any channel either, nothing about the component was read at all. Closed
				// and empty is a positive claim that it owns nothing - the same false proof
				// ContainerModel's builder-chain arm used to make for the same construction returned
				// directly, and the same UNRESOLVED_ORIGIN answers it. A shape that DID record
				// children keeps its closedness: the fields were read, only the class name was not.
				if ($shape->getSlots() === []
					&& $shape->getContainers() === []
					&& $shape->getReplicators() === []
					&& $shape->getComponentTypes() === []
				) {
					$shape = $shape->withUnknownReason(UnknownReason::UNRESOLVED_ORIGIN);
				}
			}
		}

		return $shape;
	}

	private function declaredReturnComponentClass(string $class, string $method): ?string
	{
		if (!$this->reflectionProvider->hasClass($class)) {
			return null;
		}

		$classReflection = $this->reflectionProvider->getClass($class);
		if (!$classReflection->hasNativeMethod($method)) {
			return null;
		}

		$returnType = $classReflection->getNativeMethod($method)->getVariants()[0]->getReturnType();
		$classes = $returnType->getObjectClassNames();
		if (count($classes) !== 1) {
			return null;
		}

		return (new ObjectType(IComponent::class))->isSuperTypeOf(new ObjectType($classes[0]))->yes()
			? '\\' . ltrim($classes[0], '\\')
			: null;
	}

	public function classComponentShape(string $ownerFqcn, string $componentName): ?FormShape
	{
		return $this->registeredFormShapeFor(
			$ownerFqcn,
			'createComponent' . ucfirst($componentName),
			null,
		);
	}

	/**
	 * The property-slot sibling of classComponentShape: the shape of a form held in `$this->prop`
	 * rather than in a component slot. Its sources are every method of the class that BINDS the
	 * property with a plain assignment — the constructor is the expected one, but nothing here is
	 * keyed to `__construct`; a lazy `build()` binder is the same fact. Each source is normalised
	 * into the walk's variable vocabulary (PropertyFormAccess::rewrite) and folded through the very
	 * same methodFormShape entry the registered-form channel uses, so the rebind markers and the
	 * factory / parent / constructor compensations apply to a property-held form for free. Sources
	 * join per branch: with two binders neither one definitely won, so their fields are MAYBE.
	 *
	 * OPENNESS IS THE POINT, and it is decided one level up from the walk, because v1 models no write
	 * it has not read. componentMayBeMutatedExternally's docblock states the hazard for the component
	 * slot; the property slot answers it inside the shape instead of asking the caller to ask:
	 *
	 * - a PUBLIC or PROTECTED property is writable from bodies this per-class fold never sees (any
	 *   caller, any subclass), so it is ALWAYS open — never mind that its builder read cleanly;
	 * - a PRIVATE property is legally touchable only from the declaring class's own bodies, which is
	 *   exactly the set folded here, so it may close — unless one of those bodies mutates the form
	 *   without binding it, hands it to a caller, or reaches it through a spelling the fold cannot
	 *   attribute, each of which opens it again;
	 * - two or more binders leave REBIND_UNPROVEN, the marker the walk already raises for a binding
	 *   it cannot prove, rather than a new constant for the same fact.
	 *
	 * Null — no shape at all, the declared type stands — when no method binds the property, when the
	 * property could never hold a container, or when what it is bound to is not one.
	 */
	public function classPropertyFormShape(string $ownerFqcn, string $propertyName): ?FormShape
	{
		$key = ltrim($ownerFqcn, '\\') . "\0" . $propertyName;
		if (array_key_exists($key, $this->propertyMemo)) {
			return $this->propertyMemo[$key];
		}

		// A consumer resolving a property shape from inside the walk of a body that reads the same
		// property re-enters this key; the cycle breaks without memoising the partial null.
		if (isset($this->resolvingProperty[$key])) {
			return null;
		}

		$this->resolvingProperty[$key] = true;
		try {
			$shape = $this->computePropertyShape($ownerFqcn, $propertyName);
		} finally {
			unset($this->resolvingProperty[$key]);
		}

		return $this->propertyMemo[$key] = $shape;
	}

	private function computePropertyShape(string $ownerFqcn, string $propertyName): ?FormShape
	{
		// The walk needs a borrowed analysis scope; without one the property degrades to no shape (a
		// sound miss) rather than a wrong closed one, exactly as registeredFormShapeFor does.
		if ($this->scope === null || !$this->reflectionProvider->hasClass($ownerFqcn)) {
			return null;
		}

		$classReflection = $this->reflectionProvider->getClass($ownerFqcn);
		if (!$classReflection->hasNativeProperty($propertyName)) {
			return null;
		}

		$property = $classReflection->getNativeProperty($propertyName);
		if ($property->isStatic() || !$this->mayHoldContainer($property->getReadableType())) {
			return null;
		}

		$binders = [];
		$mentioners = [];
		$open = false;
		$methods = $this->locatedMethods($classReflection->getNativeReflection());
		foreach ($methods as $methodName => [$methodNode, $file]) {
			if (PropertyFormAccess::hasUnattributableAccess($methodNode, $propertyName)) {
				$open = true;
			}

			if (!PropertyFormAccess::mentionsProperty($methodNode, $propertyName)) {
				continue;
			}

			if (PropertyFormAccess::bindsProperty($methodNode, $propertyName)) {
				$binders[$methodName] = [$methodNode, $file];
			} else {
				$mentioners[$methodName] = [$methodNode, $file];
			}
		}

		if ($binders === []) {
			return null;
		}

		$shape = null;
		foreach ($binders as $methodName => [$methodNode, $file]) {
			$arm = $this->methodFormShape(
				PropertyFormAccess::rewrite($methodNode, $propertyName),
				$file,
				$ownerFqcn,
				$methodName,
				PropertyFormAccess::variableName($propertyName),
			);
			if ($arm === null || $arm->getClassName() === null) {
				// A binder the fold could not read is a build step whose adds are missing from the
				// join — the surviving arms must not close over them.
				$open = true;

				continue;
			}

			$shape = $shape === null ? $arm : $shape->joinBranch($arm);
		}

		if ($shape === null || !$this->isContainerClass($shape->getClassName())) {
			return null;
		}

		if (count($binders) > 1) {
			$shape = $shape->withUnknownReason(UnknownReason::REBIND_UNPROVEN);
		}

		foreach ($mentioners as [$methodNode, $file]) {
			if ($this->mentionMutates($methodNode, $file, $propertyName, (string) $shape->getClassName())) {
				$open = true;
			}
		}

		return $open || !$property->isPrivate()
			? $shape->withUnknownReason(UnknownReason::PROPERTY_WRITE_UNMODELLED)
			: $shape;
	}

	/**
	 * Whether a body that mentions the property-held form without binding it WRITES to it, decided by
	 * the same walk the binders are folded through (the container-param entry: a form bound before the
	 * body starts is precisely what that entry is for) rather than by a second, weaker syntactic
	 * classifier. Anything it attaches, and any unknown it raises — an unfollowable call on the form,
	 * an alias, a control pulled into a local — counts as a write.
	 *
	 * Deciding this per body rather than treating every mention as a write is what keeps the channel
	 * useful at all: the consumer of a property-held form is usually a method of the class that built
	 * it, and `$this->form->getValues()` must not open the shape it is asking about.
	 */
	private function mentionMutates(
		ClassMethod $methodNode,
		string $file,
		string $propertyName,
		string $className
	): bool
	{
		if ($this->scope === null) {
			return true;
		}

		$rewritten = PropertyFormAccess::rewrite($methodNode, $propertyName);
		$arm = $this->analyzer->analyzeContainerParam(
			PropertyFormAccess::variableName($propertyName),
			$rewritten,
			$className,
			$this->records->taggedRecords($rewritten, $this->scope),
			$this->scope,
			$file,
		);

		return $arm->getSlots() !== []
			|| $arm->getContainers() !== []
			|| $arm->getReplicators() !== []
			|| $arm->getUnknown()->hasUnknown();
	}

	/**
	 * Every method of the class whose body this process can read, keyed by name and ordered by it, so
	 * the fold is order-independent by construction. A method reflection cannot locate (an internal
	 * or phar-declared inherited one) is skipped rather than opening every shape: the only visibility
	 * this fold ever closes is private, and PHP lets no body outside the declaring class — which is
	 * always locatable when the class itself is — touch a private property at all.
	 *
	 * @param ReflectionClass<object> $native
	 * @return array<string, array{ClassMethod, string}>
	 */
	private function locatedMethods(ReflectionClass $native): array
	{
		$located = [];
		foreach ($native->getMethods() as $method) {
			$file = $this->methodLocator->ownFile($method);
			$methodNode = $this->methodLocator->locate($method);
			if ($file === null || $methodNode === null) {
				continue;
			}

			$located[$method->getName()] = [$methodNode, $file];
		}

		ksort($located);

		return $located;
	}

	/**
	 * Whether a value of the property's declared type could be a Nette container at all. A declared
	 * class type is a hard runtime guarantee and PHP has single inheritance, so a property declared as
	 * an unrelated class can never hold a container and needs no fold; an untyped, mixed or interface
	 * declaration names no class and is decided by what the binders actually assign.
	 */
	private function mayHoldContainer(Type $declared): bool
	{
		$classes = $declared->getObjectClassNames();
		if ($classes === []) {
			return true;
		}

		$container = new ObjectType(NetteContainer::class);
		foreach ($classes as $class) {
			if ($container->isSuperTypeOf(new ObjectType($class))->yes()) {
				return true;
			}
		}

		return false;
	}

	private function isContainerClass(?string $className): bool
	{
		if ($className === null) {
			return false;
		}

		return (new ObjectType(NetteContainer::class))->isSuperTypeOf(new ObjectType(ltrim($className, '\\')))->yes();
	}

	/**
	 * Whether any site in the universe mutates the component tree of ($ownerFqcn, $componentName)
	 * through an ACCESS to that component rather than through its builder — `$ctrl['form']->addText()`
	 * from the class that instantiates $ctrl, `$this['sub']['form']->addHidden()` from a parent, a
	 * handle* method reaching back into `$this['form']`. classComponentShape walks the
	 * createComponent<Name> builder and nothing else, so such a component has members its shape does
	 * not list AND no unknown reason saying so: a consumer answering "this name does not exist" must
	 * ask this first.
	 *
	 * A null $ownerFqcn asks the name-only question (is ANY component of this name so mutated), which
	 * is what a caller holding an unnameable owner must ask. True is also the answer for a site whose
	 * own owner syntax could not name — the access root was a local, so every class is a candidate.
	 */
	public function componentMayBeMutatedExternally(?string $ownerFqcn, string $componentName): bool
	{
		$key = ($ownerFqcn ?? '') . "\0" . $componentName;
		if (array_key_exists($key, $this->mutationMemo)) {
			return $this->mutationMemo[$key];
		}

		$answer = false;
		foreach ($this->index->componentMutationSites($componentName) as $entry) {
			$owner = $this->mutationOwnerClass($entry['fact']);
			if ($owner === null || $ownerFqcn === null || self::ownerMatches($owner, $ownerFqcn)) {
				$answer = true;

				break;
			}
		}

		return $this->mutationMemo[$key] = $answer;
	}

	/**
	 * A mutation and a query match when EITHER class is a subtype of the other, because a mutated
	 * instance is described by both ends of its own hierarchy. Downwards: the owner a site names is
	 * often the DECLARED return type of the intermediate control's builder, so the class actually
	 * holding the mutated component may be any subtype of it. Upwards: the class a consumer asks about
	 * is often a BASE that declares the component while the site names a subclass of it - every runtime
	 * instance the site mutates is an instance of that base too, so answering "not mutated" there would
	 * prove a member absent that is really present. Siblings deliberately do not match: no instance of
	 * the named subclass is one of them.
	 */
	private static function ownerMatches(string $owner, string $queried): bool
	{
		$owner = ltrim($owner, '\\');
		$queried = ltrim($queried, '\\');
		if ($owner === $queried) {
			return true;
		}

		$ownerType = new ObjectType($owner);
		$queriedType = new ObjectType($queried);

		return $ownerType->isSuperTypeOf($queriedType)->yes()
			|| $queriedType->isSuperTypeOf($ownerType)->yes();
	}

	private function mutationOwnerClass(RegistrationFact $fact): ?string
	{
		$scope = $fact->getOwnerScope();
		if ($scope === RegistrationFact::OWNER_SELF) {
			return $fact->getMutatingClass();
		}

		if ($scope !== RegistrationFact::OWNER_COMPONENT) {
			return null;
		}

		$shape = $this->classComponentShape($fact->getMutatingClass(), $fact->getOwnerComponent());
		$className = $shape === null ? null : $shape->getClassName();

		// The intermediate hop of `$this['ctrl']['form']` is usually a CONTROL, and a builder that
		// returns one directly (`return new EmployerControl()`) resolves to no form shape at all. Its
		// declared return type still names the class, which is all the owner question needs — without
		// it every two-offset mutation would fall back to matching every class in the universe.
		return $className ?? $this->declaredReturnComponentClass(
			$fact->getMutatingClass(),
			'createComponent' . ucfirst($fact->getOwnerComponent()),
		);
	}

	/**
	 * Scope-free parent-inheritance compensation: when a createComponent's sole
	 * method-level binding is $form = parent::createComponent<Name>(), resolve the parent class's builder
	 * through the same registeredFormShapeFor entry the collector's forClassComponent store value is
	 * computed by (which recurses up a grandparent chain of its own accord). The parent class is the site
	 * class's parent via ReflectionProvider — for a trait-declared child method the site class is the
	 * using class (the fact's A5 re-key), so its parent is the one parent:: resolves to at runtime. Null
	 * when the binding is not a sole parent createComponent call, the child opened the form by mutating a
	 * pulled-in parent container (a lost-field unknown the parent's precise fields would wrongly close),
	 * or the parent is unresolvable — the caller then leaves the rebind marker in place (honest open).
	 */
	private function inheritedParentShape(
		ClassMethod $methodNode,
		string $formVar,
		string $registeringClass,
		string $registeringMethod,
		FormShape $childShape
	): ?FormShape
	{
		if (!$this->reflectionProvider->hasClass($registeringClass)) {
			return null;
		}

		$parent = $this->reflectionProvider->getClass($registeringClass)->getParentClass();
		if ($parent === null) {
			return null;
		}

		// The parent call must be the SOLE rebind of the tracked name: followed by any other rebind the
		// runtime form is no longer the parent's, so merging the parent's fields would launder the later
		// rebind's marker.
		$sole = ReturnShapeSupport::soleTrackedAssign($methodNode, $formVar);
		if ($sole === null) {
			return null;
		}

		$rhs = $sole->expr;
		if (
			!$rhs instanceof StaticCall
			|| !$rhs->class instanceof Name
			|| !$rhs->name instanceof Identifier
			|| $rhs->class->toString() !== 'parent'
			|| $rhs->name->toString() !== $registeringMethod
		) {
			return null;
		}

		if ($this->hasLostFieldUnknown($childShape)) {
			return null;
		}

		return $this->classComponentShape($parent->getName(), lcfirst((string) substr($registeringMethod, 15)));
	}

	/**
	 * Whether the tracked form is (re)bound from a source registeredFormShapeFor attempts to resolve
	 * interprocedurally: a factory method call or, in a createComponent, a parent createComponent. Used
	 * only to strip REBIND_UNPROVEN from the probe passed into inheritedParentShape's lost-field guard so
	 * the marker cannot self-block its own compensation. Closure/arrow-fn bodies are excluded — an assign
	 * there binds a shadowed local, not the method-level form.
	 */
	private function reassignsFromResolvedSource(
		ClassMethod $methodNode,
		string $trackedName,
		bool $isCreateComponent
	): bool
	{
		$closureInnerIds = ClosureScope::innerNodeIds($methodNode->getStmts() ?? []);

		foreach ((new NodeFinder())->findInstanceOf($methodNode->getStmts() ?? [], Assign::class) as $assign) {
			if (isset($closureInnerIds[spl_object_id($assign)])) {
				continue;
			}

			if (!$assign->var instanceof Variable || $assign->var->name !== $trackedName) {
				continue;
			}

			$rhs = $assign->expr;
			if ($rhs instanceof MethodCall) {
				return true;
			}

			if (
				$isCreateComponent
				&& $rhs instanceof StaticCall
				&& $rhs->class instanceof Name
				&& $rhs->class->toString() === 'parent'
			) {
				return true;
			}
		}

		return false;
	}

	private function hasLostFieldUnknown(FormShape $shape): bool
	{
		return array_intersect(UnknownReason::LOST_FIELD_UNKNOWN_REASONS, $shape->getUnknown()->getReasons()) !== [];
	}

	private function passThroughShape(string $queriedClass, RegistrationFact $fact): ?FormShape
	{
		$calleeClass = $fact->getCalleeClass();
		$calleeMethod = $fact->getCalleeMethod();
		$calleeParamIdx = $fact->getCalleeParamIdx();

		// Gate 1: the callee method exists on the callee class.
		if (!$this->reflectionProvider->hasClass($calleeClass)) {
			return null;
		}

		$calleeReflection = $this->reflectionProvider->getClass($calleeClass);
		if (!$calleeReflection->hasNativeMethod($calleeMethod)) {
			return null;
		}

		$calleeMethodReflection = $calleeReflection->getNativeMethod($calleeMethod);

		// Gate 4: the shape is keyed under the callee method's declaring class. An inherited callee
		// keyed here under the syntactic child answers a different key than the store's declaring-class
		// key, so it contributes nothing to the queried key.
		$declaringClass = $calleeMethodReflection->getDeclaringClass();
		if ($declaringClass->getName() !== ltrim($queriedClass, '\\')) {
			return null;
		}

		// Analysed-paths containment on the reflection-resolved declaring file (pass-through edges are
		// stored pre-containment by RegistrationIndex).
		$declaringFile = $declaringClass->getFileName();
		if ($declaringFile === null || !$this->analysedPaths->isAnalysed($declaringFile)) {
			return null;
		}

		// Gate 2: the callee parameter at the argument position is Form/Container-typed.
		$calleeParams = $calleeMethodReflection->getVariants()[0]->getParameters();
		if (
			!isset($calleeParams[$calleeParamIdx])
			|| !$this->isFormOrContainer($calleeParams[$calleeParamIdx]->getType())
		) {
			return null;
		}

		$callerOrigin = $fact->getCallerOrigin();
		if (is_string($callerOrigin)) {
			// A $this[<name>] class-component argument (the store's extractThisOffsetShape): resolve the
			// owning class's component shape — the same forClassComponent value the collector reads.
			return $this->classComponentShape($fact->getCallerClass(), $callerOrigin);
		}

		// Gate 3: the caller parameter the value flows from passes the same precise super-type test,
		// which rejects a nullable ?Form / Form|null the recognizer's coarse type test admits.
		$callerClass = $fact->getCallerClass();
		$callerMethod = $fact->getCallerMethod();
		if (!$this->reflectionProvider->hasClass($callerClass)) {
			return null;
		}

		$callerReflection = $this->reflectionProvider->getClass($callerClass);
		if (!$callerReflection->hasNativeMethod($callerMethod)) {
			return null;
		}

		$callerParams = $callerReflection->getNativeMethod($callerMethod)->getVariants()[0]->getParameters();
		if (
			!isset($callerParams[$callerOrigin])
			|| !$this->isFormOrContainer($callerParams[$callerOrigin]->getType())
		) {
			return null;
		}

		return $this->resolveMethodParam($callerClass, $callerMethod, $callerOrigin);
	}

	private function returnedVariableExpr(ClassMethod $methodNode, string $formVar): ?Variable
	{
		$stmts = $methodNode->getStmts() ?? [];
		$closureInnerIds = ClosureScope::innerNodeIds($stmts);
		foreach ((new NodeFinder())->findInstanceOf($stmts, Return_::class) as $ret) {
			if (isset($closureInnerIds[spl_object_id($ret)])) {
				continue;
			}

			if ($ret->expr instanceof Variable && $ret->expr->name === $formVar) {
				return $ret->expr;
			}
		}

		return null;
	}

	/**
	 * Every distinct method-level (closure-excluded) `return $var` name in source order — how a
	 * createComponent factory's returned form variables are located when no handler fact names one.
	 * Each name becomes an arm the caller joins, mirroring the store's one-write-per-Return_
	 * joinBranch fold (a branch returning a different variable joins instead of the first arm
	 * silently winning).
	 *
	 * @return list<string>
	 */
	private function returnedVariableNames(ClassMethod $methodNode): array
	{
		$stmts = $methodNode->getStmts() ?? [];
		$closureInnerIds = ClosureScope::innerNodeIds($stmts);
		$names = [];
		foreach ((new NodeFinder())->findInstanceOf($stmts, Return_::class) as $ret) {
			if (isset($closureInnerIds[spl_object_id($ret)])) {
				continue;
			}

			if ($ret->expr instanceof Variable && is_string($ret->expr->name)) {
				$names[$ret->expr->name] = $ret->expr->name;
			}
		}

		return array_values($names);
	}

	/**
	 * The Nette-container class the returned form variable holds, resolved scope-free from its final
	 * method-level (closure-excluded) assignment — a `new X()`, or a `$this->prop` / call receiver the
	 * class tracker can class. Null when there is no such assignment or the source is not a container,
	 * so a genuinely undecidable factory result (`$f = $this->factory->create()` → object) stays a
	 * closed-empty bail matching the store, while a container rebound more than once still opens.
	 */
	private function returnedContainerClass(ClassMethod $methodNode, string $formVar, string $ownerFqcn): ?string
	{
		$stmts = $methodNode->getStmts() ?? [];
		$closureInnerIds = ClosureScope::innerNodeIds($stmts);

		$lastRhs = null;
		foreach ((new NodeFinder())->findInstanceOf($stmts, Assign::class) as $assign) {
			if (isset($closureInnerIds[spl_object_id($assign)])) {
				continue;
			}

			if ($assign->var instanceof Variable && $assign->var->name === $formVar) {
				$lastRhs = $assign->expr;
			}
		}

		// A chained assignment ($form = $alias = new X()) nests the defining expression inside the
		// inner assign; unwrap so the entry class resolves as the collector's native scope did.
		while ($lastRhs instanceof Assign) {
			$lastRhs = $lastRhs->expr;
		}

		if ($lastRhs === null) {
			return null;
		}

		$class = $lastRhs instanceof New_ && $lastRhs->class instanceof Name
			? ltrim($lastRhs->class->toString(), '\\')
			: $this->classTracker->resolveReceiverClass(
				$this->deAliasFactoryReceiver($lastRhs, $methodNode),
				$methodNode,
				$ownerFqcn,
			);
		if ($class === null) {
			return null;
		}

		return (new ObjectType(NetteContainer::class))->isSuperTypeOf(new ObjectType($class))->yes()
			? $class
			: null;
	}

	/**
	 * When the returned form is built through a factory call whose receiver is a local-var alias of a
	 * `$this->prop` ($service = $this->factory; $form = $service->create()), the class tracker cannot
	 * class the aliased receiver, so the call is retargeted onto the property fetch its receiver aliases
	 * before the receiver-class resolution runs. Any other expression is returned unchanged, and a
	 * non-`$this` property fetch still resolves to null there — never opening a shape the store closes.
	 */
	private function deAliasFactoryReceiver(Expr $expr, ClassMethod $methodNode): Expr
	{
		if (
			(!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall)
			|| !$expr->var instanceof Variable
			|| !is_string($expr->var->name)
		) {
			return $expr;
		}

		$sole = ReturnShapeSupport::soleTrackedAssign($methodNode, $expr->var->name);
		if ($sole === null || !$sole->expr instanceof PropertyFetch) {
			return $expr;
		}

		return $expr instanceof NullsafeMethodCall
			? new NullsafeMethodCall($sole->expr, $expr->name, $expr->args)
			: new MethodCall($sole->expr, $expr->name, $expr->args);
	}

	/**
	 * Scope-free factory-rebind compensation: when the tracked form's sole
	 * method-level binding is a factory call ($form = $this->factory->create(), incl. the
	 * local-var-aliased spelling), resolve that factory's return shape. The receiver class is resolved
	 * scope-free (the class tracker over the de-aliased receiver) and handed to BuilderChainDetector,
	 * which consults the forFactoryMethod cache exactly as the store does; on a cache miss the remote
	 * factory method's new/var returns are walked (analyzeRemoteFactoryMethod). Null when the binding is
	 * not a sole factory call, the receiver/method is unlocatable, or the factory return is unfollowable
	 * — the caller then leaves the rebind marker in place (honest open), never opening a shape the store
	 * closes.
	 */
	private function factoryAssignShape(
		ClassMethod $methodNode,
		string $formVar,
		string $ownerFqcn,
		Scope $scope
	): ?FormShape
	{
		$sole = ReturnShapeSupport::soleTrackedAssign($methodNode, $formVar);
		if ($sole === null || !$sole->expr instanceof MethodCall) {
			return null;
		}

		return $this->chainCallShape($sole->expr, $methodNode, $ownerFqcn, $scope);
	}

	/**
	 * The shape a factory/builder-chain call resolves to, scope-free: the receiver class comes from
	 * the class tracker over the de-aliased receiver and is handed to BuilderChainDetector, which
	 * consults the forFactoryMethod cache exactly as the store does; on a cache miss the remote
	 * factory method's new/var returns are walked (analyzeRemoteFactoryMethod). Null when the
	 * receiver/method is unlocatable or the factory return is unfollowable.
	 */
	private function chainCallShape(
		MethodCall $call,
		ClassMethod $methodNode,
		string $ownerFqcn,
		Scope $scope
	): ?FormShape
	{
		$deAliased = $this->deAliasFactoryReceiver($call, $methodNode);
		$receiver = $deAliased instanceof MethodCall || $deAliased instanceof NullsafeMethodCall
			? $deAliased->var
			: $call->var;
		$receiverClass = $this->classTracker->resolveReceiverClass($receiver, $methodNode, $ownerFqcn);

		$detection = BuilderChainDetector::detect($call, $scope, $this->cache, $receiverClass);
		if ($detection === null) {
			return null;
		}

		$cached = $detection->getCachedShape();
		if ($cached !== null) {
			return $cached;
		}

		$declaringClass = $detection->getDeclaringClass();
		$methodName = $detection->getMethodName();
		if ($declaringClass === null || $methodName === null) {
			return null;
		}

		$native = $declaringClass->getNativeReflection();
		if (!$native->hasMethod($methodName)) {
			return null;
		}

		$reflectionMethod = $native->getMethod($methodName);
		$file = $this->methodLocator->ownFile($reflectionMethod);
		$factoryNode = $this->methodLocator->locate($reflectionMethod);
		if ($file === null || $factoryNode === null) {
			return null;
		}

		return $this->analyzeRemoteFactoryMethod($factoryNode, $file, $declaringClass->getName(), $scope);
	}

	/**
	 * Resolves the two non-`return $var` arms the factory-return pipeline handles for a
	 * createComponent method: a builder-chain return resolves through the same detector/remote-walk
	 * machinery the factory-rebind compensation uses (chainCallShape), and a bare `$this->prop`
	 * return exposes a prebuilt component — its class resolved scope-free by the class tracker —
	 * as an open shape (UNRESOLVED_ORIGIN), never a closed empty over unknown content. Arms join
	 * per return exactly as the store's per-arm writes joinBranch; an unresolvable arm is skipped
	 * so an all-unfollowable method stays a miss. A method that also returns a variable never
	 * reaches here (the var walk wins, matching the twin's established single-var-arm behaviour).
	 */
	private function nonVarReturnShape(
		ClassMethod $methodNode,
		string $registeringClass,
		string $registeringMethod
	): ?FormShape
	{
		if ($this->scope === null) {
			return null;
		}

		$stmts = $methodNode->getStmts() ?? [];
		$closureInnerIds = ClosureScope::innerNodeIds($stmts);
		$joined = null;
		foreach ((new NodeFinder())->findInstanceOf($stmts, Return_::class) as $ret) {
			if (isset($closureInnerIds[spl_object_id($ret)])) {
				continue;
			}

			if ($ret->expr instanceof MethodCall) {
				$shape = $this->chainCallShape($ret->expr, $methodNode, $registeringClass, $this->scope);
			} elseif ($ret->expr instanceof PropertyFetch) {
				$class = $this->classTracker->resolveReceiverClass($ret->expr, $methodNode, $registeringClass);
				$shape = $class === null
					? null
					: FormShape::empty($class)->withUnknownReason(UnknownReason::UNRESOLVED_ORIGIN);
			} else {
				continue;
			}

			if ($shape !== null && ReturnShapeSupport::isClassNameUnresolved($shape->getClassName())) {
				$declared = $this->declaredReturnFormClass($registeringClass, $registeringMethod);
				if ($declared !== null) {
					$shape = $shape->withClassName($declared);
				}
			}

			if ($shape === null || $shape->getClassName() === null) {
				continue;
			}

			$joined = $joined === null ? $shape : $joined->joinBranch($shape);
		}

		return $joined;
	}

	/**
	 * Resolves a remote factory method's returns scope-free: each method-level
	 * (closure-excluded) return arm resolves through the shared resolver — `return new X()` through
	 * ConstructedFormShapeResolver, `return $var` through the scope-free walk keyed to the factory's own
	 * class — and the arms join. A property-fetch / unresolvable arm is skipped so an all-unfollowable
	 * factory returns null and the caller's rebind marker survives.
	 */
	private function analyzeRemoteFactoryMethod(
		ClassMethod $factoryNode,
		string $file,
		string $declaringFqcn,
		Scope $scope
	): ?FormShape
	{
		$closureInnerIds = ClosureScope::innerNodeIds($factoryNode->getStmts() ?? []);
		$joined = null;
		foreach ((new NodeFinder())->findInstanceOf($factoryNode->getStmts() ?? [], Return_::class) as $ret) {
			if (isset($closureInnerIds[spl_object_id($ret)])) {
				continue;
			}

			if ($ret->expr instanceof New_ && $ret->expr->class instanceof Name) {
				$shape = $this->constructedResolver->resolve(
					ltrim($ret->expr->class->toString(), '\\'),
					count($ret->expr->getArgs()),
				);
			} elseif ($ret->expr instanceof Variable && is_string($ret->expr->name)) {
				$shape = $this->analyzer->analyzeFormValue(
					$ret->expr,
					$factoryNode,
					$this->records->taggedRecords($factoryNode, $scope),
					$scope,
					$file,
					$declaringFqcn,
					true,
				);
			} else {
				continue;
			}

			if ($shape === null) {
				continue;
			}

			$joined = $joined === null ? $shape : $joined->joinBranch($shape);
		}

		return $joined;
	}

	private function carriesRebindMarker(FormShape $shape): bool
	{
		return in_array(UnknownReason::REBIND_UNPROVEN, $shape->getUnknown()->getReasons(), true);
	}

	private function declaredReturnFormClass(string $class, string $method): ?string
	{
		if (!$this->reflectionProvider->hasClass($class)) {
			return null;
		}

		return $this->support->declaredReturnFormClass($this->reflectionProvider->getClass($class), $method);
	}

	private function isFormOrContainer(Type $type): bool
	{
		return (new ObjectType(NetteForm::class))->isSuperTypeOf($type)->yes()
			|| (new ObjectType(NetteContainer::class))->isSuperTypeOf($type)->yes();
	}

}
