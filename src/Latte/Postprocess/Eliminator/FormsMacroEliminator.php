<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\GlobalPropertyFetchMatcher;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeVisitor;
use function array_map;
use function array_reverse;
use function count;
use function in_array;
use function is_array;
use function is_string;

// The three generations of nette/forms' Latte bridge render a control reference three ways, and all
// of them reduce to the same Helpers::form()/formObject()/formContainer()/formField() calls the
// LatteForms bridge types. A paired {label} keeps its label in a temp on every bridge (Latte 2 guards
// it with `if`, Latte 3 with `?->`); both become one `$latteLabel = Helpers::formLabel('x')` statement
// followed by the plain startTag()/endTag() echoes. A dynamic reference on the stack-offset shape
// stays as it is: its `is_object($ʟ_tmp = …) ? $ʟ_tmp : end(…)[$ʟ_tmp]` ternary is untyped either way.
final class FormsMacroEliminator extends EliminatorVisitor
{

	// {form x} opens a scope by pushing the form on $this->global->formsStack (Latte 2 FormMacros,
	// Latte 3 + nette/forms < 3.3) or by handing it to the $this->global->forms runtime (nette/forms 3.3).
	public const ROLE_FORM_OPEN = 'formOpen';

	public const SHAPE_STACK_PUSH = 'stackPush';

	public const SHAPE_PROVIDER_BEGIN = 'providerBegin';

	// {input x}/{label x}/{inputError x}/n:name read the control off the innermost scope.
	public const ROLE_FIELD_LOOKUP = 'fieldLookup';

	public const SHAPE_STACK_OFFSET = 'stackOffset';

	public const SHAPE_RUNTIME_ITEM = 'runtimeItem';

	public const SHAPE_PROVIDER_GET = 'providerGet';

	// Static FormsLatte\Runtime calls of the stack shapes.
	public const ROLE_RUNTIME = 'runtime';

	public const ROLE_STACK_PROVIDER = 'stackProvider';

	public const ROLE_FORMS_PROVIDER = 'formsProvider';

	public const ROLE_INPUT_TEMP = 'inputTemp';

	public const ROLE_LABEL_TEMP = 'labelTemp';

	public const ROLE_ELEM_TEMP = 'elemTemp';

	private const HELPERS_CLASS = Helpers::class;

	private const CONTAINER_CLASS = 'Nette\Forms\Container';

	private const METHOD_INITIALIZE_FORM = 'initializeForm';

	private const METHOD_ITEM = 'item';

	private const METHOD_RENDER = ['renderFormBegin', 'renderFormEnd'];

	private const PROVIDER_BEGIN = 'begin';

	private const PROVIDER_END = 'end';

	private const PROVIDER_GET = 'get';

	private const PROVIDER_SCOPE = 'getScope';

	private const PROVIDER_NESTED = 'isNested';

	private const LABEL_GETTERS = ['getLabel', 'getLabelPart'];

	private const CONTROL_GETTERS = ['getControl', 'getControlPart'];

	private const LABEL_START = 'startTag';

	private const LABEL_END = 'endTag';

	private const LABEL_ATTRIBUTES = 'addAttributes';

	private const CONTAINER_VAR = 'formContainer';

	private const DYNAMIC_TEMP = "\u{29F}_tmp";

	private const TEMP_RENAMES = [
		self::ROLE_INPUT_TEMP => 'latteInput',
		self::ROLE_LABEL_TEMP => 'latteLabel',
		self::ROLE_ELEM_TEMP => 'latteElem',
	];

	private bool $labelBound = false;

	public function describePattern(): string
	{
		return '{form x}/{form $var}/{formContext x}/<form n:name> $form = formsStack[] = uiControl["x"] '
			. '(or is_object(...) ? ... : uiControl[...] for an object form) | $this->global->forms->begin($form = ..., '
			. 'global: ...) -> $form = Helpers::form(\'x\') / Helpers::formObject($var); initializeForm($form), '
			. 'echo renderFormBegin(...)/renderFormEnd(...), array_pop(formsStack), $this->global->forms->end() and the '
			. '{/formContainer} re-derive ($formContainer = end(formsStack) | forms->getScope()) dropped (unprovable '
			. 'parent restore); {formContainer c} formsStack[] = $formContainer = <lookup> | forms->begin($formContainer '
			. '= forms->get(\'c\', Container::class)) -> $formContainer = Helpers::formContainer(\'c\'); '
			. '{input}/{label}/{inputError}/n:name end(formsStack)["x"] | Runtime::item(\'x\', $this->global) | '
			. 'forms->get(\'x\') -> Helpers::formField(\'x\') with the trailing ->getControl()/->getLabel()/->getError()/'
			. '->getControlPart()->attributes() chain kept; $ʟ_input/$ʟ_label/$ʟ_elem temps renamed to '
			. '$latteInput/$latteLabel/$latteElem: ' . $this->patterns()->describe();
	}

	/**
	 * @return array<Stmt>|Node|int|null
	 */
	public function leaveNode(Node $node)
	{
		if ($node instanceof Expression) {
			$rebuilt = $this->rebuildFormOpen($node->expr) ?? $this->rebuildContainerPush($node->expr);
			if ($rebuilt !== null) {
				return new Expression($rebuilt, $node->getAttributes());
			}

			return $this->isScopePlumbing($node->expr) ? NodeVisitor::REMOVE_NODE : null;
		}

		if ($node instanceof If_) {
			return $this->rebuildLabelIf($node);
		}

		if ($node instanceof Echo_) {
			if (count($node->exprs) !== 1) {
				return null;
			}

			return $this->isRenderCall($node->exprs[0]) ? NodeVisitor::REMOVE_NODE : $this->rebuildLabelEcho($node);
		}

		if ($node instanceof Variable && is_string($node->name)) {
			foreach (self::TEMP_RENAMES as $role => $rename) {
				if ($this->patterns()->hasName($role, $node->name)) {
					return new Variable($rename, $node->getAttributes());
				}
			}

			return null;
		}

		if (!$node instanceof Expr) {
			return null;
		}

		$input = $this->rebuildAttributedInput($node);
		if ($input !== null) {
			return $input;
		}

		$lookup = $this->matchFieldLookup($node);
		if ($lookup !== null) {
			return $this->isVariableNamed($lookup, self::DYNAMIC_TEMP)
				? null
				: $this->helperCall('formField', $lookup, $node);
		}

		$container = $this->matchContainerLookup($node);
		if ($container === null || $this->isVariableNamed($container, self::DYNAMIC_TEMP)) {
			return null;
		}

		return $this->helperCall('formContainer', $container, $node);
	}

	private function rebuildFormOpen(Expr $expr): ?Assign
	{
		$open = $this->matchStackFormOpen($expr) ?? $this->matchProviderFormOpen($expr);
		if ($open === null) {
			return null;
		}

		[$formVar, $source] = $open;

		if ($this->matchHelperCall($source, 'formContainer') !== null) {
			return new Assign($formVar, $source);
		}

		if (
			$source instanceof ArrayDimFetch
			&& $source->dim !== null
			&& GlobalPropertyFetchMatcher::matches($source->var, 'uiControl')
		) {
			return new Assign($formVar, $this->helperCall('form', $source->dim, $source));
		}

		$dynamicSource = $this->matchDynamicFormSource($source);
		if ($dynamicSource === null) {
			return null;
		}

		[$method, $name] = $dynamicSource;

		return new Assign($formVar, $this->helperCall($method, $name, $source));
	}

	/**
	 * @return array{Variable, Expr}|null
	 */
	private function matchStackFormOpen(Expr $expr): ?array
	{
		if (!$this->patterns()->hasName(self::ROLE_FORM_OPEN, self::SHAPE_STACK_PUSH)) {
			return null;
		}

		if (!$expr instanceof Assign || !$expr->var instanceof Variable || !is_string($expr->var->name)) {
			return null;
		}

		if (!$expr->expr instanceof Assign || !$this->isFormsStackPush($expr->expr->var)) {
			return null;
		}

		return [$expr->var, $expr->expr->expr];
	}

	/**
	 * @return array{Variable, Expr}|null
	 */
	private function matchProviderFormOpen(Expr $expr): ?array
	{
		if (!$this->patterns()->hasName(self::ROLE_FORM_OPEN, self::SHAPE_PROVIDER_BEGIN)) {
			return null;
		}

		if (!$expr instanceof MethodCall || !$this->isProviderCall($expr, self::PROVIDER_BEGIN)) {
			return null;
		}

		$arg = $expr->args[0] ?? null;
		if (!$arg instanceof Arg || !$arg->value instanceof Assign) {
			return null;
		}

		$assign = $arg->value;
		if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
			return null;
		}

		return [$assign->var, $assign->expr];
	}

	// {form $var} resolves an object or a name; nette/forms 3.3's {form scope x} additionally reads a
	// nested scope's container by that name, which types as the form named x here.

	/**
	 * @return array{string, Expr}|null
	 */
	private function matchDynamicFormSource(Expr $source): ?array
	{
		if (!$source instanceof Ternary || $source->if === null) {
			return null;
		}

		if (
			!$source->cond instanceof FuncCall
			|| !$this->isFuncCallNamed($source->cond, 'is_object')
			|| count($source->cond->args) !== 1
		) {
			return null;
		}

		$condArg = $source->cond->args[0];
		if (!$condArg instanceof Arg || !$condArg->value instanceof Assign) {
			return null;
		}

		$tmpAssign = $condArg->value;
		if (!$tmpAssign->var instanceof Variable || !is_string($tmpAssign->var->name)) {
			return null;
		}

		$tmpName = $tmpAssign->var->name;

		if (!$this->isVariableNamed($source->if, $tmpName)) {
			return null;
		}

		if (!$this->isUiControlLookup($source->else, $tmpName) && !$this->isScopeLookup($source->else, $tmpName)) {
			return null;
		}

		$name = $tmpAssign->expr;

		return [$name instanceof String_ ? 'form' : 'formObject', $name];
	}

	private function isUiControlLookup(Expr $expr, string $tmpName): bool
	{
		return $expr instanceof ArrayDimFetch
			&& GlobalPropertyFetchMatcher::matches($expr->var, 'uiControl')
			&& $this->isVariableNamed($expr->dim, $tmpName);
	}

	private function isScopeLookup(Expr $expr, string $tmpName): bool
	{
		if (!$expr instanceof Ternary || $expr->if === null) {
			return false;
		}

		if (!$expr->cond instanceof MethodCall || !$this->isProviderCall($expr->cond, self::PROVIDER_NESTED)) {
			return false;
		}

		$container = $this->matchContainerLookup($expr->if);

		return $container !== null
			&& $this->isVariableNamed($container, $tmpName)
			&& $this->isUiControlLookup($expr->else, $tmpName);
	}

	/**
	 * @return array<Stmt>|Echo_|null
	 */
	private function rebuildLabelIf(If_ $node)
	{
		if ($node->elseifs !== [] || $node->else !== null || count($node->stmts) !== 1) {
			return null;
		}

		$echo = $node->stmts[0];
		if (!$echo instanceof Echo_ || count($echo->exprs) !== 1) {
			return null;
		}

		$label = self::TEMP_RENAMES[self::ROLE_LABEL_TEMP];
		if ($this->isVariableNamed($node->cond, $label)) {
			$chain = $this->matchLabelChain($echo->exprs[0], self::LABEL_END, $label);

			return $chain === null || !$this->labelBound ? null : $echo;
		}

		$name = $this->matchLabelAssign($node->cond, $label);
		if ($name === null || $this->matchLabelOpening($echo->exprs[0], $label) === null) {
			$this->noteUnboundLabel($node->cond, $label);

			return null;
		}

		$this->labelBound = true;

		return [$this->labelAssign($name, $node->cond, $node), $echo];
	}

	// The opening of a paired label or a self-closing label with attributes: both chain onto the
	// label the vendor macro assumes to be Html (`{label x /}` alone echoes it and keeps its read).

	/**
	 * @return array{Expr, MethodCall}|null
	 */
	private function matchLabelOpening(Expr $expr, string $label): ?array
	{
		return $this->matchLabelChain($expr, self::LABEL_START, $label)
			?? $this->matchLabelChain($expr, self::LABEL_ATTRIBUTES, $label);
	}

	/**
	 * @return array<Stmt>|Echo_|null
	 */
	private function rebuildLabelEcho(Echo_ $node)
	{
		$expr = $node->exprs[0];
		if (!$expr instanceof NullsafeMethodCall) {
			return null;
		}

		$label = self::TEMP_RENAMES[self::ROLE_LABEL_TEMP];
		$end = $this->matchLabelChain($expr, self::LABEL_END, $label);
		if ($end !== null && $this->isVariableNamed($end[0], $label)) {
			return $this->labelBound ? new Echo_([$end[1]], $node->getAttributes()) : null;
		}

		$start = $this->matchLabelOpening($expr, $label);
		if ($start === null) {
			return null;
		}

		$name = $this->matchLabelAssign($start[0], $label);
		if ($name === null) {
			$this->noteUnboundLabel($start[0], $label);

			return null;
		}

		$this->labelBound = true;

		return [$this->labelAssign($name, $start[0], $node), new Echo_([$start[1]], $node->getAttributes())];
	}

	// Only a label temp bound to Helpers::formLabel() is never null, so only then is the closing
	// tag's own guard (`if`, `?->`) droppable; a dynamic reference keeps the bridge's getLabel()
	// assignment and both guards with it.
	private function noteUnboundLabel(Expr $expr, string $label): void
	{
		if ($expr instanceof Assign && $this->isVariableNamed($expr->var, $label)) {
			$this->labelBound = false;
		}
	}

	// The call chain of a label echo down to the label temp or its assignment, rebuilt without the
	// nullsafe operators over the temp.

	/**
	 * @return array{Expr, MethodCall}|null
	 */
	private function matchLabelChain(Expr $expr, string $outer, string $label): ?array
	{
		$calls = [];
		$root = $expr;
		while ($root instanceof MethodCall || $root instanceof NullsafeMethodCall) {
			$calls[] = $root;
			$root = $root->var;
		}

		if ($calls === [] || !$calls[0]->name instanceof Identifier || $calls[0]->name->toString() !== $outer) {
			return null;
		}

		if (!$this->isVariableNamed($root, $label) && $this->matchLabelAssign($root, $label) === null) {
			return null;
		}

		$rebuilt = new Variable($label);
		foreach (array_reverse($calls) as $call) {
			$rebuilt = new MethodCall($rebuilt, $call->name, $call->args, $call->getAttributes());
		}

		return [$root, $rebuilt];
	}

	private function matchLabelAssign(Node $node, string $label): ?Expr
	{
		if (!$node instanceof Assign || !$this->isVariableNamed($node->var, $label)) {
			return null;
		}

		$getter = $node->expr;
		if (
			!$getter instanceof MethodCall
			|| !$getter->name instanceof Identifier
			|| !in_array($getter->name->toString(), self::LABEL_GETTERS, true)
		) {
			return null;
		}

		return $this->matchHelperCall($getter->var, 'formField');
	}

	private function labelAssign(Expr $name, Node $replaced, Stmt $stmt): Expression
	{
		return new Expression(
			new Assign(
				new Variable(self::TEMP_RENAMES[self::ROLE_LABEL_TEMP]),
				$this->helperCall('formLabel', $name, $replaced),
			),
			$stmt->getAttributes(),
		);
	}

	// {formContainer c} on a stack shape; the lookup inside was reduced to Helpers::formField('c')
	// on the way up. The provider shape's begin($formContainer = forms->get('c', Container::class))
	// is a form open whose source is already Helpers::formContainer('c').
	private function rebuildContainerPush(Expr $expr): ?Assign
	{
		if (!$expr instanceof Assign || !$this->isFormsStackPush($expr->var)) {
			return null;
		}

		$inner = $expr->expr;
		if (!$inner instanceof Assign || !$inner->var instanceof Variable || !is_string($inner->var->name)) {
			return null;
		}

		$name = $this->matchHelperCall($inner->expr, 'formField');

		return $name === null ? null : new Assign($inner->var, $this->helperCall('formContainer', $name, $inner->expr));
	}

	private function isScopePlumbing(Expr $expr): bool
	{
		if ($expr instanceof StaticCall && $this->isRuntimeCall($expr, self::METHOD_INITIALIZE_FORM)) {
			return true;
		}

		if ($expr instanceof FuncCall && $this->isFormsStackCall($expr, 'array_pop')) {
			return true;
		}

		if ($expr instanceof MethodCall && $this->isProviderCall($expr, self::PROVIDER_END)) {
			return true;
		}

		if (!$expr instanceof Assign || !$this->isVariableNamed($expr->var, self::CONTAINER_VAR)) {
			return false;
		}

		$call = $expr->expr;

		return ($call instanceof MethodCall && $this->isProviderCall($call, self::PROVIDER_SCOPE))
			|| ($call instanceof FuncCall && $this->isFormsStackCall($call, 'end'));
	}

	private function isRenderCall(Expr $expr): bool
	{
		foreach (self::METHOD_RENDER as $method) {
			if ($expr instanceof StaticCall && $this->isRuntimeCall($expr, $method)) {
				return true;
			}

			if ($expr instanceof MethodCall && $this->isProviderCall($expr, $method)) {
				return true;
			}
		}

		return false;
	}

	private function matchFieldLookup(Expr $expr): ?Expr
	{
		$patterns = $this->patterns();

		if ($patterns->hasName(self::ROLE_FIELD_LOOKUP, self::SHAPE_STACK_OFFSET)) {
			return $this->matchStackOffset($expr);
		}

		if ($patterns->hasName(self::ROLE_FIELD_LOOKUP, self::SHAPE_RUNTIME_ITEM)) {
			return $this->matchRuntimeItem($expr);
		}

		if ($patterns->hasName(self::ROLE_FIELD_LOOKUP, self::SHAPE_PROVIDER_GET)) {
			return $this->matchProviderGet($expr, 1);
		}

		return null;
	}

	private function matchContainerLookup(Expr $expr): ?Expr
	{
		if (!$this->patterns()->hasName(self::ROLE_FIELD_LOOKUP, self::SHAPE_PROVIDER_GET)) {
			return null;
		}

		$name = $this->matchProviderGet($expr, 2);
		if ($name === null || !$expr instanceof MethodCall) {
			return null;
		}

		$typeArg = $expr->args[1];
		$type = $typeArg instanceof Arg ? $typeArg->value : null;

		return $type instanceof ClassConstFetch
			&& $type->class instanceof Name
			&& $type->class->toString() === self::CONTAINER_CLASS
			&& $type->name instanceof Identifier
			&& $type->name->toString() === 'class'
			? $name
			: null;
	}

	private function matchStackOffset(Expr $expr): ?Expr
	{
		if (!$expr instanceof ArrayDimFetch || $expr->dim === null) {
			return null;
		}

		if (!$expr->var instanceof FuncCall || !$this->isFormsStackCall($expr->var, 'end')) {
			return null;
		}

		return $expr->dim;
	}

	private function matchRuntimeItem(Expr $expr): ?Expr
	{
		if (!$expr instanceof StaticCall || !$this->isRuntimeCall($expr, self::METHOD_ITEM)) {
			return null;
		}

		if (count($expr->args) !== 2 || !$expr->args[0] instanceof Arg || !$expr->args[1] instanceof Arg) {
			return null;
		}

		return $this->isGlobal($expr->args[1]->value) ? $expr->args[0]->value : null;
	}

	private function matchProviderGet(Expr $expr, int $argCount): ?Expr
	{
		if (!$expr instanceof MethodCall || !$this->isProviderCall($expr, self::PROVIDER_GET)) {
			return null;
		}

		if (count($expr->args) !== $argCount) {
			return null;
		}

		$values = [];
		foreach ($expr->args as $arg) {
			if (!$arg instanceof Arg) {
				return null;
			}

			$values[] = $arg->value;
		}

		return $values[0] ?? null;
	}

	private function isProviderCall(MethodCall $call, string $method): bool
	{
		if (!$call->name instanceof Identifier || $call->name->toString() !== $method) {
			return false;
		}

		foreach ($this->patterns()->names(self::ROLE_FORMS_PROVIDER) as $provider) {
			if (GlobalPropertyFetchMatcher::matches($call->var, $provider)) {
				return true;
			}
		}

		return false;
	}

	private function isRuntimeCall(StaticCall $call, string $method): bool
	{
		return $call->class instanceof Name
			&& $call->name instanceof Identifier
			&& $call->name->toString() === $method
			&& $this->patterns()->isStaticCall(self::ROLE_RUNTIME, $call->class->toString(), $method);
	}

	private function isFormsStackCall(FuncCall $call, string $function): bool
	{
		return $this->isFuncCallNamed($call, $function)
			&& count($call->args) === 1
			&& $call->args[0] instanceof Arg
			&& $this->isFormsStack($call->args[0]->value);
	}

	private function isFormsStackPush(Expr $expr): bool
	{
		return $expr instanceof ArrayDimFetch && $expr->dim === null && $this->isFormsStack($expr->var);
	}

	private function isFormsStack(Expr $expr): bool
	{
		foreach ($this->patterns()->names(self::ROLE_STACK_PROVIDER) as $provider) {
			if (GlobalPropertyFetchMatcher::matches($expr, $provider)) {
				return true;
			}
		}

		return false;
	}

	private function isGlobal(Expr $expr): bool
	{
		return $expr instanceof PropertyFetch
			&& $expr->name instanceof Identifier
			&& $expr->name->toString() === 'global'
			&& $expr->var instanceof Variable
			&& $expr->var->name === 'this';
	}

	private function matchHelperCall(Expr $expr, string $method): ?Expr
	{
		if (
			!$expr instanceof StaticCall
			|| !$expr->class instanceof FullyQualified
			|| $expr->class->toString() !== self::HELPERS_CLASS
			|| !$expr->name instanceof Identifier
			|| $expr->name->toString() !== $method
			|| count($expr->args) !== 1
		) {
			return null;
		}

		$arg = $expr->args[0];

		return $arg instanceof Arg ? $arg->value : null;
	}

	// {input x, attrs} chains addAttributes() onto the control the vendor macro assumes to be Html,
	// which BaseControl::getControl()/getControlPart() declare Html|string: the same analysis-only
	// stand-in as a paired label, a typed alias of formField('x')->getControl(Part)().
	private function rebuildAttributedInput(Expr $expr): ?MethodCall
	{
		if (
			!$expr instanceof MethodCall
			|| !$expr->name instanceof Identifier
			|| $expr->name->toString() !== 'addAttributes'
		) {
			return null;
		}

		$control = $expr->var;
		if (
			!$control instanceof MethodCall
			|| !$control->name instanceof Identifier
			|| !in_array($control->name->toString(), self::CONTROL_GETTERS, true)
			|| count($control->args) !== ($control->name->toString() === 'getControl' ? 0 : 1)
		) {
			return null;
		}

		$name = $this->matchHelperCall($control->var, 'formField');
		if ($name === null) {
			return null;
		}

		$args = [$name];
		foreach ($control->args as $arg) {
			if (!$arg instanceof Arg) {
				return null;
			}

			$args[] = $arg->value instanceof String_ ? new String_($arg->value->value) : $arg->value;
		}

		return new MethodCall(
			$this->helperCall('formInput', $args, $control),
			$expr->name,
			$expr->args,
			$expr->getAttributes(),
		);
	}

	/**
	 * @param Expr|list<Expr> $args
	 */
	private function helperCall(string $method, $args, Node $replaced): StaticCall
	{
		$attributes = [];
		if ($replaced->hasAttribute('startLine')) {
			$attributes['startLine'] = $replaced->getStartLine();
			$attributes['endLine'] = $replaced->getEndLine();
		}

		return new StaticCall(
			new FullyQualified(self::HELPERS_CLASS),
			new Identifier($method),
			array_map(static fn (Expr $arg): Arg => new Arg($arg), is_array($args) ? $args : [$args]),
			$attributes,
		);
	}

	private function isFuncCallNamed(FuncCall $node, string $name): bool
	{
		return $node->name instanceof Name && $node->name->toString() === $name;
	}

	private function isVariableNamed(?Node $node, string $name): bool
	{
		return $node instanceof Variable && $node->name === $name;
	}

}
