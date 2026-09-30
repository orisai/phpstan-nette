<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\FamilyPatterns;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\PatternSet;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArgPlaceholder;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use function array_merge;
use function array_slice;
use function count;
use function in_array;
use function strtolower;

final class FilterRewriter extends NodeVisitorAbstract
{

	// Node attribute (per-node, php-parser's own storage) carrying the literal filter name (as
	// called) onto the exact node rewriteResolvedCall() returns - the node the RULE dispatch sees,
	// since this pipeline hands its AST straight to PHPStan's analyser rather than reparsing
	// printed text. Never set for kind === 'function' (spec: functions get no provenance tip).
	public const FILTER_PROVENANCE_ATTRIBUTE = 'latte.filterProvenance';

	// Every kind === 'function' replacement node carries this instead - a node's own real
	// startLine/endLine (fixed below) would otherwise make it eligible for LatteProvenanceTipRule's
	// macro line-map fallback whenever it happens to sit on a macro-bearing source line (e.g. a
	// harvested function called inside {foreach}/{if}), silently violating "Functions: no tip".
	// This marker is checked BEFORE that fallback, short-circuiting it unconditionally.
	public const FUNCTION_NO_TIP_ATTRIBUTE = 'latte.functionNoTip';

	private const FILTER_INFO_TEMP = "\u{29F}_fi";

	private const CAPTURED_STRING_TEMP = "\u{29F}_tmp";

	// The content-type conversion wrapping a filtered block/{translate} output.
	public const ROLE_CONVERT_TO = 'convertTo';

	// The variable a compiled Latte 3 function call passes first (the template itself); at runtime
	// FunctionExecutor hands it only to a callable whose first parameter is typed
	// Latte\Runtime\Template and wraps every other one to skip it, so the stock table entries (none
	// Template-aware, the two lambdas standing in as Helpers) match their signature without it and a
	// harvested Template-aware function (FunctionTable::receivesTemplate()) keeps it.
	public const ROLE_FUNCTION_TEMPLATE_ARG = 'functionTemplateArg';

	private const FILTER_INFO_CLASS_NAMES = ['Latte\Runtime\FilterInfo', 'LR\FilterInfo'];

	private PatternSet $patterns;

	private ?FilterTable $filterTable = null;

	private ?FunctionTable $functionTable = null;

	private ?string $templateTypeClass = null;

	private ?TemplateTypeCustoms $templateTypeCustoms = null;

	/** @var array<Diagnostic> */
	private array $diagnostics = [];

	public function __construct(ShapeFamily $family)
	{
		$this->patterns = FamilyPatterns::for($family, self::class);
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Diagnostic>
	 */
	public function rewrite(
		array $stmts,
		FilterTable $filterTable,
		FunctionTable $functionTable,
		?string $templateTypeClass = null,
		?TemplateTypeCustoms $templateTypeCustoms = null
	): array
	{
		$this->filterTable = $filterTable;
		$this->functionTable = $functionTable;
		$this->templateTypeClass = $templateTypeClass;
		$this->templateTypeCustoms = $templateTypeCustoms;
		$this->diagnostics = [];

		$traverser = new NodeTraverser();
		$traverser->addVisitor($this);
		$traverser->traverse($stmts);

		return $this->diagnostics;
	}

	/**
	 * @return Node|int|null
	 */
	public function leaveNode(Node $node)
	{
		if ($node instanceof Expression && $this->isFilterInfoAssign($node->expr)) {
			return NodeVisitor::REMOVE_NODE;
		}

		if ($node instanceof MethodCall && $this->isFilterContentCall($node)) {
			return $this->rewriteFilterContent($node);
		}

		if ($node instanceof StaticCall && $this->isConvertToCall($node)) {
			return $this->rewriteConvertToCall($node);
		}

		if ($node instanceof FuncCall && $node->name instanceof PropertyFetch) {
			$filterName = $this->matchFiltersAccessor($node->name);
			if ($filterName !== null) {
				return $this->rewriteResolvedCall($filterName, $node->args, $node->getStartLine(), 'filter');
			}

			$functionName = $this->matchGlobalFnAccessor($node->name);
			if ($functionName !== null) {
				$receivesTemplate = $this->functionTable !== null
					&& $this->functionTable->receivesTemplate(strtolower($functionName));

				return $this->rewriteResolvedCall(
					$functionName,
					$receivesTemplate ? $node->args : $this->withoutTemplateArg($node->args),
					$node->getStartLine(),
					'function',
				);
			}
		}

		// Neither a harvested custom function NOR a per-template one ({function}-tagged
		// processParams() method) ever reaches the `fn->` PropertyFetch shape above: that rewrite
		// only fires vendor-side (PhpWriter::replaceFunctionsPass) for names present in
		// Compiler::$functions, which this pipeline deliberately keeps at the stock 7 (see
		// LatteCompiler - extending it would flip on vendor's own compile-time case-mismatch
		// trigger_error for every custom name too, adding surface for LatteCompiler's containment
		// to swallow with no offsetting benefit over CaseMismatchScanner's already-broader
		// coverage). Both kinds therefore still compile as a literal global-function call; only
		// intervene when the literal name is one resolveForTemplate() actually knows (built-ins
		// never take this shape, so in practice this only ever matches a harvested or per-template
		// name) - anything else is left untouched, so an unrelated real PHP function call (e.g.
		// is_object() in {if is_object($x)}) is never touched.
		if ($node instanceof FuncCall && $node->name instanceof Name) {
			$literalName = $node->name->toString();
			$known = $this->functionTable !== null
				&& (
					$this->functionTable->resolveForTemplate(
						strtolower($literalName),
						$this->templateTypeClass,
						$this->templateTypeCustoms,
						$literalName,
					) !== null
					|| $this->functionTable->registeredSpelling(
						$literalName,
						$this->templateTypeClass,
						$this->templateTypeCustoms,
					) !== null
				);

			if ($known) {
				return $this->rewriteResolvedCall($literalName, $node->args, $node->getStartLine(), 'function');
			}
		}

		// A filtered-capture/translate tail's $ʟ_tmp = Helpers::capturedString() is only ever
		// consumed once, by the very next statement (the filterContent call rewritten above); once
		// that reference is inlined the assignment is dead. Children (including the MethodCall/
		// FuncCall matches above) are already rewritten by the time a container's own leaveNode()
		// fires, since php-parser visits an array's elements before the array's owning node.
		if ($node instanceof ClassMethod && $node->stmts !== null) {
			$node->stmts = $this->inlineCapturedStringTemps($node->stmts);
		}

		if ($node instanceof If_) {
			$node->stmts = $this->inlineCapturedStringTemps($node->stmts);
		}

		if ($node instanceof Foreach_) {
			$node->stmts = $this->inlineCapturedStringTemps($node->stmts);
		}

		return null;
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>
	 */
	private function inlineCapturedStringTemps(array $stmts): array
	{
		$result = [];
		$count = count($stmts);
		$i = 0;

		while ($i < $count) {
			$stmt = $stmts[$i];
			$next = $stmts[$i + 1] ?? null;

			if ($next !== null && $this->isCapturedStringTempAssign($stmt)) {
				$inlined = $this->inlineTempVariable($next);
				if ($inlined !== null) {
					$result[] = $inlined;
					$i += 2;

					continue;
				}
			}

			$result[] = $stmt;
			$i++;
		}

		return $result;
	}

	private function isCapturedStringTempAssign(Stmt $stmt): bool
	{
		if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
			return false;
		}

		if (!$this->isVariableNamed($stmt->expr->var, self::CAPTURED_STRING_TEMP)) {
			return false;
		}

		$call = $stmt->expr->expr;

		return $call instanceof StaticCall
			&& $call->class instanceof Name
			&& $call->class->toString() === Helpers::class
			&& $call->name instanceof Identifier
			&& $call->name->toString() === 'capturedString';
	}

	private function inlineTempVariable(Stmt $stmt): ?Stmt
	{
		// The replacement class name is threaded in as a plain string, rather than the anonymous
		// class referencing Helpers::class itself: PHPat's namespace selectors (TestsArchitectureTest)
		// see an anonymous class as namespace-less, so a direct reference reads as a Tests\-namespace
		// class depending on Tests\-namespace code and trips testNonTestCodeDoesNotDependOnTestNamespace.
		$visitor = new class (self::CAPTURED_STRING_TEMP, Helpers::class) extends NodeVisitorAbstract {

			private string $variableName;

			private string $helpersClass;

			public bool $replaced = false;

			public function __construct(string $variableName, string $helpersClass)
			{
				$this->variableName = $variableName;
				$this->helpersClass = $helpersClass;
			}

			/**
			 * @return Node|null
			 */
			public function leaveNode(Node $node)
			{
				if ($node instanceof Variable && $node->name === $this->variableName) {
					$this->replaced = true;

					return new StaticCall(
						new FullyQualified($this->helpersClass),
						new Identifier('capturedString'),
						[],
					);
				}

				return null;
			}

		};

		$traverser = new NodeTraverser();
		$traverser->addVisitor($visitor);
		/** @var array<Stmt> $result */
		$result = $traverser->traverse([$stmt]);

		return $visitor->replaced ? $result[0] : null;
	}

	private function isFilterInfoAssign(Expr $expr): bool
	{
		if (!$expr instanceof Assign || !$this->isVariableNamed($expr->var, self::FILTER_INFO_TEMP)) {
			return false;
		}

		$new = $expr->expr;

		return $new instanceof New_
			&& $new->class instanceof Name
			&& in_array($new->class->toString(), self::FILTER_INFO_CLASS_NAMES, true);
	}

	private function isFilterContentCall(MethodCall $node): bool
	{
		if (!$node->name instanceof Identifier || $node->name->toString() !== 'filterContent') {
			return false;
		}

		return $this->matchFiltersReceiver($node->var) && count($node->args) >= 2;
	}

	private function rewriteFilterContent(MethodCall $node): Expr
	{
		$nameArg = $node->args[0];
		if (!$nameArg instanceof Arg || !$nameArg->value instanceof String_) {
			return $node;
		}

		$restArgs = array_slice($node->args, 2);

		return $this->rewriteResolvedCall($nameArg->value->value, $restArgs, $node->getStartLine(), 'filter');
	}

	// PhpWriter::modifierPass()'s 'escape' branch (every modifier chain ending in |escape - explicit,
	// or auto-appended by BlockMacros::macroBlock()/CoreMacros::macroInclude() whenever modifiers are
	// present) wraps %modifyContent() in LR\Filters::convertTo($ʟ_fi, $type, ...) - a StaticCall, not
	// the $this->filters->filterContent() MethodCall shape isFilterContentCall() matches above.
	private function isConvertToCall(StaticCall $node): bool
	{
		if (
			!$node->class instanceof Name
			|| !$node->name instanceof Identifier
			|| !$this->patterns->isStaticCall(self::ROLE_CONVERT_TO, $node->class->toString(), $node->name->toString())
		) {
			return false;
		}

		$first = $node->args[0] ?? null;

		return $first instanceof Arg && $this->isVariableNamed($first->value, self::FILTER_INFO_TEMP);
	}

	private function rewriteConvertToCall(StaticCall $node): StaticCall
	{
		$node->args[0] = new Arg($this->filterInfoCall());

		return $node;
	}

	/**
	 * @param array<Arg|ArgPlaceholder|VariadicPlaceholder> $args
	 * @return array<Arg|ArgPlaceholder|VariadicPlaceholder>
	 */
	private function withoutTemplateArg(array $args): array
	{
		$first = $args[0] ?? null;
		if (
			$this->patterns->has(self::ROLE_FUNCTION_TEMPLATE_ARG)
			&& $first instanceof Arg
			&& $this->isVariableNamed($first->value, $this->patterns->name(self::ROLE_FUNCTION_TEMPLATE_ARG))
		) {
			return array_slice($args, 1);
		}

		return $args;
	}

	/**
	 * @param array<Arg|VariadicPlaceholder> $args
	 */
	private function rewriteResolvedCall(string $name, array $args, int $line, string $kind): Expr
	{
		if ($kind === 'filter') {
			$resolved = $this->filterTable !== null
				? $this->filterTable->resolveForTemplate(
					strtolower($name),
					$this->templateTypeClass,
					$this->templateTypeCustoms,
					$name,
				)
				: null;
		} else {
			$resolved = $this->functionTable !== null
				? $this->functionTable->resolveForTemplate(
					strtolower($name),
					$this->templateTypeClass,
					$this->templateTypeCustoms,
					$name,
				)
				: null;
		}

		// Every replacement node below is newly constructed (never a startLine/endLine attribute
		// by default) - without this, a rule error reported directly on it (no explicit ->line())
		// falls back to PHPStan's own node->getStartLine(), landing on line -1.
		$attributes = ['startLine' => $line, 'endLine' => $line];

		if ($resolved === null) {
			$this->diagnostics[] = $this->unresolvedDiagnostic($name, $line, $kind);

			$unknownCall = new StaticCall(
				new FullyQualified(Helpers::class),
				new Identifier('unknownFilter'),
				$args,
				$attributes,
			);

			if ($kind === 'function') {
				$unknownCall->setAttribute(self::FUNCTION_NO_TIP_ATTRIBUTE, true);
			}

			return $unknownCall;
		}

		[$class, $method, $isContentAware, , $isStatic] = $resolved;

		$finalArgs = $isContentAware
			? array_merge([new Arg($this->filterInfoCall())], $args)
			: $args;

		// A STATIC method - per-template or stock - dispatches as Class::method(), never through the
		// instance-receiver shape below: PHP allows calling a static method through `->`, but
		// PHPStan's own staticMethod.dynamicCall flags it at strict level 8 (verified via a real
		// spawn). An instance method (a per-template one, or a stock Latte 3 |number) needs the
		// typed receiver.
		$call = $isStatic
			? $this->staticOrFunctionCall($class, $method, $finalArgs, $attributes)
			: $this->instanceCall($class, $method, $finalArgs, $attributes);

		if ($kind === 'filter') {
			$call->setAttribute(self::FILTER_PROVENANCE_ATTRIBUTE, $name);
		} else {
			$call->setAttribute(self::FUNCTION_NO_TIP_ATTRIBUTE, true);
		}

		return $call;
	}

	private function unresolvedDiagnostic(string $name, int $line, string $kind): Diagnostic
	{
		$table = $kind === 'filter' ? $this->filterTable : $this->functionTable;
		$spelling = $table !== null
			? $table->registeredSpelling($name, $this->templateTypeClass, $this->templateTypeCustoms)
			: null;

		if ($spelling !== null) {
			return new Diagnostic(
				$kind === 'filter' ? 'orisai.nette.latte.filterCaseMismatch' : 'orisai.nette.latte.functionCaseMismatch',
				"Latte $kind '$name' differs in case from the registered '$spelling' - Latte 3 resolves $kind names "
				. 'case-sensitively.',
				$line,
			);
		}

		return new Diagnostic(
			'orisai.nette.latte.unknownFilter',
			$kind === 'filter' ? "Unknown Latte filter '$name'." : "Unknown Latte function '$name'.",
			$line,
		);
	}

	/**
	 * @param array<Arg|VariadicPlaceholder> $args
	 * @param array<string, int> $attributes
	 */
	private function staticOrFunctionCall(string $class, string $method, array $args, array $attributes): Expr
	{
		return $class === ''
			? new FuncCall(new FullyQualified($method), $args, $attributes)
			: new StaticCall(new FullyQualified($class), new Identifier($method), $args, $attributes);
	}

	// No real instance exists at analysis time (Latte's own runtime creates the params instance at
	// render() call sites and the Essential\Filters instance inside CoreExtension, neither of which
	// this pipeline ever executes) - Helpers::templateTypeInstance() stands in as a typed receiver
	// (its own @template T of object + class-string<T> $class + @return T declaration makes PHPStan
	// resolve the MethodCall below against C's REAL reflected method).

	/**
	 * @param array<Arg|VariadicPlaceholder> $args
	 * @param array<string, int> $attributes
	 */
	private function instanceCall(string $class, string $method, array $args, array $attributes): MethodCall
	{
		$receiver = new StaticCall(
			new FullyQualified(Helpers::class),
			new Identifier('templateTypeInstance'),
			[new Arg(new ClassConstFetch(new FullyQualified($class), new Identifier('class')))],
			$attributes,
		);

		return new MethodCall($receiver, new Identifier($method), $args, $attributes);
	}

	private function filterInfoCall(): StaticCall
	{
		return new StaticCall(new FullyQualified(Helpers::class), new Identifier('filterInfo'), []);
	}

	private function matchFiltersAccessor(PropertyFetch $node): ?string
	{
		if (!$node->name instanceof Identifier) {
			return null;
		}

		return $this->matchFiltersReceiver($node->var) ? $node->name->toString() : null;
	}

	private function matchFiltersReceiver(Node $node): bool
	{
		return $node instanceof PropertyFetch
			&& $node->name instanceof Identifier
			&& $node->name->toString() === 'filters'
			&& $this->isVariableNamed($node->var, 'this');
	}

	private function matchGlobalFnAccessor(PropertyFetch $node): ?string
	{
		if (!$node->name instanceof Identifier) {
			return null;
		}

		$fn = $node->var;
		if (!$fn instanceof PropertyFetch || !$fn->name instanceof Identifier || $fn->name->toString() !== 'fn') {
			return null;
		}

		$global = $fn->var;
		if (!$global instanceof PropertyFetch || !$global->name instanceof Identifier) {
			return null;
		}

		if ($global->name->toString() !== 'global' || !$this->isVariableNamed($global->var, 'this')) {
			return null;
		}

		return $node->name->toString();
	}

	private function isVariableNamed(Node $node, string $name): bool
	{
		return $node instanceof Variable && $node->name === $name;
	}

}
