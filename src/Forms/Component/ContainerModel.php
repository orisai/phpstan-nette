<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Iterator;
use Nette\Application\UI\Component as UiComponent;
use Nette\Forms\Container as NetteContainer;
use Nette\Forms\Form as NetteForm;
use Nette\IOException;
use Nette\Utils\ArrayHash;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Analyzer\AnalyzerStackFactory;
use OriPhpstan\Nette\Forms\Analyzer\CompositionState;
use OriPhpstan\Nette\Forms\Analyzer\FormShapeAnalyzer;
use OriPhpstan\Nette\Forms\Analyzer\LocalVariableClassTracker;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\NetteEffectiveControlValueTypeResolver;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Catalog\WizardMeta;
use OriPhpstan\Nette\Forms\Graph\ClosureScope;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummaryFactory;
use OriPhpstan\Nette\Forms\Index\IndexShapeResolver;
use OriPhpstan\Nette\Forms\Inference\EnclosingFunctionLikeLocator;
use OriPhpstan\Nette\Forms\Inference\EventContextLocator;
use OriPhpstan\Nette\Forms\Inference\EventPropertyName;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use OriPhpstan\Nette\Forms\Support\BoundedMap;
use OriPhpstan\Nette\Forms\Type\FormShapeProjector;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use OriPhpstan\Nette\Forms\Type\FormValuesProjector;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function array_intersect;
use function array_key_exists;
use function array_merge;
use function array_slice;
use function array_unshift;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_file;
use function is_string;
use function lcfirst;
use function ltrim;
use function sha1;
use function spl_object_id;
use function strlen;
use function strncmp;
use function substr;
use function ucfirst;

final class ContainerModel
{

	private const REPLICATOR_OFFSET_SENTINEL = "\x00offset";

	private const WIZARD_MAX_STEPS = 100;

	private const RICH_FILE_CLASSES_CACHE_LIMIT = 64;

	private const PLAIN_FILE_GLOBAL_BOUNDARY = 'g:';

	private bool $enabled;

	private FormShapeCache $cache;

	private Parser $parser;

	private Parser $richParser;

	/**
	 * Wired from the config's declared paths (`%paths%`), not PHPStan's CLI-narrowed
	 * `%analysedPaths%` — the vendorMethodFormShape/vendorParentCallbackShape gates below must
	 * draw the project/vendor line identically regardless of a single-file/IDE run's CLI list.
	 */
	private AnalysedPaths $analysedPaths;

	private IndexShapeResolver $indexShapeResolver;

	private FormOriginTracer $tracer;

	private TraitAwareMethodLocator $methodLocator;

	private ControlAnnotationValueTypeReader $catalogReader;

	private ?LocalVariableClassTracker $chainReceiverTracker = null;

	private ?ConstructedFormShapeResolver $constructedResolver = null;

	private ?ConstructorFormShapeResolver $constructorFormShapeResolver = null;

	private ?ReturnShapeSupport $returnShapeSupport = null;

	/** @var array<string, list<Class_>> */
	private array $fileClassesCache = [];

	private BoundedMap $richFileClassesCache;

	/** @var array<int, array{m: ClassMethod, nodes: array<class-string<Node>, array<Node>>}> */
	private array $methodBodyNodeCache = [];

	/** @var array<string, FormShape|null> */
	private array $classComponentShapeMemo = [];

	/** @var array<string, FormShape|null> */
	private array $classPropertyShapeMemo = [];

	/** @var array<string, FormShape|null> */
	private array $plainFileShapeMemo = [];

	private ?string $currentFile = null;

	/**
	 * @param list<string> $analysedPaths
	 */
	public function __construct(
		bool $enabled,
		Parser $parser,
		Parser $richParser,
		array $analysedPaths,
		FormShapeCache $cache,
		IndexShapeResolver $indexShapeResolver,
		ControlAnnotationValueTypeReader $catalogReader
	)
	{
		$this->enabled = $enabled;
		$this->parser = $parser;
		$this->richParser = $richParser;
		$this->analysedPaths = new AnalysedPaths($analysedPaths);
		$this->cache = $cache;
		$this->indexShapeResolver = $indexShapeResolver;
		$this->tracer = new FormOriginTracer();
		$this->methodLocator = new TraitAwareMethodLocator($richParser);
		$this->catalogReader = $catalogReader;
		$this->richFileClassesCache = new BoundedMap(self::RICH_FILE_CLASSES_CACHE_LIMIT);
	}

	// $methodBodyNodeCache is keyed by spl_object_id() of Class_/ClassMethod nodes owned by
	// $fileClassesCache. Each entry pins the method node itself ('m'), so a live entry's id
	// cannot be reused by another node; both caches are cleared together to bound memory to the
	// current file, not to avoid an id collision.
	private function evictOnFileChange(string $file): void
	{
		if ($this->currentFile === $file) {
			return;
		}

		$this->currentFile = $file;
		$this->fileClassesCache = [];
		$this->methodBodyNodeCache = [];
		$this->classComponentShapeMemo = [];
		$this->classPropertyShapeMemo = [];
		$this->plainFileShapeMemo = [];
	}

	public function resolveFromFormShapeReceiver(
		FormShapeType $receiverType,
		MethodCall $methodCall,
		Scope $scope
	): ?Type
	{
		$this->evictOnFileChange($scope->getFile());

		$formShape = $receiverType->getFormShape();
		$args = $methodCall->getArgs();
		if (count($args) < 1) {
			return null;
		}

		$consts = $scope->getType($args[0]->value)->getConstantStrings();
		if (count($consts) === 1) {
			$segments = ComponentPath::split($consts[0]->getValue());
			$chain = array_merge([''], $segments);

			$type = $this->walk($formShape, $chain, $scope);

			return $type ?? $this->provenAbsent($formShape, $segments);
		}

		// A NON-CONSTANT offset names one of the receiver's CHILDREN, never the receiver: `$form[$name]`
		// is whichever component `$name` happens to be, so the union of what the shape holds is the
		// answer and the receiver's own shape is a confidently-wrong one. Handing back the receiver made
		// `$form[$name]` type as the form itself, and every later member access on it was then checked
		// against the FORM's class - `Call to an undefined method ApplicationForm::setItems()` and its
		// kind. This is the same question the REPLICATOR_OFFSET_SENTINEL arm of walk() asks for the same
		// expression shape, so it is the same answer, out of the same method: null when the shape holds
		// no child to name, which degrades to the wrapped class's own ArrayAccess answer.
		return $this->unionOfShapeChildren($formShape);
	}

	/**
	 * The one place this walk answers a name it can PROVE is not there, and it answers it by asking
	 * the projector rather than by re-deciding it: FormShapeProjector::offsetPath() mints an ErrorType
	 * exactly when its unknownLeaf() is reached on a CLOSED shape, which is exactly when
	 * FormShapeUnknownAccessRule reports orisaiNette.forms.noSuchComponent for the same expression - same walk,
	 * same three channels, same closedness. Every other answer it can give is dropped here, because
	 * every other answer is one this walk already has its own better one for.
	 *
	 * PROVEN ABSENT is not UNRESOLVABLE, and the RECEIVER is what separates them. This runs only where
	 * the accessed expression's own type is already a FormShapeType, so the shape asked is a shape the
	 * analysis produced by watching a construction and the rule can read it off the very same node -
	 * one mistake, one report, and the ErrorType is what stops the second one, PHPStan's own carrier
	 * for a verdict already delivered. Everything that merely runs out of road keeps degrading to the
	 * wrapped class's own ArrayAccess and its IComponent: an open shape, an unusable inner shape, a
	 * factory the walk cannot follow, a classHop() that finds nothing.
	 *
	 * Deciding this down in walk()'s leaf arm instead - where it would also cover a bare receiver -
	 * was measured and is wrong. classComponentShape() answers with an EMPTY, CLOSED shape for any
	 * class that registers no createComponent factory, and that shape is a statement about the CLASS,
	 * never about the fields of a form instance; all 19 corpus sites that reached the leaf arm with a
	 * closed shape were of exactly that kind (a bare Nette\Application\UI\Form or ApplicationForm
	 * parameter, no channel populated). An ErrorType there is a confidently-wrong answer nothing
	 * reports beside it, since walk()'s single-segment arm hands back the bare class and leaves the
	 * rule no shape to read - it silently deleted 17 findings across 6 files, 12 of them on 5 files of
	 * the real app. The gate above admits none of them.
	 *
	 * @param non-empty-list<string> $segments
	 */
	private function provenAbsent(FormShape $formShape, array $segments): ?Type
	{
		$projected = FormShapeProjector::offsetPath($formShape, $segments);

		return $projected instanceof ErrorType ? $projected : null;
	}

	private function unionOfShapeChildren(FormShape $shape): ?Type
	{
		$types = [];
		foreach ($shape->getSlots() as $slot) {
			$slotType = $slot->getControlType();
			if ($slotType !== null) {
				$types[] = $slotType;
			}
		}

		foreach ($shape->getContainers() as $container) {
			$childClass = $container->getClassName();
			if ($childClass === null) {
				continue;
			}

			$types[] = FormShapeProjector::isUsableInnerShape($container)
			? new FormShapeType($childClass, $container)
			: new ObjectType($childClass);
		}

		if ($types === []) {
			return null;
		}

		return count($types) === 1 ? $types[0] : TypeCombinator::union(...$types);
	}

	public function resolveComponentsIterator(Expr $receiver, Scope $scope): ?Type
	{
		if (!$this->enabled) {
			return null;
		}

		$this->evictOnFileChange($scope->getFile());

		$shape = $this->shapeForIterator($receiver, $scope);
		if ($shape === null) {
			return null;
		}

		$union = $this->immediateChildrenUnion($shape);

		return $union === null ? null : $this->iteratorOf($union);
	}

	public function resolveControlsIterator(Expr $receiver, Scope $scope): ?Type
	{
		if (!$this->enabled) {
			return null;
		}

		$this->evictOnFileChange($scope->getFile());

		$shape = $this->shapeForIterator($receiver, $scope);
		if ($shape === null) {
			return null;
		}

		$types = [];
		if (!$this->collectLeafControls($shape, $types) || $types === []) {
			return null;
		}

		return $this->iteratorOf(count($types) === 1 ? $types[0] : TypeCombinator::union(...$types));
	}

	/**
	 * The union of every immediate child type for getComponents(). Returns null when any child
	 * cannot be typed (an untyped slot, a classless container, a replicator with no recorded
	 * class), so getComponents() falls back to the native broad iterator rather than a union
	 * too narrow to hold all children.
	 */
	private function immediateChildrenUnion(FormShape $shape): ?Type
	{
		$componentTypes = $shape->getComponentTypes();
		$types = [];

		foreach ($shape->getSlots() as $slot) {
			$slotType = $slot->getControlType();
			if ($slotType === null) {
				return null;
			}

			$types[] = $slotType;
		}

		foreach ($shape->getContainers() as $container) {
			$childClass = $container->getClassName();
			if ($childClass === null) {
				return null;
			}

			$types[] = FormShapeProjector::isUsableInnerShape($container)
				? new FormShapeType($childClass, $container)
				: new ObjectType($childClass);
		}

		foreach ($shape->getReplicators() as $name => $replicator) {
			$childClass = $componentTypes[$name] ?? null;
			if ($childClass === null) {
				return null;
			}

			$types[] = new ObjectType(ltrim($childClass, '\\'));
		}

		$structural = $shape->getSlots() + $shape->getContainers() + $shape->getReplicators();
		foreach ($componentTypes as $name => $childClass) {
			if (!isset($structural[$name])) {
				$types[] = new ObjectType(ltrim($childClass, '\\'));
			}
		}

		if ($types === []) {
			return null;
		}

		return count($types) === 1 ? $types[0] : TypeCombinator::union(...$types);
	}

	/**
	 * Recursively collects every leaf control type; returns false when the set may be
	 * incomplete (an open shape, an unknown-typed slot, or an opaque non-structural child),
	 * so the caller falls back to the broad iterator rather than an unsound narrowing.
	 *
	 * @param list<Type> $types
	 */
	private function collectLeafControls(FormShape $shape, array &$types): bool
	{
		$complete = $shape->getUnknown()->getReasons() === [];

		foreach ($shape->getSlots() as $slot) {
			$slotType = $slot->getControlType();
			if ($slotType !== null) {
				$types[] = $slotType;
			} else {
				$complete = false;
			}
		}

		$structural = $shape->getSlots() + $shape->getContainers() + $shape->getReplicators();
		foreach ($shape->getComponentTypes() as $name => $childClass) {
			if (!isset($structural[$name])) {
				$complete = false;
			}
		}

		foreach ($shape->getContainers() as $container) {
			if (!$this->collectLeafControls($container, $types)) {
				$complete = false;
			}
		}

		foreach ($shape->getReplicators() as $replicator) {
			if (!$this->collectLeafControls($replicator->getInner(), $types)) {
				$complete = false;
			}
		}

		return $complete;
	}

	private function iteratorOf(Type $value): Type
	{
		return new GenericObjectType(Iterator::class, [
			TypeCombinator::union(new IntegerType(), new StringType()),
			$value,
		]);
	}

	/**
	 * The closed shape behind an iterator-method receiver — resolved from the expression, or
	 * carried by a FormShapeType (a form held in a variable). Open shapes return null so the
	 * native broad iterator type stands rather than an unsound narrowing.
	 */
	private function shapeForIterator(Expr $receiver, Scope $scope): ?FormShape
	{
		$shape = $this->resolveComponentShape($receiver, $scope);
		if ($shape === null) {
			$receiverType = $scope->getType($receiver);
			$shape = $receiverType instanceof FormShapeType ? $receiverType->getFormShape() : null;
		}

		return $shape !== null && $shape->getUnknown()->getReasons() === [] ? $shape : null;
	}

	public function resolveReplicatorInnerObject(Expr $receiver, Scope $scope): ?Type
	{
		if (!$this->enabled) {
			return null;
		}

		$this->evictOnFileChange($scope->getFile());

		$chain = [];
		$res = $this->unwind($receiver, $scope, $chain);
		if ($res === null) {
			return null;
		}

		[$rootVar, $chain] = $res;
		if ($chain === []) {
			return null;
		}

		$chain[] = self::REPLICATOR_OFFSET_SENTINEL;

		return $this->resolveForRoot($rootVar, $chain, $scope, false);
	}

	public function resolveReplicatorInnerShape(Expr $receiver, Scope $scope): ?FormShape
	{
		$this->evictOnFileChange($scope->getFile());

		$type = $this->resolveReplicatorInnerObject($receiver, $scope);
		if ($type instanceof FormShapeType) {
			return $type->getFormShape();
		}

		return null;
	}

	public function resolveFromStore(MethodCall $methodCall, Scope $scope): ?Type
	{
		$this->evictOnFileChange($scope->getFile());

		$chain = [];
		if (!$this->componentAccessKey($methodCall, $scope, $chain)) {
			return null;
		}

		$root = $this->peel($methodCall->var, $scope, $chain);
		if ($root === null || $chain === []) {
			return null;
		}

		if ($root instanceof PropertyFetch) {
			$shape = $this->propertyRootShape($root, $scope);

			return $shape === null ? null : $this->walk($shape, array_merge([''], $chain), $scope);
		}

		return $root instanceof Variable ? $this->resolveForRoot($root, $chain, $scope, false) : null;
	}

	/**
	 * The component-access walk, shared by every root kind: it peels `getComponent('x')` /
	 * `offsetGet('x')` / `['x']` hops off the expression, unshifting each name onto $chain, and
	 * hands back whatever expression is left underneath — the ROOT, which it does not judge. Null
	 * means a HOP was unreadable (a `$form[]` push, an argumentless access call), which is a
	 * different answer from a root this or that caller does not recognise.
	 *
	 * @param list<string> $chain
	 */
	private function peel(Expr $cur, Scope $scope, array &$chain): ?Expr
	{
		while (true) {
			if ($cur instanceof MethodCall && $this->isComponentAccessCall($cur)) {
				if (!$this->componentAccessKey($cur, $scope, $chain)) {
					return null;
				}

				$cur = $cur->var;

				continue;
			}

			if ($cur instanceof ArrayDimFetch) {
				if ($cur->dim === null) {
					return null;
				}

				$consts = $scope->getType($cur->dim)->getConstantStrings();
				if (count($consts) === 1) {
					array_unshift($chain, ...ComponentPath::split($consts[0]->getValue()));
				} else {
					array_unshift($chain, self::REPLICATOR_OFFSET_SENTINEL);
				}

				$cur = $cur->var;

				continue;
			}

			break;
		}

		return $cur;
	}

	/**
	 * The Variable-rooted specialisation of peel(), which is what every store-backed resolution
	 * below is written against. A root of any other kind is that caller's business, not this one's.
	 *
	 * @param  list<string> $chain
	 * @return array{0: Variable, 1: list<string>}|null
	 */
	private function unwind(Expr $cur, Scope $scope, array $chain): ?array
	{
		$root = $this->peel($cur, $scope, $chain);

		return $root instanceof Variable ? [$root, $chain] : null;
	}

	/**
	 * @param list<string> $chain
	 */
	private function resolveForRoot(Variable $node, array $chain, Scope $scope, bool $aliasTried): ?Type
	{
		if ($node->name === 'this') {
			if (!$scope->isInClass()) {
				return null;
			}

			$thisShape = $this->classComponentShape(
				$scope->getClassReflection()->getName(),
				$chain[0],
				$scope,
			);
			if ($thisShape !== null) {
				   return $this->walk($thisShape, $chain, $scope);
			}

			$vendorThis = $this->vendorCreateComponentShape($chain[0], $node, $scope);

			return $vendorThis === null ? null : $this->walk($vendorThis, $chain, $scope);
		}

		$ownerFqcn = $scope->isInClass() ? $scope->getClassReflection()->getName() : null;
		$shape = null;
		$component = null;
		if ($ownerFqcn !== null) {
			$pair = $this->ownerAndEnclosing($scope);
			if ($pair !== null && is_string($node->name)) {
				[$ownerNode, $enclosing] = $pair;
				$component = $this->tracer->originComponent($ownerNode, $enclosing, $node->name);
				if ($component !== null) {
					$shape = $this->classComponentShape($ownerFqcn, $component, $scope);
				}
			}
		}

		if ($shape !== null && $component !== null) {
			$r = $this->walk($shape, array_merge([$component], $chain), $scope);
			if ($r !== null) {
				return $r;
			}

			$shape = null;
			$component = null;
		}

		if ($shape === null && $ownerFqcn !== null && is_string($node->name)) {
			$pair2 = $this->ownerAndEnclosing($scope);
			if ($pair2 !== null) {
				[$ownerNode2, $enclosing2] = $pair2;
				$canon = $this->tracer->originChainKey($ownerNode2, $enclosing2, $node->name);
				if ($canon !== null) {
					$parts = explode('|', $canon);
					$chainShape = $this->classComponentShape($ownerFqcn, $parts[0], $scope);
					if ($chainShape !== null) {
						$r = $this->walk(
							$chainShape,
							array_merge([$parts[0]], array_slice($parts, 1), $chain),
							$scope,
						);
						if ($r !== null) {
								  return $r;
						}
					}
				}
			}
		}

		if ($shape === null) {
			$classes = $scope->getType($node)->getObjectClassNames();
			$shape = count($classes) === 1
			? $this->classComponentShape($classes[0], $chain[0], $scope)
			: null;
		}

		if ($shape === null && is_string($node->name)) {
			$plainFileShape = $this->plainFileShape($scope->getFile(), $node->name, $scope);
			if ($plainFileShape !== null) {
				$r = $this->walk($plainFileShape, array_merge([$node->name], $chain), $scope);
				if ($r !== null) {
					return $r;
				}
			}
		}

		if ($shape === null && is_string($node->name)) {
			$factoryShape = $this->factoryMethodShape($node->name, $scope);
			if ($factoryShape !== null) {
				$r = $this->walk($factoryShape, array_merge([$node->name], $chain), $scope);
				if ($r !== null) {
					return $r;
				}
			}
		}

		if ($shape === null && is_string($node->name) && $node->name !== 'this') {
			$paramShape = $this->paramShapeForVariable($node, $scope);
			if ($paramShape !== null) {
				// The chain must carry the root's own name as its first segment (as the plain-file, factory
				// and event-callback arms above do): walk() reads chain[0] as the shape it was handed and
				// descends from chain[1]. Without the prepend a single-segment offset on an open/empty param
				// shape falls into walk()'s count===1 arm and returns the form's own class instead of
				// deferring the unknown component to IComponent.
				$r = $this->walk($paramShape, array_merge([$node->name], $chain), $scope);
				if ($r !== null) {
					return $r;
				}
			}
		}

		if ($shape === null && is_string($node->name) && $node->name !== 'this') {
			$eventShape = $this->eventCallbackFormParamShape($node, $scope);
			if ($eventShape !== null) {
				$r = $this->walk($eventShape, array_merge([$node->name], $chain), $scope);
				if ($r !== null) {
					return $r;
				}
			}
		}

		if ($shape === null && is_string($node->name) && $node->name !== 'this') {
			$closureUseShape = $this->closureUseShapeForVariable($node, $scope);
			if ($closureUseShape !== null) {
				$r = $this->walk($closureUseShape, array_merge([$node->name], $chain), $scope);
				if ($r !== null) {
					return $r;
				}
			}
		}

		if ($shape === null && is_string($node->name)) {
			$vendorShape = $this->vendorCreateComponentShape($chain[0], $node, $scope);
			if ($vendorShape !== null) {
				$r = $this->walk($vendorShape, $chain, $scope);
				if ($r !== null) {
					return $r;
				}
			}
		}

		if ($shape === null && is_string($node->name)) {
			$vendorParent = $this->vendorParentCallbackShape($node->name, $chain, $scope);
			if ($vendorParent !== null) {
				return $vendorParent;
			}
		}

		if ($shape === null && is_string($node->name) && $node->name !== 'this') {
			$localShape = $this->localVariableShape($node, $scope);
			if ($localShape !== null) {
				$r = $this->walk($localShape, array_merge([$node->name], $chain), $scope);
				if ($r !== null) {
					return $r;
				}
			}
		}

		if ($shape === null && !$aliasTried && $node->name !== 'this' && is_string($node->name)) {
			$alias = $this->aliasRootChain($node->name, $scope);
			if ($alias !== null) {
				$r = $this->resolveForRoot($alias[0], array_merge($alias[1], $chain), $scope, true);
				if ($r !== null) {
					return $r;
				}
			}
		}

		if ($shape === null) {
			return null;
		}

		return $this->walk($shape, $chain, $scope);
	}

	/**
	 * @return array{0: Variable, 1: list<string>}|null
	 */
	private function aliasRootChain(string $varName, Scope $scope): ?array
	{
		$pair = $this->ownerAndEnclosing($scope);
		$enclosing = $pair === null ? null : $pair[1];
		$searchRoot = $enclosing !== null
		? ($enclosing->getStmts() ?? [])
		: $this->richParser->parseFile($scope->getFile());

		$rhs = null;
		foreach ((new NodeFinder())->findInstanceOf($searchRoot, Assign::class) as $assign) {
			if ($assign->var instanceof Variable && $assign->var->name === $varName) {
				if ($rhs !== null) {
					return null;
				}

				$rhs = $assign->expr;
			}
		}

		return $rhs === null ? null : $this->unwind($rhs, $scope, []);
	}

	private function isComponentAccessCall(MethodCall $call): bool
	{
		if (!$call->name instanceof Identifier || $call->isFirstClassCallable()) {
			return false;
		}

		$n = $call->name->toString();

		return $n === 'offsetGet' || $n === 'getComponent';
	}

	/**
	 * @param list<string> $chain
	 */
	private function componentAccessKey(MethodCall $call, Scope $scope, array &$chain): bool
	{
		$args = $call->getArgs();
		if (count($args) < 1) {
			return false;
		}

		$consts = $scope->getType($args[0]->value)->getConstantStrings();
		if (count($consts) === 1) {
			array_unshift($chain, ...ComponentPath::split($consts[0]->getValue()));
		} else {
			array_unshift($chain, self::REPLICATOR_OFFSET_SENTINEL);
		}

		return true;
	}

	/**
	 * @param list<string> $chain
	 */
	private function walk(
		FormShape $shape,
		array $chain,
		Scope $scope,
		bool $hopped = false,
		?RecursionCtx $ctx = null
	): ?Type
	{
		if ($chain === []) {
			return null;
		}

		$count = count($chain);
		if ($count === 1) {
			if ($chain[0] === self::REPLICATOR_OFFSET_SENTINEL) {
				$union = $this->unionOfShapeChildren($shape);
				if ($union !== null) {
					return $union;
				}
			}

			// THE FORM LEVEL - the same question the $i >= $count arm below answers after a descent, so
			// the same answer, spelled the same way. It handed back the bare class unconditionally
			// before, which is why `{var $form = $control['form']}` - the receiver every template
			// writes - carried no shape at all: offset PRESENCE, the getValues() projection and
			// FormShapeUnknownAccessRule's report are all read off THIS node, so none of them was
			// reachable from a template however much the shape held. An absence was not reported once
			// here, it was not reported at all, while core reported every member access on the
			// IComponent that stood in for it.
			//
			// isUsableInnerShape() is what keeps the wrap honest, and it is not a formality: a shape
			// that is empty AND open recorded nothing, so wrapping it would turn "nothing was read"
			// into a positive claim about the form's fields - and about the names classHop() below
			// still resolves through createComponent<Name> factories, which a shape has no channel
			// for. TwoHopStaysIComponent.php and MPropertyFetchReturnResolves.php are among the
			// fixtures that break the moment the gate is dropped.
			if ($shape->getClassName() === null) {
				return null;
			}

			return FormShapeProjector::isUsableInnerShape($shape)
			? new FormShapeType($shape->getClassName(), $shape)
			: new ObjectType($shape->getClassName());
		}

		if ($ctx === null) {
			$ctx = new RecursionCtx();
		}

		$current = $shape;
		$i = 1;
		while ($i <= $count - 2) {
			$replicators = $current->getReplicators();
			if (isset($replicators[$chain[$i]]) && isset($chain[$i + 1])) {
				// A NON-CONSTANT offset is a row whose index nothing can name, so the walk itself has to
				// swap $current for the inner row shape and carry on - only this arm can, because only it
				// keeps the recursion context and the classHop() reach the arms below need.
				if ($chain[$i + 1] === self::REPLICATOR_OFFSET_SENTINEL) {
					$inner = $this->replicatorInnerShape($current, $chain[$i], $ctx);
					if ($inner === null) {
						return null;
					}

					$ctx = $ctx->descend(self::replicatorIdentity($current->getClassName(), $chain[$i]));
					$current = $inner;
					$i += 2;

					continue;
				}

				// Every NAMED remainder is handed whole to the shared walk, which owns the row-vs-own-child
				// decision Nette makes at a replicator (a decimal name is a dynamically created row, e.g.
				// 'rep-0-x'; anything else is a control added straight onto addDynamic()'s return value,
				// e.g. 'rep-addNode'). Nette's Container::getComponent() splits a joined name on
				// IComponent::NameSeparator (ComponentPath::split()), so peel() delivers 'rep-addNode' here
				// already split. Without this arm the segment misses the container lookup below and
				// classHop() goes looking for a createComponentRep() that a replicator never has, leaving
				// the whole access at the bare IComponent the native stub answers. A name the shared walk
				// cannot prove degrades there to the wrapped class's own ArrayAccess answer; an ErrorType
				// means the wrapped class could not be reflected at all, which is not an answer - both fall
				// through to the arms below rather than being reported as a resolution. A sentinel further
				// down the chain is not a NAME at all, so the shared walk cannot read it and the whole
				// remainder is left to the arms below.
				$rest = [$chain[$i], ...array_slice($chain, $i + 1)];
				if (!in_array(self::REPLICATOR_OFFSET_SENTINEL, $rest, true)) {
					$ownType = FormShapeProjector::offsetPath($current, $rest);
					if ($ownType !== null && !$ownType instanceof ErrorType) {
						return $ownType;
					}
				}
			}

			$containers = $current->getContainers();
			if (!isset($containers[$chain[$i]])) {
				return $this->classHop($current, $chain[$i], array_slice($chain, $i + 1), $scope, $hopped, $ctx);
			}

			$current = $containers[$chain[$i]];
			$i++;
		}

		if ($i >= $count) {
			if ($current->getClassName() === null) {
				return null;
			}

			return FormShapeProjector::isUsableInnerShape($current)
			? new FormShapeType($current->getClassName(), $current)
			: new ObjectType($current->getClassName());
		}

		// THE LEAF ARM, and the ONE way it deliberately answers differently from
		// FormShapeProjector::offset()/childType(). It was measured against the projector rather than
		// carried forward from a note, because they were silently different before, and silently
		// different is how three spellings of one lookup drifted apart in the first place.
		//
		// The QUESTION first, because the rest follows from it. This arm resolves a COMPONENT-TREE
		// lookup, and that lookup THROWS: Nette's Container::getComponent() raises for a name that is not
		// attached rather than returning anything (which is also why createComponentFallback() strips
		// NullType off this channel). So no name this arm may refuse - whatever it cannot resolve
		// degrades, through classHop() below and then to null and the wrapped class's own
		// ArrayAccess<string, IComponent>, and this arm still never yields an ErrorType itself. What it
		// no longer means is that the CHANNEL never answers an absence: when the accessed expression's
		// own receiver is a FormShapeType, resolveFromFormShapeReceiver() takes this arm's null and asks
		// provenAbsent() - the projector's own ErrorType, for the shape the rule reads off that same
		// node, so one mistake gets one report either way. Its docblock has the reason that gate is on
		// the receiver and not down here.
		//
		// WHICH answer an expression gets is not a property of the expression. The projector is gated on
		// the FILE: FormAccessExpressionTypeResolver::getType() bails on
		// !hasAnyTrackedForm($scope->getFile()), so `$form['x']` on a tracked local form variable is the
		// projector's answer inside a file that BUILDS a form and this arm's answer everywhere else -
		// every .latte (50 distinct templates reach this arm in the current corpus) and every PHP file
		// that only consumes a form. Inside a builder file BOTH are live at once and the ROOT decides:
		// measured on MUnionContainerBranchesResolves.php, `$form['bag']` in the builder resolves through
		// the projector to `TextInput` while `$this['form']['bag']` in the same class stays on this arm
		// and keeps the union. Two owners for one runtime lookup is why each divergence below has to be a
		// decision.
		//
		// classHop() below is an entire resolution channel the projector does not have: a name no channel
		// of the shape holds is looked up as a createComponent<Name>() factory and the walk continues
		// into its result. 23 distinct sites in the current corpus resolve through it and through
		// nothing else - every `$this['xxxForm']`/`$this['grid']` in a presenter, grid or control - and
		// the projector's answer for those same names is unknownLeaf()'s mixed or ErrorType.
		//
		// NO LONGER a divergence, and both are worth naming because each was one:
		//
		// 1. CHANNEL ORDER, including the union. The four outcomes below - union the container and slot
		//    channels when both name the leaf, otherwise the one that does, otherwise the degrade for a
		//    channel that holds the name but can name no class - are offset()'s outcomes too, in this
		//    order. Two branches can fill one name with a container in one and a control in the other and
		//    either can be the component actually attached, so collapsing to one channel drops a real
		//    possibility; the projector used to take the slot channel first and return from it, folding
		//    MUnionContainerBranchesResolves.php's `TextInput|FormContainer{inner: string}` to
		//    `TextInput` and letting an OPAQUE slot shadow a container beside it. No corpus site names
		//    one child in both channels, so the fixtures are the only thing holding this -
		//    MUnionContainerBranchesResolves.php on this channel, CollidingChannelsOffset.php on the
		//    other, measured head-to-head as equal on every collision either can express. The REPLICATOR
		//    channel is a member of neither union: this arm reaches its replicator branch only below,
		//    through a componentTypes entry, and offset() keeps it a fall-through for the same reason.
		//
		// 2. isUsableInnerShape(). It is one predicate now, living beside the carriers it gates
		//    (FormShapeProjector::isUsableInnerShape()), and both channels ask it of the same shapes -
		//    this arm before wrapping a container or a replicator leaf, offset() before building the
		//    same two carriers for the same shape one hop earlier. It used to be private here and absent
		//    there, so an unresolvable multiplier/replicator was `IComponent` through this arm and
		//    `FormContainer{}`/`array<int, FormContainer{}>` through the projector for one expression;
		//    MultiplierUnresolvableFactoryOpens.php, ReplicatorNonEnumerableStaysIComponent.php and
		//    ReplicatorUntypedParamStaysIComponent.php now hold that answer on whichever channel replies.
		$leaf = $chain[$count - 1];
		if ($leaf === self::REPLICATOR_OFFSET_SENTINEL) {
			$union = $this->unionOfShapeChildren($current);
			if ($union !== null) {
				return $union;
			}
		}

		$containers = $current->getContainers();
		$slots = $current->getSlots();
		$containerType = null;
		if (isset($containers[$leaf])) {
			$child = $containers[$leaf];
			if ($child->getClassName() !== null) {
				$containerType = FormShapeProjector::isUsableInnerShape($child)
				? new FormShapeType($child->getClassName(), $child)
				: new ObjectType($child->getClassName());
			}
		}

		$leafSlot = $slots[$leaf] ?? null;
		$slotType = $leafSlot !== null && !$leafSlot->isTypeOpaque() && $leafSlot->getControlClasses() !== null
			? $leafSlot->getControlType()
			: null;

		if ($containerType !== null && $slotType !== null) {
			return TypeCombinator::union($containerType, $slotType);
		}

		if ($containerType !== null) {
			return $containerType;
		}

		if ($slotType !== null) {
			return $slotType;
		}

		$replicatorsAtLeaf = $current->getReplicators();
		$componentTypes = $current->getComponentTypes();
		if (isset($componentTypes[$leaf])) {
			if (isset($replicatorsAtLeaf[$leaf])) {
				$inner = $replicatorsAtLeaf[$leaf]->getInner();
				if (FormShapeProjector::isUsableInnerShape($inner)) {
					// The leaf IS the replicator, not one of its rows. FormShapeType would pair the
					// replicator's own class with the INNER ROW's shape, losing getContainers()/
					// createOne() - ReplicatorMethodReturnTypeExtension matches on the replicator's OWN
					// class, not the row's. FormReplicatorType wraps that own class instead - built by
					// FormShapeProjector::replicatorType(), the one place that recipe lives, so this arm
					// decides only WHETHER a replicator type is warranted here (a usable inner shape and
					// a known component class) and never HOW one is put together - so those
					// methods resolve; hasOffsetValueType() answers Yes for an integer offset (closing
					// the int-offset offsetAccess.notFound gap for THIS shape) and for a constant STRING
					// offset matching one of the replicator's OWN children (e.g. an "addNode" submit
					// button added on the addDynamic() return value); getOffsetValueType() resolves such
					// a child to its own type instead of the row. Any other constant string stays
					// delegated to the wrapped class (Maybe - silent, never reported; never No, per
					// FormReplicatorType::hasOffsetValueType()'s own docblock) - the wrapped-class
					// fallback documented in docs/forms-static-analysis.md still applies wherever a
					// container never reaches a FormReplicatorType at all (e.g. a plain, untracked
					// container). A non-string, non-integer offset (null/bool/float) is left entirely to
					// the wrapped class too, with no never-No guarantee - only the string/int cases above
					// are proven. The descending path above never hits this: $current has already swapped
					// to the inner shape through REPLICATOR_OFFSET_SENTINEL.
					//
					// The projector declines a replicator whose own or ROW class it cannot name, rather
					// than wrapping a placeholder class - a stricter cut of the same call this gate
					// already makes, so it cannot fire behind isUsableInnerShape(). Falling through to
					// the componentTypes answer below is the degrade either way.
					$replicatorType = FormShapeProjector::replicatorType($current, $leaf, $replicatorsAtLeaf[$leaf]);
					if ($replicatorType !== null) {
						return $replicatorType;
					}
				}
			}

			return new ObjectType($componentTypes[$leaf]);
		}

		return $this->classHop($current, $leaf, [], $scope, $hopped, $ctx);
	}

	/**
	 * @param list<string> $rest
	 */
	private function classHop(
		FormShape $current,
		string $segment,
		array $rest,
		Scope $scope,
		bool $hopped,
		?RecursionCtx $ctx = null
	): ?Type
	{
		if ($hopped || $current->getClassName() === null) {
			return null;
		}

		// A segment Nette\ComponentModel\Container::addComponent() could never have registered names no
		// component, so no createComponent<Name> can answer for it - and ucfirst('') is '', which finds
		// Container::createComponent() ITSELF and resolves a joined name like 'outer-' to that method's
		// shape. The int-offset sentinel is not a name either and is excluded by the same check.
		if (!ComponentPath::isValidSegment($segment)) {
			return null;
		}

		// A component host is a forms container or any UI component (presenters, controls, and
		// form-building controls — which are all Nette\Application\UI\Component descendants).
		$class = new ObjectType($current->getClassName());
		$isComponentHost = (new ObjectType(NetteContainer::class))->isSuperTypeOf($class)->yes()
		|| (new ObjectType(UiComponent::class))->isSuperTypeOf($class)->yes();
		if (!$isComponentHost) {
			return null;
		}

		$hopShape = $this->classComponentShape($current->getClassName(), $segment, $scope);
		if ($hopShape === null) {
			return null;
		}

		return $this->walk($hopShape, array_merge([$segment], $rest), $scope, true, $ctx);
	}

	private function replicatorInnerShape(FormShape $current, string $name, RecursionCtx $ctx): ?FormShape
	{
		$replicators = $current->getReplicators();
		if (!isset($replicators[$name])) {
			return null;
		}

		$inner = $replicators[$name]->getInner();
		$className = $inner->getClassName();
		if ($className === null) {
			return null;
		}

		if (!(new ObjectType(NetteContainer::class))->isSuperTypeOf(
			new ObjectType(ltrim($className, '\\')),
		)->yes()
		) {
			return null;
		}

		if ($inner->getContainers() === []
			&& $inner->getSlots() === []
			&& $inner->getReplicators() === []
			&& $inner->getComponentTypes() === []
		) {
			return null;
		}

		if ($ctx->exceedsCap()) {
			return null;
		}

		if ($ctx->alreadyVisited(self::replicatorIdentity($current->getClassName(), $name))) {
			return null;
		}

		return $inner;
	}

	private static function replicatorIdentity(?string $parentClassName, string $name): string
	{
		return sha1(($parentClassName ?? '') . '|' . $name);
	}

	public function resolveEventCallbackFormShape(Variable $node, Scope $scope): ?FormShape
	{
		$this->evictOnFileChange($scope->getFile());

		return $this->eventCallbackFormParamShape($node, $scope);
	}

	/**
	 * The on* event a closure parameter belongs to (e.g. 'onSuccess'), or null when
	 * the variable is not a parameter of an on*-assigned closure. Lets callers narrow
	 * required fields only in validated contexts (onSuccess).
	 */
	public function eventCallbackName(Variable $node, Scope $scope): ?string
	{
		$this->evictOnFileChange($scope->getFile());

		if (!is_string($node->name) || !$scope->isInClass() || $scope->getFunctionName() === null) {
			return null;
		}

		$pair = $this->ownerAndEnclosing($scope);
		if ($pair === null) {
			return null;
		}

		[, $enclosing] = $pair;

		$capturingClosure = $this->capturingClosureFor($node, $enclosing);
		if ($capturingClosure === null) {
			return null;
		}

		$assign = $this->findEnclosingEventAssign($capturingClosure, $enclosing);

		return $assign === null ? null : $assign[1];
	}

	private function capturingClosureFor(Variable $node, ClassMethod $enclosing): ?Closure
	{
		$varName = $node->name;
		foreach ($this->nodesInMethodBody($enclosing, Closure::class) as $closure) {
			$paramMatches = false;
			foreach ($closure->getParams() as $param) {
				if ($param->var instanceof Variable && $param->var->name === $varName) {
					$paramMatches = true;

					break;
				}
			}

			if (!$paramMatches) {
				continue;
			}

			foreach ((new NodeFinder())->findInstanceOf([$closure], Variable::class) as $v) {
				if ($v === $node) {
					return $closure;
				}
			}
		}

		return null;
	}

	/**
	 * Whether $expr sits lexically inside an onSuccess callback — i.e. the form is
	 * valid there, so getValues() should project the filled shape.
	 */
	public function isOnSuccessContext(Expr $expr, Scope $scope): bool
	{
		$this->evictOnFileChange($scope->getFile());

		return EventContextLocator::enclosingEventName(
			$this->parser->parseFile($scope->getFile()),
			$expr,
		) === 'onSuccess';
	}

	/**
	 * Whether the enclosing method is registered as an onSuccess handler — `$x->onSuccess[]
	 * = [$this, 'method']` or `$this->method(...)` — so its form parameter is validated and
	 * getValues() there projects the filled shape, just as inside an onSuccess closure.
	 */
	public function isOnSuccessHandlerMethod(Scope $scope): bool
	{
		$this->evictOnFileChange($scope->getFile());

		if (!$scope->isInClass() || $scope->getFunctionName() === null) {
			return false;
		}

		$ownerNode = $this->ownerClassNode($scope);
		if ($ownerNode === null) {
			return false;
		}

		$method = $scope->getFunctionName();
		foreach ((new NodeFinder())->findInstanceOf($ownerNode->stmts, Assign::class) as $assign) {
			$var = $assign->var;
			if ($var instanceof ArrayDimFetch) {
				$var = $var->var;
			}

			if (!$var instanceof Node\Expr\PropertyFetch
				|| !$var->name instanceof Identifier
				|| $var->name->toString() !== 'onSuccess'
			) {
				continue;
			}

			if ($this->callableTargetsMethod($assign->expr, $method)) {
				return true;
			}
		}

		return false;
	}

	private function callableTargetsMethod(Expr $expr, string $method): bool
	{
		if ($expr instanceof Node\Expr\Array_
			&& count($expr->items) === 2
			&& $expr->items[0]->value instanceof Variable
			&& $expr->items[0]->value->name === 'this'
			&& $expr->items[1]->value instanceof String_
		) {
			return $expr->items[1]->value->value === $method;
		}

		return $expr instanceof MethodCall
		&& $expr->isFirstClassCallable()
		&& $expr->var instanceof Variable
		&& $expr->var->name === 'this'
		&& $expr->name instanceof Identifier
		&& $expr->name->toString() === $method;
	}

	public function resolveFormShapeFromExpression(Expr $expr, Scope $scope): ?FormShape
	{
		$this->evictOnFileChange($scope->getFile());

		$type = $scope->getType($expr);
		if ($type instanceof FormShapeType) {
			return $type->getFormShape();
		}

		// A bare form variable inside an onSuccess handler — the closure's typed form
		// parameter, a use()/arrow capture, or the form parameter of a method handler.
		// Each resolves to the registration form's stored (scope-correct) shape.
		if ($expr instanceof Variable && is_string($expr->name) && $expr->name !== 'this') {
			$handlerShape = $this->eventCallbackFormParamShape($expr, $scope)
			?? $this->closureUseShapeForVariable($expr, $scope)
			?? $this->paramShapeForVariable($expr, $scope);
			if ($handlerShape !== null) {
				return $handlerShape;
			}
		}

		$chain = [];
		$root = $this->peel($expr, $scope, $chain);
		if ($root === null) {
			return null;
		}

		// The property slot is a root kind of its own rather than an extension of unwind()'s: unwind
		// answers "which VARIABLE does this chain hang off", the contract every store-backed
		// resolution below is written against, and a property root answers a different question with
		// a different owner. Peeling is what the two share, so peeling is what was extracted — the
		// chain underneath ($this->form['sub']['deep']) is walked by the very same loop either way.
		if ($root instanceof PropertyFetch) {
			$propertyShape = $this->propertyRootShape($root, $scope);

			return $propertyShape === null ? null : $this->descendShape($propertyShape, $chain, 0);
		}

		if (!$root instanceof Variable || $chain === [] || $root->name !== 'this' || !$scope->isInClass()) {
			return null;
		}

		$shape = $this->classComponentShape(
			$scope->getClassReflection()->getName(),
			$chain[0],
			$scope,
		);
		if ($shape === null) {
			return null;
		}

		return $this->descendShape($shape, $chain, 1);
	}

	/**
	 * `$this->prop` as a form root — the property-slot sibling of the class-component root above.
	 * The shape it returns is the property's OWN, so a chain hanging off it descends from index 0
	 * rather than from 1: nothing has been consumed to obtain it.
	 *
	 * Two deliberate refusals:
	 *
	 * - a receiver other than `$this` ($other->form) resolves to NOTHING in v1. Its owner would have
	 *   to come from the receiver's type, and every write through every other holder of that object
	 *   sits outside the per-class fold — the question the property's own visibility answers here,
	 *   asked again about a receiver. Half-answering it is how a false close is born.
	 * - a receiver whose type is not a Nette container never reaches the resolver. That gate is
	 *   COST, not correctness: classPropertyFormShape enumerates and locates every method of the
	 *   owning class before it can report a miss, and `$this->logger` / `$this->translator` and
	 *   their kind outnumber form-held properties by orders of magnitude in any real file.
	 */
	private function propertyRootShape(PropertyFetch $root, Scope $scope): ?FormShape
	{
		if (!$root->var instanceof Variable
			|| $root->var->name !== 'this'
			|| !$root->name instanceof Identifier
			|| !$scope->isInClass()
		) {
			return null;
		}

		if (!(new ObjectType(NetteContainer::class))->isSuperTypeOf($scope->getType($root))->yes()) {
			return null;
		}

		return $this->classPropertyShape(
			$scope->getClassReflection()->getName(),
			$root->name->toString(),
			$scope,
		);
	}

	/**
	 * Sole source of a property-held form's shape, mirroring classComponentShape: the index twin
	 * computes on demand, wrapped in loadInterprocedural so the store entry survives as a
	 * demand-side cache with the generator's file reads recorded as its dependencies. There is no
	 * vendor fallback here — a property in vendor code is nothing this channel models.
	 */
	private function classPropertyShape(string $ownerFqcn, string $property, Scope $scope): ?FormShape
	{
		$memoKey = $ownerFqcn . "\x00" . $property;
		if (array_key_exists($memoKey, $this->classPropertyShapeMemo)) {
			return $this->classPropertyShapeMemo[$memoKey];
		}

		$shape = $this->cache->loadInterprocedural(
			InterproceduralShapeKey::forClassProperty($ownerFqcn, $property),
			function () use ($ownerFqcn, $property, $scope): ?FormShape {
				$this->indexShapeResolver->bindScope($scope);

				return $this->indexShapeResolver->classPropertyFormShape($ownerFqcn, $property);
			},
		);

		return $this->classPropertyShapeMemo[$memoKey] = $shape;
	}

	/**
	 * The full shape for any component expression, for introspection (dumpComponent):
	 * a form/container resolves to its own shape; a non-Container UI component that declares
	 * createComponentXxx factories resolves to a synthetic parent whose children are those
	 * sub-components' shapes; anything else stays unresolved (rendered opaquely by the rule).
	 */
	public function resolveComponentShape(Expr $expr, Scope $scope): ?FormShape
	{
		$this->evictOnFileChange($scope->getFile());

		$shape = $this->resolveFormShapeFromExpression($expr, $scope);
		if ($shape !== null) {
			return $shape;
		}

		return $this->createComponentChildrenShape($scope->getType($expr), $scope);
	}

	private function createComponentChildrenShape(Type $componentType, Scope $scope): ?FormShape
	{
		if (!(new ObjectType(UiComponent::class))->isSuperTypeOf($componentType)->yes()) {
			return null;
		}

		$containers = [];
		$componentTypes = [];
		$componentTypePresence = [];
		foreach ($componentType->getObjectClassReflections() as $classReflection) {
			$ownerFqcn = $classReflection->getName();
			foreach ($this->createComponentChildNames($classReflection) as $childName) {
				if (isset($containers[$childName])) {
					continue;
				}

				$childShape = $this->classComponentShape($ownerFqcn, $childName, $scope);
				if ($childShape === null) {
					continue;
				}

				$containers[$childName] = $childShape;
				if ($childShape->getClassName() !== null) {
					$componentTypes[$childName] = $childShape->getClassName();
					// Every entry here is a class-name COMPANION to the $containers entry written one
					// line up, so this axis is never the one that answers for it (ComponentPath::
					// hasDefiniteChild() reaches the containers arm first and returns there). It is
					// recorded anyway, at the certainty the container itself gets - a createComponent<X>
					// factory is a declared method, so Nette creates the child on first access and it
					// cannot be conditionally absent - because the alternative is an entry whose
					// presence is unrecorded, which is exactly the state this axis exists to remove.
					$componentTypePresence[$childName] = Certainty::HAPPENS;
				}
			}
		}

		if ($containers === []) {
			return null;
		}

		$objectClassNames = $componentType->getObjectClassNames();

		return new FormShape(
			$objectClassNames === [] ? null : $objectClassNames[0],
			[],
			$containers,
			[],
			new UnknownInfo(),
			[],
			[],
			[],
			$componentTypes,
			$componentTypePresence,
		);
	}

	/**
	 * @return list<string>
	 */
	private function createComponentChildNames(ClassReflection $classReflection): array
	{
		$names = [];
		foreach ($classReflection->getNativeReflection()->getMethods() as $method) {
			$methodName = $method->getName();
			if (strncmp($methodName, 'createComponent', 15) !== 0 || strlen($methodName) === 15) {
				continue;
			}

			$names[] = lcfirst((string) substr($methodName, 15));
		}

		return $names;
	}

	/**
	 * getValues() on a @form-wizard component (the contributte/forms-wizard pattern):
	 * a constant array keyed by step number, each entry the corresponding step form's
	 * getValues type. Steps may be skipped at runtime (the wizard's startup() can skip
	 * them), so every entry is optional. Returns null when the receiver is not a wizard,
	 * letting the native return type stand.
	 */
	public function resolveWizardValuesType(Expr $receiver, Scope $scope): ?Type
	{
		$this->evictOnFileChange($scope->getFile());

		$receiverType = $scope->getType($receiver);
		$meta = $this->wizardMeta($receiverType);
		if ($meta === null) {
			return null;
		}

		$steps = $this->wizardStepShapes($receiverType, $meta, $scope);
		if ($steps === []) {
			return null;
		}

		$builder = ConstantArrayTypeBuilder::createEmpty();
		foreach ($steps as $n => $shape) {
			$builder->setOffsetValueType(
				new ConstantIntegerType($n),
				$this->wizardStepValuesType($shape),
				true,
			);
		}

		return $builder->getArray();
	}

	/**
	 * The per-step form shapes of a wizard, keyed by ascending step number, for
	 * introspection (dumpComponent). A step whose form cannot be shaped maps to
	 * null (rendered open). Returns null when the receiver is not a wizard.
	 *
	 * @return array<int, FormShape|null>|null
	 */
	public function resolveWizardStepShapes(Expr $receiver, Scope $scope): ?array
	{
		$this->evictOnFileChange($scope->getFile());

		$receiverType = $scope->getType($receiver);
		$meta = $this->wizardMeta($receiverType);
		if ($meta === null) {
			return null;
		}

		$steps = $this->wizardStepShapes($receiverType, $meta, $scope);

		return $steps === [] ? null : $steps;
	}

	private function wizardMeta(Type $receiverType): ?WizardMeta
	{
		foreach ($receiverType->getObjectClassNames() as $className) {
			$meta = $this->catalogReader->wizardMetaForClass($className);
			if ($meta !== null) {
				return $meta;
			}
		}

		return null;
	}

	/**
	 * Discover createStep1, createStep2, … ascending until the next is absent, each
	 * resolved to its form shape (null when unresolvable) via the same per-method
	 * resolution that shapes createComponentXxx.
	 *
	 * @return array<int, FormShape|null>
	 */
	private function wizardStepShapes(Type $receiverType, WizardMeta $meta, Scope $scope): array
	{
		$steps = [];
		for ($n = 1; $n <= self::WIZARD_MAX_STEPS; $n++) {
			$method = $meta->getStepMethodPrefix() . $n;
			if (!$receiverType->hasMethod($method)->yes()) {
				break;
			}

			$steps[$n] = $this->usableFormShape(
				$this->vendorMethodFormShape($receiverType, $method, $scope, true),
			);
		}

		return $steps;
	}

	private function wizardStepValuesType(?FormShape $shape): Type
	{
		if ($shape === null) {
			return new MixedType();
		}

		$mapped = $shape->getMappedType();

		return $mapped !== null
			? new ObjectType($mapped)
			: FormValuesProjector::projectObject($shape, ArrayHash::class);
	}

	/**
	 * Value type of a single control access ($container[key]) — i.e. the type of
	 * $container[key]->getValue(), which equals the corresponding member of
	 * $container->getValues().
	 */
	public function resolveControlValueType(Expr $controlAccess, Scope $scope): ?Type
	{
		$this->evictOnFileChange($scope->getFile());

		[$parent, $key] = $this->splitContainerOffset($controlAccess, $scope);
		if ($parent === null || $key === null) {
			return null;
		}

		$shape = $this->resolveFormShapeFromExpression($parent, $scope);
		if ($shape === null) {
			return null;
		}

		$value = FormValuesProjector::member($shape, $key);

		return $value instanceof ErrorType ? null : $value;
	}

	/**
	 * @return array{0: Expr, 1: string}|array{0: null, 1: null}
	 */
	private function splitContainerOffset(Expr $expr, Scope $scope): array
	{
		if ($expr instanceof ArrayDimFetch && $expr->dim !== null) {
			$consts = $scope->getType($expr->dim)->getConstantStrings();
			if (count($consts) === 1) {
				return [$expr->var, $consts[0]->getValue()];
			}
		}

		if ($expr instanceof MethodCall && $this->isComponentAccessCall($expr)) {
			$args = $expr->getArgs();
			if (count($args) === 1) {
				$consts = $scope->getType($args[0]->value)->getConstantStrings();
				if (count($consts) === 1) {
					return [$expr->var, $consts[0]->getValue()];
				}
			}
		}

		return [null, null];
	}

	/**
	 * @param list<string> $chain
	 */
	private function descendShape(FormShape $shape, array $chain, int $i): ?FormShape
	{
		$count = count($chain);
		while ($i < $count) {
			$containers = $shape->getContainers();
			if (!isset($containers[$chain[$i]])) {
				return null;
			}

			$shape = $containers[$chain[$i]];
			$i++;
		}

		return $shape->getClassName() === null ? null : $shape;
	}

	private function ownerClassNode(Scope $scope): ?Class_
	{
		$this->evictOnFileChange($scope->getFile());

		if (!$scope->isInClass()) {
			return null;
		}

		$name = $scope->getClassReflection()->getName();
		foreach ($this->fileClasses($scope->getFile()) as $class) {
			if (isset($class->namespacedName) && $class->namespacedName->toString() === $name) {
				return $class;
			}
		}

		return null;
	}

	/**
	 * @return list<Class_>
	 */
	private function fileClasses(string $file): array
	{
		$this->cache->recorder()->record($file);

		if (isset($this->fileClassesCache[$file])) {
			return $this->fileClassesCache[$file];
		}

		$classes = array_values((new NodeFinder())->findInstanceOf($this->parser->parseFile($file), Class_::class));
		$this->fileClassesCache[$file] = $classes;

		return $classes;
	}

	/**
	 * @return list<Class_>
	 */
	private function richFileClasses(string $file): array
	{
		$this->cache->recorder()->record($file);

		if ($this->richFileClassesCache->has($file)) {
			/** @var list<Class_> $cached */
			$cached = $this->richFileClassesCache->get($file);

			return $cached;
		}

		$classes = array_values((new NodeFinder())->findInstanceOf($this->richParser->parseFile($file), Class_::class));
		$this->richFileClassesCache->set($file, $classes);

		return $classes;
	}

	/**
	 * @template T of Node
	 * @param class-string<T> $class
	 * @return array<T>
	 */
	private function nodesInMethodBody(ClassMethod $method, string $class): array
	{
		$id = spl_object_id($method);
		if (!isset($this->methodBodyNodeCache[$id])) {
			$this->methodBodyNodeCache[$id] = ['m' => $method, 'nodes' => []];
		}

		if (!isset($this->methodBodyNodeCache[$id]['nodes'][$class])) {
			$this->methodBodyNodeCache[$id]['nodes'][$class] = (new NodeFinder())->findInstanceOf(
				$method->getStmts() ?? [],
				$class,
			);
		}

		/** @var array<T> $found */
		$found = $this->methodBodyNodeCache[$id]['nodes'][$class];

		return $found;
	}

	private function enclosingMethod(Class_ $ownerClass, Scope $scope): ?ClassMethod
	{
		$this->evictOnFileChange($scope->getFile());

		$functionName = $scope->getFunctionName();
		if ($functionName === null) {
			return null;
		}

		foreach ($ownerClass->getMethods() as $method) {
			if ($method->name->toString() === $functionName) {
				return $method;
			}
		}

		return null;
	}

	/**
	 * @return array{0: Class_, 1: ClassMethod}|null
	 */
	public function ownerAndEnclosing(Scope $scope): ?array
	{
		$ownerNode = $this->ownerClassNode($scope);
		if ($ownerNode === null) {
			return null;
		}

		$enclosing = $this->enclosingMethod($ownerNode, $scope);
		if ($enclosing === null) {
			return null;
		}

		return [$ownerNode, $enclosing];
	}

	private function localVariableShape(Variable $node, Scope $scope): ?FormShape
	{
		if (!is_string($node->name) || !$scope->isInClass() || $scope->getFunctionName() === null) {
			return null;
		}

		$pair = $this->ownerAndEnclosing($scope);
		if ($pair === null) {
			return null;
		}

		[, $enclosing] = $pair;

		$varName = $node->name;
		$firstRef = null;
		foreach ($this->nodesInMethodBody($enclosing, Variable::class) as $v) {
			if ($v->name === $varName) {
				$firstRef = $v;

				break;
			}
		}

		if ($firstRef === null) {
			return null;
		}

		$analyzer = AnalyzerStackFactory::build(
			$this->catalogReader,
			$this->cache,
			$this->richParser,
			$this->analysedPaths->all(),
		);
		$locator = new EnclosingFunctionLikeLocator();

		$shape = $analyzer->analyzeFormValue(
			$firstRef,
			$enclosing,
			$locator->taggedRecords($enclosing, $scope),
			$scope,
		);

		if ($shape->getClassName() === null) {
			return null;
		}

		$parentShape = $this->parentCallShapeForVariable($varName, $enclosing, $scope);

		return $parentShape === null ? $shape : $parentShape->merge($shape);
	}

	private function parentCallShapeForVariable(string $varName, ClassMethod $enclosing, Scope $scope): ?FormShape
	{
		$methodName = $scope->getFunctionName();
		if ($methodName === null || strncmp($methodName, 'createComponent', 15) !== 0) {
			return null;
		}

		$parent = $scope->getClassReflection()->getParentClass();
		if ($parent === null) {
			return null;
		}

		foreach ($this->nodesInMethodBody($enclosing, Assign::class) as $assign) {
			if (!$assign->var instanceof Variable || $assign->var->name !== $varName) {
				continue;
			}

			$rhs = $assign->expr;
			if (!$rhs instanceof Node\Expr\StaticCall
				|| !$rhs->class instanceof Node\Name
				|| !$rhs->name instanceof Identifier
				|| $rhs->class->toString() !== 'parent'
				|| $rhs->name->toString() !== $methodName
			) {
				continue;
			}

			$componentName = lcfirst((string) substr($methodName, 15));

			return $this->classComponentShape($parent->getName(), $componentName, $scope);
		}

		return null;
	}

	private function eventCallbackFormParamShape(Variable $node, Scope $scope): ?FormShape
	{
		if (!is_string($node->name) || !$scope->isInClass() || $scope->getFunctionName() === null) {
			return null;
		}

		$pair = $this->ownerAndEnclosing($scope);
		if ($pair === null) {
			return null;
		}

		[, $enclosing] = $pair;

		$capturingClosure = $this->capturingClosureFor($node, $enclosing);
		if ($capturingClosure === null) {
			return null;
		}

		$assign = $this->findEnclosingEventAssign($capturingClosure, $enclosing);
		if ($assign === null) {
			return null;
		}

		$receiverType = $scope->getType($assign[0]);
		if ($receiverType instanceof FormShapeType) {
			return $receiverType->getFormShape();
		}

		if ($assign[0] instanceof ArrayDimFetch && $assign[0]->dim !== null) {
			$componentNames = $scope->getType($assign[0]->dim)->getConstantStrings();
			if (count($componentNames) === 1) {
				$ownerClasses = $this->resolveReceiverClasses($assign[0]->var, $enclosing, $scope);
				if (count($ownerClasses) === 1) {
					$shape = $this->classComponentShape($ownerClasses[0], $componentNames[0]->getValue(), $scope);
					if ($shape !== null) {
						return $shape;
					}
				}
			}
		}

		// The handler was registered on a local form-building variable
		// ($form->onSuccess[] = function (FormType $form) {...}) rather than a component
		// access — analyse that variable directly, like a use()-captured form.
		if ($assign[0] instanceof Variable) {
			$localShape = $this->analyzeFormVariable($assign[0], $enclosing, $scope);
			if ($localShape !== null) {
				return $localShape;
			}
		}

		$classes = $this->resolveReceiverClasses($assign[0], $enclosing, $scope);
		if (count($classes) !== 1) {
			return null;
		}

		return $this->classComponentShape($classes[0], 'form', $scope);
	}

	/**
	 * Shape of a form-building variable by walking its enclosing method — used to
	 * resolve the form a handler closes over or receives as its parameter.
	 */
	private function analyzeFormVariable(Variable $formVar, ClassMethod $enclosing, Scope $scope): ?FormShape
	{
		if (!(new ObjectType(NetteContainer::class))->isSuperTypeOf($scope->getType($formVar))->yes()) {
			return null;
		}

		$analyzer = AnalyzerStackFactory::build(
			$this->catalogReader,
			$this->cache,
			$this->richParser,
			$this->analysedPaths->all(),
		);
		$locator = new EnclosingFunctionLikeLocator();

		$shape = $analyzer->analyzeFormValue(
			$formVar,
			$enclosing,
			$locator->taggedRecords($enclosing, $scope),
			$scope,
		);

		// The form is not actually built in this scope (e.g. it is passed in from
		// elsewhere) — defer to the broad crate rather than a misleading empty shape.
		if (
			$shape->getSlots() === []
			&& $shape->getContainers() === []
			&& $shape->getReplicators() === []
			&& $shape->getComponentTypes() === []
		) {
			return null;
		}

		return $shape;
	}

	/**
	 * @return list<string>
	 */
	private function resolveReceiverClasses(Expr $receiver, ClassMethod $enclosing, Scope $scope): array
	{
		$classes = $scope->getType($receiver)->getObjectClassNames();
		if ($classes !== []) {
			return $classes;
		}

		if (!$receiver instanceof Variable || !is_string($receiver->name)) {
			return [];
		}

		$varName = $receiver->name;
		foreach ($this->nodesInMethodBody($enclosing, Assign::class) as $assign) {
			if (!$assign->var instanceof Variable || $assign->var->name !== $varName) {
				continue;
			}

			$rhsClasses = $scope->getType($assign->expr)->getObjectClassNames();
			if ($rhsClasses !== []) {
				return $rhsClasses;
			}
		}

		return [];
	}

	/**
	 * @return array{0: Expr, 1: string}|null  [receiver expression, event name]
	 */
	private function findEnclosingEventAssign(Closure $closure, ClassMethod $enclosing): ?array
	{
		foreach ($this->nodesInMethodBody($enclosing, Assign::class) as $assign) {
			if ($assign->expr !== $closure) {
				continue;
			}

			$var = $assign->var;
			if ($var instanceof ArrayDimFetch) {
				$var = $var->var;
			}

			if (!$var instanceof Node\Expr\PropertyFetch || !$var->name instanceof Identifier) {
				continue;
			}

			$propName = $var->name->toString();
			if (!EventPropertyName::matches($propName)) {
				continue;
			}

			return [$var->var, $propName];
		}

		return null;
	}

	private function closureUseShapeForVariable(Variable $node, Scope $scope): ?FormShape
	{
		if (!is_string($node->name) || !$scope->isInClass() || $scope->getFunctionName() === null) {
			return null;
		}

		$pair = $this->ownerAndEnclosing($scope);
		if ($pair === null) {
			return null;
		}

		[, $enclosing] = $pair;

		$varName = $node->name;
		$handlers = $this->handlerFunctions($enclosing);
		if (!$this->capturesVariable($node, $handlers)) {
			return null;
		}

		// The same variable written in the enclosing method, outside every handler —
		// i.e. the form-building variable the handler closes over.
		$outerVarRef = null;
		foreach ($this->nodesInMethodBody($enclosing, Variable::class) as $v) {
			if ($v->name !== $varName) {
				continue;
			}

			if (!$this->variableInsideAny($v, $handlers)) {
				$outerVarRef = $v;

				break;
			}
		}

		if ($outerVarRef === null) {
			return null;
		}

		return $this->analyzeFormVariable($outerVarRef, $enclosing, $scope);
	}

	/**
	 * Closures and arrow functions declared in $enclosing — the handler styles that can
	 * close over the form-building variable.
	 *
	 * @return list<Closure|ArrowFunction>
	 */
	private function handlerFunctions(ClassMethod $enclosing): array
	{
		return array_values(array_merge(
			$this->nodesInMethodBody($enclosing, Closure::class),
			$this->nodesInMethodBody($enclosing, ArrowFunction::class),
		));
	}

	/**
	 * Whether $node is captured by one of $handlers: a closure that use()s it, or an
	 * arrow function that implicitly captures it (used in its body, not its own param).
	 *
	 * @param list<Closure|ArrowFunction> $handlers
	 */
	private function capturesVariable(Variable $node, array $handlers): bool
	{
		foreach ($handlers as $handler) {
			if (!$this->variableInsideAny($node, [$handler])) {
				continue;
			}

			if ($handler instanceof Closure) {
				foreach ($handler->uses as $use) {
					if ($use->var->name === $node->name) {
						return true;
					}
				}

				continue;
			}

			$isOwnParam = false;
			foreach ($handler->getParams() as $param) {
				if ($param->var instanceof Variable && $param->var->name === $node->name) {
					$isOwnParam = true;

					break;
				}
			}

			if (!$isOwnParam) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<Closure|ArrowFunction> $handlers
	 */
	private function variableInsideAny(Variable $node, array $handlers): bool
	{
		foreach ($handlers as $handler) {
			foreach ((new NodeFinder())->findInstanceOf([$handler], Variable::class) as $v) {
				if ($v === $node) {
					return true;
				}
			}
		}

		return false;
	}

	private function paramShapeForVariable(Variable $node, Scope $scope): ?FormShape
	{
		if (!is_string($node->name) || !$scope->isInClass() || $scope->getFunctionName() === null) {
			return null;
		}

		// Captured before the bindScope() call below: an intervening method call resets PHPStan's
		// narrowing of $scope->getFunctionName()/getClassReflection(), so read them while still non-null.
		$ownerFqcn = $scope->getClassReflection()->getName();
		$methodName = $scope->getFunctionName();

		$pair = $this->ownerAndEnclosing($scope);
		if ($pair === null) {
			return null;
		}

		[, $enclosing] = $pair;

		$formType = new ObjectType(NetteForm::class);
		$containerType = new ObjectType(NetteContainer::class);

		foreach (array_values($enclosing->getParams()) as $paramIdx => $param) {
			if (!$param->var instanceof Variable || $param->var->name !== $node->name) {
				continue;
			}

			if ($param->type === null) {
				return null;
			}

			$paramTypeName = null;
			if ($param->type instanceof Name) {
				$paramTypeName = $scope->resolveName($param->type);
			} elseif ($param->type instanceof Node\NullableType && $param->type->type instanceof Name) {
				$paramTypeName = $scope->resolveName($param->type->type);
			}

			if ($paramTypeName === null) {
				return null;
			}

			$paramType = new ObjectType($paramTypeName);
			if (!$formType->isSuperTypeOf($paramType)->yes() && !$containerType->isSuperTypeOf($paramType)->yes()) {
				return null;
			}

			// The registration index folds every analysed file's handler-param registrations up front,
			// so a cold reader gets the same answer a warm collector-store reader used to — the
			// store→read ordering seam is gone. bindScope is idempotent (the resolver memo is
			// scope-severed) and safe for the first caller to set.
			$this->indexShapeResolver->bindScope($scope);

			return $this->indexShapeResolver->resolveMethodParam($ownerFqcn, $methodName, $paramIdx);
		}

		return null;
	}

	/**
	 * The registered form's constructed class for a bare handler-param variable, when it is strictly
	 * more specific than the DECLARED param type — so a reader can narrow `$form` (declared base
	 * Nette\…\Form) to the class it is actually registered with (ApplicationForm). Resolved scope-free
	 * and walk-free (resolveMethodParamClass), so typing the variable never recurses into the offset
	 * walk it enables. Null when the variable is not a Form/Container param, the registered class is
	 * unresolvable, or it equals / is not a subtype of the declared type — the declared type then
	 * stands unchanged, never overridden by an unrelated or coarser answer. Also null inside an
	 * anonymous function: the match below is by NAME against the enclosing METHOD's params, and a
	 * closure param/variable shadowing that name would otherwise be misattributed to it.
	 */
	public function paramClassForVariable(Variable $node, Scope $scope): ?string
	{
		$this->evictOnFileChange($scope->getFile());

		if (
			!is_string($node->name)
			|| $node->name === 'this'
			|| !$scope->isInClass()
			|| $scope->getFunctionName() === null
			|| $scope->isInAnonymousFunction()
		) {
			return null;
		}

		$ownerFqcn = $scope->getClassReflection()->getName();
		$methodName = $scope->getFunctionName();

		$pair = $this->ownerAndEnclosing($scope);
		if ($pair === null) {
			return null;
		}

		[, $enclosing] = $pair;

		$formType = new ObjectType(NetteForm::class);
		$containerType = new ObjectType(NetteContainer::class);

		foreach (array_values($enclosing->getParams()) as $paramIdx => $param) {
			if (!$param->var instanceof Variable || $param->var->name !== $node->name) {
				continue;
			}

			$paramTypeName = null;
			if ($param->type instanceof Name) {
				$paramTypeName = $scope->resolveName($param->type);
			} elseif ($param->type instanceof Node\NullableType && $param->type->type instanceof Name) {
				$paramTypeName = $scope->resolveName($param->type->type);
			}

			if ($paramTypeName === null) {
				return null;
			}

			$declaredType = new ObjectType($paramTypeName);
			if (
				!$formType->isSuperTypeOf($declaredType)->yes()
				&& !$containerType->isSuperTypeOf($declaredType)->yes()
			) {
				return null;
			}

			$resolvedClass = $this->indexShapeResolver->resolveMethodParamClass($ownerFqcn, $methodName, $paramIdx);
			if ($resolvedClass === null) {
				return null;
			}

			$resolvedType = new ObjectType(ltrim($resolvedClass, '\\'));

			// Narrow only to a STRICT subtype of the declared param class: equal keeps the declared render,
			// and a resolved class that is not a subtype (never-a-subtype / unrelated) must never override
			// the declared type.
			return $declaredType->isSuperTypeOf($resolvedType)->yes() && !$declaredType->equals($resolvedType)
				? ltrim($resolvedClass, '\\')
				: null;
		}

		return null;
	}

	private function factoryMethodShape(string $varName, Scope $scope): ?FormShape
	{
		$ast = $this->parser->parseFile($scope->getFile());

		$searchRoot = $ast;
		if ($scope->isInClass() && $scope->getFunctionName() !== null) {
			$pair = $this->ownerAndEnclosing($scope);
			if ($pair === null) {
				return null;
			}

			[, $enclosing] = $pair;

			$searchRoot = $enclosing->getStmts() ?? [];
		}

		$assigns = [];
		foreach ((new NodeFinder())->findInstanceOf($searchRoot, Assign::class) as $assign) {
			if ($assign->var instanceof Variable && $assign->var->name === $varName) {
				$assigns[] = $assign;
			}
		}

		if (count($assigns) !== 1) {
			return null;
		}

		$rhs = $assigns[0]->expr;
		if (!$rhs instanceof MethodCall || !$rhs->name instanceof Identifier) {
			return null;
		}

		$calledOnType = $scope->getType($rhs->var);
		if (count($calledOnType->getObjectClassNames()) !== 1) {
			return null;
		}

		$methodName = $rhs->name->toString();
		if (!$calledOnType->hasMethod($methodName)->yes()) {
			return null;
		}

		$methodReflection = $calledOnType->getMethod($methodName, $scope);
		$returnType = $methodReflection->getVariants()[0]->getReturnType();
		$isForm = (new ObjectType(NetteForm::class))->isSuperTypeOf($returnType)->yes()
		|| (new ObjectType(NetteContainer::class))->isSuperTypeOf($returnType)->yes();
		if (!$isForm) {
			return null;
		}

		$declaring = $methodReflection->getDeclaringClass();
		$declaringFqcn = $declaring->getName();

		// Since the forFactoryMethod collector-write flip the on-demand remote walk is the sole
		// producer of a factory method's shape; wrap it in loadInterprocedural so fm: survives as a
		// demand-side cache (the post-flip warm-up — fm: was the biggest collector-time warm-up win,
		// underpinning every chain/factory-rebind compensation that reads it through
		// BuilderChainDetector). Post-flip the key has exactly one collector-vs-demand writer, so the
		// wrap is poison-free.
		return $this->cache->loadInterprocedural(
			InterproceduralShapeKey::forFactoryMethod($declaringFqcn, $methodName),
			function () use ($declaring, $declaringFqcn, $methodName, $scope): ?FormShape {
				$located = $this->vendorMethodNode($declaring, $methodName);
				if ($located === null) {
					return null;
				}

				return $this->shapeVendorMethod($located[0], $located[1], $declaringFqcn, $methodName, $scope);
			},
		);
	}

	private function vendorCreateComponentShape(string $componentName, Variable $node, Scope $scope): ?FormShape
	{
		if ($node->name === 'this') {
			$receiverType = $scope->isInClass()
			? new ObjectType($scope->getClassReflection()->getName())
			: null;
		} else {
			$receiverType = $scope->getType($node);
		}

		if ($receiverType === null) {
			return null;
		}

		return $this->vendorShapeByReceiver($receiverType, $componentName, $scope);
	}

	/**
	 * Sole source of a class component's shape since the forClassComponent collector-write flip:
	 * the index twin (IndexShapeResolver::classComponentShape, the full B6-proven pipeline)
	 * computes on demand, wrapped in loadInterprocedural so the store entry survives as a
	 * demand-side cache — the post-flip warm-up. The vendor fallback stays the secondary for
	 * shapes the twin does not model (a direct `return new X()` createComponent, genuine vendor
	 * receivers); it is NARROWER than the twin (no parent/constructor compensation), so it must
	 * never be consulted first.
	 */
	private function classComponentShape(string $ownerFqcn, string $component, Scope $scope): ?FormShape
	{
		$memoKey = $ownerFqcn . "\x00" . $component;
		if (array_key_exists($memoKey, $this->classComponentShapeMemo)) {
			return $this->classComponentShapeMemo[$memoKey];
		}

		$shape = $this->cache->loadInterprocedural(
			InterproceduralShapeKey::forClassComponent($ownerFqcn, $component),
			function () use ($ownerFqcn, $component, $scope): ?FormShape {
				$this->indexShapeResolver->bindScope($scope);

				return $this->indexShapeResolver->classComponentShape($ownerFqcn, $component);
			},
		) ?? $this->vendorShapeByReceiver(new ObjectType($ownerFqcn), $component, $scope, true);
		$this->classComponentShapeMemo[$memoKey] = $shape;

		return $shape;
	}

	/**
	 * Identity of the demanded variable's own class-like/function boundary, salting the
	 * on-demand memo so it never answers a different boundary's query.
	 */
	private function plainFileBoundaryKey(Scope $scope): string
	{
		if ($scope->isInClass()) {
			return 'c:' . $scope->getClassReflection()->getName() . '::' . ($scope->getFunctionName() ?? '');
		}

		$functionName = $scope->getFunctionName();

		return $functionName === null ? self::PLAIN_FILE_GLOBAL_BOUNDARY : 'f:' . $functionName;
	}

	/**
	 * Sole source of a plain-file variable's shape since the forPlainFile collector flip: the
	 * on-demand walk (shapePlainFileVar) is a byte-faithful reproduction of the deleted
	 * collector's own compute, memoised per process only — the corpus is small (template
	 * scripts) and each file's walk is cheap, so no interprocedural demand cache is kept.
	 */
	private function plainFileShape(string $file, string $var, Scope $scope): ?FormShape
	{
		$boundary = $this->plainFileBoundaryKey($scope);

		if (strncmp($file, 'phar://', 7) === 0 || !is_file($file)) {
			return null;
		}

		$memoKey = $file . "\x00" . $var . "\x00" . $boundary;
		if (array_key_exists($memoKey, $this->plainFileShapeMemo)) {
			return $this->plainFileShapeMemo[$memoKey];
		}

		return $this->plainFileShapeMemo[$memoKey] = $this->shapePlainFileVar($file, $var, $scope);
	}

	private function shapePlainFileVar(string $file, string $var, Scope $scope): ?FormShape
	{
		$varExpr = new Variable($var);
		if (!(new ObjectType(NetteContainer::class))->isSuperTypeOf($scope->getType($varExpr))->yes()) {
			return null;
		}

		$fn = $this->plainFileScopeBoundary($file, $scope);
		if ($fn === null) {
			return null;
		}

		$analyzer = AnalyzerStackFactory::build(
			$this->catalogReader,
			$this->cache,
			$this->richParser,
			$this->analysedPaths->all(),
		);
		$shape = $analyzer->analyzeFormValue(
			$varExpr,
			$fn,
			(new EnclosingFunctionLikeLocator())->taggedRecords($fn, $scope),
			$scope,
			$file,
			null,
			true,
		);

		return $this->usableFormShape($shape);
	}

	/**
	 * The demanded variable's own class-like/function boundary within the file, so a
	 * same-named local elsewhere in the file (another class's method, another top-level
	 * function) never contributes to its walk.
	 */
	private function plainFileScopeBoundary(string $file, Scope $scope): ?FunctionLike
	{
		$functionName = $scope->getFunctionName();

		if ($scope->isInClass()) {
			if ($functionName === null) {
				return null;
			}

			$target = ltrim($scope->getClassReflection()->getName(), '\\');
			foreach ($this->richFileClasses($file) as $cls) {
				if (!isset($cls->namespacedName) || $cls->namespacedName->toString() !== $target) {
					continue;
				}

				foreach ($cls->getMethods() as $method) {
					if ($method->name->toString() === $functionName) {
						return $method;
					}
				}
			}

			return null;
		}

		if ($functionName !== null) {
			foreach ((new NodeFinder())->findInstanceOf(
				$this->richParser->parseFile($file),
				Node\Stmt\Function_::class,
			) as $function) {
				if ($function->name->toString() === $functionName) {
					return $function;
				}
			}

			return null;
		}

		return new Closure(['stmts' => $this->topLevelPlainStmts($file)]);
	}

	/**
	 * @return list<Node\Stmt>
	 */
	private function topLevelPlainStmts(string $file): array
	{
		$stmts = [];
		foreach ($this->richParser->parseFile($file) as $topLevel) {
			if ($topLevel instanceof Node\Stmt\Namespace_) {
				foreach ($topLevel->stmts as $inner) {
					if (!$this->isClassOrFunctionDeclaration($inner)) {
						$stmts[] = $inner;
					}
				}
			} elseif (!$this->isClassOrFunctionDeclaration($topLevel)) {
				$stmts[] = $topLevel;
			}
		}

		return $stmts;
	}

	private function isClassOrFunctionDeclaration(Node\Stmt $stmt): bool
	{
		return $stmt instanceof Node\Stmt\ClassLike || $stmt instanceof Node\Stmt\Function_;
	}

	private function vendorShapeByReceiver(
		Type $receiverType,
		string $componentName,
		Scope $scope,
		bool $allowAnalysed = false
	): ?FormShape
	{
		return $this->vendorMethodFormShape(
			$receiverType,
			'createComponent' . ucfirst($componentName),
			$scope,
			$allowAnalysed,
		);
	}

	private function vendorMethodFormShape(
		Type $receiverType,
		string $method,
		Scope $scope,
		bool $allowAnalysed = false
	): ?FormShape
	{
		if (!$receiverType->hasMethod($method)->yes()) {
			return null;
		}

		$declaring = $receiverType->getMethod($method, $scope)->getDeclaringClass();
		$located = $this->vendorMethodNode($declaring, $method);
		if ($located === null) {
			return null;
		}

		[$methodNode, $file] = $located;
		if (!$allowAnalysed && $this->analysedPaths->isAnalysed($file)) {
			return null;
		}

		try {
			$src = FileSystem::read($file);
		} catch (IOException $e) {
			return null;
		}

		$key = InterproceduralShapeKey::forVendorMethod($declaring->getName(), $method, sha1($src));

		return $this->cache->loadInterprocedural(
			$key,
			function () use ($methodNode, $file, $declaring, $method, $scope): ?FormShape {
				$shape = $this->shapeVendorMethod($methodNode, $file, $declaring->getName(), $method, $scope);

				return $shape !== null && $shape->getClassName() !== null ? $shape : null;
			},
		);
	}

	/**
	 * @param list<string> $chain
	 */
	private function vendorParentCallbackShape(string $paramName, array $chain, Scope $scope): ?Type
	{
		if (!$scope->isInClass()) {
			return null;
		}

		$pair = $this->ownerAndEnclosing($scope);
		if ($pair === null) {
			return null;
		}

		[, $enclosing] = $pair;

		$ancestor = $scope->getClassReflection()->getParentClass();
		while ($ancestor !== null) {
			$file = $ancestor->getFileName();
			if ($file !== null
				&& strncmp($file, 'phar://', 7) !== 0
				&& is_file($file)
				&& !$this->analysedPaths->isAnalysed($file)
			) {
				foreach (
				$this->richFileClasses($file) as $cls
				) {
					if (!isset($cls->namespacedName)
						|| $cls->namespacedName->toString() !== ltrim($ancestor->getName(), '\\')
					) {
						continue;
					}

					$component = $this->tracer->originComponent($cls, $enclosing, $paramName);
					if ($component === null) {
						continue;
					}

					$shape = $this->vendorShapeByReceiver(
						new ObjectType($scope->getClassReflection()->getName()),
						$component,
						$scope,
					);

					return $shape === null
					? null
					: $this->walk($shape, array_merge([$component], $chain), $scope);
				}
			}

			$ancestor = $ancestor->getParentClass();
		}

		return null;
	}

	/**
	 * @return array{ClassMethod, string}|null [method node, the method's own file]
	 */
	private function vendorMethodNode(ClassReflection $declaring, string $method): ?array
	{
		$native = $declaring->getNativeReflection();
		if (!$native->hasMethod($method)) {
			return null;
		}

		$reflectionMethod = $native->getMethod($method);
		$file = $this->methodLocator->ownFile($reflectionMethod);
		$node = $this->methodLocator->locate($reflectionMethod);
		if ($file === null || $node === null) {
			return null;
		}

		return [$node, $file];
	}

	private function shapeVendorMethod(
		ClassMethod $fn,
		string $file,
		string $declaringFqcn,
		string $method,
		Scope $scope,
		?RecursionCtx $ctx = null
	): ?FormShape
	{
		$ctx ??= new RecursionCtx();
		$identity = ltrim($declaringFqcn, '\\') . '::' . $method;
		if ($ctx->exceedsCap() || $ctx->alreadyVisited($identity)) {
			return null;
		}

		$ctx = $ctx->descend($identity);

		// Collect every `return $form` arm (excluding ones nested in a closure, which return the
		// closure's local, not this method's form) so divergent arms join below. A builder-chain
		// return only wins when it precedes every variable return, mirroring the old first-return.
		$closureInnerIds = ClosureScope::innerNodeIds($fn->getStmts() ?? []);
		$returnVars = [];
		$returnNews = [];
		$returnChain = null;
		$returnPropertyFetch = false;
		foreach ((new NodeFinder())->findInstanceOf($fn->getStmts() ?? [], Return_::class) as $ret) {
			if (isset($closureInnerIds[spl_object_id($ret)])) {
				continue;
			}

			if ($ret->expr instanceof Variable && is_string($ret->expr->name)) {
				$returnVars[] = $ret->expr;
			} elseif ($ret->expr instanceof New_ && $ret->expr->class instanceof Name) {
				$returnNews[] = [$ret->expr->class->toString(), count($ret->expr->getArgs())];
			} elseif ($ret->expr instanceof MethodCall) {
				if ($returnVars === [] && $returnNews === [] && $returnChain === null) {
					$returnChain = $ret->expr;
				}
			} elseif ($ret->expr instanceof Node\Expr\PropertyFetch) {
				$returnPropertyFetch = true;
			}
		}

		if ($returnChain !== null) {
			$chainShape = $this->builderChainTerminalShape($returnChain, $fn, $declaringFqcn, $scope, $ctx);
			if ($chainShape !== null) {
				return $chainShape;
			}

			$declared = $fn->returnType instanceof Name
			? $fn->returnType->toString()
			: ($fn->returnType instanceof Node\NullableType && $fn->returnType->type instanceof Name
			? $fn->returnType->type->toString()
			: null);
			if ($declared === null) {
				return null;
			}

			$declaredType = new ObjectType(ltrim($declared, '\\'));
			if (!(new ObjectType(UiComponent::class))->isSuperTypeOf($declaredType)->yes()) {
				return null;
			}

			// A builder chain whose terminal this walk could not read is the property-fetch arm's
			// situation exactly (36 lines below): the declared class is known but the fields are not,
			// because whatever `return $this->factory->create()` hands back was built somewhere this
			// method never looked. Closing it would claim the component has no children at all.
			return FormShape::empty('\\' . ltrim($declared, '\\'))
				->withUnknownReason(UnknownReason::UNRESOLVED_ORIGIN);
		}

		// Parity with the on-demand remoteFactoryCallShape and the store analyzeRemoteFactoryMethod:
		// a `return new X()` arm resolves the constructed form's own controls through the shared
		// resolver. An unresolvable new (unknown class, inherited constructor) is skipped so it never
		// empty-closes; if every arm is an unresolvable new the shape stays open exactly as before.
		$newShape = null;
		foreach ($returnNews as [$newClass, $newArgCount]) {
			$constructed = $this->constructedFormShape(ltrim($newClass, '\\'), $newArgCount);
			if ($constructed === null) {
				continue;
			}

			$newShape = $newShape === null ? $constructed : $newShape->joinBranch($constructed);
		}

		if ($returnVars === []) {
			if ($newShape !== null) {
				return $newShape;
			}

			if (!$returnPropertyFetch) {
				return null;
			}

			$declared = $fn->returnType instanceof Name
			? $fn->returnType->toString()
			: ($fn->returnType instanceof Node\NullableType && $fn->returnType->type instanceof Name
			? $fn->returnType->type->toString()
			: null);

			// A property-fetch return exposes a prebuilt object this method never builds:
			// the declared class is known but the fields are not, so the shape stays open.
			return $declared === null
				? null
				: FormShape::empty('\\' . ltrim($declared, '\\'))->withUnknownReason(UnknownReason::UNRESOLVED_ORIGIN);
		}

		$callees = AnalyzerStackFactory::buildCallees($this->catalogReader, $this->cache, $this->richParser);
		$factory = AnalyzerStackFactory::buildFactory(
			$this->catalogReader,
			$this->cache,
			$callees,
			$this->analysedPaths->all(),
		);
		$analyzer = new FormShapeAnalyzer($factory, $this->cache, $callees);
		$locator = new EnclosingFunctionLikeLocator();
		$taggedRecords = $locator->taggedRecords($fn, $scope);

		$shape = $newShape;
		foreach ($returnVars as $returnVar) {
			$arm = $analyzer->analyzeFormValue(
				$returnVar,
				$fn,
				$taggedRecords,
				$scope,
				$file,
				$declaringFqcn,
				true,
			);

			// This is an interprocedural shape resolution, so a $form = $this->factory() rebind
			// (which the scope-free walk marks REBIND_UNPROVEN) is compensated here — only once
			// factoryAssignShape actually resolves the call target; an unresolvable target
			// (unlocatable method, undetectable receiver, a remote factory returning something
			// the walker can't follow) leaves the marker so the shape stays honestly open. A
			// resolved shape that itself still carries the marker (the factory's own form comes
			// from an unresolved source) merges without the strip, so the nested marker survives.
			// Skipped when the method opened the form by pulling a control into a local and
			// mutating it (a lost-field unknown): merging the factory's precise-but-now-incomplete
			// fields would wrongly close them. The probe strips the rebind marker (itself a
			// lost-field reason) so the factory's own expected marker cannot self-trigger the bail;
			// the guard precedes every resolution channel including the forFactoryMethod lookup.
			$probeArm = $arm->withoutUnknownReason(UnknownReason::REBIND_UNPROVEN);
			$factoryShape = $this->hasLostFieldUnknown($probeArm)
				? null
				: $this->factoryAssignShape($fn, $returnVar->name, $declaringFqcn, $scope);
			if ($factoryShape !== null) {
				$arm = $arm->merge($factoryShape);
				if (!$this->carriesRebindMarker($factoryShape)) {
					$arm = $arm->withoutUnknownReason(UnknownReason::REBIND_UNPROVEN);
				}
			}

			// A `$form = new SelfForm()` sole binding whose controls live in the form's own
			// constructor contributes those fields (and the CONSTRUCTOR_BUILD open marker a
			// configure-only constructor leaves), mirroring the factory merge above. Skipped
			// under the same lost-field guard.
			$constructorShape = $this->hasLostFieldUnknown($probeArm) || !is_string($returnVar->name)
				? null
				: $this->returnShapeSupport()->constructorShape($fn, $returnVar->name, $declaringFqcn);
			if ($constructorShape !== null) {
				$arm = $arm->merge($constructorShape);
			}

			$shape = $shape === null ? $arm : $shape->joinBranch($arm);
		}

		$folded = $this->foldVendorParamMutations($fn, $shape, $factory, $scope);

		if (ReturnShapeSupport::isClassNameUnresolved($folded->getClassName())) {
			$declared = $fn->returnType instanceof Name
			? $fn->returnType->toString()
			: ($fn->returnType instanceof Node\NullableType && $fn->returnType->type instanceof Name
			? $fn->returnType->type->toString()
			: null);

			if ($declared === null) {
				return null;
			}

			return FormShape::empty('\\' . ltrim($declared, '\\'));
		}

		return $folded;
	}

	/**
	 * Resolves the shape of a $trackedName = <MethodCall> rebind's target method, soundly: only
	 * a fresh `new X()` return (definitely empty, no hidden fields) or a Variable return (walked
	 * like any local form) is trusted; a property fetch, a dynamic origin, or an unlocatable /
	 * undetectable call fails (null) so the caller leaves the rebind marker in place instead of
	 * merging a false-empty shape over it.
	 *
	 * @param string|Node\Expr $varName
	 */
	private function factoryAssignShape(ClassMethod $fn, $varName, string $ownerFqcn, Scope $scope): ?FormShape
	{
		if (!is_string($varName)) {
			return null;
		}

		$sole = ReturnShapeSupport::soleTrackedAssign($fn, $varName);
		if ($sole === null || !$sole->expr instanceof MethodCall) {
			return null;
		}

		return $this->remoteFactoryCallShape($sole->expr, $fn, $ownerFqcn, $scope);
	}

	private function remoteFactoryCallShape(
		MethodCall $call,
		ClassMethod $fn,
		string $ownerFqcn,
		Scope $scope
	): ?FormShape
	{
		$receiverClass = $this->chainReceiverTracker()->resolveReceiverClass($call->var, $fn, $ownerFqcn);
		$detection = BuilderChainDetector::detect($call, $scope, $this->cache, $receiverClass);
		if ($detection === null) {
			return null;
		}

		$cached = $detection->getCachedShape();
		if ($cached !== null) {
			return $cached;
		}

		$declaring = $detection->getDeclaringClass();
		$methodName = $detection->getMethodName();
		if ($declaring === null || $methodName === null) {
			return null;
		}

		$located = $this->vendorMethodNode($declaring, $methodName);
		if ($located === null) {
			return null;
		}

		[$methodNode, $file] = $located;
		$innerIds = ClosureScope::innerNodeIds($methodNode->getStmts() ?? []);
		foreach ((new NodeFinder())->findInstanceOf($methodNode->getStmts() ?? [], Return_::class) as $ret) {
			if (isset($innerIds[spl_object_id($ret)])) {
				continue;
			}

			if ($ret->expr instanceof New_ && $ret->expr->class instanceof Name) {
				return $this->constructedFormShape(
					ltrim($ret->expr->class->toString(), '\\'),
					count($ret->expr->getArgs()),
				);
			}

			if ($ret->expr instanceof Variable && is_string($ret->expr->name)) {
				$analyzer = AnalyzerStackFactory::build(
					$this->catalogReader,
					$this->cache,
					$this->richParser,
					$this->analysedPaths->all(),
				);
				$locator = new EnclosingFunctionLikeLocator();

				return $analyzer->analyzeFormValue(
					$ret->expr,
					$methodNode,
					$locator->taggedRecords($methodNode, $scope),
					$scope,
					$file,
					$declaring->getName(),
					true,
				);
			}
		}

		return null;
	}

	private function constructedFormShape(string $fqcn, int $ctorArgCount): ?FormShape
	{
		return $this->constructedResolver()->resolve($fqcn, $ctorArgCount);
	}

	private function constructedResolver(): ConstructedFormShapeResolver
	{
		return $this->constructedResolver ??= new ConstructedFormShapeResolver(
			$this->catalogReader->getReflectionProvider(),
			$this->constructorFormShapeResolver(),
		);
	}

	private function constructorFormShapeResolver(): ConstructorFormShapeResolver
	{
		return $this->constructorFormShapeResolver ??= new ConstructorFormShapeResolver(
			$this->catalogReader->getReflectionProvider(),
			new NetteEffectiveControlValueTypeResolver(
				$this->catalogReader,
				AnalyzerStackFactory::buildCallees($this->catalogReader, $this->cache, $this->richParser),
				$this->analysedPaths->all(),
			),
			$this->richParser,
			$this->cache,
		);
	}

	private function returnShapeSupport(): ReturnShapeSupport
	{
		return $this->returnShapeSupport ??= new ReturnShapeSupport(
			$this->catalogReader->getReflectionProvider(),
			$this->constructedResolver(),
		);
	}

	private function carriesRebindMarker(FormShape $shape): bool
	{
		return in_array(UnknownReason::REBIND_UNPROVEN, $shape->getUnknown()->getReasons(), true);
	}

	private function hasLostFieldUnknown(FormShape $shape): bool
	{
		return array_intersect(UnknownReason::LOST_FIELD_UNKNOWN_REASONS, $shape->getUnknown()->getReasons()) !== [];
	}

	private function chainReceiverTracker(): LocalVariableClassTracker
	{
		return $this->chainReceiverTracker ??= new LocalVariableClassTracker(
			$this->catalogReader->getReflectionProvider(),
			$this->cache->recorder(),
		);
	}

	private function builderChainTerminalShape(
		MethodCall $call,
		ClassMethod $fn,
		string $ownerFqcn,
		Scope $scope,
		?RecursionCtx $ctx = null
	): ?FormShape
	{
		$receiverClass = $this->chainReceiverTracker()->resolveReceiverClass($call->var, $fn, $ownerFqcn);
		$detection = BuilderChainDetector::detect($call, $scope, $this->cache, $receiverClass);
		if ($detection === null) {
			return null;
		}

		$cached = $detection->getCachedShape();
		if ($cached !== null) {
			return $cached;
		}

		$declaring = $detection->getDeclaringClass();
		$methodName = $detection->getMethodName();
		if ($declaring === null || $methodName === null) {
			return null;
		}

		$located = $this->vendorMethodNode($declaring, $methodName);
		if ($located === null) {
			return null;
		}

		return $this->shapeVendorMethod($located[0], $located[1], $declaring->getName(), $methodName, $scope, $ctx);
	}

	private function usableFormShape(?FormShape $shape): ?FormShape
	{
		if ($shape === null) {
			return null;
		}

		$className = $shape->getClassName();
		if ($className === null) {
			return null;
		}

		return (new ObjectType(NetteContainer::class))
			->isSuperTypeOf(new ObjectType(ltrim($className, '\\')))
			->yes()
		? $shape
		: null;
	}

	private function foldVendorParamMutations(
		ClassMethod $fn,
		FormShape $shape,
		NodeContributionSummaryFactory $factory,
		Scope $scope
	): FormShape
	{
		$stmts = $fn->getStmts() ?? [];
		$finder = new NodeFinder();

		foreach ($finder->findInstanceOf($stmts, Assign::class) as $assign) {
			if (!$assign->var instanceof Variable
				|| !is_string($assign->var->name)
				|| !$assign->expr instanceof MethodCall
				|| !$assign->expr->name instanceof Identifier
				|| $assign->expr->name->toString() !== 'addContainer'
			) {
				continue;
			}

			$nameArg = $assign->expr->getArgs()[0]->value ?? null;
			if (!$nameArg instanceof String_) {
				continue;
			}

			$containerVar = $assign->var->name;
			$componentName = $nameArg->value;
			$existing = $shape->getContainers()[$componentName] ?? null;
			$preferClass = $existing === null ? null : $existing->getClassName();

			foreach ($finder->findInstanceOf($stmts, MethodCall::class) as $call) {
				if (!$call->name instanceof Identifier || $call->isFirstClassCallable()) {
					continue;
				}

				$argIndex = null;
				$i = 0;
				foreach ($call->getArgs() as $arg) {
					if ($arg->value instanceof Variable && $arg->value->name === $containerVar) {
						$argIndex = $i;

						break;
					}

					$i++;
				}

				if ($argIndex === null) {
					continue;
				}

				$contribution = $this->vendorParamContribution($call, $argIndex, $preferClass, $factory, $scope);
				if ($contribution === null) {
					continue;
				}

				$shape = $shape->merge(
					CompositionState::initial()
						->withContainerShape($componentName, $contribution)
						->toFormShape($shape->getClassName()),
				);
			}
		}

		return $shape;
	}

	private function vendorParamContribution(
		MethodCall $call,
		int $argIndex,
		?string $preferClass,
		NodeContributionSummaryFactory $factory,
		Scope $scope
	): ?FormShape
	{
		$methodName = $call->name instanceof Identifier ? $call->name->toString() : null;
		if ($methodName === null) {
			return null;
		}

		$receiverType = $scope->getType($call->var);
		if (!$receiverType->hasMethod($methodName)->yes()) {
			return null;
		}

		$declaring = $receiverType->getMethod($methodName, $scope)->getDeclaringClass();
		$file = $declaring->getFileName();
		if ($file === null
			|| strncmp($file, 'phar://', 7) === 0
			|| !is_file($file)
		) {
			return null;
		}

		$target = ltrim($declaring->getName(), '\\');
		$classMethod = null;
		foreach ($this->richFileClasses($file) as $cls) {
			if (!isset($cls->namespacedName) || $cls->namespacedName->toString() !== $target) {
				continue;
			}

			foreach ($cls->getMethods() as $candidate) {
				if ($candidate->name->toString() === $methodName) {
					$classMethod = $candidate;

					break 2;
				}
			}
		}

		$param = $classMethod === null ? null : ($classMethod->params[$argIndex] ?? null);
		if ($classMethod === null
			|| $param === null
			|| !$param->var instanceof Variable
			|| !is_string($param->var->name)
		) {
			return null;
		}

		$paramClass = $preferClass
		?? ($param->type instanceof Name ? $param->type->toString() : 'Nette\\Forms\\Container');

		return (new FormShapeAnalyzer(
			$factory,
			$this->cache,
			AnalyzerStackFactory::buildCallees($this->catalogReader, $this->cache, $this->richParser),
		))->analyzeContainerParam(
			$param->var->name,
			$classMethod,
			$paramClass,
			(new EnclosingFunctionLikeLocator())->taggedRecords($classMethod, $scope),
			$scope,
			$file,
		);
	}

}
