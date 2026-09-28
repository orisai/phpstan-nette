<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use Latte\MacroTokens;
use Nette\IOException;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
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
use function trim;
use function ucfirst;
use function usort;
use const SORT_STRING;

final class EdgeAnchorInjector
{

	private const FILE_FORM_TAGS = ['include', 'embed', 'sandbox', 'import'];

	private const LAYOUT_TAGS = ['layout', 'extends'];

	private TemplateEdgeIndex $edgeIndex;

	private DeclaredVarsResolver $declaredVarsResolver;

	private DeclarationScanner $scanner;

	private LatteUniverse $universe;

	private ArgTyper $argTyper;

	private Parser $phpParser;

	private bool $includeIsolation;

	public function __construct(
		TemplateEdgeIndex $edgeIndex,
		DeclarationScanner $scanner,
		LatteUniverse $universe,
		Parser $phpParser,
		bool $includeIsolation = false
	)
	{
		$this->edgeIndex = $edgeIndex;
		$this->declaredVarsResolver = new DeclaredVarsResolver($scanner, $edgeIndex);
		$this->scanner = $scanner;
		$this->universe = $universe;
		$this->argTyper = new ArgTyper();
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
	// line, in the enclosing clone, so the same insertAtLineStrict() line-search finds it. See
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
	 * @param array<string, ClassMethod> $methodsByName
	 */
	private function injectIntoMethod(
		ClassMethod $method,
		string $relativePath,
		string $includerAbsolute,
		array $sites,
		string $contextId,
		TemplateContext $context,
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
			} else {
				// A miss here is either a block-dispatch site nested inside a NON-cloned block
				// method (a block calling another block, never appearing in a latteMain_ctx{i}
				// clone's own statement tree) or a file-form site whose line-tagged statement has
				// vanished (an eliminator folding it away unexpectedly - every include-family
				// emission keeps one at the mapped line otherwise). insertAtEnd() would misattribute
				// the anchor to the wrong scope in the first case, and risk capturing a manifest
				// var's type AFTER a reassignment below the site's real line in the second (a WRONG
				// type, not merely a wider one) - both strictly worse than no anchor, so this skips
				// silently instead (documented limitation, never a wrong-scope or wrong-type
				// capture).
				$this->insertAtLineStrict($method, $site->getLatteLine(), $anchor);
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
		$argItems = $this->buildBlockArgItems($site->getArgsSource(), $ownParams);

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
		try {
			$source = FileSystem::read($absoluteFile);
		} catch (IOException $e) {
			return [];
		}

		return $this->scanner->scan($source)->getDefineParams()[$blockName] ?? [];
	}

	// Positional, not named: {include #b, expr1, expr2} threads args by DECLARATION ORDER of the
	// block's own {define} params (BlockDispatchEliminator::rebuildDirectCall's own extraValues
	// cursor is positional too, ignoring any "name:"/"name=>" prefix a caller writes) - an optional
	// prefix is stripped here only so the expression itself parses, the name it carries is never
	// used to pick the target param. TokenIterator::$position is @internal (property.internalClass),
	// so a false-positive name/colon peek is undone by re-prepending the consumed symbol's own
	// value instead of rewinding the cursor - the same "no non-internal peek-then-rewind API"
	// constraint ArgTyper/extractArgPairs already work around by never needing to undo a consume.

	/**
	 * @return list<string>
	 */
	private function extractPositionalArgs(string $argsSource): array
	{
		$tokens = new MacroTokens($argsSource);
		$args = [];

		while ($tokens->isNext(...MacroTokens::SIGNIFICANT)) {
			if ($tokens->nextValue('(expand)') !== null) {
				$tokens->joinUntilSameDepth(',');
				$tokens->nextToken(',');

				continue;
			}

			$name = $tokens->nextValue(MacroTokens::T_SYMBOL);
			$isNamed = $name !== null && ($tokens->nextToken('=>') !== null || $tokens->nextToken(':') !== null);
			$rest = trim($tokens->joinUntilSameDepth(','));

			$args[] = $name === null || $isNamed ? $rest : trim($name . ' ' . $rest);
			$tokens->nextToken(',');
		}

		return $args;
	}

	// Per-variable priority, applied per-param instead of per-file: a TYPED own param
	// ({define b, string $a}) is a declaration, so it never takes a captured type, exactly like a
	// file-form target's declared var (buildManifest() above) - only an UNTYPED own param
	// ({define b, $a}) captures its arg expression's real Scope type.

	/**
	 * @param array<int, array{string|null, string}> $ownParams
	 * @return list<ArrayItem>
	 */
	private function buildBlockArgItems(string $argsSource, array $ownParams): array
	{
		$items = [];
		foreach ($this->extractPositionalArgs($argsSource) as $i => $exprSource) {
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
		foreach ($this->extractArgPairs($site->getArgsSource()) as [$name, $exprSource]) {
			$expr = $this->parseExpr($exprSource);
			if ($expr === null) {
				continue;
			}

			$items[] = new ArrayItem($expr, new String_($name));
		}

		return $items;
	}

	// Mirrors ArgTyper::typeArgs()'s own tokenization loop exactly, capturing each arg's raw
	// expression source instead of classifying it - the same MacroTokens grammar (name: expr /
	// name => expr, comma-separated, (expand) spreads skipped) must stay in lockstep with that
	// class or the two would silently disagree on which args exist.

	/**
	 * @return list<array{string, string}>
	 */
	private function extractArgPairs(string $argsSource): array
	{
		$tokens = new MacroTokens($argsSource);
		$pairs = [];

		while ($tokens->isNext(...MacroTokens::SIGNIFICANT)) {
			if ($tokens->nextValue('(expand)') === null) {
				$name = $tokens->nextValue(MacroTokens::T_SYMBOL);
				if ($name !== null && ($tokens->nextToken('=>') !== null || $tokens->nextToken(':') !== null)) {
					$expr = trim($tokens->joinUntilSameDepth(','));
					$pairs[] = [$name, $expr];
				}
			}

			$tokens->joinUntilSameDepth(',');
			$tokens->nextToken(',');
		}

		return $pairs;
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

	// A NOT-FOUND result here must never fall back to insertAtEnd(): an end-of-body capture can
	// observe a DIFFERENT program state than the site itself did - either a manifest var
	// reassigned somewhere below the site's real line (file-form callers - the line-tagged
	// statement vanishing is the only way this method ever misses, since every include-family
	// emission keeps one at the mapped line), or the wrong method's scope entirely (block-dispatch
	// callers, whose site lives in a DIFFERENT, non-cloned method than $method - see the
	// injectIntoMethod() call site comment). Both are strictly worse than no anchor at all: no
	// anchor degrades to today's declared-wide behavior, which is always safe; a misplaced one
	// might not be. Skips silently instead.
	//
	// Residual constraint (block-dispatch only): this searches EVERY latteMain_ctx{i} clone for a
	// block-to-block site's line, and Latte line numbers are the shared source-file numbering
	// space (main body and block bodies in the same .latte file can share line numbers with
	// unrelated statements in a clone). A same-numbered false match inside a currently-iterated
	// clone would splice the anchor there instead of skipping - safety then rests on where a false
	// match can land, not on the entry being unread: every real, still-line-tagged candidate for
	// such a collision sits at or above the guard's own nesting level (e.g. the method's trailing
	// auto-appended return), strictly after every conditional's control-flow join, so PHPStan's
	// proven type there is the union of all incoming branches - a supertype (or equal) of what the
	// guarded branch alone would show. A misplaced capture can only widen, never narrow, what the
	// collector/writer later persist.
	private function insertAtLineStrict(ClassMethod $method, int $line, Expression $anchor): void
	{
		$this->tryInsertAtLine($method, $line, $anchor);
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
