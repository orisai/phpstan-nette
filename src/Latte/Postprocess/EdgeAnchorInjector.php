<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Includes\ArgTyper;
use OriPhpstan\Nette\Latte\Includes\DeclaredVarsResolver;
use OriPhpstan\Nette\Latte\Includes\EdgeScope;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Parser\Parser;
use Throwable;
use function array_diff;
use function array_keys;
use function array_merge;
use function array_splice;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function preg_match;
use function rtrim;
use function sort;
use function strcmp;
use function ucfirst;
use function usort;
use const SORT_STRING;

final class EdgeAnchorInjector
{

	private const FILE_FORM_TAGS = ['include', 'embed', 'sandbox', 'import'];

	private const LAYOUT_TAGS = ['layout', 'extends'];

	private const PREPARE_METHOD = 'lattePrepare';

	private TemplateEdgeIndex $edgeIndex;

	private DeclaredVarsResolver $declaredVarsResolver;

	private LatteUniverse $universe;

	private ArgTyper $argTyper;

	private Parser $phpParser;

	private bool $includeIsolation;

	public function __construct(
		TemplateEdgeIndex $edgeIndex,
		LatteUniverse $universe,
		Parser $phpParser,
		bool $includeIsolation = false
	)
	{
		$this->edgeIndex = $edgeIndex;
		$this->declaredVarsResolver = new DeclaredVarsResolver($edgeIndex);
		$this->universe = $universe;
		$this->argTyper = new ArgTyper($edgeIndex->getAdapterAccessor());
		$this->phpParser = $phpParser;
		$this->includeIsolation = $includeIsolation;
	}

	// One anchor statement per outgoing file-form/layout-chain edge, injected into every
	// latteMain_ctx{i} clone this includer has (cloning already ran - DeclarationInjector splices
	// clones in BEFORE the eliminator traverser this pass runs after). The clone's own numeric
	// suffix only locates $contexts[$index] - contextsFor() already returns canonicalHash-sorted
	// contexts (ContextResolver::dedupeAndSort), the same order DeclarationInjector re-derives for
	// clone naming. The store key's own context-id component is that context's canonicalHash(),
	// not the ordinal, so a key can never resolve against the wrong context.
	// Consume-time contract: store lookups key by context canonical hash, so
	// ContextResolver must derive the identical hash for the identical context - the fingerprint
	// fold depends on it.
	//
	// Block-dispatch sites (KIND_STATIC_BLOCK) ride the SAME per-clone loop above, since
	// BlockDispatchEliminator - like the file-form eliminators - runs AFTER cloning too: a
	// same-file target (found in this class's own $methodsByName) already got rewritten to a
	// direct $this->blockXxx(...) call at the site's line; a target BlockDispatchEliminator
	// couldn't resolve locally (imported via {import}, or a layout-slot block only an extender
	// declares) stays a $this->renderBlock(...) call instead (both of BlockDispatchEliminator's
	// own rewrite attempts fail identically: methodsByName lookup misses, and the vendor-compiled
	// args are already a literal [] for a cross-file target, never a get_defined_vars() FuncCall
	// dropGetDefinedVarsArg() could match) - either way the dispatch stays IN PLACE at the site's
	// line, in the enclosing clone, so the same tryInsertAtLine() line-search finds it. See
	// buildBlockAnchor()'s own comment for how same-file vs. imported changes the manifest shape.

	/**
	 * @param array<Stmt> $stmts
	 * @param list<TemplateContext> $contexts
	 */
	public function inject(array $stmts, string $relativePath, array $contexts): void
	{
		if ($relativePath === '') {
			return;
		}

		$sites = $this->edgeIndex->outgoingSites($relativePath);
		if ($sites === []) {
			return;
		}

		$class = MainMethodFinder::findClass($stmts);
		if ($class === null) {
			return;
		}

		if ($contexts !== []) {
			usort(
				$contexts,
				static fn (TemplateContext $a, TemplateContext $b): int => strcmp(
					$a->canonicalHash(),
					$b->canonicalHash(),
				),
			);
		}

		$includerAbsolute = $this->absoluteFor($relativePath);
		$methodsByName = $this->collectMethodsByName($class);

		foreach ($class->stmts as $stmt) {
			if (!$stmt instanceof ClassMethod) {
				continue;
			}

			$index = $this->matchClone($stmt->name->toString());
			if ($index === null) {
				continue;
			}

			$context = $contexts[$index] ?? TemplateContext::root([]);

			$this->injectIntoMethod(
				$stmt,
				$relativePath,
				$includerAbsolute,
				$sites,
				$context->canonicalHash(),
				$context,
				$contexts,
				$methodsByName,
			);
		}
	}

	/**
	 * @return array<string, ClassMethod>
	 */
	private function collectMethodsByName(Class_ $class): array
	{
		$methods = [];
		foreach ($class->stmts as $stmt) {
			if ($stmt instanceof ClassMethod) {
				$methods[$stmt->name->toString()] = $stmt;
			}
		}

		return $methods;
	}

	private function matchClone(string $methodName): ?int
	{
		if ($methodName === 'latteMain') {
			return 0;
		}

		if (preg_match('~^latteMain_ctx(\d+)$~', $methodName, $m) === 1) {
			return (int) $m[1];
		}

		return null;
	}

	/**
	 * @param list<IncludeTarget> $sites
	 * @param list<TemplateContext> $allContexts
	 * @param array<string, ClassMethod> $methodsByName
	 */
	private function injectIntoMethod(
		ClassMethod $method,
		string $relativePath,
		string $includerAbsolute,
		array $sites,
		string $contextId,
		TemplateContext $context,
		array $allContexts,
		array $methodsByName
	): void
	{
		foreach ($sites as $site) {
			$anchor = $this->buildAnchor($site, $relativePath, $includerAbsolute, $contextId, $context, $methodsByName);
			if ($anchor === null) {
				continue;
			}

			if (in_array($site->getTag(), self::LAYOUT_TAGS, true)) {
				$this->insertAtEnd($method, $anchor);
			} elseif (
				!$this->tryInsertAtLine($method, $site->getLatteLine(), $anchor)
				&& $site->getKind() === IncludeTarget::KIND_STATIC_FILE
				&& $this->contextsAgreeOn(
					$this->capturedNames($site, $includerAbsolute, $context, $allContexts),
					$allContexts,
				)
			) {
				// A file-form site inside a {block}/{define}/{snippet} body has its line-tagged
				// statement in that block's own method, never in a clone: the anchor goes next to it
				// there. The block method is shared by every clone and its params are the UNION of
				// the contexts' types (DeclarationInjector::buildUnionParams, `mixed` on
				// disagreement), so a capture there is only the per-context truth when every context
				// declares the same type for each name it would record - otherwise the edge already
				// knows better than the block scope and the anchor is skipped (a missed capture is
				// the safe direction). A block-dispatch site nested in a non-cloned block method
				// stays unanchored (a wrong-scope capture would be worse than none), as does a
				// file-form site whose statement vanished; insertAtEnd() is never a fallback here,
				// it could capture a manifest var AFTER a reassignment below the site's real line.
				$this->insertIntoBlockMethod($methodsByName, $site->getLatteLine(), $anchor);
			}
		}
	}

	/**
	 * @param array<string, ClassMethod> $methodsByName
	 */
	private function buildAnchor(
		IncludeTarget $site,
		string $relativePath,
		string $includerAbsolute,
		string $contextId,
		TemplateContext $context,
		array $methodsByName
	): ?Expression
	{
		if ($site->getKind() === IncludeTarget::KIND_STATIC_BLOCK) {
			return $this->buildBlockAnchor(
				$site,
				$relativePath,
				$includerAbsolute,
				$contextId,
				$context,
				$methodsByName,
			);
		}

		if (
			!in_array($site->getTag(), self::FILE_FORM_TAGS, true)
			&& !in_array($site->getTag(), self::LAYOUT_TAGS, true)
		) {
			return null;
		}

		if ($site->getKind() !== IncludeTarget::KIND_STATIC_FILE || !$this->edgeIndex->targetExists($site)) {
			return null;
		}

		$manifest = $this->buildManifest($site, $includerAbsolute, $context);
		$argItems = $this->buildArgItems($site);

		if ($manifest === [] && $argItems === []) {
			return null;
		}

		return $this->makeAnchorExpression($relativePath, $site, $contextId, $manifest, $argItems);
	}

	// Block-dispatch counterpart of the file-form branch above: BlockDispatchEliminator has
	// already run by this point (same ordering guarantee as the file-form case), so a SAME-FILE
	// target's block method already carries its FINAL declared param names (own {define} params
	// merged with inherited header params) as real PHP Param nodes - reading them directly off
	// $methodsByName is the single source of truth BlockDispatchEliminator's own rebuildDirectCall()
	// used to decide the same thing, so there is no risk of the two drifting apart. A target NOT
	// found in $methodsByName (imported via {import}, or a layout-slot block only an extender
	// declares) never got a direct-call rewrite either (BlockDispatchEliminator's own methodsByName
	// lookup fails identically), so the dispatch stays a renderBlock() call - see class doc comment.

	/**
	 * @param array<string, ClassMethod> $methodsByName
	 */
	private function buildBlockAnchor(
		IncludeTarget $site,
		string $relativePath,
		string $includerAbsolute,
		string $contextId,
		TemplateContext $context,
		array $methodsByName
	): ?Expression
	{
		$calleeMethod = $methodsByName['block' . ucfirst($site->getRawTarget())] ?? null;
		$ownParams = $calleeMethod !== null
			? $this->ownDefineParamsFor($includerAbsolute, $site->getRawTarget())
			: [];

		$manifest = $calleeMethod !== null
			? $this->buildSameFileBlockManifest($includerAbsolute, $context, $calleeMethod, $site->getRawTarget())
			: $this->buildImportedBlockManifest($context, $relativePath, $site->getRawTarget());
		$argItems = $this->buildBlockArgItems($site, $ownParams);

		if ($manifest === [] && $argItems === []) {
			return null;
		}

		return $this->makeAnchorExpression($relativePath, $site, $contextId, $manifest, $argItems);
	}

	/**
	 * @param list<string> $manifest
	 * @param list<ArrayItem> $argItems
	 */
	private function makeAnchorExpression(
		string $relativePath,
		IncludeTarget $site,
		string $contextId,
		array $manifest,
		array $argItems
	): Expression
	{
		$key = SiteScopeStore::key($relativePath, $site->getLatteLine(), $site->getRawTarget(), $contextId);

		$call = new StaticCall(
			new FullyQualified(Helpers::class),
			new Identifier('edgeScope'),
			[
				new Arg(new String_($key)),
				new Arg(new Array_($argItems)),
			],
			['startLine' => $site->getLatteLine(), 'endLine' => $site->getLatteLine()],
		);
		$call->setAttribute('latte.edgeManifest', $manifest);

		return new Expression($call, ['startLine' => $site->getLatteLine(), 'endLine' => $site->getLatteLine()]);
	}

	// Caller locals a bare/untyped-header block leaves untyped: the includer's own context vars
	// (its formally-declared params) UNION its top-level {var} locals - the same static
	// approximation EdgeScope's layout branch already uses for "child's finished scope" - minus
	// whatever the callee's FINAL param list (own {define} params + inherited header params,
	// already materialized on $calleeMethod by DeclarationInjector) already declares, minus the
	// callee's own body-depth-0 {varType} names (DeclaredVarsResolver::forBlock) - a
	// contractually-typed block var is never call-site-narrowed, same per-variable priority as a
	// file-form target's own declared vars (buildManifest() above).

	/**
	 * @return list<string>
	 */
	private function buildSameFileBlockManifest(
		string $includerAbsolute,
		TemplateContext $context,
		ClassMethod $calleeMethod,
		string $blockName
	): array
	{
		$callerLocals = array_merge(
			$context->getVars(),
			$this->edgeIndex->factsFor($includerAbsolute)->getTopLevelVars(),
		);

		$declaredTarget = [];
		foreach ($calleeMethod->params as $param) {
			if ($param->var instanceof Variable && is_string($param->var->name)) {
				$declaredTarget[$param->var->name] = true;
			}
		}

		foreach (array_keys($this->declaredVarsResolver->forBlock($includerAbsolute, $blockName)) as $name) {
			$declaredTarget[$name] = true;
		}

		$manifest = array_values(array_diff(array_keys($callerLocals), array_keys($declaredTarget)));
		sort($manifest, SORT_STRING);

		return $manifest;
	}

	// An imported/layout-slot block is a SEPARATE compiled class in a file this pass never scans
	// locally, so its own {define}/{block} PARAM names still aren't available here - only its
	// body-depth-0 {varType} names are (DeclaredVarsResolver::forBlock on the DEFINING file,
	// resolved via TemplateEdgeIndex::reachableBlockOrigins() - the same mechanism
	// IncludeContractChecker::checkBlockDeclaredVars already uses for this exact "which file
	// declares this block name" problem). An ambiguous name (more than one reachable origin) unions
	// every origin's declared names rather than skipping, unlike checkBlockDeclaredVars's own
	// single-origin-only contract check: over-EXCLUDING from a manifest is always the safe
	// direction (a declared-target var never reads a captured type anyway), whereas guessing which
	// origin's CONTRACT applies is not. Caller {var} locals are deliberately NOT unioned in:
	// RuntimeParityTest (testImportedBlockRunsInImporterScope) proves they never reach an imported
	// block at runtime.

	/**
	 * @return list<string>
	 */
	private function buildImportedBlockManifest(
		TemplateContext $context,
		string $relativePath,
		string $blockName
	): array
	{
		$declaredTarget = [];
		foreach ($this->edgeIndex->reachableBlockOrigins($relativePath)[$blockName] ?? [] as $originRel) {
			$originAbsolute = $this->absoluteFor($originRel);
			foreach (array_keys($this->declaredVarsResolver->forBlock($originAbsolute, $blockName)) as $name) {
				$declaredTarget[$name] = true;
			}
		}

		$manifest = array_values(array_diff(array_keys($context->getVars()), array_keys($declaredTarget)));
		sort($manifest, SORT_STRING);

		return $manifest;
	}

	/**
	 * @return array<int, array{string|null, string}>
	 */
	private function ownDefineParamsFor(string $absoluteFile, string $blockName): array
	{
		return $this->edgeIndex->declarationsFor($absoluteFile)->getDefineParams()[$blockName] ?? [];
	}

	// Per-variable priority, applied per-param instead of per-file: a TYPED own param
	// ({define b, string $a}) is a declaration, so it never takes a captured type, exactly like a
	// file-form target's declared var (buildManifest() above) - only an UNTYPED own param
	// ({define b, $a}) captures its arg expression's real Scope type.

	/**
	 * @param array<int, array{string|null, string}> $ownParams
	 * @return list<ArrayItem>
	 */
	private function buildBlockArgItems(IncludeTarget $site, array $ownParams): array
	{
		$items = [];
		foreach ($this->argTyper->argSources($site) as $i => $exprSource) {
			$param = $ownParams[$i] ?? null;
			if ($param === null || $param[0] !== null) {
				continue;
			}

			$expr = $this->parseExpr($exprSource);
			if ($expr === null) {
				continue;
			}

			$items[] = new ArrayItem($expr, new String_($param[1]));
		}

		return $items;
	}

	// Manifest = provided names (EdgeScope's own per-tag algebra) minus the explicit arg
	// names (those are captured with their real expression type via buildArgItems() instead of a
	// same-named ambient variable lookup) minus whatever the target already declares (per-variable
	// priority keeps the store minimal - a declared-target var never reads a captured type anyway).

	/**
	 * @return list<string>
	 */
	private function buildManifest(IncludeTarget $site, string $includerAbsolute, TemplateContext $context): array
	{
		$scope = EdgeScope::resolve(
			$site,
			$context,
			$this->argTyper,
			fn (): array => $this->edgeIndex->factsFor($includerAbsolute)->getTopLevelVars(),
			$this->includeIsolation,
		);

		$declaredTarget = $this->declaredVarsResolver->forFile($this->absoluteFor($site->getResolvedPath()));

		$manifest = array_values(array_diff(
			array_keys($scope['vars']),
			array_keys($scope['namedKeys']),
			array_keys($declaredTarget),
		));
		sort($manifest, SORT_STRING);

		return $manifest;
	}

	/**
	 * @return list<ArrayItem>
	 */
	private function buildArgItems(IncludeTarget $site): array
	{
		$items = [];
		foreach ($this->argTyper->namedArgSources($site) as [$name, $exprSource]) {
			$expr = $this->parseExpr($exprSource);
			if ($expr === null) {
				continue;
			}

			$items[] = new ArrayItem($expr, new String_($name));
		}

		return $items;
	}

	// Degradation rule: an arg expression this project's own parser cannot parse (e.g. leftover
	// Latte filter syntax) is omitted rather than crashing the whole pipeline - the target simply
	// keeps today's mixed-typed behaviour for that one arg.
	private function parseExpr(string $source): ?Expr
	{
		try {
			$stmts = $this->phpParser->parseString('<?php ' . $source . ';');
		} catch (Throwable $e) {
			return null;
		}

		$first = $stmts[0] ?? null;

		return $first instanceof Expression ? $first->expr : null;
	}

	// The block method holding a file-form site's statement: any non-clone method with a statement
	// at the site's line ({block}/{define}/{snippet} bodies keep their own line numbers), never
	// lattePrepare (its statements borrow the head's last line). Every clone inserts its own
	// anchor here, one per context, all right before the same statement.

	/**
	 * @param array<string, ClassMethod> $methodsByName
	 */
	private function insertIntoBlockMethod(array $methodsByName, int $line, Expression $anchor): void
	{
		foreach ($methodsByName as $name => $blockMethod) {
			if (
				$this->matchClone($name) === null
				&& $name !== self::PREPARE_METHOD
				&& $this->tryInsertAtLine($blockMethod, $line, $anchor)
			) {
				return;
			}
		}
	}

	// The names a block anchor would record: the manifest, or - once explicit args are captured
	// too, their expressions may read any provided name - every name ANY context provides: a name
	// one context lacks types the shared block method from the other, and would be captured under
	// the wrong key.

	/**
	 * @param list<TemplateContext> $allContexts
	 * @return list<string>
	 */
	private function capturedNames(
		IncludeTarget $site,
		string $includerAbsolute,
		TemplateContext $context,
		array $allContexts
	): array
	{
		if ($this->argTyper->namedArgSources($site) === []) {
			return $this->buildManifest($site, $includerAbsolute, $context);
		}

		$names = [];
		foreach ($allContexts as $other) {
			foreach (array_keys($other->getVars()) as $name) {
				$names[$name] = true;
			}
		}

		return array_keys($names);
	}

	/**
	 * @param list<string> $names
	 * @param list<TemplateContext> $contexts
	 */
	private function contextsAgreeOn(array $names, array $contexts): bool
	{
		if (count($contexts) <= 1) {
			return true;
		}

		foreach ($names as $name) {
			$types = [];
			foreach ($contexts as $context) {
				$types[$context->getVars()[$name] ?? ''] = true;
			}

			if (count($types) !== 1) {
				return false;
			}
		}

		return true;
	}

	// A returned array from leaveNode() replaces the matched node in whatever Stmt-array property
	// currently holds it (top-level main body, an If_/Foreach_/TryCatch/... branch, at any nesting
	// depth) - the same NodeTraverser splice mechanism every eliminator in this directory already
	// relies on - so the anchor lands next to the real call site even when it is nested under
	// {if}/{foreach}, without hand-rolling a parent-tracking walk over every Stmt subtype.
	private function tryInsertAtLine(ClassMethod $method, int $line, Expression $anchor): bool
	{
		$visitor = new class ($line, $anchor) extends NodeVisitorAbstract {

			private int $line;

			private Expression $anchor;

			private bool $inserted = false;

			public function __construct(int $line, Expression $anchor)
			{
				$this->line = $line;
				$this->anchor = $anchor;
			}

			public function wasInserted(): bool
			{
				return $this->inserted;
			}

			/**
			 * @return array<Stmt>|null
			 */
			public function leaveNode(Node $node)
			{
				if ($this->inserted || !$node instanceof Stmt || $node->getStartLine() !== $this->line) {
					return null;
				}

				$this->inserted = true;

				return [$this->anchor, $node];
			}

		};

		$traverser = new NodeTraverser();
		$traverser->addVisitor($visitor);

		/** @var array<Stmt> $stmts */
		$stmts = $traverser->traverse($method->stmts ?? []);
		$method->stmts = $stmts;

		return $visitor->wasInserted();
	}

	private function insertAtEnd(ClassMethod $method, Expression $anchor): void
	{
		$stmts = $method->stmts ?? [];
		for ($i = count($stmts) - 1; $i >= 0; $i--) {
			if ($stmts[$i] instanceof Return_) {
				array_splice($stmts, $i, 0, [$anchor]);
				$method->stmts = $stmts;

				return;
			}
		}

		$stmts[] = $anchor;
		$method->stmts = $stmts;
	}

	private function absoluteFor(?string $relativePath): string
	{
		if ($relativePath === null) {
			return '';
		}

		return rtrim($this->universe->projectRoot(), '/') . '/' . $relativePath;
	}

}
