<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Declarations\PropertyTypeResolver;
use OriPhpstan\Nette\Latte\Includes\ArgTyper;
use OriPhpstan\Nette\Latte\Includes\CapturedOverlay;
use OriPhpstan\Nette\Latte\Includes\DeclaredVarsResolver;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use PhpParser\Comment\Doc;
use PhpParser\Modifiers;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Coalesce;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PHPStan\Parser\Parser;
use ReflectionClass;
use ReflectionProperty;
use function array_filter;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_pop;
use function array_shift;
use function array_splice;
use function array_values;
use function class_exists;
use function count;
use function end;
use function implode;
use function is_string;
use function ksort;
use function rtrim;
use function sort;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function ucfirst;
use function usort;
use const SORT_STRING;

final class DeclarationInjector
{

	private const L_ARGS = "\u{29F}_args";

	private const THIS = 'this';

	// Latte\Runtime\Template declares concrete main()/prepare(); adding required params to either
	// makes PHPStan flag it as parameter.notOptional, an identifier ignoreErrors cannot silence.
	// Renamed unconditionally so no generated class ever overrides the parent.
	private const METHOD_RENAMES = [
		'main' => 'latteMain',
		'prepare' => 'lattePrepare',
	];

	private Parser $phpParser;

	private TemplateEdgeIndex $edgeIndex;

	private LatteUniverse $universe;

	private CapturedOverlay $capturedOverlay;

	private ArgTyper $argTyper;

	private DeclaredVarsResolver $declaredVarsResolver;

	public function __construct(
		Parser $phpParser,
		TemplateEdgeIndex $edgeIndex,
		LatteUniverse $universe,
		CapturedOverlay $capturedOverlay
	)
	{
		$this->phpParser = $phpParser;
		$this->edgeIndex = $edgeIndex;
		$this->universe = $universe;
		$this->argTyper = new ArgTyper($edgeIndex->getAdapterAccessor());
		$this->capturedOverlay = $capturedOverlay;
		$this->declaredVarsResolver = new DeclaredVarsResolver($edgeIndex);
	}

	/**
	 * @param array<Stmt> $stmts
	 * @param list<TemplateContext> $contexts
	 * @return list<Diagnostic>
	 */
	public function inject(
		array $stmts,
		Declarations $declarations,
		ShapeFamily $family,
		array $contexts = [],
		string $relativePath = ''
	): array
	{
		$class = (new NodeFinder())->findFirstInstanceOf($stmts, Class_::class);
		if ($class === null) {
			return [];
		}

		$latte2Layout = $family->latteLine === ShapeFamily::LATTE_2;

		if ($contexts !== []) {
			// The resolver normally returns hash-ordered contexts already; sorting here too makes
			// clone-index assignment (latteMain_ctx{i}) a pure function of the context SET, never of
			// incidental caller-supplied array order. Shared with LatteDebugDumpRule
			// (TemplateContext::sortByHash) so a clone's ctx-index always maps back to the same
			// context on both sides.
			$contexts = TemplateContext::sortByHash($contexts);
		}

		[$headerParams, $diagnostics] = $this->buildHeaderParams($declarations);
		$parameters = $declarations->getParameters();
		$unionParams = $contexts !== [] ? $this->buildUnionParams($contexts) : [];
		$effectiveHeaderParams = $contexts !== [] ? $unionParams : $headerParams;

		if (!$latte2Layout) {
			$this->absorbPrepare($class, $parameters);
		}

		foreach (['main', 'prepare'] as $methodName) {
			$method = $this->findMethod($class, $methodName);
			if ($method === null) {
				continue;
			}

			if ($latte2Layout) {
				if ($parameters !== null) {
					$this->dropParametersProlog($method, $parameters);
				} else {
					$this->dropParamsExtract($method);
				}
			}

			if ($contexts !== []) {
				// main's own params/doc are rebuilt per-clone below (buildUnionParams doesn't carry
				// {parameters} defaults, so main is never left half-typed from this branch).
				if ($methodName === 'prepare') {
					$this->applyNamedParams($method, $unionParams);
				}

				continue;
			}

			if ($methodName === 'main' && $parameters !== null) {
				$this->applyParameterList($method, $parameters);
			} elseif ($parameters === null) {
				$this->applyNamedParams($method, $headerParams);
			}
		}

		$defineMethodMap = $this->buildDefineMethodMap($declarations->getDefineParams());
		$blockNameMap = $this->buildBlockNameMap($declarations->getDefineParams());
		foreach ($class->stmts as $stmt) {
			if (!$stmt instanceof ClassMethod || !$this->isBlockMethod($stmt)) {
				continue;
			}

			$this->dropBlockProlog($stmt);
			$methodName = $stmt->name->toString();
			$ownParams = $defineMethodMap[$methodName] ?? [];
			$blockName = $blockNameMap[$methodName] ?? null;
			$this->applyBlockParams($stmt, $effectiveHeaderParams, $ownParams, $blockName, $relativePath, $contexts);
		}

		$this->applyDeclaredScope($class, $declarations);
		$this->renameOverridingMethods($class);

		if ($contexts !== []) {
			$this->cloneMainPerContext($class, $contexts);
		}

		return $diagnostics;
	}

	/**
	 * @param list<TemplateContext> $contexts
	 */
	private function cloneMainPerContext(Class_ $class, array $contexts): void
	{
		$main = $this->findMethod($class, self::METHOD_RENAMES['main']);
		if ($main === null) {
			return;
		}

		$position = null;
		$cursor = 0;
		foreach ($class->stmts as $stmt) {
			if ($stmt === $main) {
				$position = $cursor;

				break;
			}

			$cursor++;
		}

		if ($position === null) {
			return;
		}

		$clones = [];
		foreach ($contexts as $i => $context) {
			$clone = $this->deepCloneMethod($main);
			$clone->name = new Identifier(self::METHOD_RENAMES['main'] . '_ctx' . $i);
			$this->applyContextParams($clone, $context);
			$clones[] = $clone;
		}

		array_splice($class->stmts, $position, 1, $clones);
	}

	// A shallow `clone` only duplicates the ClassMethod object itself - its ->stmts/->params arrays
	// keep pointing at the SAME child Node objects as the original. AnalysisPipeline runs its
	// eliminator NodeTraverser once, AFTER injection, over every clone; with shared children it
	// would visit (and mutate) each one N times instead of once, corrupting the result.
	// CloningVisitor clones every node it enters during a full traversal and rebuilds the
	// parent/child links from those clones, producing an independent tree per top-level node it's
	// given while preserving each node's own attributes (line numbers, doc comments) via PHP's
	// by-value `clone` semantics on scalar/immutable attribute values.
	private function deepCloneMethod(ClassMethod $method): ClassMethod
	{
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new CloningVisitor());

		/** @var list<ClassMethod> $cloned */
		$cloned = $traverser->traverse([$method]);

		return $cloned[0];
	}

	private function applyContextParams(ClassMethod $method, TemplateContext $context): void
	{
		$headerParams = [];
		foreach ($context->getVars() as $name => $type) {
			$headerParams[] = [$name, $type];
		}

		[$params, $docLines] = $this->buildParamsAndDocs($headerParams);
		if ($this->returnsArray($method)) {
			$docLines[] = ' * @return array{}';
		}

		$method->params = $params;
		$this->setParamDoc($method, $docLines);
	}

	// Latte 3 compiles the template head into prepare(): array and hands its get_defined_vars() to
	// main(array $ʟ_args); putting the head statements back in front of the body restores the Latte
	// 2 layout, so head {var}/{default} typing and every later pass see one shape.

	/**
	 * @param array<int, array{string|null, string, string|null, int}>|null $parameters
	 */
	private function absorbPrepare(Class_ $class, ?array $parameters): void
	{
		$main = $this->findMethod($class, 'main');
		if ($main === null || $main->stmts === null) {
			return;
		}

		$main->params = [];
		$this->dropParamsExtract($main);
		$this->dropLArgsUnset($main);
		if (isset($main->stmts[0]) && $this->isSnippetGuard($main->stmts[0])) {
			array_shift($main->stmts);
		}

		$prepare = $this->findMethod($class, 'prepare');
		if ($prepare === null) {
			return;
		}

		if ($parameters !== null) {
			$this->dropParametersProlog($prepare, $parameters);
		} else {
			$this->dropParamsExtract($prepare);
		}

		$this->dropLArgsUnset($prepare);
		$head = $prepare->stmts ?? [];
		$last = end($head);
		if ($last instanceof Return_ && $this->isGetDefinedVarsCall($last->expr)) {
			array_pop($head);
		}

		$main->stmts = array_merge($head, $main->stmts ?? []);
		$class->stmts = array_values(array_filter(
			$class->stmts,
			static fn (Stmt $stmt): bool => $stmt !== $prepare,
		));
	}

	private function dropLArgsUnset(ClassMethod $method): void
	{
		if (isset($method->stmts[0]) && $method->stmts[0] instanceof Unset_ && $this->targetsLArgs($method->stmts[0])) {
			array_shift($method->stmts);
		}
	}

	private function isSnippetGuard(Stmt $stmt): bool
	{
		if (
			!$stmt instanceof If_
			|| $stmt->else !== null
			|| $stmt->elseifs !== []
			|| count($stmt->stmts) !== 1
			|| !$stmt->stmts[0] instanceof Return_
			|| $stmt->stmts[0]->expr !== null
		) {
			return false;
		}

		$cond = $stmt->cond;

		return ($cond instanceof MethodCall || $cond instanceof NullsafeMethodCall)
			&& $cond->name instanceof Identifier
			&& $cond->name->toString() === 'renderSnippets';
	}

	private function isGetDefinedVarsCall(?Expr $expr): bool
	{
		return $expr instanceof FuncCall
			&& $expr->name instanceof Name
			&& $expr->name->toString() === 'get_defined_vars'
			&& $expr->args === [];
	}

	private function returnsArray(ClassMethod $method): bool
	{
		return $method->returnType instanceof Identifier && $method->returnType->toLowerString() === 'array';
	}

	/**
	 * @param list<TemplateContext> $contexts
	 * @return array<int, array{string, string|null}>
	 */
	private function buildUnionParams(array $contexts): array
	{
		/** @var array<string, array<string, true>> $typesByName */
		$typesByName = [];
		foreach ($contexts as $context) {
			foreach ($context->getVars() as $name => $type) {
				$typesByName[$name][$type] = true;
			}
		}

		ksort($typesByName);

		$params = [];
		foreach ($typesByName as $name => $types) {
			$params[] = [$name, count($types) === 1 ? array_key_first($types) : 'mixed'];
		}

		return $params;
	}

	private function renameOverridingMethods(Class_ $class): void
	{
		$main = $this->findMethod($class, 'main');
		if ($main !== null) {
			$main->name = new Identifier(self::METHOD_RENAMES['main']);
			// The rename drops the override that used to exempt Latte 2 main()'s native `: array`
			// return type from missingType.iterableValue; PrologEliminator always reduces its
			// `return get_defined_vars()` to `return [];`, so `array{}` is the exact type.
			if ($this->returnsArray($main)) {
				$this->appendDocLine($main, ' * @return array{}');
			}
		}

		$prepare = $this->findMethod($class, 'prepare');
		if ($prepare !== null) {
			$prepare->name = new Identifier(self::METHOD_RENAMES['prepare']);
		}
	}

	private function appendDocLine(ClassMethod $method, string $line): void
	{
		$existing = $method->getDocComment();
		if ($existing === null) {
			$method->setDocComment(new Doc("/**\n{$line}\n */"));

			return;
		}

		$closing = "\n */";
		$text = $existing->getText();
		$method->setDocComment(new Doc(substr($text, 0, -strlen($closing)) . "\n{$line}{$closing}"));
	}

	/**
	 * @return array{array<int, array{string, string|null}>, list<Diagnostic>}
	 */
	private function buildHeaderParams(Declarations $declarations): array
	{
		$names = [];
		/** @var array<string, string|null> $types */
		$types = [];
		$diagnostics = [];

		$templateTypeClass = $declarations->getTemplateTypeClass();
		if ($templateTypeClass !== null) {
			if (class_exists($templateTypeClass)) {
				$reflection = new ReflectionClass($templateTypeClass);
				foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
					$name = $property->getName();
					$names[] = $name;
					$types[$name] = PropertyTypeResolver::resolve($property);
				}
			} else {
				$diagnostics[] = new Diagnostic(
					'orisaiNette.latte.unknownType',
					"Unknown template type class $templateTypeClass.",
					$declarations->getTemplateTypeLine() ?? 1,
				);
			}
		}

		foreach ($declarations->getHeaderVarTypes() as $name => $type) {
			if (!isset($types[$name])) {
				$names[] = $name;
			}

			$types[$name] = $type;
		}

		// A top-level {block} always `extract($this->params)` at runtime (BlockMacros::extractMethod)
		// and also receives main()'s get_defined_vars() as $ʟ_args, so a {parameters}-declared var is
		// visible inside it exactly like a templateType/varType one (RuntimeParityTest fixture
		// parameters-block.latte pins this). main()/prepare() consume {parameters} through the
		// separate applyParameterList()/dropParametersProlog() path in inject(), so folding it in
		// here only reaches the callers of this method that main/prepare's own branch never touches.
		foreach ($declarations->getParameters() ?? [] as [$type, $name]) {
			if (!isset($types[$name])) {
				$names[] = $name;
			}

			$types[$name] = $type ?? 'mixed';
		}

		$ordered = [];
		foreach ($names as $name) {
			$ordered[] = [$name, $types[$name]];
		}

		return [$ordered, $diagnostics];
	}

	/**
	 * @param array<string, array<int, array{string|null, string}>> $defineParams
	 * @return array<string, array<int, array{string|null, string}>>
	 */
	private function buildDefineMethodMap(array $defineParams): array
	{
		$map = [];
		foreach ($defineParams as $name => $params) {
			$map['block' . ucfirst($name)] = $params;
		}

		return $map;
	}

	// Reverse of buildDefineMethodMap()'s own name -> method transform, built from the SAME source
	// (the scanner's raw {define} name) rather than guessed back from the method name via lcfirst()
	// - a block whose own name already starts uppercase would make that guess ambiguous.

	/**
	 * @param array<string, array<int, array{string|null, string}>> $defineParams
	 * @return array<string, string>
	 */
	private function buildBlockNameMap(array $defineParams): array
	{
		$map = [];
		foreach ($defineParams as $name => $params) {
			$map['block' . ucfirst($name)] = $name;
		}

		return $map;
	}

	private function findMethod(Class_ $class, string $name): ?ClassMethod
	{
		foreach ($class->stmts as $stmt) {
			if ($stmt instanceof ClassMethod && $stmt->name->toString() === $name) {
				return $stmt;
			}
		}

		return null;
	}

	private function isBlockMethod(ClassMethod $method): bool
	{
		return count($method->params) === 1
			&& $method->params[0]->var instanceof Variable
			&& $method->params[0]->var->name === self::L_ARGS;
	}

	private function dropParamsExtract(ClassMethod $method): void
	{
		if ($method->stmts === null || !isset($method->stmts[0])) {
			return;
		}

		$stmt = $method->stmts[0];
		if (
			$stmt instanceof Expression
			&& $stmt->expr instanceof FuncCall
			&& $stmt->expr->name instanceof Name
			&& $stmt->expr->name->toString() === 'extract'
			&& count($stmt->expr->args) === 1
		) {
			array_shift($method->stmts);
		}
	}

	/**
	 * @param array<int, array{string|null, string, string|null, int}> $parameters
	 */
	private function dropParametersProlog(ClassMethod $method, array $parameters): void
	{
		if ($method->stmts === null) {
			return;
		}

		$removed = 0;
		while (
			$removed < count($parameters)
			&& isset($method->stmts[0])
			&& $this->isNamedVarAssign($method->stmts[0], $parameters[$removed][1])
		) {
			array_shift($method->stmts);
			$removed++;
		}
	}

	private function isNamedVarAssign(Stmt $stmt, string $name): bool
	{
		return $stmt instanceof Expression
			&& $stmt->expr instanceof Assign
			&& $stmt->expr->var instanceof Variable
			&& $stmt->expr->var->name === $name;
	}

	private function dropBlockProlog(ClassMethod $method): void
	{
		if ($method->stmts === null) {
			return;
		}

		$position = 0;
		foreach ($method->stmts as $stmt) {
			$position++;
			if ($stmt instanceof Unset_ && $this->targetsLArgs($stmt)) {
				array_splice($method->stmts, 0, $position);

				return;
			}
		}
	}

	private function targetsLArgs(Unset_ $stmt): bool
	{
		foreach ($stmt->vars as $var) {
			if ($var instanceof Variable && $var->name === self::L_ARGS) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array{string, string|null}> $headerParams
	 */
	private function applyNamedParams(ClassMethod $method, array $headerParams): void
	{
		if ($headerParams === []) {
			return;
		}

		[$params, $docLines] = $this->buildParamsAndDocs($headerParams);
		$method->params = $params;
		$this->setParamDoc($method, $docLines);
	}

	/**
	 * @param array<int, array{string, string|null}> $headerParams
	 * @param array<int, array{string|null, string}> $ownParams
	 * @param list<TemplateContext> $contexts
	 */
	private function applyBlockParams(
		ClassMethod $method,
		array $headerParams,
		array $ownParams,
		?string $blockName,
		string $relativePath,
		array $contexts
	): void
	{
		// A {define}'s own typed param is the block author's explicit, locally-scoped declaration -
		// it must win over an ambient header/union var of the same name (e.g. a templateType
		// property), never sit alongside it as a second same-named Param (RedefinedParametersRule,
		// nonIgnorable).
		$ownNames = [];
		foreach ($ownParams as [, $name]) {
			$ownNames[$name] = true;
		}

		$filteredHeaderParams = [];
		$headerNames = [];
		foreach ($headerParams as $headerParam) {
			if (!isset($ownNames[$headerParam[0]])) {
				$filteredHeaderParams[] = $headerParam;
				$headerNames[$headerParam[0]] = true;
			}
		}

		[$params, $docLines] = $this->buildParamsAndDocs($filteredHeaderParams);

		// Block-body depth-0 {varType}: a declared contract, so it wins over a captured/inferred
		// type below wherever the two could collide (an untyped own param re-declared in the body,
		// or a param-less block's explicit-arg name) - per-variable priority, same rule a typed own
		// param already gets unconditionally.
		// universe->contains() guards forBlock()'s own factsFor()/cache lookup the same way
		// TemplateEdgeIndex::targetExists() already does: unlike outgoingSites()/incomingEdges()
		// (safe dictionary misses), factsFor() falls through to a raw sha1_file() on ANY absolute
		// path it's given, so a $relativePath this instance's own universe never indexed must
		// degrade to no body-varType facts rather than reach that fallback - see wiring.neon's own
		// orisai.nette.latte.narrowing.storePath comment for why a RuleTestCase-built container's projectRoot can
		// disagree with $relativePath's real coordinate system this way. This method (unlike
		// EdgeAnchorInjector's own forBlock() call, gated by $narrowingEnabled) runs unconditionally,
		// which is why only this call site needed the guard.
		$absoluteFile = $this->absoluteFor($relativePath);
		$bodyVarTypes = $blockName !== null && $this->universe->contains($absoluteFile)
			? $this->declaredVarsResolver->forBlock($absoluteFile, $blockName)
			: [];

		$capturedTypes = $blockName !== null
			? $this->capturedBlockArgTypes($blockName, $relativePath, $contexts, $bodyVarTypes)
			: [];

		foreach ($ownParams as [$type, $name]) {
			$params[] = new Param(new Variable($name));
			if ($type !== null) {
				$docLines[] = " * @param $type \$$name";
			} elseif (isset($bodyVarTypes[$name])) {
				$docLines[] = " * @param {$bodyVarTypes[$name]} \$$name";
			} elseif (isset($capturedTypes[$name])) {
				$docLines[] = " * @param {$capturedTypes[$name]} \$$name";
			}
		}

		// Real Latte's extractMethod only emits the universal extract($ʟ_args) fallback for a block
		// with ZERO own declared params (BlockMacros.php's own `$params ? ... : null` branch picks
		// the positional-only prologue the moment a block declares even one param) - a block with an
		// own param never binds a same-call named arg beyond its own declared list, so the whole
		// extra-args mechanism must never fire once $ownParams is non-empty (paired runtime probe:
		// CrossFileScopeParityTest::testOwnParamBlockNeverExtractsUnmatchedExtraArg).
		if ($blockName !== null && $ownParams === []) {
			$extraTypes = $this->extraBlockArgTypes($blockName, $relativePath, $ownParams);
			foreach ($extraTypes as $name => $type) {
				if (isset($headerNames[$name])) {
					continue;
				}

				$params[] = new Param(new Variable($name));
				$docLines[] = ' * @param ' . ($bodyVarTypes[$name] ?? $type) . " \$$name";
			}
		}

		$method->params = $params;
		$this->setParamDoc($method, $docLines);
	}

	// Consumes the SAME per-call-site capture EdgeAnchorInjector wrote for this block's UNTYPED own
	// params (see LatteSiteScopeCaptureIntegrationTest::testCapturesNarrowedAmbientVarAndCompoundArg
	// ExpressionEndToEnd) - a typed own param never reaches this method (see the $type !== null
	// branch above), matching per-variable priority. Every dispatch site targeting this block name,
	// across every context this file itself is reached with, is UNIONED per param name: multiple
	// call sites/contexts disagreeing on a captured type widen the doc to `Foo|Bar` rather than
	// picking one arbitrarily - deduped, sorted for determinism.
	// Only sees sites edgeIndex->outgoingSites() reports WITH a real anchor: a block-to-block or
	// imported-block dispatch site never got one (EdgeAnchorInjector::injectIntoMethod skips
	// them/buildImportedBlockManifest), so it silently contributes nothing here - fixing that is an
	// anchor-side change, not a capturedBlockArgTypes one.
	// $declaredNames (this block's own body-depth-0 {varType}s) is threaded into CapturedOverlay's
	// own declared filter rather than re-checked here: an old-store-version entry for a name the
	// body now declares must never even enter $typesByName, not just lose the applyBlockParams()
	// priority check afterward (defense in depth against a future caller of this union that
	// forgets the priority check).

	/**
	 * @param list<TemplateContext> $contexts
	 * @param array<string, string> $declaredNames
	 * @return array<string, string>
	 */
	private function capturedBlockArgTypes(
		string $blockName,
		string $relativePath,
		array $contexts,
		array $declaredNames = []
	): array
	{
		if ($relativePath === '') {
			return [];
		}

		$sites = [];
		foreach ($this->edgeIndex->outgoingSites($relativePath) as $site) {
			if ($site->getKind() === IncludeTarget::KIND_STATIC_BLOCK && $site->getRawTarget() === $blockName) {
				$sites[] = $site;
			}
		}

		if ($sites === []) {
			return [];
		}

		$includerAbsolute = $this->absoluteFor($relativePath);
		$effectiveContexts = $contexts !== [] ? $contexts : [TemplateContext::root([])];

		/** @var array<string, array<string, true>> $typesByName */
		$typesByName = [];
		foreach ($sites as $site) {
			foreach ($effectiveContexts as $context) {
				$slice = $this->capturedOverlay->getSlice(
					$relativePath,
					$includerAbsolute,
					$site->getLatteLine(),
					$site->getRawTarget(),
					$context->canonicalHash(),
					$declaredNames,
				);
				if ($slice === null) {
					continue;
				}

				foreach ($slice['args'] as $name => $type) {
					$typesByName[$name][$type] = true;
				}
			}
		}

		$result = [];
		foreach ($typesByName as $name => $types) {
			$names = array_keys($types);
			sort($names, SORT_STRING);
			$result[$name] = implode('|', array_map([self::class, 'parenthesizeUnionMember'], $names));
		}

		return $result;
	}

	// F4 closure: a param-less block still receives explicit include-args at runtime via Latte's
	// universal extract($ʟ_args), independent of capturedBlockArgTypes()'s own-param-only,
	// anchor-only reach above - every {include name: expr}/{embed name: expr} site targeting this
	// block name is a candidate, same-file (this file's own outgoingSites()) or reached through any
	// file that structurally includes/imports/extends/embeds this one (this file's own
	// incomingEdges(), each includer's own outgoingSites()). Typed via ArgTyper's compile-time
	// literal/variable classification (a root, var-less context - real precision for a call site's
	// own declared vars is a possible future refinement, not required to close this gap) rather than
	// the narrowing store: no anchor is ever written for these names, so this mechanism can never
	// perturb the committed sitescope.

	/**
	 * @param array<int, array{string|null, string}> $ownParams
	 * @return array<string, string>
	 */
	private function extraBlockArgTypes(string $blockName, string $relativePath, array $ownParams): array
	{
		if ($relativePath === '') {
			return [];
		}

		$ownNames = [];
		foreach ($ownParams as [, $name]) {
			$ownNames[$name] = true;
		}

		$sites = $this->blockTargetSites($relativePath, $blockName);
		foreach ($this->edgeIndex->incomingEdges($relativePath) as $edge) {
			foreach ($this->blockTargetSites($edge['includer'], $blockName) as $site) {
				$sites[] = $site;
			}
		}

		if ($sites === []) {
			return [];
		}

		$rootContext = TemplateContext::root([]);

		/** @var array<string, array<string, true>> $typesByName */
		$typesByName = [];
		foreach ($sites as $site) {
			$typed = $this->argTyper->typeArgs($site, $rootContext);
			foreach ($typed['vars'] as $name => $type) {
				if (isset($ownNames[$name])) {
					continue;
				}

				$typesByName[$name][$type] = true;
			}
		}

		$result = [];
		foreach ($typesByName as $name => $types) {
			$names = array_keys($types);
			sort($names, SORT_STRING);
			$result[$name] = implode('|', array_map([self::class, 'parenthesizeUnionMember'], $names));
		}

		ksort($result, SORT_STRING);

		return $result;
	}

	/**
	 * @return list<IncludeTarget>
	 */
	private function blockTargetSites(string $relativePath, string $blockName): array
	{
		$sites = [];
		foreach ($this->edgeIndex->outgoingSites($relativePath) as $site) {
			if ($site->getKind() === IncludeTarget::KIND_STATIC_BLOCK && $site->getRawTarget() === $blockName) {
				$sites[] = $site;
			}
		}

		return $sites;
	}

	// A captured member string carrying its own top-level `|`/`:`/`(` (a callable shape's
	// `(params): returnType`, or an already-unioned describe()) can re-parse with different
	// precedence once joined into a wider union by a bare `|` - wrapping it in parens makes the
	// join point unambiguous. TypeStringResolver accepts a redundant parenthesis around any member,
	// so a plain name passes through untouched.
	private static function parenthesizeUnionMember(string $type): string
	{
		if (strpos($type, '|') === false && strpos($type, ':') === false && strpos($type, '(') === false) {
			return $type;
		}

		return '(' . $type . ')';
	}

	private function absoluteFor(string $relativePath): string
	{
		return rtrim($this->universe->projectRoot(), '/') . '/' . $relativePath;
	}

	/**
	 * @param array<int, array{string|null, string, string|null, int}> $parameters
	 */
	private function applyParameterList(ClassMethod $method, array $parameters): void
	{
		$params = [];
		$docLines = [];

		foreach ($parameters as [$type, $name, $defaultSource]) {
			if ($name === self::THIS) {
				continue;
			}

			$default = $defaultSource !== null ? $this->parseExpr($defaultSource) : null;
			$params[] = new Param(new Variable($name), $default);
			if ($type !== null) {
				$docLines[] = " * @param $type \$$name";
			}
		}

		$method->params = $params;
		$this->setParamDoc($method, $docLines);
	}

	/**
	 * @param array<int, array{string, string|null}> $headerParams
	 * @return array{array<int, Param>, array<int, string>}
	 */
	private function buildParamsAndDocs(array $headerParams): array
	{
		$params = [];
		$docLines = [];

		foreach ($headerParams as [$name, $type]) {
			// $this is always the implicit object context - never a real declared parameter
			// (InvalidParameterNameRule, nonIgnorable). A {varType $this} declaration is simply not
			// honored here; it produces no diagnostic, it is just dropped.
			if ($name === self::THIS) {
				continue;
			}

			$params[] = new Param(new Variable($name));
			if ($type !== null) {
				$docLines[] = " * @param $type \$$name";
			}
		}

		return [$params, $docLines];
	}

	/**
	 * @param array<int, string> $docLines
	 */
	private function setParamDoc(ClassMethod $method, array $docLines): void
	{
		if ($docLines === []) {
			$method->setAttribute('comments', []);

			return;
		}

		$method->setDocComment(new Doc("/**\n" . implode("\n", $docLines) . "\n */"));
	}

	private function parseExpr(string $source): Expr
	{
		$stmts = $this->phpParser->parseString('<?php ' . $source . ';');
		$first = $stmts[0] ?? null;
		if ($first instanceof Expression) {
			return $first->expr;
		}

		return new ConstFetch(new Name('null'));
	}

	private function applyDeclaredScope(Class_ $class, Declarations $declarations): void
	{
		$plan = $this->buildPropPlan($declarations);

		$byVarLine = [];
		$byDefaultLine = [];
		$midFile = [];
		foreach ($plan as $entry) {
			if ($entry['kind'] === 'var') {
				$byVarLine[$entry['line'] . ':' . $entry['name']] = $entry;
			} elseif ($entry['kind'] === 'default') {
				$byDefaultLine[$entry['line']][$entry['name']] = $entry;
			} else {
				$midFile[] = $entry;
			}
		}

		$newProps = [];

		foreach ($class->stmts as $stmt) {
			if (!$stmt instanceof ClassMethod || $stmt->stmts === null) {
				continue;
			}

			$this->rewriteMethodBody($stmt, $byVarLine, $byDefaultLine, $newProps);
		}

		$main = $this->findMethod($class, 'main');
		if ($main !== null && $main->stmts !== null) {
			foreach ($midFile as $entry) {
				$propName = 'prop_' . $entry['propIndex'] . '_' . $entry['name'];
				$newProps[] = $this->buildProp($propName, $entry['type']);
				$insertStmt = new Expression(
					new Assign(new Variable($entry['name']), new StaticPropertyFetch(new Name('self'), $propName)),
				);
				$index = $this->findInsertIndex($main->stmts, $entry['line']);
				array_splice($main->stmts, $index, 0, [$insertStmt]);
			}
		}

		if ($newProps !== []) {
			array_splice($class->stmts, 0, 0, $newProps);
		}
	}

	/**
	 * @param array<string, array{kind: string, name: string, type: string, line: int, propIndex: int}> $byVarLine
	 * @param array<int, array<string, array{kind: string, name: string, type: string, line: int, propIndex: int}>> $byDefaultLine
	 * @param array<int, Property> $newProps
	 */
	private function rewriteMethodBody(
		ClassMethod $method,
		array $byVarLine,
		array $byDefaultLine,
		array &$newProps
	): void
	{
		if ($method->stmts === null) {
			return;
		}

		$i = 0;
		while ($i < count($method->stmts)) {
			$stmt = $method->stmts[$i];
			$line = $stmt->getStartLine();

			$assign = $this->matchVarAssign($stmt);
			if (
				$assign !== null
				&& $assign->var instanceof Variable
				&& is_string($assign->var->name)
				&& isset($byVarLine[$line . ':' . $assign->var->name])
			) {
				$varName = $assign->var->name;
				$entry = $byVarLine[$line . ':' . $varName];
				$propName = 'prop_' . $entry['propIndex'] . '_' . $varName;
				$newProps[] = $this->buildProp($propName, $entry['type']);

				$replacement = [
					new Expression(new Assign(new StaticPropertyFetch(new Name('self'), $propName), $assign->expr)),
					new Expression(
						new Assign(new Variable($varName), new StaticPropertyFetch(new Name('self'), $propName)),
					),
				];
				array_splice($method->stmts, $i, 1, $replacement);
				$i += count($replacement);

				continue;
			}

			$pairs = $this->matchDefaultExtract($stmt) ?? $this->matchDefaultCoalesce($stmt);
			if ($pairs !== null) {
				$replacement = [];
				foreach ($pairs as [$name, $exprNode]) {
					$entry = $byDefaultLine[$line][$name] ?? null;
					if ($entry !== null) {
						$propName = 'prop_' . $entry['propIndex'] . '_' . $name;
						$newProps[] = $this->buildProp($propName, $entry['type']);
						$replacement[] = new Expression(
							new Assign(new StaticPropertyFetch(new Name('self'), $propName), $exprNode),
						);
						$replacement[] = new Expression(
							new Coalesce(new Variable($name), new StaticPropertyFetch(new Name('self'), $propName)),
						);
					} else {
						$replacement[] = new Expression(new Coalesce(new Variable($name), $exprNode));
					}
				}

				array_splice($method->stmts, $i, 1, $replacement);
				$i += count($replacement);

				continue;
			}

			$i++;
		}
	}

	private function matchVarAssign(Stmt $stmt): ?Assign
	{
		if ($stmt instanceof Expression && $stmt->expr instanceof Assign && $stmt->expr->var instanceof Variable) {
			return $stmt->expr;
		}

		return null;
	}

	/**
	 * @return array<int, array{string, Expr}>|null
	 */
	private function matchDefaultExtract(Stmt $stmt): ?array
	{
		if (
			!$stmt instanceof Expression
			|| !$stmt->expr instanceof FuncCall
			|| !$stmt->expr->name instanceof Name
			|| $stmt->expr->name->toString() !== 'extract'
			|| count($stmt->expr->args) !== 2
			|| !$stmt->expr->args[0] instanceof Arg
			|| !$stmt->expr->args[1] instanceof Arg
		) {
			return null;
		}

		$skipArg = $stmt->expr->args[1]->value;
		if (!$skipArg instanceof ConstFetch || $skipArg->name->toString() !== 'EXTR_SKIP') {
			return null;
		}

		$arrayArg = $stmt->expr->args[0]->value;
		if (!$arrayArg instanceof Array_) {
			return null;
		}

		$pairs = [];
		foreach ($arrayArg->items as $item) {
			if (!$item->key instanceof String_) {
				continue;
			}

			$pairs[] = [$item->key->value, $item->value];
		}

		return $pairs;
	}

	// Latte 3 {default}: `$x ??= array_key_exists('x', get_defined_vars()) ? null : <expr>;`

	/**
	 * @return array<int, array{string, Expr}>|null
	 */
	private function matchDefaultCoalesce(Stmt $stmt): ?array
	{
		if (
			!$stmt instanceof Expression
			|| !$stmt->expr instanceof Coalesce
			|| !$stmt->expr->var instanceof Variable
			|| !is_string($stmt->expr->var->name)
		) {
			return null;
		}

		$value = $stmt->expr->expr;
		if (
			!$value instanceof Ternary
			|| !$value->if instanceof ConstFetch
			|| strtolower($value->if->name->toString()) !== 'null'
			|| !$value->cond instanceof FuncCall
			|| !$value->cond->name instanceof Name
			|| $value->cond->name->toString() !== 'array_key_exists'
		) {
			return null;
		}

		return [[$stmt->expr->var->name, $value->else]];
	}

	/**
	 * @param array<int, Stmt> $stmts
	 */
	private function findInsertIndex(array $stmts, int $line): int
	{
		foreach ($stmts as $index => $stmt) {
			if ($stmt->getStartLine() >= $line) {
				return $index;
			}
		}

		foreach ($stmts as $index => $stmt) {
			if ($stmt instanceof Return_) {
				return $index;
			}
		}

		return count($stmts);
	}

	private function buildProp(string $name, string $type): Property
	{
		$property = new Property(Modifiers::PRIVATE | Modifiers::STATIC, [
			new PropertyProperty($name),
		]);
		$property->setDocComment(new Doc("/**\n * @var $type\n */"));

		return $property;
	}

	/**
	 * @return array<int, array{kind: string, name: string, type: string, line: int, propIndex: int}>
	 */
	private function buildPropPlan(Declarations $declarations): array
	{
		$entries = [];
		foreach ($declarations->getTypedVars() as [$name, $type, $line]) {
			$entries[] = ['kind' => 'var', 'name' => $name, 'type' => $type, 'line' => $line];
		}

		foreach ($declarations->getTypedDefaults() as [$name, $type, $line]) {
			$entries[] = ['kind' => 'default', 'name' => $name, 'type' => $type, 'line' => $line];
		}

		foreach ($declarations->getMidFileVarTypes() as [$name, $type, $line]) {
			$entries[] = ['kind' => 'varType', 'name' => $name, 'type' => $type, 'line' => $line];
		}

		$positions = [];
		foreach ($entries as $originalIndex => $entry) {
			$positions[] = [$entry['line'], $originalIndex];
		}

		usort($positions, static function (array $a, array $b): int {
			$byLine = $a[0] <=> $b[0];
			if ($byLine !== 0) {
				return $byLine;
			}

			return $a[1] <=> $b[1];
		});

		$plan = [];
		foreach ($positions as $propIndex => [, $originalIndex]) {
			$entry = $entries[$originalIndex];
			$plan[] = [
				'kind' => $entry['kind'],
				'name' => $entry['name'],
				'type' => $entry['type'],
				'line' => $entry['line'],
				'propIndex' => $propIndex,
			];
		}

		return $plan;
	}

}
