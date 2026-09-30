Maintainer documentation; the user-facing guide is ../README.md.

# Latte versions

Latte 2.11, 3.0 and 3.1 are analysed by one pipeline. Everything that reads Latte's own API or depends on the shape of
the code it generates sits behind one seam in `src/Latte/Version/`; everything after the seam — typing, the cross-file
model, narrowing, discovery, the rules — sees one layout. This page describes the seam, the shape families it selects,
how the Latte 3 compile works, the upstream template corpus which gates it, and how to add a family.
[latte.md](latte.md) describes the pipeline the seam feeds.

## Shape families

A `ShapeFamily` (`src/Latte/Version/ShapeFamily.php`) names the generated-code shapes one install produces: the Latte
line decides the core/UI shapes and the line markers, the forms bridge how nette/forms' Latte bridge renders controls.
`ShapeFamily::detect(latteVersion, formsVersion)` derives it from the installed versions; its `id()` is
`<latteLine>/<formsBridge>`.

| Family         | Installed                                  | Line marker          | Forms bridge                                          |
|----------------|--------------------------------------------|----------------------|-------------------------------------------------------|
| `2/macros`     | Latte 2.11, nette/forms 3.1–3.2            | `/* line N */`       | `FormMacros`, `$this->global->formsStack`             |
| `3.0/item`     | Latte 3.0, nette/forms 3.1.7–3.2           | `/* line N */`       | `FormsExtension`, `Runtime::item('x', $this->global)` |
| `3.0/provider` | Latte 3.0, nette/forms 3.3                 | `/* line N */`       | `$this->global->forms` runtime provider              |
| `3.1/item`     | Latte 3.1, nette/forms 3.2.7–3.2           | `/* pos L:C */`      | `FormsExtension`, `Runtime::item('x', $this->global)` |
| `3.1/provider` | Latte 3.1, nette/forms 3.3                 | `/* pos L:C */`      | `$this->global->forms` runtime provider              |

`ShapeFamily::all()` lists every combination `detect()` can produce, so the coverage tests enumerate all five;
`3.0/provider` is not installable (nette/forms 3.3 and nette/application 3.3 conflict with `latte/latte <3.1.4`).
`ShapeFamily::supports()` accepts Latte 2.x, 3.0 and 3.1; `detect()` throws for anything else (loud over guessing),
and `composer.json` declares `conflict: latte/latte >=3.2.0` so no installable set reaches that throw.

`{form f}{input x}{/form}` as each family compiles it (generated with the real engines — Latte 2 with `UIMacros` and
`FormMacros`, Latte 3 with `UIExtension(null)` and `FormsExtension`; `main()` bodies only):

`2/macros` (latte 2.11.7, nette/forms 3.1.15):

```php
public function main(): array
{
	extract($this->params);
	$form = $this->global->formsStack[] = $this->global->uiControl["f"];
	Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
	echo Nette\Bridges\FormsLatte\Runtime::renderFormBegin($form, []) /* line 1 */;
	echo '
	';
	echo end($this->global->formsStack)["x"]->getControl() /* line 2 */;
	echo "\n";
	echo Nette\Bridges\FormsLatte\Runtime::renderFormEnd(array_pop($this->global->formsStack));
	echo "\n";
	return get_defined_vars();
}
```

`3.0/item` (latte 3.0.26, nette/forms 3.2.9):

```php
public function main(array $ʟ_args): void
{
	extract($ʟ_args);
	unset($ʟ_args);

	if ($this->global->snippetDriver?->renderSnippets($this->blocks[self::LayerSnippet], $this->params)) {
		return;
	}

	$form = $this->global->formsStack[] = $this->global->uiControl['f'] /* line 1 */;
	Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
	echo Nette\Bridges\FormsLatte\Runtime::renderFormBegin($form, []) /* line 1 */;
	echo '
	';
	echo Nette\Bridges\FormsLatte\Runtime::item('x', $this->global)->getControl() /* line 2 */;
	echo "\n";
	echo Nette\Bridges\FormsLatte\Runtime::renderFormEnd(array_pop($this->global->formsStack)) /* line 3 */;

	echo "\n";
}
```

`3.1/item` (latte 3.1.2, nette/forms 3.2.8) — the `3.0/item` body with `/* pos L:C */` markers:

```php
	$form = $this->global->formsStack[] = $this->global->uiControl['f'] /* pos 1:1 */;
	Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
	echo Nette\Bridges\FormsLatte\Runtime::renderFormBegin($form, []) /* pos 1:1 */;
	echo '
	';
	echo Nette\Bridges\FormsLatte\Runtime::item('x', $this->global)->getControl() /* pos 2:2 */;
	echo "\n";
	echo Nette\Bridges\FormsLatte\Runtime::renderFormEnd(array_pop($this->global->formsStack)) /* pos 3:1 */;
```

`3.1/provider` (latte 3.1.6, nette/forms 3.3.0), same prologue:

```php
	$this->global->forms->begin($form = $this->global->uiControl['f'], global: $this->global) /* pos 1:1 */;
	echo $this->global->forms->renderFormBegin([]) /* pos 1:1 */;
	echo '
	';
	echo $this->global->forms->get('x')->getControl() /* pos 2:2 */;
	echo "\n";
	echo $this->global->forms->renderFormEnd() /* pos 3:1 */;
	$this->global->forms->end();
```

All four reduce to the same analysed statements, `$form = Helpers::form('f')` and
`echo Helpers::formField('x')->getControl()` (the committed `processed/` snapshots). The per-family raw and processed
snapshots live in `tests/Unit/Latte/Fixtures/__snapshots__/` (`raw`/`processed` for Latte 2, `latte3.0/` and
`latte3.1/` for Latte 3); the Latte version in their `Generated by Latte` header is normalised, so a patch release does
not re-snapshot by itself.

## Adapter contract

`LatteVersionAdapter` (`src/Latte/Version/LatteVersionAdapter.php`) is the seam:

- `compile(string $source, string $className, string $relativePath): CompiledTemplate` — the `CompileResult` and the
  `ExtractedFacts` (declarations, template facts, form sites) from **one** parse.
- `extractFacts(string $source, string $relativePath): ExtractedFacts` — the facts-only path (`TemplateEdgeIndex`,
  `declarationsFor()`, the `FormMacroCollector` shell).
- `lineMarkerPattern(): string` — the regex with a `<line>` group `LineMapper` reads
  (`ShapeFamily::LINE_MARKER_PATTERN_LINE`/`_POS`).
- `family(): ShapeFamily`.
- `defaultCallables(): DefaultCallables` — the stock filter and function tables and the `Helpers` stand-ins for the
  entries Latte wraps in closures.
- `parseTagArguments(string $argsSource): list<TagArgument>` — an include-family tag's arguments as version-neutral
  `TagArgument`s (`Latte2\MacroTokensArguments`, `Latte3\TagLexerArguments`); `ArgTyper` is the only consumer.

`Latte2Adapter` returns `ExtractedFacts::lazy()` closures, so a caller pays only for the facts it reads;
`Latte3Adapter` captures the facts from the node tree before the compiler passes mutate it and returns
`ExtractedFacts::eager()`.

The engine harvest has a sibling seam, `LatteEngineReader::read(Engine): HarvestedCustoms` (`Latte2EngineReader`,
`Latte3\Latte3EngineReader`), see [latte.md](latte.md#latte-3-extension-harvest).

Selection rules:

- `LatteVersionAdapterFactory` is the one version switch. It reads installed versions only through
  `ProjectInstalledVersions` (the project's own install data, never the Composer view PHPStan's phar merges in).
- Nothing version-dependent runs while the DI container is built. `LatteVersionAdapterAccessor::get()` resolves the
  adapter on the first `.latte` parse, and `CustomsHarvester` asks the factory for the reader on its first harvest. An
  unsupported install is therefore inert with `orisaiNette.latte.enabled` off, and with it on `ConfigurationGuard`
  rejects it with its own message before any parse. `LatteVersionSelectionTest` pins this with spawns whose
  `orisaiNette.installedVersions` service is overridden through `ProjectInstalledVersions::fromRawData()`.
- The Latte 3 classes (`src/Latte/Version/Latte3/`) are never referenced by a type: the factory constructs them by
  reflection from the class-name constants `LATTE3_ADAPTER_CLASS` and `LATTE3_ENGINE_READER_CLASS`
  (`Latte3Adapter::create(ShapeFamily, AdapterCollaborators)`, `Latte3EngineReader::create()`). The primary PHPStan
  config (`tools/phpstan.neon`) analyses the directory against Latte 3; `tools/phpstan.latte2.neon` excludes it.
- `src/Latte/Version/Latte3/**` may use PHP 8 syntax: it is loaded only with Latte 3 installed, which requires PHP 8.
  `make lint` leaves it out, and the factory tests which construct its classes on any install require PHP 8.
- A Latte 3 class must not extend or implement a Latte 3 type if anything calls `class_exists()` on it while Latte 2 is
  installed.

Cache identity follows the family everywhere a family-specific shape is cached: the compile-cache key carries
`family()->id() . '|' . <adapter class>`, `LatteCodeVersion` joins `ShapeFamily::id()` next to the raw versions, and
facts are cached under the content-addressed `latte-facts|<family>` node id and the bridge's form sites
(`FormMacroCollector`) under `latteforms-macros-v2|<family>`.

## Latte 3 compile

`Latte3Compiler` (`src/Latte/Version/Latte3/`) builds a fresh `Latte\Engine` per attempt and calls `parse()` and
`generate()` itself — no loader, no engine cache, no render:

- **Engine.** With an extension harvest: a fresh engine (its own `CoreExtension` and `SandboxExtension`), the
  harvested extensions in the project's order, the functions the project added with `addFunction()`, and the
  harvested feature flags copied as stored. Without one, the fixed set: `UIExtension(null)`, `FormsExtension`,
  `CacheExtension` when nette/caching is installed, `TranslatorExtension(null)`, `Feature::StrictTypes` off.
  `engineSalt()` names that choice for the compile cache.
- **`AnalysisExtension`** is registered last, so its parsers win Latte's last-registration-wins tag dispatch. It
  re-registers every tag the other extensions register through `TagRecorder`, re-implements the declaration tags to
  record their arguments (`TypeCapturingParsers`), replaces `{cache}`'s node with `DeterministicCacheNode` (keyed by
  tag position instead of random bytes; the bridge's own `print()` output with the key replaced — nette/caching 3.1
  prints the key after the storage argument, 3.2 and newer first, both pinned against the real prints; a print
  without a quoted key stays the vendor's, random key included), and gives every claimed unknown name a passthrough
  parser.
- **Unknown tags.** Latte 3 has no unknown-tag hook, only the `CompileException` "Unexpected tag {x}" / "Unexpected
  attribute n:x". `matchUnknown()` claims the name and the parse retries (at most 20 times), unless the name is
  known to the engine as a tag or `n:` attribute (a misplaced known tag), is one of `LATTE2_ONLY_TAGS`
  (`includeblock`, `ifCurrent`, `status`, `use`: a migration error, never a custom tag) or of the intermediate tags
  (`else`, `elseif`, `elseifset`, `case`), or the message carries Latte's "(in JavaScript or CSS" hint (an unescaped
  brace). A claimed name is paired when the source contains `{/name}` or a generic `{/}` anywhere
  (`hasClosingTag()`, textual) and void otherwise. `PassthroughTagParser::paired()` yields the intermediate tags, so
  Latte's own routing hands an `{else}`/`{elseif}`/`{elseifset}`/`{case}` to the pair only while the unknown pair is
  the innermost open tag; the intermediate's arguments are consumed, not analysed, like the unknown tag's own.
- **Class name.** `generate()` output carries Latte's config-hash class name; it is replaced by the path-derived
  `LatteTpl_*` name.
- **Vendor failures.** `parse()` and `generate()` catch `Throwable` through `Compile\VendorCompileFailure::message()`,
  which mirrors `Engine::compile()`: `CompileException`/`SecurityViolationException` and `InvalidArgumentException`
  keep their message, anything else becomes "Thrown exception '…'", and a throwable raised from this library's own
  `src/` is rethrown so it stays an internal error. The parse-error line comes from `TagRecorder::lastTagLine()`.
- **Generated PHP.** `Compile\GeneratedSyntaxCheck::check($php, $lineMarkerPattern)` parses the generated code with
  php-parser on a fresh compile; a failure is a `parseError` "Error in template: …" on the mapped template line and
  replaces the result with `CompileResult::failure()`, so it is not cached. Without it one unparsable template
  (`{php $a = }` with `RawPhpExtension`) is a `phpstan.parse` error, and PHPStan drops every other finding of the run.
- **Memory.** PHPStan runs with the cycle collector off and every Latte 3 parse (engine, parser, tags, nodes) is
  cyclic garbage, about 0.4 MB. `Latte3Adapter::collectParserCycles()` runs `gc_collect_cycles()` after a parse once
  usage grew by `COLLECT_AFTER_BYTES` (64 MB) since the last collection; collecting after every parse is about eight
  times slower.

Latte 2 (`Compile\LatteCompiler`) got the same treatment where it applies: throwables through
`VendorCompileFailure`, `GeneratedSyntaxCheck` with the `/* line N */` pattern, textual pairing of passthrough tags,
the JavaScript/CSS rule, and a token clone per retry (Latte 2's compiler rewrites tokens in place).

## Facts from the node tree

Latte 3 nodes keep no tag name, no argument text and no trace of the intermediate or closing tags that closed them.
`TagRecorder` wraps every tag parser and pairs the node each returns with its `Tag` (`TagRecord`);
`NodeFactsExtractor` walks the pre-pass node tree into a `TemplateEvent` stream, and the ported scanners
(`EventDeclarationScanner`, `EventFactExtractor`, `NodeFormSiteCollector`) apply the Latte 2 rules to that stream.
The adapters' `Declarations`, `TemplateFacts` and form sites are byte-identical on every shared fixture
(`Latte3FactsParityTest` against the `__facts__` JSON references); the divergences Latte 3's own parsing forces are
pinned in `Latte3FactsDivergenceTest`.

**Head rule (ruling).** Header variable types and `{varType}` placement use the Latte 2 head rule — the head ends at
the first tag outside `import`, `extends`, `layout`, `contentType`, `parameters`, `varType`, `varPrint`,
`templateType`, `templatePrint` (`EventDeclarationScanner::HEAD_ALLOWED_TAGS`) — not Latte 3's `Tag::isInHead()`,
which keeps the head open through `{if}`, `{foreach}`, `{define}`, `{var}`, `{do}` and `{php}`. Shared syntax must
mean the same on both lines.

## Method layout and line markers

`DeclarationInjector` keys on `ShapeFamily::latteLine`. Latte 3 emits `main(array $ʟ_args): void` with an
`extract()`/`unset()` prologue and, under `UIExtension`, the snippet-driver guard, plus `prepare(): array` holding the
head statements when the head has content or `{parameters}`.

**Hoisting (ruling).** For a Latte 3 family the injector drops main's prologue and prepare's own prolog and
`return get_defined_vars()`, moves the remaining head statements in front of main's body and removes `prepare()`. The
result is the Latte 2 layout, so `latteMain`/`latteMain_ctx{i}` and the `block*` methods carry the same typed
parameters on every line. A Latte 3 compiled class therefore never has a `lattePrepare` method.

Latte 2 and 3.0 mark lines `/* line N */`, 3.1 only `/* pos L:C */` (the column is ignored). The
`/** {block x} on line N */` doc comment above a block method is a marker of its own: the method and its prologue take
the body's first marker, or the tag's line when the body has none, and the comment is a barrier the back-fill never
crosses. This moved Latte 2 block, define and snippet method findings (`class.notFound` on an injected `@param`,
`missingType.iterableValue`, `shipmonk.deadMethod`) from main's last line to the tag line.

## Eliminator patterns

Each eliminator reads the shapes it matches from a `PatternSet` (`src/Latte/Postprocess/Eliminator/PatternSet.php`):
static-call roles (`isStaticCall(role, class, method)`) and name roles (`hasName()`, `names()`, `name()`).
`FamilyPatterns::for(ShapeFamily, consumerClass)` is the one table of those sets for every consumer in
`FamilyPatterns::CONSUMERS` (the ten eliminators and `FilterRewriter`); the forms eliminator keys on
`ShapeFamily::$formsBridge`, the others on the Latte line. `FamilyCoverageTest` fails when a family lacks a table or a
consumer's Latte 3 table is its Latte 2 one. [latte.md](latte.md#what-is-analysed) lists the shapes per line.

## Test profiles

Each family is tested by installing its versions into a separate vendor directory; the profiles, version groups and
`make` targets are described in the [internals index](README.md#dependency-profiles).

## Upstream template corpus

The upstream Latte, nette/application and nette/forms test suites are a corpus of templates with known outcomes.
`tests/Corpus/CorpusManifestTest.php` analyses all of them in one PHPStan spawn per profile and compares the outcome
per template with a committed manifest, so any change in what compiles is a visible diff.

- **Harvest.** `make corpus-harvest [PROFILE=…]` (`tools/corpus/harvest.php`) clones the upstream repositories at the
  installed pretty versions (`git clone --depth 1 --branch <tag>`) and extracts every template the `.phpt` tests and
  template directories contain into `var/corpus/templates/<profile>/<repo>/<path>#<n>.latte`, plus
  `manifest-source.json` with the package versions and, per template, `expects`. A template is extracted from a
  method call `->compile()`, `->render()`, `->renderToString()`, `->parse()` or `->createTemplate()` whose first
  argument is a string literal or a variable bound to one, and from `StringLoader` arrays. Templates handed to an
  upstream test helper function instead (`exportTraversing()` from Latte 3's `tests/helpers.php`, the per-file
  `testTemplate()` functions of the embed tests) are not extracted, and `*.nodes.phpt` files are skipped.
- **`expects`** is `{assertion, class, message}` or `null`: the upstream assertion (`Assert::exception()`,
  `Assert::error()`, …) wrapping the call which renders or compiles the template, with the class and message as
  written. It records an error outcome the upstream test asserts, not necessarily a compile error — a runtime,
  sandbox or deprecation outcome is recorded the same way. For a template registered in a `StringLoader` array, the
  outcome goes to the entry the call renders (`PhptTemplateExtractor::renderedLoaderEntry()`): the entry of the
  nearest preceding loader containing the key. The heuristic can misattribute when a later `setLoader()` lacks the
  key or a different engine variable renders it; sibling entries of the rendered one stay `null`.
- **Manifest.** `tests/Corpus/manifest.<profile>.json` (`default` for the primary set in `vendor/`, and the profiles
  `latte2`, `latte2-nette32`, `latte30`; the test takes the profile from `COMPOSER_VENDOR_DIR`) holds a header with the pretty versions of latte/latte, nette/application and nette/forms, and per template
  `"analysed"`, `"compileError"` (an `orisaiNette.latte.parseError` on the file) or `{"expectedFail": "<reason>"}`
  (a compile error the analysis accepts, with a reason kept across regenerations). The test fails on any
  `orisaiNette.latte.internalError` or `phpstan.parse` finding, any run-level internal error, any finding outside the
  harvested set, and a header which differs from the installed versions. It skips when the profile's corpus is not
  harvested.
- **Regenerate.** `make corpus-manifest [PROFILE=…]` runs the test with `CORPUS_MANIFEST_WRITE=1`: it rewrites the
  manifest and prints the per-state counts, the state diff and two review lists — templates analysed although the
  upstream test asserts a `CompileException`, and compile errors the upstream test does not assert. Every entry of
  both lists needs a reason (engine setup the upstream test does, a sandbox policy, …) before the manifest is
  committed.
- **Install.** CI does not float the corpus versions: `make corpus-install [PROFILE=…]` runs
  `tools/corpus/manifest-composer.php`, which writes the git-ignored `composer.corpus-<profile>.json` with every
  package of the manifest header pinned to its recorded version, and installs it. With a profile it first writes
  `composer.<profile>.json` (`tools/profile.php`), so a CI corpus row's PHPStan spawn locates vendor classes through
  the same Composer file as a local `make corpus-manifest`. The `corpus` CI job runs `corpus-install`,
  `corpus-harvest` and `make tests PROFILE=… ARGS=tests/Corpus` per profile.
- **Refresh rule.** Refreshing a profile's corpus is `make profile` (floating) + `make corpus-harvest` +
  `make corpus-manifest`, committed together in **one** commit; otherwise the manifest header no longer matches what
  `corpus-install` pins.

`make smoke-dmonitor [APP=<dir>]` (local only) analyses a real Latte 3 application — by default
`../../../apps/fr/dmonitor` — with that application's own vendor and PHPStan, the engine from its own container, and
`orisaiNette.installedVersions` overridden with its install data; it fails on any internal error.

## Adding a family

When a new Latte line (e.g. 3.2) or forms bridge shape appears:

1. `ShapeFamily`: a line constant, `detect()`, `supports()`, `all()` and `lineMarkerPattern()`.
2. `composer.json`: lift the `latte/latte` conflict to the next unsupported line.
3. `ConfigurationGuard`: the supported-version message and the version-pair rows for the bridges the line needs.
4. A profile (`tools/profiles/<name>.json`), a version group in `InstalledVersionsGuard`, the CI rows
   (`tests-profile`, `static-analysis-profile`, `corpus`), a harvested and reviewed
   `tests/Corpus/manifest.<name>.json`. When the new line becomes the newest one, the primary set moves to it: the
   former primary set becomes a profile and the manifests are re-keyed accordingly.
5. Compile the fixtures on the new line and diff the raw snapshots against the previous line's; every new shape gets a
   `FamilyPatterns` table (`FamilyCoverageTest` lists the missing ones), `DeclarationInjector` and `LineMapper` follow
   a new method layout or marker, and the processed snapshots must come out equal to the other lines'.
6. The Latte 3 facts parity tests (`Latte3FactsParityTest`, the runtime parity suite under
   `tests/Integration/Latte/RuntimeParity/`) run on the new profile unchanged.

## Known limitations

- `hasClosingTag()` is textual: a `{/foo}` inside a `{* comment *}` or a string pairs `{foo}`, and any `{/}` pairs
  every unknown name — `{foo}\nx\n{if $a}y{/}` is a parse error "Unexpected end, expecting {/foo}" where Latte 2 falls
  back to an unpaired `{foo}`. Using one unknown name both paired and unpaired in one template is Latte's own parse
  error.
- `VendorCompileFailure` decides a throwable's origin by `getFile()` alone. A library bug which makes vendor code throw
  (a bad argument into a Latte API, php-parser failing inside `GeneratedSyntaxCheck`) surfaces as a template
  `parseError` "Thrown exception '…'", not as an internal error.
- Sandbox policies are not modelled; the corpus's sandbox templates are `analysed` where upstream asserts a
  violation.
- The raw snapshots, the corpus manifests and the parity probes were produced on latte 2.11.7, 3.0.26 and 3.1.6.
