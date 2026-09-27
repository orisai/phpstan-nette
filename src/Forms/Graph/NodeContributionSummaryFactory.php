<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use Nette\Forms\Container as NetteContainer;
use Nette\Forms\Form;
use OriPhpstan\Nette\Forms\Cache\DependencyRecorder;
use OriPhpstan\Nette\Forms\Catalog\ChoiceItemKeyResolver;
use OriPhpstan\Nette\Forms\Catalog\ControlValueResolution;
use OriPhpstan\Nette\Forms\Catalog\ControlValueTypeResolver;
use OriPhpstan\Nette\Forms\Catalog\FormAddsSpec;
use OriPhpstan\Nette\Forms\Catalog\RuleCastType;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function array_reverse;
use function assert;
use function count;
use function in_array;
use function is_string;
use function spl_object_id;
use function strpos;
use function usort;

final class NodeContributionSummaryFactory
{

	// addProtection() takes no name; Nette registers the control under Form::PROTECTOR_ID.
	private const FIXED_NAME_ADD_METHODS = ['addProtection' => Form::PROTECTOR_ID];

	private ControlValueTypeResolver $catalog;

	private ChoiceItemKeyResolver $choiceItemKeyResolver;

	public function __construct(ControlValueTypeResolver $catalog, ?DependencyRecorder $recorder = null)
	{
		$this->catalog = $catalog;
		$this->choiceItemKeyResolver = new ChoiceItemKeyResolver($catalog->getReflectionProvider(), $recorder);
	}

	public function getReflectionProvider(): ReflectionProvider
	{
		return $this->catalog->getReflectionProvider();
	}

	public function getCatalog(): ControlValueTypeResolver
	{
		return $this->catalog;
	}

	public function isFormDisabler(string $containerClass, string $methodName): bool
	{
		return $this->catalog->isFormDisabler($containerClass, $methodName);
	}

	public function declaresAdds(string $containerClass, string $methodName): bool
	{
		return $this->catalog->declaresAdds($containerClass, $methodName);
	}

	/**
	 * @param array<string, string> $literalNameEnv
	 * @param list<array{tip: Expr, startDepth: int, certainty: Certainty::*}> $extraChains
	 * @param array<string, true> $bodyBoundNames
	 */
	public function fromTaggedNode(
		Node $statement,
		Node $node,
		TaggedNode $tagged,
		Scope $scope,
		?string $trackedName = null,
		?string $trackedClass = null,
		array $literalNameEnv = [],
		array $extraChains = [],
		bool $controlEscaped = false,
		bool $maskVariableNames = false,
		array $bodyBoundNames = []
	): NodeContributionSummary
	{
		$opKind = $tagged->getOperationKind();
		$nodeId = $tagged->getNodeId();

		if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->isFirstClassCallable()) {
			return new NodeContributionSummary(
				$nodeId,
				NodeContributionSummary::OP_ADD,
				null,
				true,
				null,
				[UnknownReason::FIRST_CLASS_CALLABLE],
			);
		}

		if ($opKind === 'removeComponent' && ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)) {
			$arg0 = $node->getArgs()[0]->value ?? null;
			$name = $arg0 !== null
				? $this->removedComponentName($scope, $arg0, $literalNameEnv, $maskVariableNames)
				: null;

			return new NodeContributionSummary(
				$nodeId,
				NodeContributionSummary::OP_REMOVE,
				$name,
				false,
				null,
				$name === null ? [UnknownReason::UNKNOWN_REMOVAL] : [],
			);
		}

		if ($opKind === '$offsetUnset' && $node instanceof Unset_) {
			$name = null;
			foreach ($node->vars as $var) {
				if ($var instanceof ArrayDimFetch && $var->var instanceof Node\Expr\Variable && $var->dim !== null) {
					$name = $this->constantName($scope, $var->dim, $literalNameEnv, $maskVariableNames);

					break;
				}
			}

			return new NodeContributionSummary(
				$nodeId,
				NodeContributionSummary::OP_REMOVE,
				$name,
				false,
				null,
				$name === null ? [UnknownReason::UNKNOWN_REMOVAL] : [],
			);
		}

		if ($opKind === '$offsetSet' && $node instanceof Assign && $node->var instanceof ArrayDimFetch) {
			$dim = $node->var->dim;
			$name = $dim !== null ? $this->constantName($scope, $dim, $literalNameEnv, $maskVariableNames) : null;
			$dynamicName = $name === null;
			$resolution = $this->applyWrappers(
				$statement,
				null,
				$this->isBodyBoundVariable($node->expr, $bodyBoundNames)
					? new ControlValueResolution(ControlValueResolution::KIND_UNKNOWN_TYPE, null, [])
					: $this->catalog->resolveControlType($scope->getType($node->expr)),
				$scope,
				$extraChains,
				$controlEscaped ? Certainty::UNKNOWN : Certainty::HAPPENS,
				$bodyBoundNames,
			);

			return new NodeContributionSummary(
				$nodeId,
				NodeContributionSummary::OP_ADD,
				$name,
				$dynamicName,
				$resolution,
				$dynamicName ? [UnknownReason::DYNAMIC_NAME] : [],
			);
		}

		if ($opKind === 'addComponent' && ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)) {
			$args = $node->getArgs();
			$name = isset($args[1])
				? $this->constantName($scope, $args[1]->value, $literalNameEnv, $maskVariableNames)
				: null;
			$dynamicName = $name === null;
			$resolution = isset($args[0]) && !$this->isBodyBoundVariable($args[0]->value, $bodyBoundNames)
				? $this->catalog->resolveControlType($scope->getType($args[0]->value))
				: new ControlValueResolution(ControlValueResolution::KIND_UNKNOWN_TYPE, null, []);

			return new NodeContributionSummary(
				$nodeId,
				NodeContributionSummary::OP_ADD,
				$name,
				$dynamicName,
				$resolution,
				$dynamicName ? [UnknownReason::DYNAMIC_NAME] : [],
			);
		}

		assert($node instanceof MethodCall || $node instanceof NullsafeMethodCall);
		$args = $node->getArgs();

		$receiverType = $this->receiverType($node, $scope, $trackedName, $trackedClass);
		$trustReceiverReflection = !$this->readsBodyBoundVariable($node->var, $bodyBoundNames);

		// Which ARGUMENT carries the component name is the second thing @form-adds declares, and the
		// only place it is asked is here. Absent the tag, argument 0 stands — the vendor signature
		// and the convention every add* helper follows. The receiver-reflection distrust that gates
		// the class axis gates this one identically: a stale same-named binding would resolve the
		// tag off the wrong method.
		$specs = $trustReceiverReflection ? $this->catalog->addsSpecs($opKind, $receiverType, $scope) : [];

		// A fixed-name add* has no name parameter at all - vendor names the component itself - so
		// argument 0 is not a name to read. Reading it invents a component called after the error
		// message when that message is a literal, and claims a LOST name when it is not.
		$fixedName = $specs === [] ? (self::FIXED_NAME_ADD_METHODS[$opKind] ?? null) : null;

		// The other half of the same question, for the code the walk cannot see: an unannotated
		// registrar declared outside the analysed paths whose body refutes the argument-0 convention.
		// Argument 0 is then not a name to read either, but for the opposite reason to a fixed-name
		// add — it names something, and something else is the component. Reading it would register
		// the label and prove the real component absent, so the name is dropped and the shape opens.
		$conventionRefuted = $specs === []
			&& $fixedName === null
			&& $trustReceiverReflection
			&& $this->catalog->bodyRefutesNameConvention($opKind, $receiverType, $scope);

		$nameArg = $specs !== [] ? $this->specArg($args, $specs[0]) : ($args[0] ?? null);
		$name = $fixedName ?? (!$conventionRefuted && $nameArg !== null
			? $this->constantName($scope, $nameArg->value, $literalNameEnv, $maskVariableNames)
			: null);
		$dynamicName = $fixedName === null && $name === null;
		$resolution = $this->catalog->resolve(
			$opKind,
			$receiverType,
			$node,
			$scope,
			$trustReceiverReflection,
		);

		$additional = $this->additionalAdds(
			$nodeId,
			$specs,
			$args,
			$scope,
			$resolution,
			$literalNameEnv,
			$maskVariableNames,
		);

		$resolution = $this->applyWrappers(
			$statement,
			$node,
			$resolution,
			$scope,
			$extraChains,
			$controlEscaped ? Certainty::UNKNOWN : Certainty::HAPPENS,
			$bodyBoundNames,
		);

		$reasons = $dynamicName ? [UnknownReason::DYNAMIC_NAME] : [];
		if ($conventionRefuted) {
			$reasons[] = UnknownReason::UNANNOTATED_REGISTRAR;
		}

		return new NodeContributionSummary(
			$nodeId,
			NodeContributionSummary::OP_ADD,
			$name,
			$dynamicName,
			$resolution,
			$reasons,
			$additional,
		);
	}

	/**
	 * The registrations a REPEATED @form-adds contributes beyond the first — one call adding several
	 * components under distinct name parameters.
	 *
	 * They are built before applyWrappers() and never receive its chains: a chained `->setRequired()`
	 * applies to the control the call RETURNS, which is at most one of them, so folding the chain
	 * into all of them would state a modifier that never ran. An occurrence that names no class of
	 * its own shares the primary's, the declared return type being the only class such a method has.
	 *
	 * @param list<FormAddsSpec> $specs
	 * @param array<int, Arg> $args
	 * @param array<string, string> $literalNameEnv
	 * @return list<NodeContributionSummary>
	 */
	private function additionalAdds(
		string $nodeId,
		array $specs,
		array $args,
		Scope $scope,
		?ControlValueResolution $primaryResolution,
		array $literalNameEnv,
		bool $maskVariableNames
	): array
	{
		$additional = [];
		foreach ($specs as $index => $spec) {
			if ($index === 0) {
				continue;
			}

			$controlClass = $spec->getControlClass();
			$arg = $this->specArg($args, $spec);
			$name = $arg !== null
				? $this->constantName($scope, $arg->value, $literalNameEnv, $maskVariableNames)
				: null;

			$additional[] = new NodeContributionSummary(
				$nodeId,
				NodeContributionSummary::OP_ADD,
				$name,
				$name === null,
				$controlClass !== null
					? $this->catalog->resolveControlType(new ObjectType($controlClass))
					: $primaryResolution,
				$name === null ? [UnknownReason::DYNAMIC_NAME] : [],
			);
		}

		return $additional;
	}

	/**
	 * The argument a spec's name parameter is bound to, matched by NAME first so a named argument
	 * resolves wherever it sits, then by position. Nothing is guessed: a position filled by a named
	 * argument (the positional ones having run out) or a call spreading an array answers null, and
	 * the caller degrades to an unreadable name.
	 *
	 * @param array<int, Arg> $args
	 */
	private function specArg(array $args, FormAddsSpec $spec): ?Arg
	{
		foreach ($args as $arg) {
			if ($arg->unpack) {
				return null;
			}

			if ($arg->name !== null && $arg->name->toString() === $spec->getParameterName()) {
				return $arg;
			}
		}

		$positional = $args[$spec->getParameterIndex()] ?? null;

		return $positional !== null && $positional->name === null ? $positional : null;
	}

	/**
	 * @param MethodCall|NullsafeMethodCall $node
	 */
	private function receiverType(
		Expr $node,
		Scope $scope,
		?string $trackedName,
		?string $trackedClass
	): Type
	{
		$receiver = $node->var;
		$scopeType = $scope->getType($receiver);

		if (
			$trackedClass !== null
			&& $trackedName !== null
			&& strpos($trackedClass, '|') === false
			&& $receiver instanceof Variable
			&& $receiver->name === $trackedName
		) {
			// On the cross-file on-demand path the caller's scope knows only the form's
			// declared base (e.g. Nette\Forms\Form), missing add* declared on the concrete
			// subclass; prefer the scope-free tracked class when it refines the scope type.
			$trackedType = new ObjectType($trackedClass);
			if (
				!(new ObjectType(NetteContainer::class))->isSuperTypeOf($scopeType)->yes()
				|| $scopeType->isSuperTypeOf($trackedType)->yes()
			) {
				return $trackedType;
			}
		}

		return $scopeType;
	}

	/**
	 * @param array<string, string> $literalNameEnv
	 */
	private function removedComponentName(
		Scope $scope,
		Expr $arg,
		array $literalNameEnv,
		bool $maskVariableNames = false
	): ?string
	{
		if ($arg instanceof ArrayDimFetch && $arg->dim !== null) {
			return $this->constantName($scope, $arg->dim, $literalNameEnv, $maskVariableNames);
		}

		if (
			($arg instanceof MethodCall || $arg instanceof NullsafeMethodCall)
			&& $arg->name instanceof Identifier
			&& in_array($arg->name->toString(), ['getComponent', 'offsetGet'], true)
		) {
			$accessArg = $arg->getArgs()[0]->value ?? null;
			if ($accessArg !== null) {
				return $this->constantName($scope, $accessArg, $literalNameEnv, $maskVariableNames);
			}
		}

		return null;
	}

	/**
	 * @param array<string, string> $literalNameEnv
	 */
	private function constantName(
		Scope $scope,
		Expr $expr,
		array $literalNameEnv = [],
		bool $maskVariableNames = false
	): ?string
	{
		// With $maskVariableNames the scope belongs to the enclosing function, not the
		// closure body being walked, so it must not type any variable-dependent name.
		if (!$maskVariableNames || !$this->containsVariable($expr)) {
			$strings = $scope->getType($expr)->getConstantStrings();
			if (count($strings) === 1) {
				return $strings[0]->getValue();
			}
		}

		if ($expr instanceof Variable && is_string($expr->name) && isset($literalNameEnv[$expr->name])) {
			return $literalNameEnv[$expr->name];
		}

		return null;
	}

	private function containsVariable(Expr $expr): bool
	{
		return (new NodeFinder())->findFirstInstanceOf($expr, Variable::class) !== null;
	}

	/**
	 * @param array<string, true> $bodyBoundNames
	 */
	private function isBodyBoundVariable(Expr $expr, array $bodyBoundNames): bool
	{
		return $expr instanceof Variable && is_string($expr->name) && isset($bodyBoundNames[$expr->name]);
	}

	/**
	 * @param array<string, true> $bodyBoundNames
	 */
	private function readsBodyBoundVariable(?Expr $expr, array $bodyBoundNames): bool
	{
		if ($expr === null || $bodyBoundNames === []) {
			return false;
		}

		foreach ((new NodeFinder())->findInstanceOf($expr, Variable::class) as $variable) {
			if (is_string($variable->name) && isset($bodyBoundNames[$variable->name])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The control was bound to a variable that escaped this scope (passed to a helper, stored,
	 * returned, aliased), so a modifier applied elsewhere could change its value type unseen.
	 * Opening the slot keeps it present but unknown rather than mis-attributing the un-modified
	 * base type.
	 */
	private function openEscapedControl(ControlValueResolution $resolution): ControlValueResolution
	{
		return new ControlValueResolution(
			ControlValueResolution::KIND_UNKNOWN_TYPE,
			null,
			[UnknownReason::FORM_ALIASED],
			$resolution->getControlClass(),
		);
	}

	/**
	 * @param MethodCall|NullsafeMethodCall|null $node null for an offset assignment, which carries
	 *     no inline modifier chain of its own — only $extraChains (its separate-statement modifiers).
	 * @param list<array{tip: Expr, startDepth: int, certainty: Certainty::*}> $extraChains
	 * @param Certainty::* $controlCertainty
	 * @param array<string, true> $bodyBoundNames
	 */
	private function applyWrappers(
		Node $statement,
		?Expr $node,
		ControlValueResolution $resolution,
		Scope $scope,
		array $extraChains = [],
		string $controlCertainty = Certainty::HAPPENS,
		array $bodyBoundNames = []
	): ControlValueResolution
	{
		if ($controlCertainty === Certainty::UNKNOWN && $resolution->getKind() === ControlValueResolution::KIND_VALUE) {
			return $this->openEscapedControl($resolution);
		}

		$readByArgArg = null;
		$readByArgMethod = null;
		$nullable = false;
		$maybeNullable = false;
		$customNullable = false;
		$required = false;
		$disabled = false;
		$explicitOmitted = null;
		$omissionUnknown = false;
		$controlClass = $resolution->getControlClass();
		$itemsArg = null;
		$itemsUseKeys = true;
		$itemsUnknown = false;
		$ruleCast = null;
		$conditionalCastReadTypes = [];

		foreach ($this->collectModifiers($statement, $node, $extraChains) as $modifier) {
			$call = $modifier['call'];
			// A first-class callable in the chain does not run the modifier; it closes over
			// the control — the same escape as an aliased control, so the slot opens.
			if ($call->isFirstClassCallable()) {
				return $this->openEscapedControl($resolution);
			}

			// A rule inside a condition branch, or any modifier applied only on some path,
			// may not run; only a chain that HAPPENS narrows, anything less only widens.
			$conditional = $modifier['certainty'] !== Certainty::HAPPENS;
			$unconditional = $modifier['depth'] === 0 && !$conditional;
			$method = $modifier['method'];
			if ($method === 'setNullable') {
				$arg = $call->getArgs()[0]->value ?? null;
				if ($this->readsBodyBoundVariable($arg, $bodyBoundNames)) {
					// The shared scope cannot type a body-bound argument: nullable-unknown
					// only widens (null added), never applies the non-empty narrowing.
					$maybeNullable = true;

					continue;
				}

				$isNullable = $arg === null || !$scope->getType($arg)->isFalse()->yes();
				if ($conditional) {
					$maybeNullable = $maybeNullable || $isNullable;
				} else {
					$nullable = $isNullable;
				}
			} elseif ($method === 'setRequired' && !$conditional) {
				// Narrowing requires certainty: a field is required unless the argument
				// is `false`, so only treat it as required when the argument is provably
				// not false (a bare call, `true`, or a message string). An unknown
				// condition stays non-required so the filled narrowing remains sound.
				$arg = $call->getArgs()[0]->value ?? null;
				$required = $arg === null
					|| (!$this->readsBodyBoundVariable($arg, $bodyBoundNames) && $scope->getType(
						$arg,
					)->isFalse()->no());
			} elseif (($method === 'setDisabled' || $method === 'setOmitted') && !$conditional) {
				// Nette's isOmitted() = omitted ?? disabled: setOmitted(bool) is explicit, while
				// setDisabled(bool) only omits when omitted was never set. Either way the control
				// drops out of getValues() (it stays reachable as a component).
				$arg = $call->getArgs()[0]->value ?? null;
				if ($this->readsBodyBoundVariable($arg, $bodyBoundNames)) {
					$omissionUnknown = true;

					continue;
				}

				$on = $arg === null || $scope->getType($arg)->isFalse()->no();
				if ($method === 'setOmitted') {
					$explicitOmitted = $on;
				} else {
					$disabled = $on;
				}
			} elseif ($method === 'setItems' && !$conditional) {
				$useKeysArg = $call->getArgs()[1]->value ?? null;
				if ($this->readsBodyBoundVariable($useKeysArg, $bodyBoundNames)) {
					$itemsUnknown = true;

					continue;
				}

				$itemsArg = $call->getArgs()[0]->value ?? null;
				$itemsUseKeys = $useKeysArg === null || !$scope->getType($useKeysArg)->isFalse()->yes();
			} elseif ($method === 'addRule') {
				$cast = $this->castRuleType($call);
				if ($cast === null) {
					continue;
				}

				if ($unconditional) {
					$ruleCast = $cast;
				} else {
					$conditionalCastReadTypes[] = TypeCombinator::removeNull($cast->getReadType());
				}
			} elseif ($controlClass !== null && !$conditional) {
				if ($this->catalog->isReadByArgModifier($controlClass, $method)) {
					$readByArgArg = $call->getArgs()[0]->value ?? null;
					$readByArgMethod = $method;
				}

				$effect = $this->catalog->modifierEffect($controlClass, $method);
				if ($effect === ControlAnnotationValueTypeReader::MODIFIER_NULLABLE) {
					$customNullable = true;
				} elseif ($effect === ControlAnnotationValueTypeReader::MODIFIER_REQUIRED) {
					$required = true;
				}
			}
		}

		if ($readByArgMethod !== null && $controlClass !== null) {
			$resolution = $this->applyReadByArg($resolution, $controlClass, $readByArgMethod, $readByArgArg);
		}

		if (!$itemsUnknown) {
			$resolution = $this->applyChoiceNarrowing(
				$resolution,
				$node,
				$itemsArg,
				$itemsUseKeys,
				$scope,
			);
		}

		if ($ruleCast !== null) {
			$resolution = $this->applyRuleCast($resolution, $ruleCast);
		}

		$appliesNullable = $customNullable
			|| (
				$nullable
				&& in_array(
					$resolution->getAcceptedSetSpec(),
					['text', 'integer', 'float', 'hidden'],
					true,
				)
			);

		if ($appliesNullable) {
			$resolution = ControlValueResolution::applyNullable($resolution);
		} elseif ($ruleCast !== null) {
			$resolution = $this->applyRuleCastEmptyString($resolution);
		}

		$resolution = $this->applyConditionalCasts($resolution, $conditionalCastReadTypes);

		if (!$appliesNullable && $maybeNullable) {
			$resolution = $this->applyMaybeNullable($resolution);
		}

		if ($explicitOmitted === true || ($explicitOmitted === null && $disabled)) {
			$resolution = $resolution->withOmitted();
		} elseif ($explicitOmitted === null && $omissionUnknown) {
			// Whether the control drops out of getValues() is decided by a value the shared
			// scope cannot see: keep the resolved control type but mark omission UNKNOWN, so the
			// values projection widens the field to nullable rather than dropping the type. An
			// explicit setOmitted(false) (=== false) forces it in, so this only fires when no
			// definite omission decision was made.
			$resolution = $resolution->withOmissionUnknown();
		}

		return $required ? $resolution->withRequired() : $resolution;
	}

	private function applyMaybeNullable(ControlValueResolution $resolution): ControlValueResolution
	{
		// setNullable applied only on some path: the value is null when that path ran and
		// emptied the control, otherwise the plain value (an empty non-nullable text keeps '').
		// Unlike an unconditional setNullable, the non-empty narrowing does not hold, so null
		// is simply added to the value type.
		$valueType = $resolution->getValueType();
		if (
			$resolution->getKind() !== ControlValueResolution::KIND_VALUE
			|| $valueType === null
			|| !in_array($resolution->getAcceptedSetSpec(), ['text', 'integer', 'float', 'hidden'], true)
		) {
			return $resolution;
		}

		return $resolution->withValueType(TypeCombinator::union($valueType, new NullType()));
	}

	/**
	 * @param list<Type> $readTypes
	 */
	private function applyConditionalCasts(ControlValueResolution $resolution, array $readTypes): ControlValueResolution
	{
		// A conditional casting rule runs only when its condition holds, so after
		// validation the value is the base type (condition false) OR the cast type
		// (condition true). Widen the read type by the union of the cast read types
		// without touching the write spec, which a conditional rule does not constrain.
		if ($readTypes === [] || $resolution->getKind() !== ControlValueResolution::KIND_VALUE) {
			return $resolution;
		}

		$valueType = $resolution->getValueType();
		if ($valueType === null) {
			return $resolution;
		}

		return $resolution->withValueType(TypeCombinator::union($valueType, ...$readTypes));
	}

	/**
	 * @param MethodCall|NullsafeMethodCall|null $node
	 */
	private function applyChoiceNarrowing(
		ControlValueResolution $resolution,
		?Expr $node,
		?Expr $setItemsArg,
		bool $useKeys,
		Scope $scope
	): ControlValueResolution
	{
		$controlClass = $resolution->getControlClass();
		if ($controlClass === null || $resolution->getKind() !== ControlValueResolution::KIND_VALUE) {
			return $resolution;
		}

		$model = $this->catalog->choiceModel($controlClass);
		if ($model === null) {
			return $resolution;
		}

		$itemsArg = $setItemsArg ?? ($node !== null ? ($node->getArgs()[2]->value ?? null) : null);
		$keys = $itemsArg !== null
			? $this->choiceItemKeyResolver->resolveKeyUnion($itemsArg, $useKeys, $scope->getClassReflection())
			: null;

		$extra = $model->getExtra();

		// A static item set narrows the value to its literal keys (plus an open control's
		// extra type); a dynamic or absent set leaves the value at the declared key domain.
		$known = $keys ?? $model->getKeyDomain();
		$element = $extra !== null ? TypeCombinator::union($known, $extra) : $known;

		if ($model->isMulti()) {
			$valueType = TypeCombinator::intersect(
				new ArrayType(new IntegerType(), $element),
				new AccessoryArrayListType(),
			);
		} else {
			// A native (closed) choice control's getValue returns null for an unselected or
			// unknown value, so the single key union always admits null. An open control
			// declares any non-key possibility (including null) through its extra type.
			$valueType = $extra !== null ? $element : TypeCombinator::union($element, new NullType());
		}

		return $resolution->withValueType($valueType);
	}

	/**
	 * @param MethodCall|NullsafeMethodCall $call
	 */
	private function castRuleType(Expr $call): ?RuleCastType
	{
		$arg = $call->getArgs()[0]->value ?? null;
		$ruleIdentifier = $arg !== null ? $this->resolveArgLiteral($arg) : null;
		if ($ruleIdentifier === null) {
			return null;
		}

		return $this->catalog->ruleCastType($ruleIdentifier);
	}

	/**
	 * Every modifier/rule call that applies to the control, in source order, each paired
	 * with its condition depth (0 = unconditional, > 0 = inside that many open
	 * addCondition/addConditionOn branches). The inline chain of the add-call is folded
	 * together with the separate-statement and Rules-variable chains the analyzer resolved
	 * to the same control, so a modifier applied apart from the add-call is honoured exactly
	 * as if it had been chained onto it.
	 *
	 * @param MethodCall|NullsafeMethodCall $node
	 * @param list<array{tip: Expr, startDepth: int, certainty: Certainty::*}> $extraChains
	 * @return list<array{call: MethodCall|NullsafeMethodCall, depth: int, certainty: Certainty::*, method: string}>
	 */
	private function collectModifiers(Node $statement, ?Expr $node, array $extraChains): array
	{
		$modifiers = [];
		$inlineTip = $this->inlineChainTip($statement, $node);
		if ($inlineTip !== null) {
			foreach ($this->collectChainModifiers($inlineTip, $node, 0, Certainty::HAPPENS) as $modifier) {
				$modifiers[] = $modifier;
			}
		}

		foreach ($extraChains as $chain) {
			foreach ($this->collectChainModifiers(
				$chain['tip'],
				null,
				$chain['startDepth'],
				$chain['certainty'],
			) as $modifier) {
				$modifiers[] = $modifier;
			}
		}

		// Execution order: a separate statement runs after an earlier one (greater start),
		// and within one chain the receiver runs before the call wrapping it. Chained calls
		// share a start position (the leftmost receiver token), so the end position — larger
		// for the outer call — orders them root-to-tip.
		usort(
			$modifiers,
			static fn (array $a, array $b): int => [$a['call']->getStartFilePos(), $a['call']->getEndFilePos()]
				<=> [$b['call']->getStartFilePos(), $b['call']->getEndFilePos()],
		);

		return $modifiers;
	}

	/**
	 * The outermost method call of the add-call $node's inline chain within $statement: the
	 * single call whose receiver chain bottoms out at $node and which is itself nobody's
	 * receiver. Returns null when $node ends the statement with no chained modifiers.
	 *
	 * @return MethodCall|NullsafeMethodCall|null
	 */
	private function inlineChainTip(Node $statement, ?Expr $node): ?Expr
	{
		if ($node === null) {
			return null;
		}

		$finder = new NodeFinder();
		$receivers = [];
		$onChain = [];
		foreach ($finder->find(
			$statement,
			static fn (Node $n): bool => $n instanceof MethodCall || $n instanceof NullsafeMethodCall,
		) as $call) {
			assert($call instanceof MethodCall || $call instanceof NullsafeMethodCall);
			if (!$this->chainReaches($call, $node)) {
				continue;
			}

			$onChain[spl_object_id($call)] = $call;
			$receivers[spl_object_id($call->var)] = true;
		}

		foreach ($onChain as $id => $call) {
			if (!isset($receivers[$id])) {
				return $call;
			}
		}

		return null;
	}

	/**
	 * @param MethodCall|NullsafeMethodCall $call
	 */
	private function chainReaches(Expr $call, Expr $node): bool
	{
		if ($call === $node) {
			return false;
		}

		$cursor = $call->var;
		while ($cursor instanceof MethodCall || $cursor instanceof NullsafeMethodCall) {
			if ($cursor === $node) {
				return true;
			}

			$cursor = $cursor->var;
		}

		return $cursor === $node;
	}

	/**
	 * Walks the spine of a method-call chain from its outermost call $tip down to (but
	 * excluding) $stopNode — the add-call for the inline chain, or null for a standalone
	 * statement whose root is the control/Rules variable — recording each call with the
	 * condition depth of its receiver. Depth starts at $startDepth (the depth of the chain's
	 * root) and each addCondition/addConditionOn opens a branch (+1) while endCondition
	 * closes one (-1); a rule's depth is therefore the depth at which it is registered.
	 *
	 * @param Certainty::* $certainty
	 * @return list<array{call: MethodCall|NullsafeMethodCall, depth: int, certainty: Certainty::*, method: string}>
	 */
	private function collectChainModifiers(Expr $tip, ?Expr $stopNode, int $startDepth, string $certainty): array
	{
		$depth = $startDepth;
		$modifiers = [];
		foreach (array_reverse(MethodChainSpine::calls($tip, $stopNode)) as $call) {
			if (!$call->name instanceof Identifier) {
				continue;
			}

			$modifiers[] = ['call' => $call, 'depth' => $depth, 'certainty' => $certainty, 'method' => $call->name->toString()];
			$depth += MethodChainSpine::conditionTransition($call->name->toString());
		}

		return $modifiers;
	}

	private function applyRuleCast(ControlValueResolution $resolution, RuleCastType $cast): ControlValueResolution
	{
		if ($resolution->getKind() !== ControlValueResolution::KIND_VALUE) {
			return $resolution;
		}

		return $resolution
			->withValueType($cast->getReadType())
			->withAcceptedSetSpec($cast->getWriteSetSpec());
	}

	private function applyRuleCastEmptyString(ControlValueResolution $resolution): ControlValueResolution
	{
		// A casting rule governs only the filled value; an empty submission skips the
		// rule (Rules::validate empty-optional skip) and a non-nullable control returns
		// '' for that empty value, so the read type is the filled cast type plus ''.
		$valueType = $resolution->getValueType();
		if ($resolution->getKind() !== ControlValueResolution::KIND_VALUE || $valueType === null) {
			return $resolution;
		}

		return $resolution->withValueType(TypeCombinator::union($valueType, new ConstantStringType('')));
	}

	private function applyReadByArg(
		ControlValueResolution $resolution,
		string $controlClass,
		string $methodName,
		?Node $arg
	): ControlValueResolution
	{
		if ($resolution->getKind() !== ControlValueResolution::KIND_VALUE) {
			return $resolution;
		}

		$base = $this->catalog->readTypeByArg(
			$controlClass,
			$methodName,
			$this->resolveArgLiteral($arg),
		);
		if ($base === null) {
			return $resolution;
		}

		return $resolution->withValueType(TypeCombinator::union($base, new NullType()));
	}

	private function resolveArgLiteral(?Node $arg): ?string
	{
		if ($arg instanceof String_) {
			return $arg->value;
		}

		if (!$arg instanceof ClassConstFetch || !$arg->class instanceof Name || !$arg->name instanceof Identifier) {
			return null;
		}

		$className = $arg->class->toString();
		$constName = $arg->name->toString();
		$reflectionProvider = $this->getReflectionProvider();
		if (!$reflectionProvider->hasClass($className)) {
			return null;
		}

		$class = $reflectionProvider->getClass($className);
		if (!$class->hasConstant($constName)) {
			return null;
		}

		$strings = $class->getConstant($constName)->getValueType()->getConstantStrings();

		return count($strings) === 1 ? $strings[0]->getValue() : null;
	}

}
