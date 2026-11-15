Maintainer documentation; the user-facing guide is ../README.md.

# PHPStan workarounds

Every place where the library works around a limitation of PHPStan (or of another extension). When PHPStan changes,
this is the list of what to re-check and what to delete. Verified against PHPStan 2.3.5 (`composer.json` requires
`^2.3.5`); the PHPStan classes, lines and `conf/*.neon` definitions named are those of the 2.3.5 phar. Where a
statement was checked by reading code only and not by running it, it is marked *(inferred)*.

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
  `InlinedStatementsProcessor`, and an `InlinedFile` on `ScopeContext` (trait analysis reimplemented on top of it).
  Known limits of the prototype, read off the branch:
  - no Turbo parity: no `turbo-ext/` file is changed, and 2.3's `NodeScopeResolver` is shadowed by the Turbo
    extension;
  - variables the inlined statements assign do not flow back (`InlinedStatementsProcessor::applyToResult()`,
    `MutatingScope::enterInlinedFile()`);
  - PHPDoc and `__FILE__` resolve against the borrower: `enterInlinedFile()` keeps the borrower's
    `ScopeContext::$file` and rebinds only the error file (PHPDoc *(inferred)*: no trait-like path exists for it);
  - a `return` in the inlined statements is checked against the borrower's function *(inferred)*: the walk keeps the
    borrower's `getFunction()`; no fixture pins it;
  - inlined files get no parser nodes (`FileAnalyserCallback` passes `[]`), so a fix on inlined code takes the
    re-parse path of [fixes on template files](#fixes-on-template-files) and needs the same veto.

  There is no upstream discussion of inlined statements yet. TwigStan (0.2.6) has the same problem and solves it
  outside PHPStan: its `twigstan analyze` command spawns a collect and an analyse process per round
  (`PHPStanRunner::run()`) and repeats the round while includes reveal new contexts (`AnalyzeCommand`'s `while
  ($changedTemplates !== [])` loop).
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

### Persistent reflection caches keyed by the template's raw bytes

- **Does.** `LatteReflectionCacheBypass` replaces `cacheStorage!` (delegating to `latteInnerCacheStorage`, the
  phar's `FileCacheStorage(%tmpDir%/cache/PHPStan)` definition) and answers `load()` with `null` and `save()` with
  nothing for every key containing `.latte`. See [operational notes](latte.md#operational-notes). In 2.3.5 two
  persistent caches reach such keys:

  | Cache | Key | Valid while | Reached for a template by |
  |---|---|---|---|
  | `FileTypeMapper::getNameScopeMap()` | `ftm-[simplified-]<path>` | variable key `v8-<phpdoc-parser version>`, and for every file the map was built from, `FileContentHasher::hash()` (sha256 of the bytes on disk, memoised per process by mtime and size) and its simplified-AST flag | every PHPDoc lookup in the compiled AST (the `@var`/`@param` of the `latteMain_ctx<N>` clones and so on) |
  | `OptimizedSingleFileSourceLocator` | `osfsl-<path>-presentSymbols`, `osfsl-<path>-<type>-<name>` | variable key `v2-<hash_file(sha256, path)>-<PHP version>`, plus the BetterReflection version for reflections | `LatteTemplateSourceLocator` resolving every `LatteTpl_*` class through `OptimizedSingleFileSourceLocatorRepository::getOrCreate(<.latte path>)`, and a `.latte` named on the command line |

  `OptimizedDirectorySourceLocator`'s `odsl-<file>-*` keys never name a template: its class map comes from the raw
  file, and raw Latte declares no class *(inferred)*. The in-memory caches (`CachedParser`, `FileTypeMapper`'s memory
  cache, reflection memoisation) need no bypass: within one process `LatteRoutingParser`'s per-path memo fixes the AST
  of a path. The test is `strpos($key, '.latte')`, not the `TemplatePath::is()` suffix test used everywhere else,
  because the path sits inside the key; it also bypasses keys of PHP files whose path contains `.latte` (a
  `foo.latte.php` file, a `x.latte/` directory), which are then only uncached.
- **Because.** PHPStan decides whether such an entry is valid from the path and the hash of the *raw* file on disk;
  it never sees the compiled PHP. "Identical compiled content gives identical reflection" holds (`FileTypeMapper` and
  BetterReflection are deterministic functions of the AST), but it does not make a hit safe, because identical raw
  `.latte` bytes do not give identical compiled content. `LatteRoutingParser::parseLatteFile()` also folds in the
  includers' contexts (`ContextResolver`, the per-edge clones of `DeclarationInjector`), the narrowing slices
  (`SiteScopeStore::sliceHash()`), the edge index (the `EDGE` fingerprint constant, neighbour class references),
  the `{templateType}` classes reachable through includes, discovery references, the harvested filters, functions
  and tags, the configuration (`narrowing.enabled`, `includeIsolation`, `discovery.enabled`, …), and the installed
  Latte and this library's code. None of them is in a variable key. Scenarios which would serve a stale entry:
  - an includer edits its `{include … x: $y}` arguments: the included template's bytes are unchanged, so
    `LatteDependencyRule` re-queues it, it compiles with new contexts, and `ftm-<path>`/`osfsl-<path>-*` still
    validate. The same holds for every settle run of the narrowing store, a `{templateType}` class edit, a filter or
    extension edit, a configuration change and an upgrade of this library or Latte (the result cache is discarded
    on a lock-file change, `%tmpDir%/cache/PHPStan` is not). Effect: new clones get no PHPDoc and fall back to
    implicit `mixed` (the cold-vs-warm A/B on `_twoStateRadioList.latte` recorded in the class's comment), and files
    reflecting a neighbour template class see its old methods and `EDGE` value *(inferred)*;
  - a `.latte` named on the command line: the re-entrant answer of `LatteRoutingParser` (the compiled class alone,
    [operational notes](latte.md#operational-notes)) is what `OptimizedSingleFileSourceLocator` latches and `save()`s
    as `presentSymbols` and class reflection, which would persist into the next process under a valid key.

  A template re-analysed because a dependency changed has unchanged bytes by definition, so the bypass matters
  exactly when a hit would be wrong. Keys hold the path, so nothing is shared across paths.
- **Needs.** A cache-key contribution from the parser for a file (the parser knows the compile inputs), or a
  parser-declared "not cacheable".
- **Alternative, not implemented.** Salt instead of bypass: rewrite the variable key of `.latte` keys to
  `<variableKey>-<salt(path)>`, the salt covering every compile input (`LatteCodeVersion::get()`, the Latte version,
  the harvest salt, the `neighbourhood`/`templateFactory`/`harvestHost` values `LatteDependencyRule` already
  re-derives, the slice hash), and skip `save()` while `LatteRoutingParser` is inside the re-entrant parse of that
  path. Hits are correct only while the salt is complete: a forgotten input is a silent stale cache, the failure
  mode of [parsers get no `DependencyTracker`](#parsers-get-no-dependencytracker). Salting with the hash of the final
  compiled source is complete but needs the compile, which is the cost the salt would save; it would save only the
  `FileTypeMapper` and BetterReflection work. Worth doing only if the measurement below shows reflection-driven
  compiles are a significant share of warm runs.
- **Cost.** `.latte` name scopes and reflection are rebuilt per process; reflecting a template class compiles the
  template (`FileNodesFetcher` parses through `defaultAnalysisParser`), also on warm runs where the template itself is
  not re-analysed.
- **Gone when.** PHPStan lets a parser salt these keys; or the narrowing fixpoint goes *and* compilation becomes a
  function of the file's bytes.

#### Measured cost

Measured on a consumer with about 3,400 analysed files and 450 templates, PHPStan 2.3.5, parallel workers, three
interleaved repetitions per variant at a 1-minute load below 4. Only the cost was measured (variants A and B, unchanged
project); the stale-entry steps 3-6 of the procedure below were not run.

| Run | A, bypass on (s) | B, bypass off (s) |
|---|---|---|
| Whole `tmpDir` deleted | 69.1 / 70.6 / 71.8 | 69.7 / 71.8 / 69.7 |
| Only the result cache deleted | 33.6 / 33.5 / 32.9 | 32.9 / 32.4 / 32.5 |
| Warm, nothing changed | 10.3 / 10.1 / 9.5 | 9.7 / 10.2 / 9.8 |

The finding sets were identical in every pair. Files under `tmpDir/cache` mentioning `.latte`: 466 with the bypass,
1,372 without. The differences are within run-to-run noise, so the bypass has no measurable cost and salting the key
would buy nothing; the bypass stays.

#### Measurement procedure

Variants, each in its own checkout of the library (a separate worktree, not a busy checkout), installed against the
consumer's PHPStan 2.3.5, each analysis with its own `tmpDir`:

| Variant | Change |
|---|---|
| A | as is |
| B | `src/Latte/Cache/LatteReflectionCacheBypass.php`, `isLatteDerived()`: `return false;` (or delete the `cacheStorage!` service in `config/latte.neon`) |
| C (optional) | a wrapper salting the variable key of `.latte` keys with `LatteCodeVersion::get()` and `sha1()` of the compiled source from `LatteRoutingParser`'s memo, skipping saves during the re-entrant parse |

In every variant count compiles: a static counter incremented in `LatteRoutingParser::parseLatteFile()` and written to
`STDERR` from a shutdown function (or one SPX profile; php-spx is installed for PHP 8.4/8.5, not auto-loaded). Workers
are separate processes, so sum the counts of all processes, or run with `--debug` (one process) for the count and
without it for the time.

Corpus: the consumer application's templates, the largest available, and the library's corpus (`tools/smoke/`).

Per variant, each step starting from the state the previous one left:

1. Delete the `tmpDir`; cold run.
2. Warm run, nothing changed.
3. Edit only one includer's `{include … arg: …}` arguments, so the included template's bytes stay unchanged; warm
   run.
4. Run until `orisai.nette.latte.narrowingStoreChanged` stops; count the runs, time each.
5. Change a compile input (`orisai.nette.latte.includeIsolation`, or bump `LatteCodeVersion`); warm run.
6. `phpstan analyse <a renderer-linked template>.latte` (the re-entrant single-file path), then a full warm run.

Oracle for each step: a cold run (empty `tmpDir`) on the same tree *and the same narrowing store*; copy the store
directory before the step's run and restore it for the oracle, since the runs rewrite it.

Per run record the wall time, the peak RSS (`/usr/bin/time -v`) and PHPStan's used memory (`-v`), the compile count,
the `--error-format=json` findings normalised (sorted by file, line, identifier, message) and diffed against the
oracle, and the number of files in `tmpDir/cache/PHPStan` whose payload contains `.latte` (0 for A).

Pass criteria: A and C equal the oracle at every step; B is expected to diverge at steps 3 to 6. Compare A and C at
steps 2 and 3 (compile count and time); step 1 should be equal. Interleave the variants (A, B, A, B, and C) with at
least three repetitions, on a machine with a low 1-minute load average.

Cheap first signal, already run on 2.3.5 with variant B over `tests/Integration/Latte`: one test fails, and only on
the `latte2` profile (it is a `latte2` group test): `MultiContextClonesReachTheEngineTest::
testWarmSecondRunWithANewlyAddedContextStillHonorsItsOwnType`, the pin of the stale per-edge-clone scenario; it
passes with variant A. The invalidation, determinism and single-file re-entrancy tests do not catch variant B, so
steps 3 to 6 above are the evidence that matters.

## Hosting templates in PHPStan

### Service replacements

- **Does.** `config/latte.neon` replaces four phar services and keeps each original under a delegate-able name:

  | Replaced | By | Delegating to |
  |---|---|---|
  | `defaultAnalysisParser!` | `LatteRoutingParser` | `latteInnerAnalysisParser`, defined as the phar defines `defaultAnalysisParser`: `factory: @pathRoutingParser` (the phar's `PathRoutingParser`, which since 2.3.5 holds one `CachedParser` per routed parser, `cachedRichParser` and `cachedSimpleParser`) |
  | `betterReflectionSourceLocator!` | `AggregateSourceLocator([LatteTemplateSourceLocator, …])` | `latteInnerBetterReflectionSourceLocator`, built by `InnerSourceLocatorFactory` from the phar's `BetterReflectionSourceLocatorFactory`, or PHPStan's `TestCaseSourceLocatorFactory` under `TestCase.neon` |
  | `cacheStorage!` | `LatteReflectionCacheBypass` ([above](#persistent-reflection-caches-keyed-by-the-templates-raw-bytes)) | `latteInnerCacheStorage`, the phar's `FileCacheStorage(%tmpDir%/cache/PHPStan)` |
  | `registry!` | `LatteProvenanceTipRegistry` over `LatteFixStrippingRegistry` ([below](#fixes-on-template-files)) | `latteInnerRuleRegistry`, the phar's `LazyRegistry` |

  `.latte` files enter `%paths%` through `fileExtensions: [php, latte]`. `LatteRoutingParser::parseFile()` compiles a
  template path (`TemplatePath::is()`) to a PHP AST with template lines, memoised per path, and hands every other
  path to the delegate. Errors are therefore native PHPStan errors at the template's file and line: baselines,
  `ignoreErrors`, identifiers, the result cache, parallel workers and editor mode treat a template as an ordinary
  analysed file. `LatteTemplateSourceLocator` answers `LatteTpl_*`, `LatteSlice_*` and discovery classes by handing
  the template (or slice) path to `OptimizedSingleFileSourceLocatorRepository::getOrCreate()`, so their reflection
  comes from `FileNodesFetcher`, which parses through `defaultAnalysisParser`, i.e. the compiled AST. A `.latte` named
  directly on the command line gets an `OptimizedSingleFileSourceLocator`, which re-enters the parser for the file it
  is parsing; `LatteRoutingParser` guards that per file ([operational notes](latte.md#operational-notes)). The
  compile pipeline parses the generated PHP with `currentPhpVersionSimpleDirectParser`, not a `CachedParser` (which
  returns shared, mutated nodes for identical source) nor the `CleaningParser` behind `currentPhpVersionSimpleParser`
  (which empties method bodies); the discovery walk and Forms use `currentPhpVersionRichParser`
  (`config/forms.neon`, `formShapeRecordingRichParser`).

  The routing parser sits *above* `pathRoutingParser`, not in its place, for two reasons. `CommandHelper` and
  `WorkerCommand` fetch the service named `pathRoutingParser` and call `setAnalysedFiles()` on it, which a
  replacement would have to forward. And `CachedParser` keys by the raw file content, so whatever sits below one must
  be a function of the bytes; a compiled template is not
  ([above](#persistent-reflection-caches-keyed-by-the-templates-raw-bytes)), and `LatteRoutingParser` never hands a
  template path to the delegate.
- **Because.** PHPStan has no extension point for non-PHP source files; replacing the parser is how a file it
  analyses gets an AST.
- **How the counterparts do it.**
  - *TwigStan 0.2.6*: a wrapper command. `twigstan analyze` compiles templates to real `.php` files at stable paths
    (rewritten only when their content changes) and spawns separate `phpstan analyse` processes with a generated
    configuration (`paths!` of the compiled directories, a result cache per mode, an environment fingerprint
    parameter): one collecting render points, then per round one collecting block contexts and one analysing, in a
    loop while includes reveal new contexts. No PHPStan service is replaced, only an error formatter added. Errors
    are mapped back to Twig lines after PHPStan; TwigStan has its own baseline and ignores, since PHPStan's would see
    the compiled paths only, and drops PHPStan's `--fix` hint.
  - *Bladestan 0.11*: a nested analysis inside a rule. `BladeRule` (a `Rule<CallLike>`, through `ViewRuleHelper`)
    compiles the rendered template to a temporary file and runs `FileAnalyser::analyseFile()` on it from a
    `DerivativeContainerFactory` container in which `defaultAnalysisParser`/`pathRoutingParser` are redefined. Each
    error is rebuilt at the calling PHP file and line, the template location in metadata; the result cache is
    discarded on any Blade edit; fixes are lost. A Bladestan maintainer calls it "slow, it caches badly, and it produces false positives" in
    phpstan/phpstan#15444; the `rewrite` branch abandons it.
  - *Bladestan `rewrite` branch (unreleased)*: our design. `pathRoutingParser!` is replaced by a parser wrapping a
    fresh `PathRoutingParser` and forwarding `setAnalysedFiles()`; `*.blade.php` files in `paths` compile at parse
    time and are analysed as ordinary files, with a `Rule<FileNode>` tracking dependencies like our
    `LatteDependencyRule`. Template types come from a signature docblock inside the template, written by a separate
    command, so a compile is a function of the template's bytes and no reflection-cache bypass is needed. Workers are
    told apart by backtrace class names (`PHPStan\Parallel\WorkerRunner`, `FixerWorkerRunner`).
  - *efabrica/phpstan-latte 0.20*: a `Rule<CollectedDataNode>` running once in the main process, which walks the
    PHP files again with its own `NodeScopeResolver` passes to find contexts, compiles each template per context to
    a cache file named by content, context signature, configuration and versions, and analyses it with a nested
    `FileAnalyser`. Every error becomes `latte.error` at the template line, so the original identifier is lost; it
    replaces `errorFormatter.table!`; nothing is integrated with the result cache, and fixes are lost.

  The wrapper removes our multi-run settle (it loops by itself) and every service replacement, but is not plain
  `phpstan analyse`: editor mode, baselines and `ignoreErrors` need re-implementing. The nested analyses remove the
  narrowing store, but go deeper into internals (`DerivativeContainerFactory`, `FileAnalyser`,
  `NodeScopeResolver`), lose identifiers or locations and the result cache. Parser replacement is the only approach
  keeping PHPStan-native errors, identifiers, baselines, ignores, result cache and parallelism.
- **Needs.** A supported "source file kind" extension: a parser for an extension, a locator for its classes, a cache
  identity – and a parser-level "returns a simplified AST for this file" query in place of `FileTypeMapper`'s
  `instanceof PathRoutingParser`. Discussed upstream in phpstan/phpstan#15444, which is about virtual and generated
  files: the Bladestan maintainer asks there for a supported file-content provider, provider-declared inputs for
  cache invalidation, and virtual files mapping back to source lines.
- **Cost.**
  - No supported seam: every replaced service is non-API (`phpstanApi.*` ignores in `tools/phpstan.common.neon`) and
    breaks on a constructor or tag change in a minor release.
  - The originals are hand-copied phar definitions (`latteInnerAnalysisParser`, `latteInnerCacheStorage`,
    `latteInnerRuleRegistry`, the parser service names the pipeline and Forms use) which must be re-synced with the
    phar's `conf/parsers.neon` and `conf/services.neon` on every PHPStan minor. Before 2.3.5 the phar's
    `defaultAnalysisParser` was a `CachedParser` over `pathRoutingParser`; 2.3.5 made it `pathRoutingParser` itself
    with a cache per routed parser, and our copy kept the old outer cache until it was re-synced.
    `PharServiceWiringTest` now pins the phar definitions we mirror and that the routing parser's delegate is the
    phar's `pathRoutingParser`, and names what to re-sync when either moves.
  - `FileTypeMapper::returnsSimplifiedAst()` is `$this->phpParser instanceof PathRoutingParser && …`, its parser
    being `defaultAnalysisParser`. With the replacement the check is always false, for every PHP file of a project
    loading the extension, Latte on or off: name-scope maps are cached under `ftm-<path>` whether they were built from
    the simplified AST (function bodies stripped, files outside the analysed set) or from the full one, and the
    per-file flag that should tell them apart is always `false`. Reproduced on 2.3.5: a file `A.php` declaring an
    anonymous class with `/** @return int */` on a method returning a string; `phpstan analyse B.php` (B uses A,
    A only scanned) followed by `phpstan analyse A.php B.php` misses "should return int but returns string", which
    a cold run and a run without the extension report. `PathRoutingParser` is `final`, so no wrapper can satisfy the
    `instanceof`; Bladestan's replacement has the same cost. User-facing in the
    [Latte limitations](../README.md#latte-limitations).
  - Templates are typed at parse time from what the previous run proved
    ([narrowing needs several runs to settle](#narrowing-needs-several-runs-to-settle)).
  - No fixes inside templates ([below](#fixes-on-template-files)).
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

- **Does.** `LatteFixStrippingRegistry` (wrapping the phar's `LazyRegistry`) routes every rule, `CollectedDataNode`
  rules included (the finalizer asks the same `registry`), through `LatteFixStrippingRule`. It rebuilds an error
  without its fixes when the scope file, the error's reported file (`FileRuleError::getFile()`) or the file of any of
  its node fixes (`NodeFix::$file`) is a template path (`TemplatePath::is()`, the `.latte` suffix, independent of
  `orisai.nette.latte.enabled`); the error itself stays. The first test alone left a `CollectedDataNode` rule's error
  reported in a template, or a fix a PHP-scope rule aims at a template, unstripped
  (`LatteFixStrippingCollectedDataTest`, `LatteFixStrippingCrossFileTest`). `FixSupport::supportsFixes()` is the scope test for rules that want to skip
  building a fix. `LatteProvenanceTipRegistry` wraps it as `registry!` to append filter and tag provenance tips. See
  [rules with fixes](../README.md#rules-with-fixes).
- **Because.** How 2.3.5 makes and applies a fix:
  1. A rule's error carries `NodeFix{node, callback, file}`s (`RuleErrorBuilder::fixNode()`/`removeNode()`, the
     non-API `FixableNodeRuleError`).
  2. `FileAnalyserCallback` hands it to `RuleErrorTransformer::transform()` with the analysed file's nodes, which come
     from `FileAnalyser`'s `defaultAnalysisParser`: for a template, our compiled AST. `AnalyserResultFinalizer` does
     the same for `CollectedDataNode` rules with no nodes and a scope on the file `N/A`.
  3. `transform()` groups the fixes by `$nodeFix->file ?? <trait file> ?? <error file or scope file>` and fixes each
     file; one file failing drops them all.
  4. `fix()`: for a file without nodes (collected-data rules, a fix in another file) it re-parses the file with
     `defaultAnalysisParser` – our `LatteRoutingParser`, so a template gives the compiled AST again. Independently,
     `getParsedFile()` reads the file from disk (`FileReader::read()`) and tokenizes it with
     `currentPhpVersionPhpParser`, the raw nikic parser, not the analysis parser: for a template, the tokens of Latte
     text. It then replaces the nodes matched by class and position (`ReplacingNodeVisitor`) and prints with
     `PhpPrinter::printFormatPreserving($newStmts, $fileNodes, $tokens)`: compiled-PHP node positions against Latte
     tokens. The result is a unified diff against the raw file with its sha256.
  5. `phpstan analyse --fix`, `ErrorFixer` and the fixer worker read the files from disk and splice the hunks with
     `Patcher`, checking only the hash. No parser is involved there.

  The source locator takes no part. So the fixer combines our compiled nodes with the template's raw tokens, and no
  configuration of ours can make them agree: replacing `currentPhpVersionPhpParser!` would change every PHP file.
  Reproduced on 2.3.5 without the reported-file and node-fix tests: a `CollectedDataNode` rule reporting a node of a
  template's compiled AST in that template, with a fix, makes the analysis emit `TokenStream` "Undefined array key"
  warnings, and `--fix` overwrites the four-line template with a 226-byte mix of copies of it, NUL bytes and `false`.
  Nothing aborts, the hash check passes.

  Real fixes in templates are impossible today. Of the 2.3.5 core fixes, `#[\Override]` (`OverridingMethodRule`,
  `OverridingPropertyRule`) targets methods of the compiled `LatteTpl_*` class, which have no Latte source, Latte has
  no backtick syntax (`BacktickRule`) *(inferred)*, and only `argument.named` (`FunctionCallParametersCheck`) can
  target an expression the template's author wrote. Supporting it would need a Latte source span per compiled node
  (Latte 3 nodes have a `Position`, but the compiled PHP keeps only `/* line N */`; Latte 2 has nothing), a printer
  writing the replacement as Latte expression syntax (PHP syntax is valid only for plain expressions, not filters,
  escaping wrappers or `$ʟ_*` internals), and a seam to hand PHPStan a precomputed diff for a file:
  `RuleErrorTransformer::createFixedErrorDiff()` is public, but `transform()` derives diffs only from node fixes,
  and `IgnoreErrorExtension` can only drop errors.
- **Needs.** A veto per file consulted before the file is re-parsed. Prototyped on the fork, branch
  `skip-fixnode-for-virtual-files` (`e5799252d`, on `2.2.x`): an `@api` `FixableFileExtension::isFixable(string
  $file)` consulted in `RuleErrorTransformer::fix()` before `getParsedFile()`. On 2.3.x it must also precede the newer
  `analysisParser->parseFile()` branch, or every vetoed fix of a collected-data rule still compiles the template. Real
  fixes would also need the precomputed-diff seam and source spans above.
- **Cost.** No fixes inside templates, and none a rule aims at a template from elsewhere. `Registry`, `LazyRegistry`,
  `FixableNodeRuleError::getNodeFixes()` and `NodeFix` are non-API. Every rule PHPStan runs is a
  `LatteProvenanceTipRule` instance, so `--debug` names it for every collected-data rule, and the finalizer's
  reuse keys (`<rule class>#<ordinal>`) carry the wrapper's class and the registry order.
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

- **Does.** `FormValuesWriteIgnoreExtension` (`phpstan.ignoreErrorExtension`) drops `assign.propertyReadOnly` on a
  `PropertyAssignNode` whose fetch is a field the receiver's `FormValuesObjectShapeType` lists (`$values->name = 'x'`).
  Every other read-only write still reports.
- **Because.** The projection is an object shape, whose properties are read-only; a writable wrapper would implement
  `ExtendedPropertyReflection`, which is `@api-do-not-implement`.
- **Needs.** A writable object-shape property, or permission to implement the reflection.
- **Cost.** The written value is not checked against the field's type, and reads after the write keep the form's type
  ([known limitations](forms.md#known-limitations)). `PropertyAssignNode` is not `@api` (table below).
- **Gone when.** PHPStan offers either; then the extension goes and a write is type-checked.

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

Each is ignored by path in `tools/phpstan.common.neon` (or inline, or in the baselines), with the reason there. The
phpstanApi rules skip `PHPStan\BetterReflection\` and `PHPStan\PhpDocParser\` symbols, `catch` clauses, neon and
assumptions about argv or command names, so the rows for those are kept by hand. The last column records the
`@api` alternative looked for in the 2.3.5 phar.

| Where | What | Why | API alternative checked on 2.3.5 |
|---|---|---|---|
| `BootstrapFilesLoader` | `BootstrapFilesRunner::mergeNewAutoloadFunctions()` | publish bootstrap autoloaders ([above](#value-extensions-run-before-bootstrapfiles)) | none: class and method untagged |
| `RegistrationIndex` | `FileFinder` | the analysed-paths universe walk reuses PHPStan's file finder | none; near-miss: rebuilding it from `%paths%`, `%fileExtensions%` and `%excludePaths%` would replicate the exclusion semantics |
| `PreAnalysisIndexBuilder` | `FileExcluder` | the discovery build reuses PHPStan's analyse excluder | none: `FileExcluderFactory` and `@fileExcluderAnalyse` are untagged too |
| `LatteTemplateSourceLocator`, `InnerSourceLocatorFactory` | BetterReflection `SourceLocator`, `OptimizedSingleFileSourceLocatorRepository`, `BetterReflectionSourceLocatorFactory`, `TestCaseSourceLocatorFactory` | answer template classes ([above](#service-replacements)) | none: BetterReflection is outside PHPStan's BC promise |
| `LatteReflectionCacheBypass` | `CacheStorage` | [above](#persistent-reflection-caches-keyed-by-the-templates-raw-bytes) | none |
| `LatteFixStrippingRegistry`, `LatteProvenanceTipRegistry`, `LatteFixStrippingRule` | `Registry`, `LazyRegistry`, `FixableNodeRuleError::getNodeFixes()`, `NodeFix`, rule-error interfaces | [above](#fixes-on-template-files) | none; near-miss: `IgnoreErrorExtension` (`@api`) can drop an error, not decorate it or remove its fix; `FixableFileExtension` exists only on the fork |
| `RichAttributeDecorator` | `ExtensionsCollection::getAll()` | [above](#rich-parser-attributes-on-a-synthetic-ast) | near-miss: `Container::getServicesByTag('phpstan.parser.richParserNodeVisitor')` uses only an `@api` method, but the tag string is not promised either (its constant `RichParser::VISITOR_SERVICE_TAG` was removed); `ExtensionsCollection` is the return type of the `@api` `getExtensionsCollection()` |
| `VarTypeExpressionChecker` | `VarTagTypeRuleHelper` internals, `TypeNodeResolver`, `FileTypeMapper`, `catch NameScopeAlreadyBeingCreatedException` | [above](#ported-core-rule-logic) | none: the helper and the exception are untagged |
| `DicUsageProvider` | `instanceof InClassNode` | `InClassNode` is `@api`; the `instanceof` assumption on a node is not (`phpstanApi.instanceofAssumption`) | none: shipmonk's usage provider receives every node, so there is no node-typed callback |
| `TypeCanonicalizer` | `instanceof AccessoryType` | accessory types are skipped when compound types are flattened | near-miss: `in_array($type, TypeUtils::getAccessoryTypes($type), true)` uses `@api` symbols only, but is obscure; the guard is needed because many accessory types expose `getTypes()` |
| `FormShapeType` | `VerbosityLevel::isPrecise()` | the precise describe of a shape | near-miss: `$level == VerbosityLevel::precise()` uses `@api` factories only, but relies on the same internal state by loose comparison |
| `FormValuesWriteIgnoreExtension` | `instanceof PropertyAssignNode`, `getPropertyFetch()` | the node `WritingToReadOnlyPropertiesRule` reports on ([above](#a-values-crate-cannot-be-writable)) | none |
| `LatteResultCacheMeta` | `ResultCacheMetaExtension` (deprecated, still `@api`) | [above](#the-pre-analysis-seam-is-a-deprecated-meta-extension) | near-miss: `ResultCacheValueExtension` and `DependencyTracker` (`@api`, 2.3) do not run once in the coordinator before `restore()` |
| `config/latte.neon`, `config/forms.neon` | replaced services `defaultAnalysisParser!`, `betterReflectionSourceLocator!`, `cacheStorage!`, `registry!`; hand-copied phar definitions (`latteInnerAnalysisParser` = `factory: @pathRoutingParser`, `latteInnerCacheStorage` = `FileCacheStorage(%tmpDir%/cache/PHPStan)`, `latteInnerRuleRegistry` = `LazyRegistry`, `AggregateSourceLocator`); service names `pathRoutingParser`, `currentPhpVersionSimpleDirectParser`, `currentPhpVersionRichParser` | [service replacements](#service-replacements); re-check against the phar's `conf/parsers.neon` and `conf/services.neon` on every PHPStan minor, `PharServiceWiringTest` fails when they move | none: service names and definitions are not part of the BC promise |
| `LatteAnalysisCache` | `($_SERVER['argv'][1] ?? null) === 'worker'` | prune old code versions only in the coordinator | none: PHPStan's `worker` command name is internal (Bladestan's rewrite uses the internal `WorkerRunner` class names instead); near-miss: moving the pruning into `LatteResultCacheMeta::getHash()`, which runs only in the coordinator, would drop the assumption |
| tests (`TrackingScope`, rule tests, `TestFileFinder`, `PharServiceWiringTest`, …) | constructors of `CollectedDataNode`, `InClassNode`, `CleaningParser`, `PathRoutingParser::class`, … | build or pin PHPStan internals by hand | not applicable |

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
| 2 | A supported source-file-kind seam (parser + class locator + cache identity, a parser-level simplified-AST query for `FileTypeMapper`); discussed in phpstan/phpstan#15444 (file-content provider, provider-declared cache inputs, virtual files mapped to source lines) | the four service replacements and their hand-copied definitions, `RichAttributeDecorator`, the `FileTypeMapper` simplified-AST cost, most `phpstanApi.*` ignores |
| 3 | A non-deprecated pre-analysis hook, run once in the coordinator after `bootstrapFiles`, before `restore()` | `LatteResultCacheMeta`, `BootstrapFilesLoader`, `PhpstanRuntimeStubs` |
| 4 | A per-file fix veto (`FixableFileExtension`), consulted before the file is re-parsed | `FixSupport`, the fix-stripping registry and rule |
| 5 | A `DependencyTracker` for parsers | `LatteDependencyRule` |
| 6 | Offset narrowing that asks `offsetGet()` return type extensions | `ComponentAccessTypeResolver::offsetGetType()`, the `??` limitation |
| 7 | A volatile marker for `CollectedDataNode` rules | `RerunValueExtension` |
| 8 | Writable object-shape properties | `FormValuesWriteIgnoreExtension` and its unchecked write types |
| 9 | Per-member stub merging | the reflection-read catalogs (in part) |
| 10 | `@api` on `VarTagTypeRuleHelper`, `FileFinder`, `FileExcluder`, `BootstrapFilesRunner`, `ExtensionsCollection` | the remaining `phpstanApi.*` ignores |
