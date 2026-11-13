Maintainer documentation; the user-facing guide is ../README.md.

# PHPStan workarounds

Every place where the library works around a limitation of PHPStan (or of another extension). When PHPStan changes,
this is the list of what to re-check and what to delete. Verified against PHPStan 2.3.x (`composer.json` requires
`^2.3.5`); the PHPStan classes named are those of `phpstan-src` on `2.3.x`.

Each entry states:

- **Does** – what the library does, and where;
- **Because** – the PHPStan behaviour it answers, with the PHPStan class or method;
- **Needs** – what PHPStan would have to offer for the workaround to go;
- **Cost** – what users see meanwhile;
- **Gone when** – how to tell the workaround became unnecessary.

Background already described elsewhere is linked, not repeated. Add an entry with every new workaround, and remove it
with the code.

## Analysis model

### Narrowing needs several runs to settle

- **Does.** Call-site captures are collected during analysis, written by `LatteSiteScopeWriterRule` (a
  `Rule<CollectedDataNode>`) into the committed slice directory (`SiteScopeStore`), and read by the *next* run's parse
  (`ContextResolver`, `DeclarationInjector`). A run which rewrote or removed a slice reports the non-ignorable
  `orisai.nette.latte.narrowingStoreChanged`. See [latte.md](latte.md#the-committed-store) and
  [one-run capture lag](latte.md#one-run-capture-lag-and-convergence).
- **Because.** A template is a file of its own to PHPStan, typed at parse time from what the previous run proved. The
  types proven at an include site exist only after `NodeScopeResolver` walked the includer, and PHPStan has no second
  round: `AnalyserResultFinalizer` runs the `CollectedDataNode` rules once and never re-analyses a file afterwards.
  Only traits are analysed in a borrowing scope with errors bound to their own file
  (`MutatingScope::enterTrait()`, `ScopeContext::enterTrait()`), and that binding is derived from a `ClassReflection`,
  so no extension can reach it. The bleeding-edge two-pass body walk
  (`StatementsHandler::processBodyStmtNodesTwoPass()`, behind `unresolvedTemplateArguments`) re-walks a single function body for template-argument resolution; it is
  private and does not cross files.
- **Needs.** Either a re-analysis round after the `CollectedDataNode` phase until nothing moves (a fixpoint in the
  engine), or a way to have provider-supplied ("inlined") statements analysed in the borrowing scope with errors bound
  to their origin file. The second is prototyped on the owner's `phpstan-src` fork, branch
  `inlined-statements-provider` on `2.3.x`: an `InlinedStatementsProvider` extension point,
  `InlinedStatementsProcessor`, and an `InlinedFile` on `ScopeContext` (trait analysis reimplemented on top of it). Known limits of the prototype: no
  Turbo parity, a `return` in the inlined statements leaks into the borrower, variables they assign do not flow back,
  PHPDoc and `__FILE__` resolve against the borrower. Upstream discussion: phpstan/phpstan#15444. TwigStan has the
  same problem and still loops in a wrapper.
- **Cost.** A store which moves needs another plain run, and CI fails until the committed store matches: an include
  chain of depth D settles within D + 1 runs. On `NarrowingStoreRunsTest`'s three-template chain two runs write and the
  third is the first clean one, from an existing store and from an absent store directory alike. The store is
  committed and merged by regenerating it ([narrowing store lifecycle](../README.md#narrowing-store-lifecycle)).
- **Gone when.** PHPStan can analyse an include target in the includer's scope. Then the store, the writer rule, the
  slice file dependencies in `LatteDependencyRule`, `LatteReflectionCacheBypass` (below) and the
  `narrowingStoreChanged` identifier all go.

### The pre-analysis seam is a deprecated meta extension

- **Does.** `LatteResultCacheMeta` implements `ResultCacheMetaExtension`, deprecated in 2.3, with the constant hash
  `pre-analysis build`, and carries `@phpstan-ignore class.implementsDeprecatedInterface`. Its `getHash()` runs the
  discovery build (`PreAnalysisIndexBuilder::build()`) once. See
  [result-cache dependency tracking](README.md#result-cache-dependency-tracking).
- **Because.** `ResultCacheManager::restore()` calls `getMetaFromPhpStanExtensions()` once, in the coordinator,
  before any file is parsed or handed to a worker. The discovery store must exist before the first template is parsed,
  and 2.3 has no other hook at that moment.
- **Needs.** A non-deprecated extension point run once in the coordinator before `restore()` hashes and parses the
  analysed files – ideally after `bootstrapFiles` (see
  [bootstrap files](#value-extensions-run-before-bootstrapfiles)).
- **Cost.** None visible: the hash never moves, so the cache is never discarded. A deprecation ignore in the source.
- **Gone when.** `ResultCacheMetaExtension` is removed (the class stops compiling) or a pre-analysis hook appears.
  Move the build there and delete the class and its `phpstan.resultCacheMetaExtension` tag in `config/latte.neon`.

## Result cache

### `CollectedDataNode` rules must never be reused

- **Does.** `LatteTemplateGraphRule`, `LatteFormsRule`, `LatteSiteScopeWriterRule` and `ShadowDivergenceRule` track
  `RerunValueExtension` (`src/Support/`), whose value is a random token per process. The writer also tracks the slice
  directory before writing and one written or removed slice file after
  ([the committed store](latte.md#the-committed-store)).
- **Because.** With `featureToggles.reuseCollectedDataRuleErrors` (bleeding edge) `AnalyserResultFinalizer` replays a
  `CollectedDataNode` rule's errors without running it when `canReuse()` finds the collector hashes and tracked values
  unchanged. These rules read stores, indexes and files beyond their collected data, and the writer has a side effect.
- **Needs.** A way to mark such a rule volatile ("never reuse"), or a reuse check covering what the rule read.
- **Cost.** None: the four rules simply always run, as they do without the toggle.
- **Gone when.** PHPStan offers the marker. Drop `RerunValueExtension` and the `trackValueDependency()` calls; keep the
  writer's directory tracking only if reuse should ever skip a run that would have written nothing.

### Value extensions run before `bootstrapFiles`

- **Does.** `BootstrapFilesLoader` (`src/Support/`, service `orisai.nette.bootstrapFilesLoader`) runs
  `%bootstrapFiles%` itself before the first `.latte` parse and before the readers of the container loaders
  (`TemplateFactoryDefaultResolver`, `DiscoveryResolver`, `EngineSource`, `MultiContainerRegistry`) and the Forms
  catalog classes (`CatalogIdentity`, the configuration guard's catalog check), then publishes the new autoloaders
  through `BootstrapFilesRunner::mergeNewAutoloadFunctions()` (`phpstanApi.classConstant` ignore, capability-checked with
  `method_exists()`). See [result-cache dependency tracking](README.md#result-cache-dependency-tracking) and
  [bootstrap files](latte.md#bootstrap-files-and-the-parse-time-templatetype-lookup).
- **Because.** `CommandHelper` defers `bootstrapFiles` to right before the analysis, but `ResultCacheManager::restore()`
  asks every `ResultCacheValueExtension::getValue()` and re-parses changed files in the coordinator first, while the
  workers answer after the bootstrap. Two answers for one key re-analyse the file on every run.
- **Needs.** `bootstrapFiles` run before `restore()`, or value extensions asked only after it.
- **Cost.** Resources a bootstrap file opens are inherited by forked workers again. Without the runner's publishing
  hook (an older PHPStan) the loader does nothing. Forked workers inherit what the coordinator memoised and hide a
  disagreement, so the tests of it spawn their workers (`InvalidationScenario::spawnWorkers()`,
  `BootstrapDependentWarmRunTest`).
- **Gone when.** A spawned-worker warm run with a bootstrap-only container class reports no re-analysed files with the
  loader turned into a no-op.

### Parsers get no `DependencyTracker`

- **Does.** `LatteDependencyRule`, a `Rule<FileNode>` for `.latte` files, re-derives at analysis time what the parse
  read: the `harvest`, `templateFactory`, `neighbourhood` and `harvestHost` values, the renderers' render facts, the
  files of every transitive includer and the slices it and they read.
- **Because.** `Parser::parseFile(string $file)` receives nothing to record dependencies on; only rules, collectors
  and type extensions get a scope implementing `DependencyTracker`.
- **Needs.** A tracker handed to the parser (or a per-file dependency provider asked after parsing).
- **Cost.** The parse and the rule must agree on the keys; a key the parse reads and the rule forgets is a stale cache
  no test sees unless it covers that input.
- **Gone when.** The parser can record its own dependencies; `LatteDependencyRule` then goes.

### Store-derived values read the store, not the files

- **Does.** The discovery records a template depends on are folded into the `neighbourhood` value
  (`TemplateNeighbourhood::digest()` reads `DiscoveryStore::recordsForTemplate()`), never tracked as file dependencies
  on the store files.
- **Because.** File dependencies are valued through `FileContentHasher`, which memoises a hash per process and
  revalidates it by `mtime` and size only: a same-second, same-size rewrite keeps the old hash. The discovery store
  is rewritten by the pre-analysis build in the coordinator, inside `restore()`, so when a store file in `%paths%` is
  hashed relative to that rewrite depends on timing.
- **Needs.** Content-exact file values for files rewritten during the run, or a defined moment for the hashing.
- **Cost.** None visible; the digest costs one store read per component.
- **Gone when.** Not applicable while the store is built inside `restore()`. Narrowing slices are plain file
  dependencies: only the coordinator writes them, after the analysis, and the next process hashes them fresh.

### Persistent reflection caches keyed by file content

- **Does.** `LatteReflectionCacheBypass` replaces `cacheStorage!` and keeps every `.latte` key out of the file cache
  (`FileTypeMapper`'s `ftm-*`, `OptimizedSingleFileSourceLocator`'s `osfsl-*`). See
  [operational notes](latte.md#operational-notes).
- **Because.** Those caches are valid while `hash_file()` of the referenced path matches; a compiled template also
  depends on its contexts and the narrowing store.
- **Needs.** A cache-key contribution from the parser for a file, or a parser-declared "not cacheable".
- **Cost.** `.latte` reflection is recomputed per process.
- **Gone when.** Together with the narrowing fixpoint, or when PHPStan keys these caches by more than content.

## Hosting templates in PHPStan

### Service replacements

- **Does.** `config/latte.neon` replaces `defaultAnalysisParser!` (`LatteRoutingParser`, delegating to the phar's
  `CachedParser` over `pathRoutingParser`), `betterReflectionSourceLocator!` (`LatteTemplateSourceLocator` in front of
  the phar's locator, built by `InnerSourceLocatorFactory`, which keeps PHPStan's own `TestCaseSourceLocatorFactory`
  under `TestCase.neon`), `cacheStorage!` (above) and `registry!` (below). The compile pipeline parses with
  `currentPhpVersionSimpleDirectParser`, not `CachedParser` (shared mutated nodes for identical source) nor the
  `CleaningParser` behind `currentPhpVersionSimpleParser` (empties method bodies).
- **Because.** PHPStan has no extension point for non-PHP source files; replacing the parser is how a file it
  analyses gets an AST. `LatteTemplateSourceLocator` answers `LatteTpl_*`, `LatteSlice_*` and discovery classes,
  reading `OptimizedSingleFileSourceLocatorRepository`. A `.latte` named directly on the command line gets an
  `OptimizedSingleFileSourceLocator`, which re-enters the parser for the file it is parsing; `LatteRoutingParser`
  guards that per file ([operational notes](latte.md#operational-notes)).
- **Needs.** A supported "source file kind" extension: a parser for an extension, a locator for its classes, a cache
  identity.
- **Cost.** Every replaced service is non-API (`phpstanApi.*` ignores in `tools/phpstan.common.neon`) and breaks on a
  constructor or tag change in a minor release.
- **Gone when.** Such an extension point exists.

### Rich parser attributes on a synthetic AST

- **Does.** `RichAttributeDecorator` runs every `NodeVisitor` from
  `Container::getExtensionsCollection(NodeVisitor::class)` over the compiled template, as `RichParser::parseString()` does. It replaced `getServicesByTag()` with
  `RichParser::VISITOR_SERVICE_TAG`, which 2.3 removed.
- **Because.** Core rules (e.g. `ContinueBreakInLoopRule`) read attributes only those visitors set, and the template
  AST never goes through `RichParser`.
- **Needs.** The visitor pass as API, or the parser seam above.
- **Cost.** `ExtensionsCollection::getAll()` is not `@api` (`phpstanApi.method` ignore).
- **Gone when.** With the parser seam.

### Fixes on template files

- **Does.** Rules with `fixNode()` consult `FixSupport::supportsFixes()`; `LatteFixStrippingRegistry` (wrapping the
  phar's `LazyRegistry`) routes every rule through `LatteFixStrippingRule`, which drops fixes on `.latte` files.
  `LatteProvenanceTipRegistry` wraps it as `registry!` to append filter/macro provenance tips. See
  [rules with fixes](../README.md#rules-with-fixes).
- **Because.** `RuleErrorTransformer::fix()` re-reads the file from disk and renders with
  `printFormatPreserving()` against its tokens; a template has no PHP token stream, so it crashes (core rules such as
  `OverridingMethodRule` and `BacktickRule` ship fixes too).
- **Needs.** A veto per file before the file is re-read. Prototyped on the fork, branch
  `skip-fixnode-for-virtual-files` (on `2.2.x`): an `@api` `FixableFileExtension::isFixable(string $file)` consulted
  in `RuleErrorTransformer`.
- **Cost.** No fixes inside templates. `Registry` and `LazyRegistry` are non-API.
- **Gone when.** The veto exists; the stripping registry and rule go. The provenance tips still need a way to
  decorate rule errors.

### Ported core rule logic

- **Does.** `VarTypeExpressionChecker` ports `WrongVariableNameInVarTagRule` and `VarTagTypeRuleHelper` for mid-file
  `{varType}` (`phpstanApi.instanceofType`, `phpstanApi.method` ignores).
- **Because.** Neither is API, and the check needs the inferred type of the anchor's assigned expression.
- **Needs.** The helper as API.
- **Cost.** A port drifts from upstream silently.
- **Gone when.** The helper is `@api`, or inlined statements let core's own rule see the declaration.

## Type inference

### `isset()` on a component offset skips `offsetGet()` extensions

- **Does.** `ComponentAccessTypeResolver::offsetGetType()` answers a read whose scope type is the bare `IComponent`
  with the type of the equivalent `offsetGet()` call. `FormShapeType::getOffsetValueType()` answers a form's own
  offset from its shape. See [forms.md](forms.md#existence-checks-whose-answer-is-already-known).
- **Because.** PHPStan 2.3 prices the narrowing `isset($c['x'])` and `$c['x'] ?? …` leave through
  `Type::getOffsetValueType()` (`IssetabilityDescriptor`), never asking `offsetGet()`'s
  `DynamicMethodReturnTypeExtension`s, and later reads keep that answer.
- **Needs.** Offset narrowing that asks the dynamic return type extensions of `offsetGet()`.
- **Cost.** `$c['x'] ?? null` on a non-form container still types its left side as `IComponent`
  ([known limitations](forms.md#known-limitations)).
- **Gone when.** `IssetOffsetControl`/`IssetOffsetPresenter` (`tests/Unit/Forms/Inference/Fixtures/Type/`) pass with
  `offsetGetType()` removed.

### Re-entrant event-callback resolution

- **Does.** `ContainerModel` keeps `$eventCallbackShapesComputing`, a per-closure guard around
  `eventAssignFormShape()`.
- **Because.** Walking the form-building method types the calls inside the callback, whose receiver is the parameter
  being resolved. 2.3 types a call's receiver from the walk's `ExpressionResult` (`MethodCallHandler`), so the
  expression-string cache of the scope (`MutatingScope::$resolvedTypes`), which used to answer the nested ask, no
  longer ends the loop.
- **Needs.** Nothing specific; the guard is cheap and correct regardless.
- **Cost.** None.
- **Gone when.** Keep it; listed so a change of the 2.3 typing model is checked against
  `MEventParamCallOnLocalFormTerminates`.

### Several return type extensions are unioned

- **Does.** Form values and component offsets are typed by `ExpressionTypeResolverExtension`s
  (`FormAccessExpressionTypeResolver`, `ComponentAccessTypeResolver`, …), not `DynamicMethodReturnTypeExtension`s, and
  re-enter `$scope->getType()` under a guard to see what the others answer.
- **Because.** PHPStan unions the non-null results of every matching `DynamicMethodReturnTypeExtension`, so a
  competing phpstan-nette extension (`ArrayHash`, `IComponent`) would pollute the answer; an
  `ExpressionTypeResolverExtension` is asked first and wins with the first non-null.
- **Needs.** A precedence among dynamic return type extensions.
- **Cost.** A resolver per expression kind instead of per method; the guards.
- **Gone when.** Unlikely to change; the [phpstan-nette](#phpstan-nette) switches remove the competition at its
  source.

### A values crate cannot be writable

- **Does.** Nothing: a write to a known field of typed values (`$values->name = 'x'`) is reported as
  `assign.propertyReadOnly`.
- **Because.** The projection is an object shape, whose properties are read-only; a writable wrapper would implement
  `ExtendedPropertyReflection`, which is `@api-do-not-implement`.
- **Needs.** A writable object-shape property, or permission to implement the reflection.
- **Cost.** Users copy values into an array or DTO ([known limitations](forms.md#known-limitations)).
- **Gone when.** PHPStan offers either.

### One stub per class

- **Does.** Vendor control facts live in catalog classes read by reflection (`FormValueTypeCatalog` and the other
  `src/Forms/Catalog/Stub/` catalogs), not in a `Nette\Forms\Container` stub. See
  [forms.md](forms.md#where-these-vendor-facts-live-and-how-they-are-kept-fresh).
- **Because.** phpstan-nette already stubs `Container`; a second stub of one class is a non-ignorable `class.duplicate`,
  and `StubPhpDocProvider` replaces a class's whole member map per stub file.
- **Needs.** Stub merging per member.
- **Cost.** A second mechanism beside stubs, kept fresh by `VendorCatalogFreshnessTest`.
- **Gone when.** Two stubs of one class merge.

## Runtime and platform

### Runtime polyfills on PHP 7.4

- **Does.** `PhpstanRuntimeStubs::ensureLoaded()` (`src/Support/`) requires PHPStan's `stubs/runtime/`
  `ReflectionUnionType`, `ReflectionIntersectionType`, `ReflectionAttribute` and `Attribute85` on PHP < 8, first thing
  in `LatteResultCacheMeta` and every value extension (`ContainerValueExtension`, `DicUsageValueExtension`,
  `FormsValueExtension`, `LatteTemplateValueExtension`, `LatteRenderFactsValueExtension`).
- **Because.** PHPStan loads those polyfills as bootstrap files, after the meta and value extensions ran (2.2.10+);
  the discovery build reflects attributed methods there.
- **Needs.** The polyfills before the meta phase. Fixed on the fork, branch `result-cache-meta-after-bootstrap`,
  commit "Load the runtime polyfills before ResultCacheMetaExtensions compute their hash" (`CommandHelper`).
- **Cost.** None.
- **Gone when.** That fix is in the lowest supported PHPStan, or PHP 7.4 support is dropped.
  `MetaPhaseStubReflectionSpawnTest` (the `php74` profile) pins it.

### Extensions built in the stub-validator container

- **Does.** Type extensions call `ConfigurationGuard::validate()` on first use, after their node filter, never in the
  constructor ([configuration guard](README.md#configuration-guard)).
- **Because.** PHPStan builds extensions in the stub-validator container too, before the analysed classes exist.
- **Needs.** Nothing to ask for; recorded because a validation moved into a constructor fails there.
- **Cost.** None.
- **Gone when.** Not applicable.

### Neon registration instead of `#[AutowiredService]`

- **Does.** Every service is registered in `config/*.neon`.
- **Because.** `src/` must parse and run on PHP 7.4, where `#[…]` is a comment to the runtime; registration by
  attribute (`AutowiredService`, collected through composer-attribute-collector) has not been tried there.
- **Needs.** Nothing from PHPStan.
- **Cost.** Neon wiring to maintain.
- **Gone when.** PHP 7.4 support is dropped.

## phpstan-nette

- **Does.** A consumer with phpstan/phpstan-nette applies the shipped patch
  (`patches/phpstan-nette-conditional-dynamic-return-types.patch`) and sets `netteComponentModelDynamicReturnType`,
  `netteFormContainerValuesDynamicReturnType` and `netteServiceLocatorDynamicReturnType` to `false`
  ([patch phpstan-nette](../README.md#patch-phpstan-nette)). The `mixed` (non-null) fallback of
  `ComponentModelAccessDynamicReturnTypeExtension` for an unknown `offsetGet()` name is copied from phpstan-nette's
  `ComponentModelArrayAccessDynamicReturnTypeExtension` on purpose, so turning phpstan-nette's extension off changes
  nothing for a name neither knows.
- **Because.** phpstan-nette's extensions answer first and always (`IComponent`, `ArrayHash`, `mixed`) and cannot be
  switched off without the patch.
- **Needs.** The switches upstream in phpstan-nette.
- **Cost.** A patch step for users, verified against phpstan-nette 2.0.8 – 2.0.15.
- **Gone when.** phpstan-nette ships the parameters; the patch and its docs go.

## Other non-API uses

Each is ignored by path in `tools/phpstan.common.neon` (or inline, or in the baselines), with the reason there.

| Where | What | Why |
|---|---|---|
| `BootstrapFilesLoader` | `BootstrapFilesRunner::mergeNewAutoloadFunctions()` | publish bootstrap autoloaders ([above](#value-extensions-run-before-bootstrapfiles)) |
| `RegistrationIndex` | `FileFinder` | the analysed-paths universe walk reuses PHPStan's file finder |
| `PreAnalysisIndexBuilder` | `FileExcluder` | the discovery build reuses PHPStan's analyse excluder |
| `LatteTemplateSourceLocator`, `InnerSourceLocatorFactory` | BetterReflection `SourceLocator`, `OptimizedSingleFileSourceLocatorRepository`, `BetterReflectionSourceLocatorFactory`, `TestCaseSourceLocatorFactory` | answer template classes ([above](#service-replacements)) |
| `LatteReflectionCacheBypass` | `CacheStorage` | [above](#persistent-reflection-caches-keyed-by-file-content) |
| `LatteFixStrippingRegistry`, `LatteProvenanceTipRegistry`, `LatteFixStrippingRule` | `Registry`, `LazyRegistry`, rule-error interfaces | [above](#fixes-on-template-files) |
| `RichAttributeDecorator` | `ExtensionsCollection::getAll()` | [above](#rich-parser-attributes-on-a-synthetic-ast) |
| `VarTypeExpressionChecker` | `VarTagTypeRuleHelper` internals | [above](#ported-core-rule-logic) |
| `DicUsageProvider` | `instanceof InClassNode` | shipmonk's usage provider receives every node; the class node is not BC-promised |
| `TypeCanonicalizer` | `instanceof AccessoryType` | accessory types are skipped when compound types are flattened |
| `FormShapeType` | `VerbosityLevel::isPrecise()` | the precise describe of a shape |
| tests (`TrackingScope`, rule tests, `TestFileFinder`, …) | constructors of `CollectedDataNode`, `InClassNode`, `CleaningParser`, … | build PHPStan internals by hand |

## Seen, not worked around in code

- **Undefined-variable narrowing (PHPStan 2.2.8+).** A check on a maybe-undefined variable is reported "always true"
  and narrows it, which cascades into further findings in templates no renderer reaches (orphans), whose variables are
  all undeclared. Nothing in the source handles it and no test pins it; the [typing guidance](latte.md#typing-templates)
  (declare the template's variables) is the remedy.
- **`ErrorType` at level max.** A proven-absent component is answered with `ErrorType`
  (`FormShapeProjector::offsetPath()`, `ContainerModel::provenAbsent()`) so that `orisai.nette.forms.noSuchComponent`
  is the only report. `ErrorType` is an implicit `MixedType`, and at level 10 (`checkImplicitMixed`)
  `RuleLevelHelper::findTypeToCheck()` checks it as strict `mixed`, so every use of the access reports again. The
  projector mints it only where the rule reports the same expression, which keeps the cascade to those sites.

## Not ours, but seen

- **nextras/orm-phpstan 1.1.0 on PHPStan 2.3** loses the entity type through `->toCollection()` (the collection types
  as a bare `ICollection`). Seen in a consumer's analysis; the cause is unverified and nothing here works around it.

## Upstream wishlist

Ordered by value to this library.

| # | Ask | Removes |
|---|---|---|
| 1 | Analyse provider-supplied statements in a borrowing scope with origin-file errors (or a post-collect re-analysis round) | the narrowing store and its multi-run lifecycle, `narrowingStoreChanged`, the slice dependencies, `LatteReflectionCacheBypass` |
| 2 | A supported source-file-kind seam (parser + class locator + cache identity) | the four service replacements, `RichAttributeDecorator`, most `phpstanApi.*` ignores |
| 3 | A non-deprecated pre-analysis hook, run once in the coordinator after `bootstrapFiles`, before `restore()` | `LatteResultCacheMeta`, `BootstrapFilesLoader`, `PhpstanRuntimeStubs` |
| 4 | A per-file fix veto (`FixableFileExtension`) | `FixSupport`, the fix-stripping registry and rule |
| 5 | A `DependencyTracker` for parsers | `LatteDependencyRule` |
| 6 | Offset narrowing that asks `offsetGet()` return type extensions | `ComponentAccessTypeResolver::offsetGetType()`, the `??` limitation |
| 7 | A volatile marker for `CollectedDataNode` rules | `RerunValueExtension` |
| 8 | Writable object-shape properties | the `assign.propertyReadOnly` limitation |
| 9 | Per-member stub merging | the reflection-read catalogs (in part) |
| 10 | `@api` on `VarTagTypeRuleHelper`, `FileFinder`, `FileExcluder`, `BootstrapFilesRunner` | the remaining `phpstanApi.*` ignores |
