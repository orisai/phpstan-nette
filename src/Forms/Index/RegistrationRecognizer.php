<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Index;

use OriPhpstan\Nette\Forms\Graph\ClosureScope;
use OriPhpstan\Nette\Forms\Graph\RegisteringMethodName;
use OriPhpstan\Nette\Forms\Inference\EventPropertyName;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use function array_keys;
use function count;
use function in_array;
use function is_string;
use function spl_object_id;
use function strncmp;

final class RegistrationRecognizer
{

	/**
	 * @return list<RegistrationFact>
	 */
	public function eventRegistrations(ClassMethod $method, string $className, string $returnedVarName): array
	{
		$stmts = $method->getStmts() ?? [];
		$registeringMethod = $method->name->toString();
		$closureInnerIds = ClosureScope::innerNodeIds($stmts);

		$facts = [];
		foreach ((new NodeFinder())->findInstanceOf($stmts, Assign::class) as $assign) {
			if (isset($closureInnerIds[spl_object_id($assign)])) {
				continue;
			}

			$lhs = $assign->var;
			if ($lhs instanceof ArrayDimFetch) {
				$lhs = $lhs->var;
			}

			if (!$lhs instanceof PropertyFetch || !$lhs->name instanceof Identifier) {
				continue;
			}

			if (!$lhs->var instanceof Variable || $lhs->var->name !== $returnedVarName) {
				continue;
			}

			$propName = $lhs->name->toString();
			if (!EventPropertyName::matches($propName)) {
				continue;
			}

			if (!$assign->expr instanceof Array_ || count($assign->expr->items) !== 2) {
				continue;
			}

			$itemThis = $assign->expr->items[0];
			$itemMethod = $assign->expr->items[1];
			if (
				!$itemThis->value instanceof Variable
				|| $itemThis->value->name !== 'this'
				|| !$itemMethod->value instanceof String_
			) {
				continue;
			}

			$facts[] = RegistrationFact::eventHandlerRegistration(
				$className,
				$registeringMethod,
				$returnedVarName,
				$className,
				$itemMethod->value->value,
				$propName,
			);
		}

		return $facts;
	}

	/**
	 * Array-callable event handlers assigned on the variable a createComponent* method returns. The
	 * createComponent gate and the receiver-is-returned gate are applied scope-free (no reflection), so
	 * the fold recognizes the same handler-param registrations a scope-bound walk would.
	 *
	 * @return list<RegistrationFact>
	 */
	public function arrayCallableRegistrations(ClassMethod $method, string $className): array
	{
		$registeringMethod = $method->name->toString();
		if (strncmp($registeringMethod, 'createComponent', 15) !== 0) {
			return [];
		}

		$stmts = $method->getStmts() ?? [];
		$closureInnerIds = ClosureScope::innerNodeIds($stmts);
		$returnedVars = $this->returnedVariableNamesForStmts($stmts, $closureInnerIds);

		$facts = [];
		foreach ((new NodeFinder())->findInstanceOf($stmts, Assign::class) as $assign) {
			if (isset($closureInnerIds[spl_object_id($assign)])) {
				continue;
			}

			$lhs = $assign->var;
			if ($lhs instanceof ArrayDimFetch) {
				$lhs = $lhs->var;
			}

			if (!$lhs instanceof PropertyFetch || !$lhs->name instanceof Identifier) {
				continue;
			}

			if (!$lhs->var instanceof Variable || !is_string($lhs->var->name)) {
				continue;
			}

			$receiverVar = $lhs->var->name;
			$propName = $lhs->name->toString();
			if (!EventPropertyName::matches($propName)) {
				continue;
			}

			if (!$assign->expr instanceof Array_ || count($assign->expr->items) !== 2) {
				continue;
			}

			$itemThis = $assign->expr->items[0];
			$itemMethod = $assign->expr->items[1];
			if (
				!$itemThis->value instanceof Variable
				|| $itemThis->value->name !== 'this'
				|| !$itemMethod->value instanceof String_
			) {
				continue;
			}

			if (!isset($returnedVars[$receiverVar])) {
				continue;
			}

			$facts[] = RegistrationFact::eventHandlerRegistration(
				$className,
				$registeringMethod,
				$receiverVar,
				$className,
				$itemMethod->value->value,
				$propName,
			);
		}

		return $facts;
	}

	/**
	 * The pass-through grammar: a form/container-typed parameter of the method passed as an argument
	 * of a `$this-><literalName>(...)` call. Type gating on the parameter is the coarse syntactic
	 * "declared class type" test; the precise Form/Container super-type gate, the analysed-paths
	 * containment, and calleeClass resolution for non-`$this` receivers are demand-time shape
	 * computation left to IndexShapeResolver.
	 *
	 * @return list<RegistrationFact>
	 */
	public function passThroughEdges(ClassMethod $method, string $className): array
	{
		$stmts = $method->getStmts() ?? [];
		$callerMethod = $method->name->toString();
		$closureInnerIds = ClosureScope::innerNodeIds($stmts);
		$paramIndexes = $this->classTypedParamIndexes($method);

		$edges = [];
		foreach ((new NodeFinder())->findInstanceOf($stmts, MethodCall::class) as $call) {
			if (isset($closureInnerIds[spl_object_id($call)])) {
				continue;
			}

			if (!$call->name instanceof Identifier) {
				continue;
			}

			if (!$call->var instanceof Variable || $call->var->name !== 'this') {
				continue;
			}

			// A first-class callable passes no argument here; a later invocation with the
			// form escapes through the walk's argument-pass detection instead.
			if ($call->isFirstClassCallable()) {
				continue;
			}

			$calleeMethod = $call->name->toString();
			foreach ($call->getArgs() as $argIdx => $arg) {
				$value = $arg->value;

				// A $this[<literal>] class-component read passed as an argument (the store's
				// extractThisOffsetShape): the string origin is the component name, resolved at demand
				// time through forClassComponent. Literal-name-only, mirroring the collector's blind spot
				// for const-dim / dynamic offsets.
				if (
					$value instanceof ArrayDimFetch
					&& $value->var instanceof Variable
					&& $value->var->name === 'this'
					&& $value->dim instanceof String_
				) {
					$edges[] = RegistrationFact::paramPassThrough(
						$className,
						$callerMethod,
						$value->dim->value,
						$className,
						$calleeMethod,
						(int) $argIdx,
					);

					continue;
				}

				if (!$value instanceof Variable || !is_string($value->name) || $value->name === 'this') {
					continue;
				}

				if (!isset($paramIndexes[$value->name])) {
					continue;
				}

				$edges[] = RegistrationFact::paramPassThrough(
					$className,
					$callerMethod,
					$paramIndexes[$value->name],
					$className,
					$calleeMethod,
					(int) $argIdx,
				);
			}
		}

		return $edges;
	}

	/**
	 * Component-tree mutations reached through a component ACCESS rather than through the builder
	 * the shape resolver walks: `$x['name']->addText(...)`, `$x->getComponent('name')->addText(...)`,
	 * `$x['name']['child'] = $control`, `$control->setParent($x['name'])`, and the local aliases of
	 * any of those. A component so mutated has members its createComponent* builder never
	 * mentions, and nothing in its shape records that — this fact is what lets a consumer refuse to
	 * treat the shape's name set as complete.
	 *
	 * Three spellings reach a component without naming it on the mutating statement, and each records
	 * a fact of its own rather than staying invisible: a chain of local copies (closed transitively
	 * below), a hand-over to another method (handedOverAccesses), and a getter hop
	 * (indirectAccessRoot, whose fact names a wildcard component of the owner it CAN name).
	 *
	 * Closure bodies are deliberately NOT excluded: a mutation inside an event handler is still a
	 * mutation. Syntactic and receiver-blind, as the whole fold is — the owner is named only when
	 * the access root is `$this`, otherwise every class is a candidate.
	 *
	 * @param array<string, true> $selfDispatchProperties property names of the enclosing class, which
	 *                                                    `$this->name(...)` invokes as a callable
	 * @return list<RegistrationFact>
	 */
	public function componentMutations(ClassMethod $method, string $className, array $selfDispatchProperties): array
	{
		$stmts = $method->getStmts() ?? [];
		$finder = new NodeFinder();

		$aliases = $this->localAliases($finder, $stmts);

		$facts = [];
		foreach ($finder->findInstanceOf($stmts, Assign::class) as $assign) {
			// `$this['a']['b']['x'] = $control` is an offsetSet on the component the last-but-one
			// offset names, so the mutated component is the assignment target's own receiver.
			if (!$assign->var instanceof ArrayDimFetch) {
				continue;
			}

			$target = $this->componentAccessChain($assign->var->var);
			if ($target !== null) {
				$facts[] = $this->mutationFact($className, $target);
			}
		}

		foreach ($finder->findInstanceOf($stmts, MethodCall::class) as $call) {
			if (!$call->name instanceof Identifier || $call->isFirstClassCallable()) {
				continue;
			}

			$name = $call->name->toString();

			// setParent's receiver is the CHILD being attached; the mutated component is its new
			// parent, which the first argument names.
			if ($name === 'setParent') {
				$args = $call->getArgs();
				$parent = isset($args[0]) ? $this->componentAccessChain($args[0]->value) : null;
				if ($parent !== null) {
					$facts[] = $this->mutationFact($className, $parent);
				}

				continue;
			}

			if (!self::isTreeMutatingMethod($name)) {
				continue;
			}

			$receiver = $this->componentAccessChain($call->var);
			if ($receiver !== null) {
				$facts[] = $this->mutationFact($className, $receiver);

				continue;
			}

			if ($call->var instanceof Variable && is_string($call->var->name)) {
				foreach ($aliases[$call->var->name] ?? [] as $aliased) {
					$facts[] = $this->mutationFact($className, $aliased);
				}

				continue;
			}

			$indirect = $this->indirectAccessRoot($call->var);
			if ($indirect !== null) {
				$facts[] = $this->mutationFact($className, $indirect);
			}
		}

		foreach ($this->handedOverAccesses($finder, $stmts, $selfDispatchProperties) as $handOver) {
			$facts[] = $this->mutationFact($className, $handOver['access'], $handOver['callee']);
		}

		return $facts;
	}

	/**
	 * What a method does to the components it is GIVEN: mutates a parameter itself, or hands one on to
	 * a further callee. The fold joins these against the hand-over sites of componentMutations, so a
	 * component passed to a method that only reads it stays closed while one passed to a registering
	 * helper does not — the difference between a gate that costs three pairs and one that costs half
	 * the corpus.
	 *
	 * @return list<RegistrationFact>
	 */
	public function paramMutations(ClassMethod $method, string $className): array
	{
		$methodName = $method->name->toString();

		$facts = [];
		foreach ($this->paramMutationSites($method) as [$idx, $callee]) {
			$facts[] = RegistrationFact::paramMutation($className, $methodName, $idx, $callee);
		}

		return $facts;
	}

	/**
	 * The same answer for a FREE FUNCTION. The callee key is a bare name, so a function is looked up
	 * exactly as a method of that name is — and without this, a hand-over to a project-declared global
	 * helper is dropped for want of a callee fact, which is the one direction where "no fact" means
	 * "assumed clean". Recorded under both the declared short name and the namespaced one, since a
	 * call site may spell either and neither is resolvable at fold time.
	 *
	 * @return list<RegistrationFact>
	 */
	public function freeFunctionParamMutations(Function_ $function): array
	{
		$names = [$function->name->toString()];
		if (isset($function->namespacedName)) {
			$namespaced = $function->namespacedName->toString();
			if ($namespaced !== $names[0]) {
				$names[] = $namespaced;
			}
		}

		$facts = [];
		foreach ($this->paramMutationSites($function) as [$idx, $callee]) {
			foreach ($names as $name) {
				$facts[] = RegistrationFact::paramMutation('', $name, $idx, $callee);
			}
		}

		return $facts;
	}

	/**
	 * @return list<array{int, string|null}> parameter index and the callee key it was handed on to
	 */
	private function paramMutationSites(FunctionLike $function): array
	{
		$components = [];
		$arrays = [];
		foreach ($function->getParams() as $idx => $param) {
			if (!$param->var instanceof Variable || !is_string($param->var->name)) {
				continue;
			}

			if (self::mayBeComponentParam($param)) {
				$components[$param->var->name] = (int) $idx;
			}

			// An array parameter is never a component itself, but its ELEMENTS are exactly what a
			// literal-array hand-over gives it. An undeclared parameter is both.
			if (self::mayBeComponentArrayParam($param)) {
				$arrays[$param->var->name] = (int) $idx;
			}
		}

		if ($components === [] && $arrays === []) {
			return [];
		}

		$stmts = $function->getStmts() ?? [];
		$finder = new NodeFinder();
		$bindings = $this->paramBindings($finder, $stmts, $components, $arrays);

		$sites = [];
		foreach ($finder->findInstanceOf($stmts, Assign::class) as $assign) {
			if (!$assign->var instanceof ArrayDimFetch) {
				continue;
			}

			foreach ($this->boundParamIndexes($assign->var->var, $bindings, $arrays) as $idx) {
				$sites[] = [$idx, null];
			}
		}

		foreach ($finder->findInstanceOf($stmts, MethodCall::class) as $call) {
			if (!$call->name instanceof Identifier || $call->isFirstClassCallable()) {
				continue;
			}

			$name = $call->name->toString();
			if ($name === 'setParent') {
				$args = $call->getArgs();
				$target = isset($args[0]) ? $this->boundParamIndexes($args[0]->value, $bindings, $arrays) : [];
				foreach ($target as $idx) {
					$sites[] = [$idx, null];
				}

				continue;
			}

			if (!self::isTreeMutatingMethod($name)) {
				continue;
			}

			foreach ($this->boundParamIndexes($call->var, $bindings, $arrays) as $idx) {
				$sites[] = [$idx, null];
			}
		}

		foreach ($finder->findInstanceOf($stmts, CallLike::class) as $call) {
			if ($call->isFirstClassCallable()) {
				continue;
			}

			$callee = self::calleeKeyOf($call);
			foreach ($call->getArgs() as $argIdx => $arg) {
				foreach (self::argumentComponentExprs($arg->value) as $expr) {
					foreach ($this->boundParamIndexes($expr, $bindings, $arrays) as $idx) {
						$sites[] = [
							$idx,
							$callee === null ? null : RegistrationFact::calleeKey($callee, (int) $argIdx),
						];
					}
				}
			}
		}

		return $sites;
	}

	/**
	 * The variables that may hold a component the function was GIVEN: the component parameters
	 * themselves, every local copy of one (closed transitively, exactly as localAliases closes the
	 * access side), and every variable bound to an ELEMENT of an array parameter — by offset or by
	 * foreach. Without the copy closure a callee that assigns its parameter to a local before
	 * registering reads as non-mutating, which leaves the handed-over component wrongly closed.
	 *
	 * @param array<Node> $stmts
	 * @param array<string, int> $components
	 * @param array<string, int> $arrays
	 * @return array<string, array<int, true>>
	 */
	private function paramBindings(NodeFinder $finder, array $stmts, array $components, array $arrays): array
	{
		$bindings = [];
		foreach ($components as $name => $idx) {
			$bindings[$name][$idx] = true;
		}

		$copies = [];
		foreach ($finder->find(
			$stmts,
			static fn (Node $node): bool => $node instanceof Assign || $node instanceof Foreach_,
		) as $node) {
			if ($node instanceof Foreach_) {
				if ($node->valueVar instanceof Variable && is_string($node->valueVar->name)) {
					foreach ($this->arrayIndexesOf($node->expr, $arrays) as $idx) {
						$bindings[$node->valueVar->name][$idx] = true;
					}
				}

				continue;
			}

			if (!$node instanceof Assign || !$node->var instanceof Variable || !is_string($node->var->name)) {
				continue;
			}

			if ($node->expr instanceof Variable && is_string($node->expr->name)) {
				$copies[$node->var->name][] = $node->expr->name;

				continue;
			}

			if ($node->expr instanceof ArrayDimFetch) {
				foreach ($this->arrayIndexesOf($node->expr->var, $arrays) as $idx) {
					$bindings[$node->var->name][$idx] = true;
				}
			}
		}

		for ($round = count($copies); $round > 0; $round--) {
			$changed = false;
			foreach ($copies as $target => $sources) {
				foreach ($sources as $source) {
					foreach ($bindings[$source] ?? [] as $idx => $bound) {
						if (!isset($bindings[$target][$idx])) {
							$bindings[$target][$idx] = true;
							$changed = true;
						}
					}
				}
			}

			if (!$changed) {
				break;
			}
		}

		return $bindings;
	}

	/**
	 * @param array<string, array<int, true>> $bindings
	 * @param array<string, int> $arrays
	 * @return list<int>
	 */
	private function boundParamIndexes(Expr $expr, array $bindings, array $arrays): array
	{
		if ($expr instanceof ArrayDimFetch) {
			return $this->arrayIndexesOf($expr->var, $arrays);
		}

		if (!$expr instanceof Variable || !is_string($expr->name)) {
			return [];
		}

		return array_keys($bindings[$expr->name] ?? []);
	}

	/**
	 * @param array<string, int> $arrays
	 * @return list<int>
	 */
	private function arrayIndexesOf(Expr $expr, array $arrays): array
	{
		if (!$expr instanceof Variable || !is_string($expr->name) || !isset($arrays[$expr->name])) {
			return [];
		}

		return [$arrays[$expr->name]];
	}

	/**
	 * Local variables bound to a component access, transitively: `$f = $ctrl['form']` binds $f, and
	 * `$g = $f` binds $g to the same component. The copy edges are closed by iteration bounded at the
	 * edge count, which also terminates a cyclic pair of assignments; a variable rebound to several
	 * accesses keeps all of them, since the fold is flow-insensitive and every binding is a candidate.
	 *
	 * @param array<Node> $stmts
	 * @return array<string, array<string, array{scope: string, owner: string, name: string}>>
	 */
	private function localAliases(NodeFinder $finder, array $stmts): array
	{
		$aliases = [];
		$copies = [];
		foreach ($finder->findInstanceOf($stmts, Assign::class) as $assign) {
			if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
				continue;
			}

			$aliased = $this->componentAccessChain($assign->expr);
			if ($aliased !== null) {
				$aliases[$assign->var->name][self::accessKey($aliased)] = $aliased;

				continue;
			}

			if ($assign->expr instanceof Variable && is_string($assign->expr->name)) {
				$copies[$assign->var->name][] = $assign->expr->name;
			}
		}

		for ($round = count($copies); $round > 0; $round--) {
			$changed = false;
			foreach ($copies as $target => $sources) {
				foreach ($sources as $source) {
					foreach ($aliases[$source] ?? [] as $key => $access) {
						if (!isset($aliases[$target][$key])) {
							$aliases[$target][$key] = $access;
							$changed = true;
						}
					}
				}
			}

			if (!$changed) {
				break;
			}
		}

		return $aliases;
	}

	/**
	 * A component of THIS class handed to another method: `$this['form']`, `$this['ctrl']['form']` or
	 * `$this->getComponent('form')` passed as an argument. Neither the builder walk nor this fold
	 * follows the callee's body from here, so the hand-over is recorded as a mutation CONDITIONAL on
	 * the callee key — paramMutations answers, universe-wide, whether any method of that name mutates
	 * that argument. A callee the syntax cannot name (a constructor, a dynamic method) is
	 * unconditional, and so is one it names but that no body can answer for: `$this->onReach($form)`
	 * invokes a PROPERTY holding subscriber callables, where the coarse name key would otherwise be
	 * decided against an unrelated method of the same name. Only a `$this`-rooted access counts:
	 * rooted in a local, an argument position is syntactically indistinguishable from a plain array
	 * read ($row['name']), where an add*() receiver would have been the evidence that the offset names
	 * a component.
	 *
	 * @param array<Node> $stmts
	 * @param array<string, true> $selfDispatchProperties
	 * @return list<array{access: array{scope: string, owner: string, name: string}, callee: string|null}>
	 */
	private function handedOverAccesses(NodeFinder $finder, array $stmts, array $selfDispatchProperties): array
	{
		$handOvers = [];
		foreach ($finder->findInstanceOf($stmts, CallLike::class) as $call) {
			if ($call->isFirstClassCallable()) {
				continue;
			}

			$callee = $this->isSelfPropertyDispatch($call, $selfDispatchProperties)
				? null
				: self::calleeKeyOf($call);

			foreach ($call->getArgs() as $argIdx => $arg) {
				foreach (self::argumentComponentExprs($arg->value) as $expr) {
					$access = $this->componentAccessChain($expr);
					if ($access === null || $access['scope'] === RegistrationFact::OWNER_UNKNOWN) {
						continue;
					}

					$handOvers[] = [
						'access' => $access,
						'callee' => $callee === null ? null : RegistrationFact::calleeKey($callee, (int) $argIdx),
					];
				}
			}
		}

		return $handOvers;
	}

	/**
	 * The expressions an argument may hand a component over as: the argument itself, and — because a
	 * literal array is the one wrapper that keeps every element addressable by the callee — each
	 * element of a literal array argument. The callee key stays the argument's own position, so the
	 * parameter side answers for it through the element routes paramMutationSites follows.
	 *
	 * @return list<Expr>
	 */
	private static function argumentComponentExprs(Expr $value): array
	{
		if (!$value instanceof Array_) {
			return [$value];
		}

		$exprs = [];
		foreach ($value->items as $item) {
			$exprs[] = $item->value;
		}

		return $exprs;
	}

	/**
	 * @param array<string, true> $selfDispatchProperties
	 */
	private function isSelfPropertyDispatch(CallLike $call, array $selfDispatchProperties): bool
	{
		return $call instanceof MethodCall
			&& $this->isThis($call->var)
			&& $call->name instanceof Identifier
			&& isset($selfDispatchProperties[$call->name->toString()]);
	}

	// A scalar, array or callable parameter is never a component, and excluding it keeps the fold from
	// carrying a fact for every string a method passes on. An undeclared or class-declared parameter
	// stays in - the fold has no types, so "not provably scalar" is the widest safe reading.
	private static function mayBeComponentParam(Param $param): bool
	{
		$type = $param->type;
		if ($type instanceof NullableType) {
			$type = $type->type;
		}

		return !$type instanceof Identifier || in_array($type->toString(), ['object', 'self', 'static', 'mixed'], true);
	}

	// An array parameter is never a component itself, but its ELEMENTS are what a literal-array
	// hand-over gives it, so the element routes have to reach it. An undeclared parameter may be
	// either and counts as both.
	private static function mayBeComponentArrayParam(Param $param): bool
	{
		$type = $param->type;
		if ($type instanceof NullableType) {
			$type = $type->type;
		}

		if ($type === null) {
			return true;
		}

		return $type instanceof Identifier && in_array($type->toString(), ['array', 'iterable'], true);
	}

	private static function calleeKeyOf(CallLike $call): ?string
	{
		if ($call instanceof MethodCall || $call instanceof NullsafeMethodCall || $call instanceof StaticCall) {
			return $call->name instanceof Identifier ? $call->name->toString() : null;
		}

		if ($call instanceof FuncCall) {
			return $call->name instanceof Name ? $call->name->toString() : null;
		}

		return null;
	}

	/**
	 * The component a mutating call reached THROUGH without naming it:
	 * `$this['ctrl']->getForm()->addHidden(...)` registers on whatever the getter returned, which no
	 * syntax at this site names. What it does name is the component the chain starts at, so the fact
	 * is recorded against a WILDCARD component owned by that one — every component of the accessed
	 * control's class is a candidate, no other class is. Null when the chain does not start at a
	 * component access, and null when it crosses a tree-mutating call of its own: that inner call
	 * recorded the chain's root itself, and the consumer's ancestor accumulation carries it down.
	 *
	 * @return array{scope: string, owner: string, name: string}|null
	 */
	private function indirectAccessRoot(Expr $expr): ?array
	{
		$current = $expr;
		while (true) {
			if ($current instanceof MethodCall) {
				if ($current->name instanceof Identifier && self::isTreeMutatingMethod($current->name->toString())) {
					return null;
				}

				$current = $current->var;
			} elseif ($current instanceof PropertyFetch || $current instanceof ArrayDimFetch) {
				$current = $current->var;
			} else {
				return null;
			}

			$access = $this->componentAccessChain($current);
			if ($access === null) {
				continue;
			}

			return $access['scope'] === RegistrationFact::OWNER_SELF
				? [
					'scope' => RegistrationFact::OWNER_COMPONENT,
					'owner' => $access['name'],
					'name' => RegistrationFact::MUTATED_ANY,
				]
				: [
					'scope' => RegistrationFact::OWNER_UNKNOWN,
					'owner' => '',
					'name' => RegistrationFact::MUTATED_ANY,
				];
		}
	}

	/**
	 * @param array{scope: string, owner: string, name: string} $access
	 */
	private static function accessKey(array $access): string
	{
		return $access['scope'] . "\0" . $access['owner'] . "\0" . $access['name'];
	}

	/**
	 * The set of variable names the method returns (method-level, closure-excluded) — the
	 * returned-var scan arrayCallableRegistrations self-contains, exposed for callers that need
	 * every returned var name a method could bind eventRegistrations against.
	 *
	 * @return array<string, true>
	 */
	public function returnedVariableNames(ClassMethod $method): array
	{
		$stmts = $method->getStmts() ?? [];

		return $this->returnedVariableNamesForStmts($stmts, ClosureScope::innerNodeIds($stmts));
	}

	/**
	 * @param array<Node> $stmts
	 * @param array<int, true> $closureInnerIds
	 * @return array<string, true>
	 */
	private function returnedVariableNamesForStmts(array $stmts, array $closureInnerIds): array
	{
		$names = [];
		foreach ((new NodeFinder())->findInstanceOf($stmts, Return_::class) as $ret) {
			if (isset($closureInnerIds[spl_object_id($ret)])) {
				continue;
			}

			if ($ret->expr instanceof Variable && is_string($ret->expr->name)) {
				$names[$ret->expr->name] = true;
			}
		}

		return $names;
	}

	/**
	 * A component access spelled as a literal offset or a literal getComponent() hop, decomposed into
	 * the accessed name and as much of its owner as syntax can name. Null when the accessed name is
	 * not a literal — a dynamic offset names no component this fold could key a fact under, which is
	 * this recognizer's one disclosed blind spot (the same one passThroughEdges has).
	 *
	 * @return array{scope: string, owner: string, name: string}|null
	 */
	private function componentAccessChain(Expr $expr): ?array
	{
		$access = $this->literalAccess($expr);
		if ($access === null) {
			return null;
		}

		if ($this->isThis($access['receiver'])) {
			return ['scope' => RegistrationFact::OWNER_SELF, 'owner' => '', 'name' => $access['name']];
		}

		$owner = $this->literalAccess($access['receiver']);
		if ($owner !== null && $this->isThis($owner['receiver'])) {
			return [
				'scope' => RegistrationFact::OWNER_COMPONENT,
				'owner' => $owner['name'],
				'name' => $access['name'],
			];
		}

		return ['scope' => RegistrationFact::OWNER_UNKNOWN, 'owner' => '', 'name' => $access['name']];
	}

	private function isThis(Expr $expr): bool
	{
		return $expr instanceof Variable && $expr->name === 'this';
	}

	/**
	 * @return array{name: string, receiver: Expr}|null
	 */
	private function literalAccess(Expr $expr): ?array
	{
		if ($expr instanceof ArrayDimFetch) {
			return $expr->dim instanceof String_
				? ['name' => $expr->dim->value, 'receiver' => $expr->var]
				: null;
		}

		if (
			$expr instanceof MethodCall
			&& $expr->name instanceof Identifier
			&& $expr->name->toString() === 'getComponent'
			&& !$expr->isFirstClassCallable()
		) {
			$args = $expr->getArgs();

			return isset($args[0]) && $args[0]->value instanceof String_
				? ['name' => $args[0]->value->value, 'receiver' => $expr->var]
				: null;
		}

		return null;
	}

	/**
	 * @param array{scope: string, owner: string, name: string} $access
	 */
	private function mutationFact(
		string $className,
		array $access,
		?string $handOverCallee = null
	): RegistrationFact
	{
		/** @var RegistrationFact::OWNER_* $scope */
		$scope = $access['scope'];

		return RegistrationFact::componentMutation(
			$className,
			$scope,
			$access['owner'],
			$access['name'],
			$handOverCallee,
		);
	}

	// add* is the whole component-registering surface a project can extend (an extension method's
	// body is not visible to this fold, so an unknown add* must count), plus the two names that take
	// a component away again.
	private static function isTreeMutatingMethod(string $name): bool
	{
		if ($name === 'removeComponent' || $name === 'offsetUnset') {
			return true;
		}

		return RegisteringMethodName::matches($name);
	}

	/**
	 * @return array<string, int>
	 */
	private function classTypedParamIndexes(ClassMethod $method): array
	{
		$indexes = [];
		foreach ($method->params as $idx => $param) {
			if (!$param->var instanceof Variable || !is_string($param->var->name)) {
				continue;
			}

			$type = $param->type;
			if ($type instanceof NullableType) {
				$type = $type->type;
			}

			if (!$type instanceof Name) {
				continue;
			}

			$indexes[$param->var->name] = (int) $idx;
		}

		return $indexes;
	}

}
