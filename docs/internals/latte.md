Maintainer documentation; the user-facing guide is ../README.md.

# Latte template analysis

Components in `src/Latte/` teach PHPStan to analyse `.latte` templates natively: each
template is compiled with the real Latte compiler, the compiled PHP is cleaned up into an
analysable shape, and the result is fed through PHPStan's normal pipeline (result cache,
baseline) with errors reported on the original `.latte` lines. Undeclared
template variables, wrong types passed into includes, calls to unknown macros/filters, and misuse
of anything PHP-visible inside a template are all caught statically instead of surfacing at
render time. (One family-wide exception: vendor `@internal`-API errors are ignored on `.latte`
paths, because generated template code necessarily calls internal Latte/Nette runtime APIs —
`orisai.nette.latte.internalAccess` below covers the user-written side of that gap.)

## What is analysed

`fileExtensions: [php, latte]` + `orisai.nette.latte.enabled: true` make PHPStan's file finder pick up
every `.latte` file under the analysed paths alongside `.php` files — no separate scan, so
parallelism, the result cache, and the baseline all apply uniformly. The library default
(`config/latte.neon`) ships `orisai.nette.latte.enabled: false`.

Every parameter this extension declares is namespaced under `orisai.nette.latte`.

Latte 2.11, 3.0 and 3.1 are supported; [latte-versions.md](latte-versions.md) describes the version seam, the shape
families, the Latte 3 compile and the upstream template corpus. In short: everything that reads Latte's own API or
depends on the shape of its generated code sits behind `LatteVersionAdapter` (`src/Latte/Version/`):
`compile()` returns a `CompiledTemplate` — the generated code and the `ExtractedFacts` (declarations, include edges, form-macro sites) from the
same parse — `extractFacts()` is the facts-only path, plus the line-marker pattern and the
`ShapeFamily` (Latte line + forms bridge, e.g. `2/macros`). The engine harvest is the sibling
`LatteEngineReader` (`Latte2EngineReader`, `Latte3\Latte3EngineReader`), whose `read()`
`CustomsHarvester` calls inside its own error containment.
`LatteVersionAdapterFactory` is the one version switch (installed `latte/latte` and `nette/forms`
versions). Nothing asks it while the container is built: the adapter is resolved by
`LatteVersionAdapterAccessor::get()` on the first `.latte` parse and the reader inside
`CustomsHarvester::harvest()` (first harvest; asked outside the containment window, so a missing
reader is a loud `LogicException` rather than an empty harvest). With `orisai.nette.latte.enabled` off no template is parsed and no
salt harvested, so an unsupported install is inert; with it on, `ConfigurationGuard` rejects the
install with its own message before any parse. `ExtractedFacts` resolves each fact on first
access, so the routing parser's `compile()` pays only for the declarations it reads. `Latte2Adapter` wraps the
classes described below (`FormSiteScanner` holds the Latte 2 form-macro token scan). The compile
cache key carries the family id and the adapter class, so a different adapter never serves
another's compiled output.

Template facts take the same route. `TemplateEdgeIndex::declarationsFor()` (and its nullable
`findDeclarations()`, null for an unreadable file) is the single declarations reader: contract
checks, declared-vars resolution, placement checks, the `{templateType}` collector and the debug dump
all read through it, and `factsFor()` caches under the content-addressed `latte-facts|<family>` node
id, so a different family never reads another's cached facts. The argument list an include-family
site carries (`IncludeTarget::getArgsSource()`) is read through `LatteVersionAdapter::parseTagArguments()`
into version-neutral `TagArgument`s (name, expression source, spread, and a lone variable or
literal classified), `MacroTokensArguments` on Latte 2 and `TagLexerArguments` on Latte 3;
`ArgTyper` is the only consumer, so no shared class tokenizes Latte syntax itself. `Latte2Adapter`
scans the token stream; `Latte3Adapter` reads the node tree of the one parse before the compiler passes mutate it
(`NodeFactsExtractor`): a `TagRecorder` turns every tag into a `TemplateEvent` stream, and the ported
scanners (`EventDeclarationScanner`, `EventFactExtractor`, `NodeFormSiteCollector`) apply the Latte 2
rules — including the Latte 2 head rule — to that stream, which is what keeps the two adapters'
`Declarations`, `TemplateFacts` and form sites byte-identical on every shared fixture
(`Latte3FactsParityTest`); the divergences Latte 3's own parsing forces are pinned in
`Latte3FactsDivergenceTest`.

The generated code itself differs by line, and `DeclarationInjector` normalises the layout before
anything else looks at it. Latte 2 emits `main(): array` with the whole template (the head `{var}`/
`{default}` statements included) and `prepare(): void` with the UI initialisation. Latte 3 emits
`main(array $ʟ_args): void` — prologue `extract($ʟ_args); unset($ʟ_args);` plus, under
`UIExtension`, an `if ($this->global->snippetDriver?->renderSnippets(...)) { return; }` guard — and,
only when the head has content or `{parameters}`, `prepare(): array` holding the head statements
and returning `get_defined_vars()`, which the runtime feeds back into `main()`. The injector keys on
`ShapeFamily::latteLine`: for a Latte 3 family it drops main's prologue, drops prepare's own prolog
(`extract($this->params)` or the `{parameters}` assignments and their `unset($ʟ_args)`) and its
`return get_defined_vars()`, moves the remaining head statements in front of main's body and removes
`prepare()` — the Latte 2 layout, so `latteMain`/`latteMain_ctx{i}` and the `block*` methods
carry the same typed parameters and the head `{var}`/`{default}` statements are typed in place
exactly as on Latte 2 (`{default}` is matched in both spellings: Latte 2's
`extract([...], EXTR_SKIP)` and Latte 3's `$x ??= array_key_exists('x', get_defined_vars()) ? null :
…`). The `@return array{}` doc follows the native `: array` return type, so Latte 3's `void` main
gets none. `DeclarationInjectorLayoutTest` pins the injector's output on the committed raw
snapshots of all three lines.

Line markers differ too: Latte 2 and 3.0 emit `/* line N */`, Latte 3.1 only `/* pos L:C */`. Each
adapter's `lineMarkerPattern()` names the `<line>` group `LineMapper` reads (the column is ignored),
and the `/** {block x} on line N */` doc comment every line writes above a block method is a marker
of its own: the method and its prologue take the body's first marker, or the tag's line when the
body has none, and the comment is a barrier the back-fill below never crosses, so a method's
trailing statements never borrow the next block's line.

For each `.latte` file on Latte 2, `LatteCompiler` (`src/Latte/Compile/`) runs the real
`Latte\Parser`/`Latte\Compiler` (no `Engine::compile`, no engine cache, no
application boot) with the five built-in macro sets (`CoreMacros`,
`BlockMacros`, `UIMacros`, `FormMacros`, `CacheMacro`), plus any macro sets harvested from the application's
real Latte engine (see *Custom filters, functions and macros* below). On Latte 3, `Latte3Compiler` calls the engine's
own `parse()` and `generate()` with the fixed or harvested extensions and `AnalysisExtension` (see
[latte-versions.md](latte-versions.md#latte-3-compile)). Macros
generate code, so compiling for real gives ground-truth semantics instead of a hand-maintained
re-implementation. The compiled class name is derived from the file path, never from content —
deterministic across runs, no efabrica-style non-determinism.

The compiled PHP then goes through post-compile passes (`src/Latte/Postprocess/`):

- **Line remap** — every generated node is retagged with its `.latte` source line, so all
  diagnostics land on template lines natively. Unmarked non-echo statements a macro emits before its
  marked statement take that marker's line, because Latte marks only the last statement of an
  attribute macro. The rule cannot tell such a prologue from an unmarked non-echo statement a macro
  emits AFTER its marked statement (for example the iterator restore after a `{/foreach}`), which
  therefore takes the next marker's line too, as do a method's unmarked non-echo prologue lines and
  the continuation lines of a multi-line statement. Findings can only relocate: the line map never changes a typing verdict.
- **Typed declaration injection** — the `extract($this->params)`/`extract($ʟ_args)` prologue Latte
  emits is replaced with explicit, typed parameters/locals (see *Typing templates* below), after the
  Latte 3 `main`/`prepare` split has been folded back into the Latte 2 layout (see above).
- **Plumbing elimination** — a registry of eliminators for each Latte line's finite set of emission
  patterns (escaping wrappers except `escapeJs()`, which JSON-encodes any value and therefore
  stays, `$ʟ_*` temporaries, snippet try/finally shells, `CachingIterator` wrapping,
  block-dispatch plumbing, forms/UI runtime calls, …) reduces generated scaffolding to the plain
  analysable expressions underneath it, so strict-rule noise doesn't leak through. The shapes an
  eliminator matches are a `PatternSet` per `ShapeFamily`, kept in one table
  (`Eliminator/FamilyPatterns`): Latte 2 and 3.0 escape through `Latte\Runtime\Filters`, 3.1
  through `HtmlHelpers`/`XmlHelpers`/`Helpers` and prints an attribute whole
  (`formatAttribute(' title', $a)` reduces to `$a`; the list/style/data/json/aria/bool formatters
  accept arrays, so their value goes through a typed `Helpers::html*Attribute()` stand-in instead of
  a bare echo); 3.x iterates with
  `Latte\Essential\CachingIterator`, opens capturing shells with `ob_start(fn() => '')`, drops
  `{templatePrint}`'s `printClass(...); exit;`, and 3.1 minifies `{spaceless}` through
  `WhitespaceMinifier::start()/end()` and re-wraps a filtered `{capture}` in `Html` behind a
  `$ʟ_fi->contentType` guard that goes with the shell. The n:attribute, UI and block shells
  differ per line too: `n:attr` prints through `NAttrNode::attrs($ʟ_tmp, false)` (Latte 2
  `Filters::htmlAttributes(...)`) and the temp array is inlined into that call; `n:tag`'s
  closing-tag temps become `$latteTagN` (Latte 2 and 3.0 index `$ʟ_tag[N]`, 3.1 snapshots a scalar
  `$ʟ_tag` into `$ʟ_tags[N]`) and 3.x's `validateTagChange()` result temp `$latteTagName`;
  `n:ifcontent`'s shell opens with the line's no-op `ob_start()`; a dynamic `{control $obj}`
  is one `if (!is_object($ʟ_tmp = ...))` on Latte 3 (Latte 2: if/else); `{embed}` has no dead
  `if (false)` mirror on Latte 3; `{include parent}` is `renderParentBlock()` (Latte 2
  `renderBlockParent()`), its `get_defined_vars()` flattened like an unresolvable block dispatch;
  Latte 3's dynamic `{block $name}` unwraps `Helpers::stringOrNull($ʟ_tmp = $name) ?? throw ...`
  to the Latte 2 `addBlock($ʟ_nm = $name, ...)` shape and its anonymous filtered `{block |f}` IIFE
  to the body Latte 2 prints inline, so the filtered-block capture shell stays the same on every
  line; `{formPrint}`/`{formClassPrint}`'s `Blueprint::latte|dataClass(...); exit;` is dropped like
  `{templatePrint}`. A Latte 3 `{cache}` compiles through `DeterministicCacheNode`, keyed by tag
  position instead of `CacheNode`'s random bytes (Latte 2: `DeterministicCacheMacro`), and the
  `createCache()/end()/rollback()` calls stay analysed on every line. The forms shapes follow the
  forms bridge rather than the Latte line: Latte 2 `FormMacros` offsets
  `end($this->global->formsStack)['x']` into `$ʟ_input`/`$ʟ_label` temps, Latte 3 with
  nette/forms 3.1.7–3.2 resolves `FormsLatte\Runtime::item('x', $this->global)` into
  `$ʟ_label`/`$ʟ_elem`, and nette/forms 3.3 keeps the scope inside the `$this->global->forms`
  runtime (`begin($form = uiControl['x'], global: …)`, `get('x')`, `get('c', Container::class)`,
  `getScope()`, `renderFormBegin/End()`, `end()`); all three reduce to the same
  `Helpers::form('x')`/`formObject($var)`/`formContainer('c')`/`formField('x')` calls (a dynamic
  Latte 3 name goes through `formField($name)` too, the Latte 2 `is_object($ʟ_tmp = …) ? … :
  end(…)[$ʟ_tmp]` ternary stays as it is), with `$ʟ_elem` renamed to `$latteElem`; nette/forms
  3.3's `{form scope x}` ternary (`isNested() ? forms->get($ʟ_tmp, Container::class) :
  uiControl[$ʟ_tmp]`) types as the form named `x`, `{form detached x}` as a plain `{form x}`. A
  paired `{label x}…{/label}` keeps its label in a temp on every bridge — Latte 2 guards it with
  `if ($ʟ_label = …->getLabel()) echo $ʟ_label->startTag()`, Latte 3 with
  `($ʟ_label = …->getLabel())?->startTag()` — and `BaseControl::getLabel()` returns
  `Html|string|null`, so both become one `$latteLabel = Helpers::formLabel('x')` statement (an
  analysis-only `Nette\Utils\Html` stand-in, what the vendor macro assumes at runtime) followed by
  plain `startTag()`/`endTag()` echoes, and a self-closing `{label x, attrs /}` (which chains
  `addAttributes()` onto the label the same way) binds the same stand-in; a bare `{label x /}`
  keeps its `formField('x')->getLabel()` read. `{input x, attrs}` chains `addAttributes()` onto
  `getControl()`/`getControlPart('part')`, declared `Html|string` on `BaseControl`, and reduces the
  read to the same kind of stand-in, `Helpers::formInput('x'[, 'part'])`; a plain `{input x}` keeps
  `formField('x')->getControl()`.
  `FamilyCoverageTest` fails when a family lacks a table or when a consumer's Latte 3 table is
  its Latte 2 one.
- **Filter-call rewrite** — `($this->filters->truncate)(...)` becomes the real callable
  (`\Latte\Runtime\Filters::truncate(...)` on Latte 2, `\Latte\Essential\Filters::truncate(...)` on
  Latte 3, `number_format(...)`, ...), so filter arguments check against real vendor signatures;
  harvested and per-template custom filters/functions rewrite the same way, against their own real
  signatures (see *Custom filters, functions and macros* below). The stock table comes from the
  adapter's `defaultCallables()`: Latte 2's `Latte\Runtime\Defaults`, Latte 3's compile engine
  (`CoreExtension` plus the bridges it carries), with typed `Helpers` standing in for the entries
  Latte wraps in closures (`|limit`, `hasBlock()`, `hasTemplate()`, `|translate`, `|modifyDate`).
  A Latte 3 function call passes the template first (`($this->global->fn->clamp)($this, $v)`); that
  argument is dropped before the signature is matched, and an instance-method entry such as Latte
  3's locale-aware `|number` dispatches through a typed receiver instead of a static call.
  The stock `slice` filter is redirected to a typed helper whose return follows the input (array in,
  an array whose values keep their type and whose keys widen to `int|string` out (refinements such
  as non-empty or list are dropped, because a slice can be empty and preserved keys need not be
  sequential); string in, string out) instead of the vendor's `array|string` (the widening is
  pinned by the `filter-slice` integration fixture).
- **Diagnostics materialization** — recorded diagnostics (unknown macro, unresolved include, ...)
  become synthetic calls that a small rule (`LatteDiagnosticRule`) turns into ordinary
  identifier-tagged, ignorable/baselinable PHPStan errors.

Everything not eliminated is native PHP as far as PHPStan is concerned: `variable.undefined`,
`method.notFound`, `argument.type`, etc. fire exactly as they would in a `.php` file, on the
`.latte` line that produced them.

## Typing templates

Latte 2.11 erases all type declarations at compile time, so the analysis reads them straight from
`.latte` source instead. A template with none of these declared analyses every variable as
undefined — that is the intended, fully-strict default, not a bug.

- **`{templateType C}`** (head-only) — the declared parameter set becomes `C`'s public properties,
  inherited ones included. Unknown class → `orisai.nette.latte.unknownType`. A property's type comes from its
  native type hint or its own `@var` docblock, whichever it has
  (`PropertyTypeResolver::resolve()`); a property typed as a bare `@template` parameter resolves to
  `mixed` rather than to whatever an `@extends UITemplate<FooControl>` binds it to. The surface is
  native reflection, so a purely virtual class-level `@property` tag with no backing property
  statement contributes **nothing** — see *Known limitations*.

  ```latte
  {templateType Acme\Control\CallWindow\CallWindowControlTemplate}
  {contentType html}

  <div class="call-window" id="{$control->htmlId('root')}">
  	{$customerName}
  </div>
  ```

  `{varType SomeTemplate $this}` is **not** equivalent — it is a documented no-op for the `this`
  variable name (it never materializes as a parameter or property). Use `{templateType}` to type
  the template class itself.

- **`{varType T $v}`** — declares `$v : T`. In the header it joins the parameter set; mid-file it
  overrides `$v`'s type from that point on, trusted like an inline `@var`.
- **`{var $x = expr}` / `{var T $x = expr}`** — compiles to `$x = expr;`; the typed form also
  checks `expr` against `T` and narrows `$x` to `T`.
- **`{parameters T $a = default, ...}`** — the per-parameter extraction becomes a typed parameter
  list with defaults, checked like a real function signature.
- **`{default $x = expr}`** / **`{default T $x = expr}`** — becomes `$x ??= expr;`; the typed form
  checks like `{var}`. See *Known limitations* for the one place this diverges from runtime.

```latte
{templateType Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Support\FixtureTemplate}
{varType string $label}

{var $x = 1}
{var int $y = 2}
{default $z = 3}
{$label} {$x} {$y} {$z}

{default bool $flag = false}
{if $flag}on{/if}
```

Declaring `{templateType}` is not cosmetic: `{varType CallWindowControlTemplate $this}` (the no-op
form above) leaves every template variable undeclared and reported as `Undefined variable`, while
`{templateType CallWindowControlTemplate}` makes the class's public properties the declared, typed
parameter set. Declaring a template's type is usually a single-line change that removes a batch of
false undefined-variable errors at once.

**A declared property is a parameter whether or not it has a default.** `{templateType C}` where
`C` declares `public int $x;` — typed, never initialized — makes `$x` *definitely* defined in the
body: neither `Undefined variable: $x` nor `Variable $x might not be defined`. A header
`{varType T $x}` behaves the same way. `PropertyTypeResolver::resolveAllPublic()` filters on
visibility and nothing else; there is deliberately no `getDefaultProperties()`/`isInitialized()`
gate.

This is the point at which *declaring* and *inferring* are answered differently, on purpose.
`FactoryProvidedVars::readTemplateClass()` asks the opposite question — what does the template
factory actually write? — and therefore *does* apply that gate, dropping a typed-no-default
property from its scope as **absent** rather than nullable unless the write is statically proven
(`Certainty::HAPPENS`). An author writing `{templateType}` is stating a contract about what will
be there, not asking the analysis to work it out; taking that statement at face value is the whole
value of the tag. The price is a real one — see *A declared property is a parameter even when
nothing ever writes it* under *Known limitations*.

### Declaration consistency (native vs `{varType}` override)

A `{varType}` can coexist with a *native* declaration of the same variable — a file's
`{templateType}`-derived property, or a `{define}` block's own parameter type. When it does, the
`{varType}` is checked against the native type as an override, the same way PHPStan checks a `@var`
PHPDoc against a native property type. Only depth-0 declarations participate (the file's own
header — a mid-file `{varType}`, even at depth 0, is a flow re-type like an inline `@var` and is
never matrix-checked — or a block's own body top level) — the comparison never looks at include
edges or call sites, only at a single target's own two declarations of the same name:

| Relation (native vs override) | Identifier | Behavior |
|---|---|---|
| Exact match | `orisai.nette.latte.duplicateDeclaration` | Always reported — the `{varType}` adds nothing. |
| Override narrower (a subtype of native) | `orisai.nette.latte.narrowingOverride` | Reported unless `orisai.nette.latte.allowNarrowingOverride: true`. |
| Override wider or unrelated | `orisai.nette.latte.impossibleOverride` | Always reported — the override can never hold. |

An untyped native side (a block param without a type hint) plus a `{varType}` is **not** a
conflict — that is the ordinary block input-contract channel (the `{varType}` is the param's only
type, not an override of one), so it is never reported here; a `{block}` tag falls in this same
bucket unconditionally, since it never carries params at all (only `{define}` does). Same for a
`{varType}` naming a property `{templateType}`'s class doesn't have, or one that exists but
resolves to `mixed` (no native type hint, no `@var`) — indistinguishable from the untyped case at
this resolver's precision. An unresolvable type string on either side degrades to no report
(tight-catch, OPEN — never crashes, never false-closes).

`orisai.nette.latte.allowNarrowingOverride` (bool, default `false`) is the one knob: strict by default, matching
PHPStan's own trust-phpdoc stance on `@var` narrowing a native property type. A downstream
consumer that deliberately narrows a wider or cross-repo-vendored native type via `{varType}` (a
native type it cannot itself edit) opts in explicitly; `duplicateDeclaration` and
`impossibleOverride` are unconditional regardless of this flag — narrowing is the only relation
that's ever a legitimate choice, never a bug.

### Mid-file `{varType}` placement

A **header** `{varType T $v}` joins the parameter set and applies to the whole template. A
**mid-file** one only applies from its own position onward, which is easy to misplace: a developer
writes it somewhere in the middle expecting whole-file scope and gets a declaration that silently
covers only part of the file. Requiring it to sit directly above the construct that binds `$v`
makes its scope self-evident from where it is written.

The model, the identifier suffixes and the message shapes deliberately mirror PHPStan's own
`@var` rule, `PHPStan\Rules\PhpDoc\WrongVariableNameInVarTagRule` — a user who knows the PHP
behaviour should recognise these immediately. Latte's tag is **stricter about placement than that
rule is for a named `@var`**, and the reason is structural: PHP's `@var` may omit the variable
name, so upstream's anchor list mostly exists to decide *which* variable an unnamed tag means, and
a named tag above an arbitrary statement is only checked for the variable's existence. Latte's
compiler rejects a variable-less `{varType}` outright (`Unexpected content, expecting
{varType type $var}`, surfaced here as `orisai.nette.latte.parseError`), so every `{varType}` that reaches this
check is named, and the anchor list is repurposed as the placement contract it describes.

Two positions are **exempt entirely**, because they are parameter declarations rather than
mid-file locals:

- a **header** `{varType}` — the file's own parameter set;
- a `{varType}` at a `{block}`/`{define}` body's own **nesting depth** — that block's input
  contract, the one this extension already reads for include-site checking (see *Cross-file
  model*). This is a DEPTH test, not a position one: `{define b}{$q}{varType string $x}{$x}{/define}`
  is exempt even though the tag follows other content, because it still sits directly in the
  block's own body, one level deeper than the file itself — not nested inside a further
  `{if}`/`{foreach}` etc. *within* the block, which would push it one level deeper still and make it
  an ordinary mid-file declaration again. The block/define must be **statically named**: an
  anonymous `{block}` or a dynamically-named `{define $name}` is never a call site's own target, so
  it gets no exemption either — a `{varType}` at its own nesting depth is checked as an ordinary
  mid-file local, the same way `TemplateFactExtractor` never records a `blockDeclaredVar` for one.

Accepted anchors — the first non-`{varType}`, non-filler construct after the tag (comments and
blank text never break the run; several `{varType}` tags in a row share one anchor):

| Latte construct | Compiles to | Binds |
|---|---|---|
| `{var $x = e}` / `{var T $x = e}` | `Expression`-wrapping-`Assign` | every name the tag declares |
| `{default $x = e}` / `{default T $x = e}` | `Expression`-wrapping-`AssignOp` (`??=`) | every name the tag declares |
| `{capture $v}` | `Expression`-wrapping-`Assign` (post-elimination) | `$v` |
| `{foreach $items as $k => $v}` | `Foreach_` | `$items` (only when it is a bare variable), `$k`, `$v` |
| `<el n:foreach="…">` / `n:inner-foreach` | the same `Foreach_` | as above |
| `{php $x = e}` / `{do $x = e}` | `Expression`-wrapping-`Assign` | `$x`, or *nothing checkable* |

A `{php}`/`{do}` body that is not a plain `$name = …` (a list assignment, a property write, a bare
call) binds an unresolvable set: it still counts as an anchor, but the name is never checked, so an
unparsed expression can never manufacture a mismatch. Latte has **no equivalent of PHP's `static`
or `global`**, so upstream's two anchors for those have no counterpart here rather than an invented
one.

| Situation | Identifier | Message shape |
|---|---|---|
| No anchor at all (separated from its assignment, above a construct that assigns nothing, at end of template) | `orisai.nette.latte.varTypeMisplaced` | *Mid-file `{varType}` for `$x` must sit directly above an assignment to `$x`, not above `{if}`.* |
| Anchor binds exactly one variable, and it is not this one | `orisai.nette.latte.varTypeDifferentVariable` | *Variable `$x` in `{varType}` does not match assigned variable `$y`.* |
| `foreach` anchor that binds no such variable | `orisai.nette.latte.varTypeDifferentVariable` | *Variable `$x` in `{varType}` does not match any variable in the foreach loop: `$items`, `$item`* |
| Anchor binds several variables (or several tags share it), and none is this one | `orisai.nette.latte.varTypeVariableNotFound` | *Variable `$x` in `{varType}` does not exist.* — mirrors `processAssign()`'s own wording verbatim: with more than one candidate, upstream stops listing them and just says the name does not exist |

The dominant real-world trigger is not a misplaced local at all: it is a **template parameter whose
`{varType}` lost its header position** to a preceding non-header tag (a `{var}` above it is enough).
There the named variable is never assigned anywhere in the file, so the assignment the message asks
for cannot be written — the fix is to move the tag back into the header. `orisai.nette.latte.varTypeMisplaced`
detects that case (nothing in the whole template binds the variable) and adds a tip saying so:

> Variable `$items` is never assigned in this template - move the `{varType}` above the first
> non-header tag to declare it as a parameter for the whole template instead.

The tip is gated, not unconditional: a variable that *is* assigned somewhere is a genuine local
declaration in the wrong place, where the message's own remedy is the right one. A template
containing a construct whose bindings cannot be resolved (an opaque `{php}`/`{do}` body) suppresses
the tip entirely rather than claim a variable is never assigned when an unread statement might
assign it.

**`foreach` is a first-class anchor and no warning is emitted for declaring the ITEM rather than
the iterable.** `{varType string $item}` above `{foreach $items as $item}` is silent by design —
suggesting `{varType array<string> $items}` instead would be a style opinion, and PHPStan's own
rule does not make it either. Only the *name* is checked.

**A name mismatch is silent when the name is already known from earlier in the template**, mirroring
`processAssign()`'s own `!$scope->hasVariableType($key)->no()` guard exactly: a header parameter, a
`{parameters}` entry, or an earlier `{var}`/`{default}`/`{capture}`/`{foreach}`/`{php}`/`{do}`
assignment all count, regardless of whether that earlier binding is the one this particular tag was
even trying to describe.

```latte
{varType string $p}
{var $q = 1}
{varType string $p}
{var $other = 2}
```

The second `{varType string $p}` is anchored to `{var $other = 2}` and does not match it — but `$p`
is already known (it is a header parameter), so this is silent, exactly as PHPStan is silent on the
PHP analogue. Without the guard this would wrongly report `orisai.nette.latte.varTypeDifferentVariable`. The
guard is scoped to non-`foreach` anchors only, matching upstream precisely: `processForeach()` has
no such guard, so a `foreach` mismatch is reported even when the name is otherwise known. An earlier
construct whose bindings cannot be resolved (an opaque `{php}`/`{do}` body) counts as known too,
the same MAYBE-counts-as-YES direction `hasVariableType()->no()` takes on a scope it cannot fully
see through either.

Upstream's `varTag.noVariable` and `varTag.multipleTags` have **no Latte counterpart** and are
deliberately not implemented: both fire only for an *unnamed* `@var`, and Latte's grammar has no
unnamed form (see the compiler rejection above), so neither has a reachable input.

Placement is checked at the Latte **token** level, never against the compiled PHP. The pipeline
rewrites the very shapes an AST matcher would key on — `{capture}` is an output-buffering shell
before the eliminator pass and a plain assignment after it, `{foreach}` gains a `CachingIterator`
wrapper when the body uses `$iterator`, and a mid-file `{varType}` injects its own assignment at
the tag's own line — so a token-level answer is the only one stable across every pipeline stage.

### `{varType}` against the assigned expression

The third and last `{varType}` axis (the first two being the native-declaration override matrix
above and the cross-file include contract): once a mid-file `{varType}` has a valid anchor, its
declared type is compared against the **real inferred type of the expression that anchor assigns**.
This is a port of PHPStan's `VarTagTypeRuleHelper`, including both of its knobs:

| Identifier | Mirrors | Gate |
|---|---|---|
| `orisai.nette.latte.varTypeNativeType` | `varTag.nativeType` | always runs — but its own strictness on a *widening* declaration is toggled by `orisai.nette.latte.reportAnyTypeWideningInVarType` (see below); only an outright incompatible type is unconditional |
| `orisai.nette.latte.varTypeType` | `varTag.type` | `orisai.nette.latte.reportWrongPhpDocTypeInVarType` (skipped entirely when off) |

| Option | Mirrors | PHPStan core default | **Default here** |
|---|---|---|---|
| `orisai.nette.latte.reportWrongPhpDocTypeInVarType` | `reportWrongPhpDocTypeInVarTag` | `false` | **`true`** |
| `orisai.nette.latte.reportAnyTypeWideningInVarType` | `reportAnyTypeWideningInVarTag` | `false` | **`true`** |

Both default to **`true`**, deliberately stricter than PHPStan core. The reason is that a
`{varType}` has no runtime meaning whatsoever — Latte 2.11 emits no code for it, it exists purely
for the analyser — so a declared type that disagrees with what the anchor assigns is simply a
mistake, with none of the "the PHPDoc is the intended contract and the native type is incidental"
tension that makes these opt-in for real PHP code.

- `orisai.nette.latte.reportWrongPhpDocTypeInVarType` adds a second comparison, against the expression's
  PHPDoc-level type, once the native-type comparison — which always runs, regardless of either
  flag — has passed. It is what catches a `{varType}` that is not merely wider or narrower but
  **incompatible** with an expression whose precision lives in a docblock (`{varType array<int>
  $nums}` over `{var $nums = $rows}` where `$rows` came from an `@return array<string>` method
  whose native return type is a bare `array`).
- `orisai.nette.latte.reportAnyTypeWideningInVarType` drops the constant-value and generic-variance leniency, so
  a `{varType}` that **widens** a precisely inferred expression is reported too
  (`{varType int $n}` over `{var $n = $q}` where `$q` is the literal `1`).

Narrowing is always silent — that is what the tag is for. A literal on the right-hand side keeps
core's own leniency in both settings (`{varType int $n}{var $n = 5}` is never reported), matching
`VarTagTypeRuleHelper`'s special-casing of scalars, `array` literals and constant fetches.

Upstream's third identifier, `phpstanApi.varTagAssumption`, is not ported: it fires only when a
PHPStan `Type` object is the inferred type of the assigned expression, which is an
extension-authoring situation, never a template one.

**Several `{varType}`s on one anchor line.** The declaration a given assignment is compared against
is found by (anchor line, anchor kind, variable name). That normally identifies exactly one
declaration; it identifies several only when more than one `{varType}` for the *same* variable
shares one anchor line. Two shapes exist, and they are treated differently:

- Each declaration has its **own** `{var}`/`{default}`/`{foreach}` right there
  (`{varType int $x}{var $x = 1}{varType string $x}{var $x = 'a'}`) — assignments pair with
  declarations one-to-one in source order, so both are checked against their own type.
- A **run** of declarations anchored on one single statement
  (`{varType int $x}{varType string $x}{var $x = 1}`) — this re-declares one variable twice, which
  core's own `variable.overwrite` already reports. Only the first declaration of the run is
  compared against the statement; the rest are not re-checked on this axis. (Known limitation:
  before this was pinned, *which* declaration got compared depended on how many other templates
  happened to include this one — each include context is its own method clone re-traversing the
  same statements, and the pairing cursor kept advancing across those re-traversals. It is
  restarted per traversal now, so the answer is a pure function of the template's own bytes.)

A declaration is additionally matched only inside the **compiled method it was written in**. Latte
compiles the template body to `main()` and every named `{block}`/`{define}`/`{snippet}`/
`{snippetArea}` body to its own method, so a whole block written on a declaration's own line puts a
second assignment to the same variable on the same line in a *different* method — and a `{varType}`
at a block body's own top level is that block's input contract, never a placement of its own. Before
this was pinned, `{varType int $x}{var $x = 1}{block foo}{varType string $x}{var $x = 'a'}{/block}`
on one line reported `{varType} for $x with type int is not subtype of native type 'a'.` — the
block's assignment matched the main body's declaration. Known limitations of the boundary test:

- It distinguishes the main body from a block, not one block from another, so two *different* blocks
  on one source line, each declaring and assigning the same variable name, can still cross-match.
- A `{varType}` inside an `n:block`/`n:define`/`n:snippet` element, or inside a dynamically named
  `{snippet}`, is attributed to the wrong side of the boundary and is therefore not checked at all.
  Both degrade to silence, never to a wrong answer.

## Cross-file model — includes, layout, import, embed

Every included/extended/imported file is analyzed once per **distinct incoming context** it is
reached with (includer's effective scope ∪ explicit args), always trait-flattened — never a single
generic "included from somewhere" shape. Contexts dedupe by canonical hash, so a partial included
identically from several places is analyzed once. Each distinct context becomes one method clone
in the *included file's own* compiled class, so every error attributes to that file's own `.latte`
lines, never to the includer.

What context each construct passes to its target (verified against real Latte 2.11 runtime
behavior by paired execute-and-compare tests):

| Construct | Context provided to target |
|---|---|
| `{include file}` | includer's template params + explicit args (not includer locals) |
| `{include block}` (same-file block, no params) | caller's locals |
| `{include block}` (imported block) | importer's template params only — never the caller's locals, even though same-file blocks get them |
| `{include block}` (block with params) | declared params from the call's args only |
| `{extends}` / `{layout}` | layout is analyzed per extender, with the child's finished main scope |
| block override body | owning template's scope merged with the render site's scope |
| `{import file}` | importer's params; imported blocks join the importer's block table |
| `{embed}` | embed-site locals; body blocks override the embedded template's blocks |
| `{sandbox file}` | explicit args only |

**Declarations are a checked contract.** A file's own `{templateType}`/`{varType}`/`{parameters}`
declarations are optional, but if present, every edge into that file is checked against them.
Contract errors report only definite violations: a mismatch must hold across *all* contexts that
reach the edge — if even one reachable context satisfies the declaration, the edge stays quiet
(OPEN discipline, no speculative errors):

- a provided type not assignable to the declared type → `orisai.nette.latte.includeTypeMismatch` at the include
  site;
- a declared, defaultless variable not provided by the edge → `orisai.nette.latte.includeMissingVariable` at
  the include site;
- inside the file, the declared types govern the body, so a fully-declared partial analyzes
  identically from every compatible caller (one instantiation, not one per caller).

Static include/extends targets are resolved relative to the referring template's directory. A
target that doesn't exist on disk → `orisai.nette.latte.unknownInclude`. A target only known at runtime
(`{include $x->getTemplate()}`, `{extends $originalTemplate}`) → `orisai.nette.latte.dynamicInclude` /
`orisai.nette.latte.dynamicExtends`; the site's own argument expressions are still analyzed, the target itself
is not followed. `{include #block}` against a block absent from every reachable block table →
`orisai.nette.latte.unknownBlock` (with the suppression carve-outs below); `{ifset #block}`/`{elseifset #block}`
are guards and never error. Include chains that cycle are cut with `orisai.nette.latte.includeCycle` at the
closing edge.

### Include isolation (`orisai.nette.latte.includeIsolation`)

Latte 2.11.7 compiles a file include to `$this->createTemplate($file, %node.array? + $this->params,
$mode)`: the target's scope is the includer's *entire* param set unioned with the site's explicit
args (explicit wins on key collision), so an included file's environment is always a superset of its
includer's. The flag was designed on the assumption that Latte 3 isolates include params to the
explicit args. It does not: a runtime probe (render param `a`, top-level `{var $b}`, then
`{include file 'inc'}` and `{embed file 'emb'}{/embed}` reading both) gives the same result on
2.11.7, 3.0.26 and 3.1.6 — the include sees `a` but not `b`, the embed sees neither — and
`IncludeSemanticsParityTest` passes unchanged on the Latte 3 profiles. `{layout}`/`{extends}` inheritance is a
different mechanism (`$this->params = $this->main()`, the child's finished scope flows into the
layout) and is separate from it; so is a block dispatch, which keeps receiving the
surrounding scope.

Both directions of that asymmetry are pinned as runtime probes against the real engine
(`tests/Integration/Latte/Parity/Includes/IncludeSemanticsParityTest.php`), which run on every
Latte line. The flag therefore stays off by default on every line; it models a stricter scope than
any supported Latte runs.

`orisai.nette.latte.includeIsolation` (bool, default `false`) switches `EdgeScope::resolve()`'s file-form
include/embed branch to the isolated shape — the one seam every consumer already routes through.
Turning it on reports, through the existing `orisai.nette.latte.includeMissingVariable` machinery, every edge
whose target **declares** a variable that today arrives only by inheritance — the declared-variable
slice of what fully isolated includes would miss. The undeclared remainder is invisible to this
identifier and surfaces as `variable.undefined` inside the target instead (see the table below). It
is a measurement tool, not a correctness gate — run it locally, read the findings, and do **not**
baseline them:

```neon
# a throwaway config next to the project's phpstan.neon
includes:
	- phpstan.neon

parameters:
	orisai:
		nette:
			latte:
				includeIsolation: true
```

The flag reports only through `orisai.nette.latte.includeMissingVariable`, which fires only when an
include *target* declares a variable the edge fails to satisfy. A project whose include targets
declare nothing therefore gets zero findings from it — and that is **not** evidence that isolating
includes is free. The flag's worklist grows exactly as `{varType}`/`{parameters}` coverage on include targets
grows, so re-run it after each declaration wave.

**The whole isolation worklist is visible today, under a different identifier.** With no declaration
on a target, an inherited variable never crossed the edge in the model either, so it is already
reported as `variable.undefined` (or `isset.variable`) *inside* the target. Intersecting the
file-form include edges with those findings enumerates the whole migration surface: every include
site whose target reads a variable that arrives only by inheritance needs an explicit argument, or
its target a `{default}`, before includes are isolated. `$presenter` is not exempt:
`Nette\Bridges\ApplicationLatte\TemplateFactory` sets it as a top-level template *parameter*
(`'presenter' => $presenter`), not as a Latte provider, so it stops crossing an isolated include
edge like any other variable — a partial reading it either takes `presenter: $presenter` at every
site or switches to the bridge's own `isLinkCurrent()` function.

Counting only *quoted* include literals understates this: Latte 2 accepts a
bare path as a file target (`{include parts/automatic-no-replay.latte}`), and
`TemplateFactExtractor::extractIncludeTarget()` classifies an unmodified target `KIND_STATIC_FILE`
whenever it fails `~^[\w-]+$~D` (a bare word is a *block* name, `parts/x.latte` is a file).

One deliberate divergence the flag exposes: `{embed file}` is isolated on every line (Latte 2's
`BlockMacros::macroEmbed` emits no `+ $this->params` term at all, like `{sandbox}`), but the
analysis models it as include-like union. That over-approximates the embed target's scope, which
can only *miss* findings, never invent them; with `orisai.nette.latte.includeIsolation` on, the embed edge
matches the runtime exactly. The default stays a union because `{embed}` is rare; correcting the default matters only for a
project that uses `{embed}`.

### `orisai.nette.latte.unknownBlock` suppression

Phase 1 only sees explicit Latte-tag edges (`{include}`, `{extends}`, `{import}`, ...); it has no
model of Nette's *convention*-based wiring — presenter auto-layout discovery, `setFile()`,
component render callbacks — which attach files to each other invisibly. Reporting
`orisai.nette.latte.unknownBlock` against a file phase 1 cannot prove is reachable-or-not would be a false
positive, so two categories are exempt (OPEN, never false-close):

1. **Files with zero incoming edges** — nothing in the graph points at them, so an
   `{include #block}` inside could still be satisfied by a convention-wired extender the model
   can't see. *Template-file discovery* below is what shrank this category: a store-linked
   template has a real incoming discovery edge and is no longer exempt, so its block checks are
   live; only files no renderer links and no tag targets stay open.
2. **Files whose basename starts with `@`** — Nette's auto-layout convention attaches extenders to
   `@`-named templates (e.g. `@layout.latte`) by convention even when they *do* have incoming
   edges from something else, so incoming-edge count alone isn't enough to prove them standalone.
   Deliberately **kept** after discovery landed: discovery links only part of a real project's
   `.latte` files, so a layout's extender set is provably incomplete and the exemption still earns
   its keep.

## Call-site narrowing

Cross-file analysis (above) types every include/block edge from the target's own *declarations*
— a variable the target doesn't declare falls back to the includer's declared-wide type, and a
compound argument expression falls back to `mixed`. Call-site narrowing feeds the type PHPStan
has actually *proven at the call site* into the target's analyzed body instead, for exactly the
variables the target leaves undeclared:

```latte
{* includer *}
{if $user !== null}
	{include '_card.latte', user: $user, total: $order->getTotal()}
{/if}
```

If `_card.latte` doesn't declare `$user`, its body analyzes with `$user: User` (narrowed from
`User|null` by the surrounding `{if}`) instead of the wide declared type; `total` — a compound
expression, not a bare variable — analyzes with `$order->getTotal()`'s real inferred return type
instead of today's `mixed`.

**Per-variable declaration priority.** A target-declared variable — anything covered by its own
`{templateType}`, `{varType}`, `{parameters}`, or a typed block parameter — never takes a
captured type; only variables the target leaves undeclared do. `{parameters}` stays a closed
signature: narrowing never adds a variable to it, it only sharpens the *type* of a parameter
already in the signature using the call's real argument type instead of a generic classification.

### Opt-in flag

Narrowing is gated by `orisai.nette.latte.narrowing.enabled`, which requires `orisai.nette.latte.enabled`
(`ConfigurationGuard` rejects the flag on its own). The library default (`config/latte.neon`) ships it
**off**: off reproduces pre-narrowing behavior exactly (no anchors emitted, no collector captures, no
store reads or writes), because narrowing depends on a committed store (below) that a consumer
hasn't necessarily generated — a consumer that only turns on `.latte` analysis should get the safe,
declaration-only behavior, not an error or a crash from a store that was never produced.

```neon
parameters:
	orisai:
		nette:
			latte:
				enabled: true
				narrowing:
					enabled: true
					storePath: %currentWorkingDirectory%/phpstan-latte-store
```

### The committed store

Captured types live in a committed, generated slice directory at
`orisai.nette.latte.narrowing.storePath` — one PHP file per includer. It is versioned like a baseline
file: regenerated by normal analysis runs, committed alongside the change that moved it, conflicts
resolved by regeneration rather than merged by hand. The consumer-side lifecycle (create the
directory, run until `git status` is clean, fail CI on a dirty store) is in the user guide's
[narrowing store lifecycle](../README.md#narrowing-store-lifecycle). The analysis never creates the
directory itself: `LatteSiteScopeWriterRule` writes into an existing directory only, so a missing directory
means no captures, never an error.

### Where captures are taken

An edge anchor is a statement `EdgeAnchorInjector` splices right before the include's own
statement, in the `latteMain_ctx{i}` clone of the context it records. A file include inside a
`{block}`/`{define}`/`{snippet}` body has that statement in the block's own method, so the anchor
goes there — one per context, since every clone visits the shared block method. Block params are
the union of the contexts' types (`mixed` on disagreement), so such an anchor is emitted only when
every context declares the same type for each name it would record (the manifest, or every
provided name once explicit args are captured); otherwise the edge's per-context type already
beats anything the block scope could prove and the anchor is skipped — a missed capture, the safe
direction.

### One-run capture lag and convergence

A capture is born during analysis (a PHPStan Collector records the type proven at the edge) and
can only feed the *next* run's parse — nothing engine-level removes this lag. Editing a template
needs one further warm run to pick up its own new captures. For an include chain of
depth D, propagation is bounded by D+1 runs — an upper bound, and in practice often fewer:
context resolution re-walks the whole include graph fresh each run, so narrowing that doesn't
depend on a previous hop's *captures* (only on its declarations) can travel several hops at
once; `NarrowingConvergenceTest` pins the bound on the fixture corpus. Runs stay
incremental throughout: a changed slice file's content hash is what triggers PHPStan's own
restore-gate to re-parse exactly its dependents, never a wholesale cache clear.

### Degradation direction

A missing store directory, a missing slice, or an entry whose `includerContentSha` no longer
matches the includer's current content is dropped for that variable — falling back to today's
declaration-derived (wider) type, never an error, never a crash.

Degradation is **not** one-directional, and an earlier version of this section wrongly said it was.
Widening to a narrower-but-still-typed value can ADD errors. Widening all the way to `mixed` HIDES
them: below level 9, mixed-type operations are not reported. So a lost capture
costs both directions — some findings appear, an unknown number are silently suppressed — which is
why the narrowing store is load-bearing rather than a pure optimisation.

## Access to runtime internals (`orisai.nette.latte.internalAccess`)

A compiled template's `$this` is Latte's generated `LatteTpl_*` runtime class, not your
presenter/control. Phase 1 doesn't model that boundary (phase 3 does), so `orisai.nette.latte.internalAccess`
flags every `$this->member` (method call or property fetch) that a template's own source literally
writes:

```latte
{if $this->getReferringTemplate()}
{$this->global->foo}
```

It does **not** fire for the `$this->...` calls the compiler itself generates for `{block}`,
`{define}`, `{include #x}`, `{snippet}`/`{snippetArea}`, or the standard
`UIRuntime::initialize()` prologue — those are recognized as compiler scaffolding by checking
whether the compiled node's template-mapped line + member name is literally present as `$this->
member` text in the template's own raw source, not by an allowlist of member names. Scaffolding
never appears as that literal text in the template source (it's synthesized by the compiler), so
it never matches and never warns.

The error is an ordinary ignorable/baselinable finding: every deliberate use of a Latte runtime
internal from template code is meant to surface once, get a conscious ignore/baseline entry (or
get removed), and any *new* use warns immediately instead of blending into normal template code.

The scaffolding itself is ignored by `config/latte.neon`, every entry `reportUnmatched: false`, so
a project on either line runs with PHPStan's default `reportUnmatchedIgnoredErrors` and never sees
an unmatched-ignore error: Latte 2's `UIRuntime::initialize()` prologue (`method.internal`) and its
private `Template::$blocks` read. Latte 3 needs no entry of its own: its `Blocks`/`Source`/
`ContentType` template-class constants override the vendor `Template`'s, which shipmonk's vendor
usage provider counts as used, and 3.1's `$parentArgs` is declared on `Template`.
`RuntimeInternalsIgnoreSpawnTest` spawns each line's corpus with unmatched reporting on, with and
without the entries.

A spawned PHPStan runs from the library's own vendor directory, so it takes the library root as the
Composer project and reflects vendor classes through the composer file `COMPOSER` names. `ScratchProject`
sets `COMPOSER=composer.<profile>.json` for the spawn (`VendorDirectory::composerFile()`); without it the
spawn reflects vendor classes from the default `vendor/` — Latte 2.11 while Latte 3 runs — which is
where earlier Latte 3 ignore entries for those constants and `$parentArgs` came from
(`ScratchProjectVendorTest` pins it).

## Custom filters, functions and macros

Beyond the five built-in macro sets and Latte's stock filters/functions, an application registers
its own — a translation macro family (e.g. `h4kuna/gettext`), template-specific helpers, and
imperative `addFilter()` calls. Two channels are modeled; a third is deliberately deferred:

- **Engine harvest** — construction-time registrations discovered by instantiating the application's
  real Latte engine and enumerating it (below).
- **Per-template customs** — Latte's own `{templateType C}` + `processParams()` mechanism, where
  `C`'s own tagged/attributed methods become filters/functions (below).
- **Imperative render-time registration** (`$template->addFilter(...)`,
  `$compiler->addFilter(...)` called from presenter/control code) stays out of scope — see *Known
  limitations*. All such names still analyse (reported as `orisai.nette.latte.unknownFilter`), just without
  real-signature checking.

### Engine harvest (global customs)

`CustomsHarvester` resolves a source in this order, degrading to an **empty harvest** (built-ins
only — today's behavior) whenever a step is unavailable, never erroring:

1. `orisai.nette.dic.containerLoader` (the loader the DI extension uses) — load the compiled container,
   `getByType(Nette\Bridges\ApplicationLatte\ILatteFactory::class)`, `create()`. `EngineSource` reads
   the same `orisai.nette.dic.containerLoader` option, so one loader serves both extensions.
2. `orisai.nette.latte.engineLoader` (optional, default `null`) — a PHP file path returning a bare
   `Latte\Engine` directly, for consumers without `nette/application` (mirrors PHPStan's own
   `symfony.consoleApplicationLoader` shape):
   ```neon
   parameters:
   	orisai:
   		nette:
   			latte:
   				engineLoader: %currentWorkingDirectory%/build/latte-engine-loader.php
   ```
3. Neither configured, or every step throws/returns the wrong shape → `HarvestedCustoms::empty()`.
   The extension stays correct with zero config either way.

Enumeration reads real vendor state, never re-implements it: filter names from
`Engine::getFilters()` with real callables resolved through `FilterExecutor`; functions via
reflection over `Engine`'s private function table; macros by running the engine's own `onCompile`
hooks against a throwaway `Compiler` and reading back `Compiler::getMacros()`. `addFilterLoader`
loaders cannot be enumerated; they are asked per name instead (*Loader-provided filters* below).

The harvest runs once per PHPStan process and is memoized; two harvests of the same state are
byte-identical (proven by a dedicated determinism test), which is what makes the salt in
*Invalidation* below meaningful.

### Latte 3 extension harvest

On Latte 3 the same two sources yield an engine whose customs live on its extensions.
`Latte3EngineReader` reads `Engine::getExtensions()` in registration order, the tag names from each
extension's `getTags()` keys (a later extension wins a name, as in `TemplateParser::addTags()`),
the static `getFilters()`/`getFunctions()`/`getProviders()` entries and the engine's feature flags
(read from `Engine`'s private `$features` as stored: 3.0 keys by the `Feature` constant's value,
3.1 by the enum case name). `HarvestedCustoms::fromExtensions()` carries them: the tag map reuses
the macro-name slot (`getMacroNames()`), and every generator tag parser also contributes the
`n:name`, `n:inner-name` and `n:tag-name` attributes Latte derives from it, recorded explicitly;
`getExtensions()`, `getFeatures()` and `getProviderTypes()` are new and empty for a Latte 2
harvest, whose salt is therefore unchanged. Filter loaders are invisible to `Engine::getFilters()`
and asked per name (*Loader-provided filters* below); Latte 3 has no function loaders, so functions
come from `getFunctions()` only.

nette/application adds two extensions only at render time: `TemplateFactory` adds
`UIExtension($control)` when `LatteFactory::create()` ran without a control (application 3.2 —
the harvest calls `create()` without one), and `Template::setTranslator()` adds
`TranslatorExtension`. The reader appends `UIExtension(null)` and `TranslatorExtension(null)` after
the project's own extensions when the engine lacks them, so `{link}`, `{control}` or `{_}` never
read as unknown tags.

The compile then runs over the harvest instead of the fixed extension set: a fresh `Engine` (its
own `CoreExtension` and `SandboxExtension` stand for the project engine's), the harvested
extensions in the project's order, functions the project added directly with `addFunction()` (so
CoreExtension's `customFunctions` pass compiles their calls to `$this->global->fn->name(...)` like
the project's engine does), the harvested features copied as stored — strict types, strict parsing
and the rest follow the project's engine — and `AnalysisExtension` last. 3.1's
`Feature::ScopedLoopVariables` wraps every `{foreach}`/`n:foreach` in a
`try { $ʟ_fe_N = get_defined_vars(); unset(VARS); LOOP } finally { restore }` shell;
`ControlFlowEliminator` reduces it to the loop, so the analysis keeps the unscoped semantics (a
loop variable stays visible after the loop) — an approximation of the scoped runtime that never
reports on the shell itself. With nothing harvested
the fixed set stays: `UIExtension(null)`, `FormsExtension`, `CacheExtension` when nette/caching is
installed, `TranslatorExtension(null)`, strict types off on both lines. The filter/function tables
keep the fixed set's callables as the stock entries; harvested ones join as `HarvestedCustoms`,
and from an extension harvest an instance-bound `[$object, 'method']` array or a named-method
closure (`$this->method(...)`) resolves to that method with an instance dispatch, a plain-function
closure (`trim(...)`) to the function (Latte 2 harvests keep the static-only resolution).
`FunctionExecutor` hands the template only to a function whose first parameter's type prints
exactly as `Latte\Runtime\Template` (a nullable `?Template` does not count); such a harvested
function keeps the compiled call's leading `$this` (`FunctionTable::receivesTemplate()`), every
other one has it dropped.

`isLinkCurrent()`/`isModuleCurrent()` are registered by `UIExtension` only with a presenter, which
no harvest has; the Latte 3 function table therefore always carries them as typed stand-ins
(`Helpers::presenterIsLinkCurrent()`/`presenterIsModuleCurrent()`, the `Component`/`Presenter`
signatures).

### Loader-provided filters

A filter name that neither the stock table, the harvest nor the template's `{templateType}` knows
goes to the harvested engine's filter loaders, the way the runtime asks them on the name's first
call. The engine reader hands `HarvestedCustoms` a `FilterLoaderProbe` (none when the engine has no
loaders, so without an engine loader this is a no-op); `FilterTable::resolveForTemplate()` asks it
on a miss and reflects the answered callable exactly like a harvested extension callable
(`resolveDefaultTarget()`: static, instance-bound or named-method; an anonymous closure is known but
untyped, `Helpers::untypedFilter()`), and a decline stays `orisai.nette.latte.unknownFilter`. Latte 3 asks
`FilterExecutor::__get()`, which consults the loaders in registration order (newest first) and
throws `LogicException` on a decline; the answered callable is read back from the executor's
`_static` because `__get()` wraps a FilterInfo-aware one. Latte 2's `__get()` only returns a lazy
closure that would call the filter itself, so the Latte 2 reader calls the loaders
`Engine::addFilterLoader()` wrapped (the `callback` of each wrapper closure in `_dynamic`, same
order); a bare `addFilter(null, ...)` dynamic filter computes the filtered value itself, is no
loader and is ignored. Case: both lines ask with the name as written, as the runtime does (a
filter name must start lowercase on Latte 3 or it parses as a constant). Latte 3 is case-sensitive
and stops there. Latte 2 files a loaded filter under its lowercase name, so a spelling the loaders
decline is asked once more, in lowercase only (`FilterLoaderProbe`'s `$lowercaseFallback`): at
runtime that spelling works only once another spelling has loaded the filter (and throws while
none has), an order the analysis cannot know, so it types it. The fallback is one-way — no other
spelling is tried: when the loaders answer only `formatDyn`, a written `{$s|formatdyn}` stays
`orisai.nette.latte.unknownFilter`, although at runtime it works once `{$s|formatDyn}` has loaded the
filter. Answers are memoised per written name for the process.

Limitation (Latte 2): a loader filter used only as a block filter (`{block|name}`) goes through
`filterContent()`, which on Latte 2 never asks the loaders, so it works at runtime only when an
earlier `{$x|name}` loaded it; the analysis types it either way.

The answers are part of the harvest salt: `CustomsHarvester` scans the analysed templates
(`LatteUniverse`) for every identifier after a `|` (`FilterNameScan`, a superset of the filter
names), asks the probe for those the static filters lack, and `HarvestedCustoms::withLoaderFilters()`
adds a `loaderFilter` line per answered name with the callable's descriptor and its declaring file's
`sha1_file()` (a closure's file and lines). A loader that starts or stops answering a name the
templates use therefore changes both the result-cache meta and the compile-cache key; the same
memoised probe serves the analysis, so the salt and the findings agree. When the scan fails (a
template outside the project root makes `LatteUniverse::files()` throw) the loaders are dropped
instead - their filters report unknown rather than risk a stale cache - and `dumpLatteCustoms()`
shows a `harvest note:` saying so. `dumpLatteCustoms()` lists
those names under `loader filters:` when the engine has loaders.

### Typed filters and functions

Harvested filter/function names join the existing filter-rewrite table with **real, reflected
signatures** — a call through a harvested name checks its arguments against the actual PHP
callable, exactly like a built-in. `orisai.nette.latte.unknownFilter` (and PHPStan's native `function.notFound`
for functions) stop firing for harvested names; an unrecognized name still reports as before. (Tag
*name* collisions — a harvested macro sharing a built-in's tag name — are a separate, macro-level
concern; see *Native macros* below.)

### Case-mismatch diagnostics (`orisai.nette.latte.filterCaseMismatch` / `orisai.nette.latte.functionCaseMismatch`)

Latte 2.11 resolves filter and function names **case-insensitively** —
`{$x|Upper}` and `{$x|upper}` are the same call. Latte 3 made resolution **case-sensitive**: a
spelling that doesn't exactly match the registered name throws a `LogicException` at render time,
invisible to static analysis without a purpose-built check. A dedicated token scan compares every
called spelling against the registered one (built-ins and harvested names alike) and reports when
they differ:

```latte
{$x|Webalize}     {* registered as webalize — flagged *}
{MyHelper($x)}    {* registered as myHelper — flagged *}
```

> Latte filter 'Webalize' differs in case from the registered 'webalize' - Latte 2.x resolves this
> case-insensitively, but Latte 3 makes filter resolution case-sensitive and will break on upgrade.

The scan runs on Latte 2 only (`LatteCompiler`). On Latte 3 the filter and function tables are
case-sensitive (`FilterTable`/`FunctionTable` built with `$caseSensitive`): next to each entry they
keep every registered spelling — the stock names, the bridge entries, the harvested names — and a
`{templateType}` entry matches its method name only; a loader is asked with the name as written
anyway. A call matches a registered spelling only. A spelling that differs in case alone
(`{$s|upPer}`, `{=cLamp(1, 2, 3)}` on 3.1) is reported by `FilterRewriter` with the same identifiers,
naming the registered spelling, and typed through the `Helpers::unknownFilter()` stand-in like an
unknown name:

> Latte filter 'upPer' differs in case from the registered 'upper' - Latte 3 resolves filter names
> case-sensitively.

Latte 3.0 still resolves a function spelled in another case in its `customFunctionsPass`, with a
compile warning that carries no line, and compiles the registered spelling: `Latte3Compiler` turns the
warning into `orisai.nette.latte.functionCaseMismatch` ("… - Latte 3.0 resolves it with a warning,
Latte 3.1 does not resolve it.") on the call's line, read from the node tree before the pass, and the
call keeps the registered function's signature. Probed on 3.0.26 and 3.1.6: a mis-cased filter throws
`LogicException` at render time on both, a mis-cased function is an undefined PHP function on 3.1. A
mis-cased tag (`{IF}…{/IF}`) is `orisai.nette.latte.unknownMacro`; a name after `|` which starts
uppercase (`{$s|Upper}`) is no filter call on Latte 3 (see *Loader-provided filters* above).

Latte's own `Defaults` deliberately double-registers some names under two valid spellings
(`dataStream`/`datastream`, `stripTags`/`striptags`, `breakLines`/`breaklines`,
`replaceRe`/`replaceRE`) — the scanner drops any lowercase key with more than one distinct
registered spelling rather than guessing, so it never false-positives against either equally-valid
form. The scan reads only `Latte\Token::MACRO_TAG` text — a filter/function name written exclusively
inside an `n:attribute` expression (`n:if="$x|upper"`-style) is not scanned; see *Known
limitations*.

### Native macros

Harvested macro sets install into the compiler alongside the five built-ins, deduplicated by class
— the five built-in classes themselves are protected, so a harvested set never reinstalls or
shadows any of them. Everything else — e.g. `h4kuna\Gettext\Macros\Gettext` — compiles through its
**real** macro code instead of falling through to the unknown-macro passthrough retry:

```latte
{_'Hello'}       {* compiles to a real gettext('Hello') call, not a Core-translate passthrough *}
{ng_ 'one item', 'many items', $count}
```

This matches runtime exactly: an application registering `Gettext` after `CoreMacros` on the same
compiler (from an `onCompile` hook) already has Latte's own name-collision handling shadow
`CoreMacros`' `_` (translate) with `Gettext`'s `_` (raw `gettext()`) — the harvest reproduces that
shadowing via Latte's own mechanism, not a special case.

A consequence, by design: the real compiled calls are analysed. A project that forbids
`gettext()` (e.g. with a function-call denial rule) sees one finding per translated string in
every template using the macro family — findings the passthrough shape never produced. They are
true positives; baseline them as a migration backlog rather than ignoring the identifier.

### Vendor error containment and `orisai.nette.latte.deprecated`

Vendor Latte code calls `trigger_error()` at 30 sites total, 22 of them reachable from a
compile-only invocation (deprecations, its own dormant case-mismatch checks, …). Left alone these
leak as raw PHP warning text on stdout
during cold/invalidated-cache runs, because PHPStan's result-cache machinery
(`ResultCacheManager`/`ExportedNodeFetcher`) invokes the routing parser outside its own
error-collecting handler. A scoped `set_error_handler` — installed and restored per call, never
chained to any ambient handler — wraps every vendor invocation this extension makes: template
compilation, engine construction/enumeration during harvest, and harvested macro-set installation.

`E_USER_DEPRECATED` captured during compilation becomes an ordinary `orisai.nette.latte.deprecated` finding at
the compiler's current line (falling back to line 1 when no position is available); every other
captured severity is silently dropped — vendor-internal noise, not a report. The one deprecation
class plausible in ordinary templates is `n:ifcontent` on a void/self-closing element.

### Provenance tips

An error on macro- or filter-generated code reports at the `.latte` line, but that line shows the
*tag*, not the generated call the error is actually about. A tip names the origin:

- **Filters** — node-precise (the rewrite step tags the exact node it builds):
  `Generated by the |webalize filter.`
- **Macros** — line-precise with column, from the fact extractor's own tokenization (pairing an
  error to one specific macro on a multi-macro line isn't possible — emissions only carry a line
  marker, not a per-macro one — so every macro on the line is listed, honestly, rather than
  guessed):
  - one macro on the line: `Generated by the {_} macro at column 5.`
  - several: `Generated by a macro on this line: {_} at column 3, {ng_} at column 20.`
- **Functions** — never tipped; the developer wrote the call directly.
- **`orisai.nette.latte.debugDump`** findings (below) are never tipped either, for the same reason — a debug
  dump is a diagnostic the developer wrote directly, not generated code.

A provenance tip **combines with**, never replaces, a tip the underlying rule already set (PHPStan
renders each as its own 💡 line). Tips are baseline/ignore-**invisible** — matching is message and
path only — so adding or wording a tip never ripples the baseline.

### Per-template customs (`{templateType}`'s own filters and functions)

Latte 2.11's `Engine::processParams()` is modeled faithfully, not invented: when an object (rather
than a plain array) is passed to `render()`, its own public methods tagged `@filter`/`@function` in
the docblock — or, only when PHP ≥ 8.0, attributed `#[Latte\Attributes\TemplateFilter]`/
`#[TemplateFunction]` — register as filters/functions for that render. A template declaring
`{templateType C}` gets `C`'s qualifying methods as per-template customs, typed with the method's
own real signature:

```php
final class MyTemplate
{
	/** @filter */
	public function myTplFilter(int $a): string { ... }

	/** @function */
	public function myTplFunction(int $a): string { ... }
}
```

```latte
{templateType MyTemplate}
{$value|myTplFilter}
{myTplFunction($value)}
```

Qualification mirrors the vendor condition exactly, verified against the real engine, not assumed:
`getMethods(ReflectionMethod::IS_PUBLIC)` — a **public static** method qualifies too — and the tag
check is a bare substring search (a docblock merely containing `@filterish` counts, same as
vendor's `strpos`). The attribute channel is honored only when PHPStan's configured `phpVersion` is
`80000` or above — below that only the docblock form is live, matching what actually runs at
render time on PHP 7.

**Scoping — a deliberate, strict divergence from runtime.** At real runtime,
`Engine::processParams()` mutates the *shared* engine: after a template first renders, its
`{templateType}`-declared filters/functions can silently leak into every *other* template rendered
afterward, in an order that depends on which template happens to render first — the same
order-dependence landmine as the imperative `addFilter()` sites. This pipeline never models that
leak. A per-template custom is visible only to templates whose own `{templateType}` is the exact
declaring class (and that file's include-context clones) — an unrelated template calling the same
name still reports `orisai.nette.latte.unknownFilter`/"function not found", even though the real runtime might
have accidentally made it work depending on render order. This is the documented recommendation
for new code: never rely on the runtime leak, declare `{templateType}` on every template that needs
a custom.

**Latte 3.** The mechanism stays: Latte 3.0 keeps `Engine::processParams()` (the docblock tags
raise a deprecation notice but still register, attributes are always read), 3.1 moves it to
`Helpers::resolveParams()`/`inspectParamsClass()`, which reads the attributes only.
`TemplateTypeCustoms` models the installed line; `ProcessParamsQualificationParityTest` pins the
model against the real registration on every line.

**Invalidation.** Every `.latte` file gets a direct dependency edge to every `{templateType}` class
reachable *anywhere* in its own transitive include graph (`{include}`/`{layout}`/`{extends}`/
`{embed}`/`{import}`, cycle-safe) — not only a class the file declares itself. PHPStan's own
dependency propagation is single-hop from a file whose content genuinely changed, so without this
direct edge an includer three hops from the declaring class would stay stale after a docblock-tag
edit; the direct edge makes a signature/docblock change on `C` reach every consumer at any include
depth, in one run.

### `dumpLatteCustoms()` — debug dump helper

Joins the existing `dumpLatteIncluders()`/`dumpLatteVarOrigin()` debug family
(`src/Latte/Testing/`) — a no-op function whose bare `{do ...()}` call is intercepted by a
rule and turned into a non-ignorable, always-reported finding (never baselined — it's a
developer-invoked dump, not a real error):

```latte
{do dumpLatteCustoms()}
```

prints the harvested **global** filters/functions/macros (orig-case spelling appended where it
differs from the lowercase key, e.g. `escapecss (escapeCss)`; a name that's already all-lowercase,
like `webalize`, renders bare) or the explicit
`no harvest source configured (orisai.nette.dic.containerLoader / orisai.nette.latte.engineLoader)` state, followed by the
**current template's** own `{templateType}`-declared filter/function entries with their declaring
class — or `(none)` for each when there's nothing to show.

### Invalidation (harvest salt)

The global harvest feeds analysis facts, so it participates in both cache contracts that read the
compiled/analysed result back. `HarvestedCustoms::getSaltHash()` folds filters, functions and
macros into one hash:

- Filters/functions salt by name plus a callable identity descriptor (`Class::method`, or a
  closure's `file:startLine-endLine`).
- Macros salt by name plus the *providing class* plus a content hash (`sha1_file`) of that class's
  declaring file — not the tag name alone. Name-only salting would miss a same-name macro-set CODE
  change (a class swap, an edited `install()`/`nodeOpened()` body): `LatteCodeVersion` (the
  extension's own files-plus-composer-versions digest) only tracks first-party/`latte`/`nette`
  packages, never third-party macro providers (e.g. `h4kuna/gettext`), so nothing
  else would ever notice. The file-content-hash approach is deliberately structural rather than a
  composer-slice addition: it covers *any* provider package without maintaining a list, and it
  reacts to the thing that actually matters (the generated code changed), not a proxy for it (the
  package version bumped).

Two independent caches consume this salt:

- **PHPStan's own result cache** — `LatteResultCacheMeta` folds `sha1(canonical HarvestedCustoms)`
  into the existing edge-topology hash — any harvest change (a config edit, a factory change, a
  `latte/latte`/`nette/application` update that changes what gets enumerated) invalidates the whole
  `.latte` surface at once, the same coarse trade the topology salt already makes.
- **`LatteCompiler`'s own compile cache** (`LatteAnalysisCache`, keyed under `LatteCodeVersion`) —
  the cached `CompileResult` bakes in harvest-dependent shapes (native macros vs. passthrough,
  `orisai.nette.latte.unknownMacro`, case-mismatch diagnostics), so `compile()`'s content-addressed key is
  `sha1($source) . '|' . $className . '|' . $harvestSalt`, not just source+class name. Without the
  salt in this key, a harvest change would invalidate PHPStan's result cache (forcing
  re-analysis) but the re-analysis would still read the STALE compiled PHP back out of this cache,
  silently keeping the old harvest's shape indefinitely — exactly the failure mode an application
  hits when it deregisters a macro extension.

A Latte 3 harvest adds one line per extension, one per feature flag and one per provider. An
extension's generated code lives in its node classes as much as in the extension, so its line
identifies the code, not the class: an extension declared inside an installed Composer package
(`ProjectInstalledVersions::packageContaining()`, the root package excluded) by that package's name,
version and reference; a first-party extension by the relative path and `sha1_file()` of every
`*.php` file under its class's directory, recursively — so an edited node class beside or below the
extension changes the salt even when no class or tag name does (`ExtensionSourceSalt`, applied by
`CustomsHarvester` through `HarvestedCustoms::withExtensionSources()`). The walk leaves out
PHPStan's `%tmpDir%`, the discovery and narrowing stores (`discovery.storePath`,
`narrowing.storePath`, rewritten by every run), installed package roots, Composer vendor directories
(a `composer/installed.json` inside) and a `composer` directory itself, dot-directories, symlinked
directories and nested projects (a `composer.json` of their own). When the extension's directory is
the project root (`%currentWorkingDirectory%`) or holds the `%tmpDir%`, or the walk passes
`ExtensionSourceSalt::MAX_FILES` (5000) `*.php` files (other files are not counted, so a tree heavy
in non-PHP files is still walked in full), the extension is salted shallowly: only its own
directory's `*.php` files, with a `harvest note:` line in `dumpLatteCustoms()`. Limitation: node
classes of such an extension in subdirectories do not invalidate the analysis — declare extensions
in a directory of their own. An unreadable directory in the walk is salted as `<path> unreadable`
(so a permission flip changes the salt), skipped, and reported once as
`orisai.nette.latte.customsHarvest` on line 1 of the analysed universe's first template (sorted, the
same file on every worker and run; `HarvestProblemReporter`) and as a `harvest problem:` dump line —
the harvest itself is kept. The reported path is relative to `%currentWorkingDirectory%` when it
lies inside it, so the message is stable in a baseline. A run which does not analyse that first
template (a partial run over some paths) carries the dump line but not the finding.

The Latte 3 compile joins `LatteAnalysisCache` under
`Latte3Adapter::compile()` with the key `sha1($source)|$className|$relativePath|$engineSalt|
$discoverySalt|$family|Latte3Adapter`, where `$engineSalt` is the harvest salt for an extension
harvest and, for the fixed set, a constant plus whether nette/caching (its `CacheExtension`) is
installed; the entry holds the `CompileResult` and the resolved facts.

A harvester with nothing configured and no harvester wired at all produce byte-identical salts
(both `HarvestedCustoms::empty()`) — wiring the harvester service by itself never causes spurious
invalidation in either cache. Per-template customs use the separate direct dependency edge
described above, not this salt.

`LatteCodeVersion` digests every `.php` file under the packages listed in
`LatteCodeVersion::SALTED_PACKAGES` (`['Latte', 'LatteForms']`, i.e. `src/Latte/` and `src/LatteForms/`)
plus the installed `phpstan/phpstan`, `latte/latte`, `nette/application`, `nette/forms` and `nette/caching`
(`absent` when not installed; its bridge prints the Latte 3 `{cache}` code) versions.
Every package that writes into `LatteAnalysisCache` must be listed — the bridge's `FormMacroCollector`
does — or its bug fixes never invalidate the entries it produced. A listed directory that does not
exist throws rather than hashing to an empty digest, so a rename of `src/LatteForms/` cannot silently
drop it from the salt.

### `dumpLatteRenderFacts()` — PHP-side render facts dump

The fourth member of the debug family is the introspection window over the PHP-side fact
extraction (`OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk`): what the scope-free walk records about
one class's template usage. The argument must be a `::class` constant:

```latte
{do dumpLatteRenderFacts(\Acme\SomeModule\SomePresenter::class)}
```

Unlike the three template-centric dumps, this one fires from **any analyzed context** — the facts
describe a PHP class, not the hosting template, so the call can sit in the presenter's own PHP
file (via the fully qualified function name) with no scratch template needed.

The dump lists the facts by domain, one line each:

- the resolved **template class** with its provenance channel (`phpdoc`, `createTemplate`, `new`,
  `factoryStatic`, `genericBinding`, `factoryDefault`, `templateFloor`) and certainty,
- **template class candidates** — every observed candidate as `class (channel, certainty)` plus
  its observation lines (`@ 12, 30`), in walk encounter order; the block only appears when at
  least one candidate was observed (the fallback rungs `factoryDefault`/`templateFloor` are
  definitional, never observed candidates),
- **assignments** — `$var: type (certainty) @ file:line` per template variable, every recorded
  site,
- **setFile targets** — kind (`literal` with the resolved path, `convention`, `opaque`) plus
  certainty and site,
- **render sites** — site plus the literal file argument, `(file arg, non-literal)` or
  `(no file arg)`.

An unknown class, a class reflection knows but has no source file for (`class has no analyzable
file` — runtime-defined classes; phpstorm-stubs-backed built-ins carry a stub file and report as
non-qualifying instead) and a class that does not qualify for extraction (no template surface, no
trusted `createTemplate()` call under `orisai.nette.latte.firstPartyPaths`) each report their own explicit text instead
of an empty dump. Facts are read through `PhpFactsCache` — the read-set-validated persistence
layer — so repeated dumps of an unchanged class cost one extraction.

Two parameters feed the walk (`config/latte.neon`): `orisai.nette.latte.firstPartyPaths` (default
`%paths%`) bounds qualification and call-following to first-party code, and
`orisai.nette.latte.templateFactoryContainerLoader` (default `null`; it may point at the same file as
`orisai.nette.dic.containerLoader`) supplies the `factoryDefault` rung of the template-class resolution ladder.

## Template-class pairing

The first PHP-side diagnostics of the bridge: the render facts above record every observed
template-class candidate per class, and a per-run judge (`PairingJudge`) turns them into a
verdict — the resolved primary pairing plus conflict/opaque findings. Verdicts are computed
fresh every run and never persisted: subtype judgments reflect over candidate class
hierarchies — files outside the walk's read-set — so per-run judging means no cache can ever go
stale over them.

### Channels

Class-level channels describe *the* template class of the class:

- **Runtime-authoritative** (creation decides): `convention` — the `::class` return of the
  class's own resolved `getTemplateClass()`/`formatTemplateClass()` hook; `new` — the class's
  own `createTemplate()` override's creation (a direct `return new X` or its trusted factory
  call).
- **Declaration-side**: `phpdoc` — the `@property`/`@property-read` override on `$template`;
  `genericBinding` — the reflection-resolved template surface (a generic `@extends Base<X>`
  argument included). A surface declared inside an installed Composer package is the `templateFloor`,
  not a binding (`VendorPaths`) — unless the package is linked into the vendor directory from outside it (its
  install path resolves elsewhere, a monorepo's path-repository package) and the declaring file lies inside
  `firstPartyPaths`. A first-party path holding the project root (the `%paths%` default) never exempts a package
  installed into the vendor directory.

Per-site channels pair one call site, never the class: `createTemplate` (a manual
`templateFactory->createTemplate($this, X::class)` outside the override) and `factoryStatic`.
A per-site class differing from the primary is a legitimate secondary template, never a
conflict.

The verdict's primary is the runtime-authoritative winner; the declaration side if no runtime
channel was observed; the facts ladder's `factoryDefault`/`templateFloor` rung if neither.

### Agreement rule (subtype-tolerated)

The declaration may widen; creation decides:

- the runtime class equals the declared class, or is a subtype of it: OK — silent;
- the declared class is a strict subtype of what creation produces (declared properties may not
  exist on the real object): conflict;
- no ancestry in either direction: conflict;
- two different classes observed within one class-level channel (e.g. two reachable
  `getTemplateClass()` returns): conflict;
- a class-level channel fed by a dynamic/unresolvable class expression: opaque.

### Pairing diagnostics

`LattePairingRule` fires on the PHP class — unlike everything in the *Error surface* table
below, these are reported on `.php` lines (the offending declaration/creation site), and unlike
the dump family they are ordinary, ignorable, baselinable errors:

- `orisai.nette.latte.pairingConflict` — e.g. `Template class pairing conflict: Acme\UserPanel\DetailTemplate
  (phpdoc, genericBinding) vs Acme\UserPanel\ListTemplate (new).` One message per
  (kind, declared, runtime) pair; every channel that observed a side is named on that side.
- `orisai.nette.latte.pairingOpaque` — e.g. `Template class pairing is opaque in channel convention.` A
  class-level channel whose class expression is not statically resolvable (the message never
  names a class — there is none to name).

Clean default-fallback classes (bare presenters/controls pairing to the factory default or the
`Template` floor) are silent; a strict mode that would require explicit pairing is future
scope. The rule is gated on `orisai.nette.latte.enabled`: with the flag off it is fully dormant — no
findings, no walk or cache cost.

### `dumpLattePairing()` — pairing verdict dump

The fifth member of the debug family renders the judge's verdict for one class. It dumps the
*verdict*, not the raw facts: a convention-only class shows its convention-resolved primary
where `dumpLatteRenderFacts()`'s ladder — which has no convention rung — would floor-resolve
the same class to the `Template` interface.

```latte
{do dumpLattePairing(\Acme\SomeModule\SomeControl::class)}
```

fires from any analyzed context (like `dumpLatteRenderFacts()`; the argument must be a
`::class` constant) and prints:

- `primary:` — the resolved pairing as `class (channel, certainty)`,
- `candidates:` — every observed candidate with channel, certainty and observation lines,
- `site pairings:` — per-site pairings as `class (channel) @ line`,
- `conflicts:` — merged conflict lines naming both classes and every observing channel (the
  same dedup the rule's messages use),
- `opaques:` — opaque channels with their site lines.

A convention hook whose return is not a resolvable `::class` constant surfaces as the literal
`*dynamic*` marker in the primary/candidate slots — never a real class name — with the opaque
line naming the channel. An unknown class reports `class not found`, a class without a source
file `class has no analyzable file`, and a non-qualifying class reports that explicitly —
non-qualifying classes never reach the judge.

## Template-file discovery

The bridge's third stage answers the question phase 1 had to leave open (*`orisai.nette.latte.unknownBlock`
suppression* above): **which `.latte` file does a given renderer render?** Every walked class
resolves a view set, a per-view list of candidate paths, and its layout candidates; the resulting
class→file links publish through a derived store and become real incoming edges in the
cross-file model, which is what lifts the convention-wiring blind spot for every linked template.

The links are **markers only** — a discovery edge carries a `{renderer class, view, kind}` triple
and *no variable payload*. Providing root contexts across the PHP↔Latte boundary (what a presenter
actually assigns to `$template->x`) is deliberately out of scope here.

### Discovery formulas (`orisai.nette.latte.discovery.formulas`)

A template path is a pure function of (class file, presenter/short class name or a resolved
property default, view name) — the formula. Nette's own two are recognized without configuration;
every *override* of
`formatTemplateFiles()`/`formatLayoutTemplateFiles()` and every convention `setFile()` helper
(`getTemplateFilePath()`-style) is app code this extension will not interpret, so it must be
*assigned* a vocabulary name:

| Formula | Derives |
|---|---|
| `vendor-two-candidate` | `<dir>/templates/<Presenter>/<view>.latte`, then `<dir>/templates/<Presenter>.<view>.latte` (the vendor `formatTemplateFiles()`, `<dir>` ascending once when the class's own directory has no `templates/`) |
| `vendor-layout-walk` | the vendor `formatLayoutTemplateFiles()` ascent: `<dir>/templates/<Presenter>/@layout.latte`, `<dir>/templates/<Presenter>.@layout.latte`, then `templates/@layout.latte` at each level upwards, one per module part |
| `samedir-single` | `<dir>/<Presenter>.<view>.latte` |
| `dirname-lcfirst` | `<dir>/<lcfirst class name>.latte` |
| `dirname-templates-lcfirst` | `<dir>/templates/<lcfirst class name>.latte` |
| `dirname-templates-lcfirst-fallback` | the above, plus one fixed `is_file`-gated shared fallback (derived path when it exists, else the shared file, else the derived path anyway) |
| `dirname-property-lcfirst` | `<dir>/<nameProperty default>.latte`; when that default is `null`, `<dir>/<lcfirst directory basename>.latte` — the derived half names the **directory**, not the class, so a class whose directory is named differently takes the directory's name |

Assignment keys are **resolved declaring identities**, not the classes that use them: native
reflection reports a trait-declared method with the *using* class as its declaring class, so the
resolver re-attributes it by file — the key for a trait-declared locator is the **trait's** FQCN.
Recognition looks only at the resolved method; a dead overridden ancestor body never contributes.

```neon
parameters:
	orisai:
		nette:
			latte:
				discovery:
					formulas:
						Acme\Presentation\TemplateLocator: samedir-single
						Acme\Component\BaseControl: dirname-templates-lcfirst
						Acme\Component\BaseGridControl:
							formula: dirname-templates-lcfirst-fallback
							# @@ is the DI-argument escape for a literal leading @ (a single one is read as a
							# service reference) - the resolver receives '@grid.latte'.
							sharedFallback: '@@grid.latte'
						Acme\Component\ContentControl:
							formula: dirname-property-lcfirst
							nameProperty: layout
```

Only `dirname-templates-lcfirst-fallback` takes `sharedFallback` and only
`dirname-property-lcfirst` takes `nameProperty`, which it also *requires*; pairing either key with
any other formula, naming an unknown formula, or applying a formula to the wrong axis (a convention
formula on `formatTemplateFiles()`) is reported as an opaque entry rather than guessed. **An
unassigned override is opaque, never guessed** — one config line is the whole fix, and the
diagnostic names the declaring class to put in it.

`nameProperty` names a property, and the property's default is read off the **entry class, not the
declaring class** — the class being discovered, not the one the assignment is keyed on. A subclass
that inherits the locator method but overrides the property default names its own template, which
reading the declaring class would flatten: every subclass would inherit the base's default and be
linked to a template it never renders. The property's own declaration site is irrelevant; only the
value the entry class resolves to is.

Three shapes of property cannot be resolved to a name, and each records an opaque instead of a
candidate, per the usage-narrowing asymmetry — an unresolvable property yields **no** path, never a
fabricated one:

- the property is not declared on the entry class at all (`name property layout of formula
  dirname-property-lcfirst is not declared on Acme\Foo`);
- it is declared **without a default value** — a typed property, whose runtime read throws rather
  than yielding `null` (`name property layout on Acme\Foo is declared without a default value`).
  This one cannot be answered by key presence: PHPStan's reflection is BetterReflection, whose
  `getDefaultProperties()` maps *every* declared property to a value, so a typed property with no
  default reads back as `null` there — indistinguishable from an explicit `null` default, which is
  the value that *derives* a name;
- its default is neither a string nor `null` (`name property layout on Acme\Foo has a default that
  is neither a string nor null`).

An `is_file($this->layout)`-style early return in front of the derivation — the origin treats a
property value that is already a readable path as the template itself — is **deliberately not
modelled**, following the same precedent as the `$this->file` override gate the convention formulas
already carry: the existence gate is invisible per-class, so the derived convention path is the
candidate.

Presenter names come from the application's own mapping, read through the same container-loader
seam the pairing ladder uses (`orisai.nette.latte.templateFactoryContainerLoader`): the resolver mirrors the
deprecated vendor `unformatPresenterClass()` inverse and hardens it with a forward round-trip, so
a class whose inverse name does not format back to the identical class does not reverse-map at
all (opaque, never a wrong name). With no loader configured, every presenter's discovery is
opaque — controls, which need no name, still resolve. That opaque stays in the cached
`DiscoveryFact`; `LatteDiscoveryRule` only suppresses its *report* when no
`orisai.nette.latte.templateFactoryContainerLoader` is configured
(`ConfigurationGuard::hasTemplateFactoryContainerLoader()`). The suppression must not move into
`DiscoveryResolver`: a cached fact that depended on configuration the cache envelope does not
compare would make a warm run disagree with a cold one after a loader change, and
`orisai.nette.latte.templateMissing` would lose its opaque escape (a false close).

The mirrored path algebra is a committed tripwire: `FormulaParityTest` byte-compares this
extension's output against the **real** vendor `formatTemplateFiles()`/`formatLayoutTemplateFiles()`
on reflection-set fixture presenters, so a vendor upgrade that moves the formula fails a test
instead of silently mis-resolving every presenter template.

### Proven lifecycle windows

Discovery has to know *when* a `setView()`/`changeAction()`/`setFile()` call still affects the
rendered file. Those windows are not assumed — they are pinned by runtime probes against the real
`nette/application` (`tests/Integration/Latte/Parity/Lifecycle/`), committed as vendor-upgrade
tripwires the same way the include-semantics probes are. The facts the model encodes:

- **Order**: `checkRequirements()` for the class, then `startup()`, then per-method
  `checkRequirements()` via `tryCall()`, `action<Action>`, component signal processing (form
  `onSuccess` callbacks included), `beforeRender()`, `render<View>`, `afterRender()`, then the
  `sendTemplate()`-time evaluation of `formatTemplateFiles()`, and `shutdown()` last.
- **`setView()` / `changeAction()` are effective until that resolution** — a write in `shutdown()`
  is provably too late; a write in `afterRender()` is not (it still precedes the resolution).
- **`setFile()` has no too-late window at all in full-render flows** — the template file is read
  lazily when the response sends, after `shutdown()`. (AJAX snippet responses send earlier, inside
  `run()`; treating late `setFile()` as effective is the never-suppress direction, so the
  over-approximation is safe for discovery.)
- **`changeAction()` rewrites action *and* view, last write wins** — it overwrites a preceding
  `setView()` in the same body. There is no `setAction()` on a presenter at all
  (`Nette\Application\UI\Form::setAction()` is an unrelated method on a different class).
- **`switch()` (nette/application 3.2) is a view write too** — `run()` turns its `SwitchException`
  into `changeAction()` from an action method and `setView()` from a render method, so the walk
  records it as a mutation of its own kind (`switch`). It does not model the `never` return: a
  write after `switch()` in the same body is still walked, so a `setFile()` placed after it is
  still treated as an effective write — the same class of over-approximation as code following
  `redirect()`/`terminate()`.
- **An effective `setFile()` means `formatTemplateFiles()` is never consulted** — a proven,
  unconditional `setFile()` inside a proven dispatch window therefore *suppresses* the formula
  candidates of its scope (its own view for an `action<View>`/`render<View>` body, the whole class
  for `startup`/`checkRequirements`/`beforeRender`). Anything less than proven — a conditional
  write, a helper reachable from several phases, a signal handler — records its candidate and
  suppresses nothing.
- **A control links every template its render methods set** — `render()` and `renderX()` are
  separate entry points (`{control x}` vs `{control x:y}`), so each method's last `setFile()` wins
  for that method. A presenter keeps one winner per view.

### Views: method-derived, write-derived, file-derived

A view enters the set from three channels: an `action<View>`/`render<View>` method; a literal
`setView()`/`changeAction()` write inside its effective window (last-write-wins per body, a
conditional write scoring `maybe`); and — because **both dispatch methods are optional in Nette** —
the formula's own *inverse*: listing the directory the fixed part of the formula names and
recovering the view from what remains. A template file sitting where the presenter's formula would
put it renders on request with no `action`/`render` method anywhere, so method-derived views alone
would under-approximate the renderable set. Recovered names are held to the vendor's own
action-name grammar (the `initGlobalParameters()` guard), which keeps `@layout.latte`, block
partials and dotted leftovers out.

A **dynamic** `setView($x)` argument *opens* the view set: the real set is unknown, not empty, so
every per-view check (`orisai.nette.latte.templateMissing` above all) skips that class entirely rather than
answer from a set it knows to be incomplete.

### The derived discovery store

Class→file links live in a generated directory at `orisai.nette.latte.discovery.storePath` (default
`%tmpDir%/orisai-nette/latte-discovery`), one PHP file per template plus a class index. It is **not committed and not an input**: every run
rebuilds it from the configured universe before its own first parse, so a run never depends on an
artifact a previous run left behind. Deleting the directory costs nothing but the rebuild.

- **Setup**: none. There is no `*-init` target and no freshness gate, because there is nothing to
  drift from.
- **Who writes it**: `PreAnalysisIndexBuilder`, once, coordinator-side, triggered as a side effect of
  `LatteResultCacheMeta`'s pre-analysis phase — the one seam that runs before any file is handed to
  the analyser. It needs only a `ReflectionProvider` and its own parser: the scope-free
  `PhpRenderWalk` consumes no analysis results, which is what makes the placement possible. The
  build contributes **no term** to the meta hash, because a meta hash that moved would wipe the whole
  result cache.
- **Its universe is `%paths%` minus `%excludePaths%`**, never the CLI-narrowed analysed set, for both
  halves: the classes it indexes and the templates it materializes a (possibly empty) file for. That
  is what makes a path-narrowing spawn derive the same index a full run does. An excluded template
  gains no store file even when an analysed template includes it; a template whose findings are all
  silenced by `ignoreErrors` is analysed normally and still gets one.
- Each store file exports a `RECORDS_HASH` constant whose *value* is what PHPStan's own exported-node
  diffing watches, so a record change re-analyses exactly the templates that reference it — in the
  layout where the store sits inside `%paths%` (see *Store-path precondition* below). Every template
  in the universe gets a file, linked or not — an empty one is the recorded fact that nothing links
  that template, not a gap.
- The whole feature is gated by `orisai.nette.latte.discovery.enabled` (library default **on**) and is
  inert while `orisai.nette.latte.enabled` is off. It defaults on because it needs no setup — the store
  lives under `%tmpDir%` — and it is what links presenter templates; `ConfigurationGuard` has no
  "discovery requires latte" row, since the defaults would violate it
  (`testDiscoveryWithoutLatteIsInert` pins the inert combination). Off means literally no store read
  or write anywhere — every consumer short-circuits before touching the store, whose own directory
  load is lazy, and the build itself never runs — and `orisai.nette.latte.fileDiscoveryOpaque` is not
  reported.
- **Until the store has links, all four store-consuming diagnostics stay silent.**
  `orisai.nette.latte.orphanTemplate`, `orisai.nette.latte.templateMissing`, `orisai.nette.latte.templateTypeMismatch` and
  `orisai.nette.latte.templateTypeRequired` are answered from the store's class→file links, so a run that finds
  none — a corpus with no renderers at all, or a store whose per-template files exist but carry no
  records — reports nothing at all rather than declaring every analysed template unreachable. The
  precondition is phrased on the LINK SET, never on the directory, which is what makes it independent
  of whether the directory exists.
- **The store directory is created if absent.** The build has no "inert until someone mkdirs it"
  gate; that was the aggregate writer's, and inheriting it would make the first run poor again for a
  new reason.

Ingested records become incoming edges with an important carve-out on the layout side, every rule of
it pinned against the real runtime by `LayoutSuppressionParityTest` — one case excepted, called out
below. `{layout auto}` is the one declaration that *requests* the walk: it attaches the auto-layout
edge **unconditionally**, whatever else the template declares or contains. For every other template
an **auto-layout edge attaches only to one that declares no `{layout}`/`{extends}` of its own** (an
explicit one wins, `{layout none}` means none), and then only when it actually registers a block —
`{block}` *or* `{define}`, both of which enter the same block set — because a template registering
neither never consults the layout at runtime (`UIRuntime::initialize()` bails on an empty block
set). The `{layout auto}` exception is rare in practice; it is stated because the rule must hold
wherever it appears.

A dynamic `{layout $x}` also suppresses the auto edges, and that one is **not parity — it is a
deliberate divergence** from the runtime, which the same probe pins from the other side. See *A
dynamic layout argument suppresses the auto-layout edges and the runtime does not* under *Known
limitations*.

### Layout reachability (a class that renders no view cannot reach its layout)

`getLayoutCandidates()` is view-independent by vendor design, so a directory walk that produces a
layout candidate proves nothing about whether the layout is ever loaded. `DiscoveryRecords::forClass()`
therefore appends a layout record only when `mayReachALayout()` holds, which rests on a runtime proof:
`Presenter::findLayoutTemplateFile()` is called from more than one live site in the vendor tree —
`UIRuntime::initialize()` and the `{extends auto}`/`{layout auto}` macro compiled by `UIMacros`
(plus two Latte-3-only sites in `UIExtension.php` that never execute against the installed
`latte/latte` v2.11.7) — but what the proof rests on is not the count: every one of them sits
**inside a compiled template's own initialization**, so a layout is only ever resolved while some
template is already rendering — code that a class which never renders a view never reaches.
`sendTemplate()` also errors on the missing view first (`findTemplateFile()`'s `$this->error(...)`),
before `findLayoutTemplateFile()` is ever called, so there is no partial-render path that reaches the
layout without a resolved view either.
A layout candidate is therefore only ever a real edge when at least one of three conditions holds:

- **the class renders a view** — a chosen, existing view candidate (`rendersAView()`, read off the
  candidates rather than the records, so a candidate that escaped the project root still counts);
- **its discovery is opaque** (`getOpaques() !== []`) — an unresolvable channel is not proof of
  viewlessness, so the link stays exactly as before;
- **its view set is open** (`PhpRenderFacts::hasOpenViewSet()`) — a dynamic `setView()` argument
  means the real set is unknown, not empty.

The last two are what keep this from false-closing on a class that really does render something
through a path the walk can't prove statically — typically a presenter picking its template
dynamically: a computed `setView($finalView)` opens the view set (condition 3), and
`$this->template->setFile(__DIR__ . '/../templates/' . $this->name . '/' . $this->view . '.latte')`
is not statically resolvable (opaque, condition 2). Either way the layout link survives.

The classes the gate drops are genuine non-renderers: base presenters never instantiated directly
and API-only presenters that respond with JSON/redirects and never reach `sendTemplate()`.
`orisai.nette.latte.orphanTemplate` reachability only needs one surviving live edge, so a layout that keeps
several concrete renderers is unaffected, and `orisai.nette.latte.unknownBlock`'s "zero incoming edges"
exemption (*`orisai.nette.latte.unknownBlock` suppression* above) applies only to a layout that dropped to
zero.

**Consequence for `orisai.nette.latte.templateMissing`.** A class whose only discovery record was ever the
layout one — a class this gate now correctly links to nothing — can no longer host that finding, and
this DOES create new instances of the hole *Known limitations* already documents for
`missingByHost()` (it iterates only `firstLinkedTemplates()`, so a renderer linking no template hosts
nothing). `TemplateTypeChecker::missingDiagnostics()` (`:385-435`) fires for a view whose candidates
all answer `exists() === false`, and `resolvesATemplateFile()` (`:449`) answers true off a live,
non-terminating `render<View>` hook alone — no template file has to exist for that. So a presenter
with `renderFoo()` and no `foo.latte` anywhere DOES satisfy `orisai.nette.latte.templateMissing`'s own
precondition, regardless of its layout link: before this branch its (unconditional) layout record
made it that class's first linked template, hosting the finding there; now that link is gone, the
class has no record at all, it never reaches `firstLinkedTemplates()`, and `missingByHost()` never
iterates it. That is the canonical "forgot to create the template" case, and it WAS reported (hosted
on the layout) before this branch. The direction is a detection loss, never a false positive — the
class stops being reported, it is never wrongly reported — and it is accepted for that reason, the
same trade the pre-existing hole already makes.

**Consequence for the multi-renderer merge.** Before the merge rule (below) existed, this precision
was only an edge-count property: wrongly dropping a renderer's layout link cost at most a missed
detection, which is safe. `FactoryProvidedVars::intersect()` now folds `$presenter`'s type over
exactly the renderer set a template links, read straight from the store, so a wrongly dropped link
also moves that folded type — and a wrongly-narrowed type invents findings rather than merely missing
one. Layout reachability's correctness is therefore now a false-positive-safety property for the
merge, not only a detection-completeness property for `orisai.nette.latte.orphanTemplate`/`orisai.nette.latte.unknownBlock`.

### Store-path precondition (granular vs. coarse invalidation)

**The default `orisai.nette.latte.discovery.storePath` (`%tmpDir%/orisai-nette/latte-discovery`) sits OUTSIDE the analysed
`%paths%`, and that is a deliberate trade.** A store file's changed `RECORDS_HASH` reaches its linked
template through PHPStan's own dependency machinery, and that machinery tracks nothing outside the
analysed set. Outside `%paths%` the analysis stays *correct* — `LatteResultCacheMeta` falls back to a
whole-store **content** salt — but the regime degrades: a change to any record then discards the
entire result cache instead of re-analysing just the templates that reference it. This is not
hypothetical; it was proven by constructing a second-renderer mismatch that never surfaced on any
number of warm runs while a cold run reported it.

Class resolution is *not* part of the trade. `LatteTemplateSourceLocator` reflects the store's
generated classes straight out of the store directory, so a compiled template's self-reference
resolves identically in either layout; only result-cache granularity moves.

The cost is bounded to *record* changes — a renderer's template surface moving — and a template-SET
change already discarded the whole cache in either regime. What buys it is that the store is one
directory in one cache location, purged with `%tmpDir%`, ignored by git, cs and lint by virtue of
living there, and kept out of the analysed set.

The granular channel is narrower than it reads. Placing the store under `%paths%` sweeps its
generated files into the analysed set, and they contribute no finding of their own (apart from
dead-constant reports a dead-code detector may raise on their `RECORDS_HASH` constants). And
retargeting one renderer to another existing template — the canonical record change — also moves a
discovery *marker*, so it moves this extension's own edge-topology salt through
`TemplateEdgeIndex::ingestDiscovery()` and wipes the whole cache in *either* layout. Only a
`certainty`-only record move — the one record field deliberately kept out of the marker — is left
for the granular channel to narrow.

A consumer who would rather pay a full run only on a set change can still point
`orisai.nette.latte.discovery.storePath` at a directory inside one of its `%paths%`; nothing else in the extension
changes. Budget for the rest of it: the directory then has to be gitignored and excluded from
whatever coding-standard and lint sweeps cover that path, and its files join the analysed count.
Measure before buying it — the win only appears for record changes that leave every marker
(`class`, `kind`, `view`) alone.

With an **empty `%paths%`** — PHPStan's own default, where the analysed set arrives on the command
line — the store can never be inside the analysed set, so the coarse regime is *permanent* and the
"keep it inside `%paths%`" advice is unfollowable. That one case says so out loud: a once-per-process
note on STDERR (machine-readable stdout and the `[OK]` verdict untouched), silenced by
`orisai.nette.latte.discovery.coarseInvalidationAccepted: true` for a CLI-driven workflow that means it.
Every other coarse layout is fixable by moving the store and stays silent — a project which declares
`%paths%` never prints the note and never needs the flag.

### Discovery diagnostics

All of them need `orisai.nette.latte.enabled`; the four Latte-side ones additionally need
`orisai.nette.latte.discovery.enabled` (no records, no linked templates, nothing to check).

- **`orisai.nette.latte.fileDiscoveryOpaque`** (PHP side, on the class) — a channel discovery could not resolve:
  `Template file discovery is opaque: formatTemplateFiles override declared by Acme\Control\FooBase
  has no assigned discovery formula.`, `Template file discovery is opaque: setFile argument is not
  statically resolvable.`, `Template file discovery is opaque: presenter name unresolved: no
  mapping reverse-maps class Acme\Module\BarPresenter.`
- **`orisai.nette.latte.ineffectiveTemplateMutation`** (PHP side, at the call) — a write proven outside its
  window: `Call to setView() has no effect at this point of the presenter lifecycle.` Only
  provably-out-of-window calls report; the ambiguous state (`maybe`) is silent by construction.
- **`orisai.nette.latte.templateTypeMismatch`** (template side) — `Template declares {templateType
  Acme\UserPanel\ListTemplate} but renderer Acme\UserPanel\ListControl pairs
  Acme\UserPanel\DetailTemplate.` Checked against *every* linked renderer; a declaration may widen
  (subtype-tolerated, the pairing rule above), and an opaque or `*dynamic*` verdict skips.
- **`orisai.nette.latte.templateMissing`** (template side, hosted on the renderer's first linked template) —
  `No template file found for Acme\Module\FooPresenter::bar (tried: templates/Foo/bar.latte,
  templates/Foo.bar.latte).` The runtime-500 catch: every candidate missing, for a view that
  *provably* reaches file resolution (an explicit `setFile`/convention write, or a `render<View>`
  hook that does not always terminate — an `action<View>` alone proves nothing, it may redirect or
  send JSON first). Opaque discovery, an open view set and abstract renderers all skip.
- **`orisai.nette.latte.templateTypeRequired`** (template side, opt-in) — `Template has no {templateType} and
  renderer Acme\Module\FooPresenter pairs the default template class.` Behind
  `orisai.nette.latte.templateTypeRequired` (default **off**): it is a
  migration nudge toward typed templates, not a defect.
- **`orisai.nette.latte.orphanTemplate`** (template side) — `No analysable render, include or layout path
  reaches this template file.` Computed as a reachability fixpoint over the whole template graph:
  live roots are templates a PHP renderer renders, liveness propagates through
  include/import/extends/layout edges, and anything in app scope not reached is reported, with a
  tip naming its unreachable includers when it has any. **Advisory only — see the limitations
  below for why it is never auto-fixable.**

### `dumpLatteDiscovery()` — discovery dump

The sixth member of the debug family renders one class's discovery, the way
`dumpLattePairing()` renders its verdict:

```latte
{do dumpLatteDiscovery(\Acme\SomeModule\SomePresenter::class)}
```

It fires from any analyzed context (the argument must be a `::class` constant) and prints, in
order: `open view set:` (`yes` means every per-view check skips this class); `views:` — each view
as `name (certainty) @ sites from <sources>`, where a source is either the deriving method name
(`actionDefault`) or the writing call (`setView:29`); `view candidates:` — one block per view
(`(no view)` for the viewless bucket a control or a class-level write lands in), each candidate as
`path (kind, exists|missing[, chosen])` with `kind` one of `formula`/`setFile`/`convention`;
`layout candidates:` in the same shape (kind `layout`); `opaques:` — each reason, with its site line where it has
one; and `ineffective mutations:` — the provably-too-late writes as `setView (outside) @ 13`.

A file-derived view shows up under `view candidates:` while `views:` stays `(none)` — that is the
method-less presenter case above, not a bug. An unknown class reports `class not found`, a class
without a source file `class has no analyzable file`, a non-qualifying class says so explicitly,
and facts that carry no discovery fact at all (hand-built or restored from an older envelope)
report `facts carry no discovery fact` rather than an all-empty dump.

## Error surface

Latte-specific identifiers (all ordinary, ignorable, baselinable, reported on `.latte` lines):

| Identifier | Meaning | Reported at |
|---|---|---|
| `orisai.nette.latte.parseError` | template failed to tokenize/compile, a vendor throwable during the compile (`Thrown exception '…'`), or generated PHP which does not parse (`Error in template: …`) | offending line (file-level fallback line 1) |
| `orisai.nette.latte.unknownMacro` | tag / n:attribute not in the registered set | tag site |
| `orisai.nette.latte.unknownFilter` | filter name not in the known set | filter site |
| `orisai.nette.latte.unknownType` | `{templateType}`/`{varType}` references an unknown class | declaration site |
| `orisai.nette.latte.dynamicInclude` | include target not statically determinable | include site |
| `orisai.nette.latte.dynamicExtends` | extends/layout target not statically determinable | tag site |
| `orisai.nette.latte.unknownInclude` | static include target file does not exist | include site |
| `orisai.nette.latte.unknownBlock` | block include target exists in no reachable table (see suppression above) | include site |
| `orisai.nette.latte.includeCycle` | include chain cycles | closing edge site |
| `orisai.nette.latte.includeTypeMismatch` | provided context incompatible with target's declaration | include site |
| `orisai.nette.latte.includeMissingVariable` | declared (defaultless) variable not provided by edge | include site |
| `orisai.nette.latte.internalAccess` | template source writes `$this->member` on the compiled runtime class | access site |
| `orisai.nette.latte.duplicateDeclaration` | `{varType}` override exactly matches its native declaration | `{varType}` site |
| `orisai.nette.latte.narrowingOverride` | `{varType}` override narrows its native declaration (see *Declaration consistency*) | `{varType}` site |
| `orisai.nette.latte.impossibleOverride` | `{varType}` override is wider than or unrelated to its native declaration | `{varType}` site |
| `orisai.nette.latte.varTypeMisplaced` | mid-file `{varType}` is not directly above an assignment to its variable (see *Mid-file `{varType}` placement*) | `{varType}` site |
| `orisai.nette.latte.varTypeDifferentVariable` | mid-file `{varType}` names a variable its single anchor does not bind | `{varType}` site |
| `orisai.nette.latte.varTypeVariableNotFound` | mid-file `{varType}` names a variable none of its anchor's several bindings match | `{varType}` site |
| `orisai.nette.latte.varTypeNativeType` | `{varType}` conflicts with the native type of the expression its anchor assigns | `{varType}` site |
| `orisai.nette.latte.varTypeType` | `{varType}` conflicts with the PHPDoc type of that expression (`orisai.nette.latte.reportWrongPhpDocTypeInVarType`) | `{varType}` site |
| `orisai.nette.latte.filterCaseMismatch` | called filter spelling differs in case from the registered one (Latte 2: token scan, breaks on Latte 3; Latte 3: `FilterRewriter`, throws at runtime) | filter site |
| `orisai.nette.latte.functionCaseMismatch` | called function spelling differs in case from the registered one (Latte 2: token scan; Latte 3.0: the compile warning; Latte 3.1: `FilterRewriter`) | function call site |
| `orisai.nette.latte.deprecated` | vendor `E_USER_DEPRECATED` captured during compilation (see *Vendor error containment*) | compiler's current line (fallback line 1) |
| `orisai.nette.latte.internalError` | the analysis pipeline's own diagnostic-materialization invariant was violated | offending node |
| `orisai.nette.latte.customsHarvest` | a directory under a harvested Latte 3 extension could not be read for the harvest salt (see *Invalidation*); the path is relative to `%currentWorkingDirectory%` when inside it | line 1 of the universe's first template (sorted) |
| `orisai.nette.latte.templateTypeMismatch` | `{templateType}` outside what a linked renderer pairs (see *Template-file discovery*) | `{templateType}` site |
| `orisai.nette.latte.templateMissing` | a renderable view whose every candidate file is missing | renderer's first linked template, line 1 |
| `orisai.nette.latte.templateTypeRequired` | linked template with no `{templateType}`, renderer pairs the default class (opt-in) | line 1 |
| `orisai.nette.latte.orphanTemplate` | no render, include or layout path reaches this template (advisory) | line 1 |
| `orisai.nette.latte.providerUnavailable` | a `uiControl`/`uiPresenter`/`snippetBridge`-requiring macro whose every linked renderer proves `CONTROL_NONE` (see *Provider-availability guard*) | macro site |

Everything else is native PHPStan (`variable.undefined`, `method.notFound`, `argument.type`,
`booleanAnd.leftNotBoolean`, ...) with standard identifiers, reported on `.latte` lines exactly as
it would be on a `.php` line.

`orisai.nette.latte.debugDump` (the `dumpLatteCustoms()`/`dumpLatteIncluders()`/`dumpLatteVarOrigin()`/
`dumpLatteRenderFacts()`/`dumpLattePairing()`/`dumpLatteDiscovery()` family, see above) is a
deliberate exception to "ordinary, ignorable, baselinable": it is a developer-invoked,
non-ignorable finding by design — a debug print, never meant to be silenced or baselined.

`orisai.nette.latte.pairingConflict`/`orisai.nette.latte.pairingOpaque` (see *Template-class pairing* above) plus
`orisai.nette.latte.fileDiscoveryOpaque`/`orisai.nette.latte.ineffectiveTemplateMutation` (see *Template-file discovery*)
are the PHP-side surface: ordinary, ignorable and baselinable like the table, but reported on
`.php` class/site lines, not `.latte` lines.

The five `orisai.nette.latteForms.*` identifiers (`unknownControl`, `unknownForm`, `containerAsControl`,
`controlAsContainer`, `labellessControl`) come from the Latte+Forms bridge (`src/LatteForms/`), a
separate area joining this extension's discovery store to the Forms extension's shapes — see
[latte-forms.md](latte-forms.md).

## TemplateFactory-provided variables

`Nette\Bridges\ApplicationLatte\TemplateFactory` writes a fixed set of variables into every template
it creates, under two conditions the analysis mirrors exactly
(`TemplateFactory.php`, the `foreach ($params …)` loop):

```php
if ($value !== null && property_exists($template, $key)) { $template->$key = $value; }
```

**Modelled from the wired container: `user`, `baseUrl`, `basePath`, `flashes`.** Availability is
decided per template:

- the property must be declared by the RESOLVED template class — the full resolution ladder,
  including its factory-default rung. No resolvable class means no factory variables at all.
- the factory's own value must not be null. `flashes` falls back to `[]` and so is unconditional;
  `user` and the `httpRequest`-derived `baseUrl`/`basePath` are nullable constructor dependencies,
  so their availability is read from the **compiled container** — the same seam that already
  supplies the factory's configured template class. A dependency the container wires makes the
  variable definitely available; one it does not leaves it merely possible.

Reading the container matters more than it looks: PHPStan reports a merely-possible variable too
("Variable $x might not be defined"), so a MAYBE answer only rewords the finding. Only a
definitely-available answer removes it.

The wired object's concrete class **refines** the type when the template class's own `@var` admits
it, and never contradicts it — a template declaring its own narrower `$user` keeps its declaration.

**Also modelled: `control` and `presenter`, through the recorded `createTemplate()` control
argument.** `createTemplate()`'s first argument decides both
(`$presenter = $control ? $control->getPresenterIfExists() : null`), so `PhpRenderWalk` records it
per renderer class as one of four answers:

| answer | how it is reached |
| --- | --- |
| `self` | the renderer's own instance reaches the factory — the vendor `Control::createTemplate()`/`Presenter::createTemplate()` body a component INHERITS (both pass `$this`), or an explicit `$factory->createTemplate($this, …)` |
| `none` | a standalone `$factory->createTemplate()` with no control argument, or an explicit `createTemplate(null, X::class)` |
| `other` | an argument that resolves to neither — including a call on another component's instance, and **two call sites of the same class disagreeing** |
| absent | no `createTemplate()` path observed at all |

A call delegating to an app-level `createTemplate()` override on the same instance records nothing:
that override's own body is walked in the same pass, so a `parent::createTemplate()` chain resolves
to whatever it really ends in however many app classes deep it runs. The disagreement rule is what
keeps this off "the renderer is a component, therefore it has a control" — a component that also
calls the factory standalone lands on `other` and claims nothing.

`control` follows from that answer directly, because the recorded argument IS the control:

- `self` → **definitely present, and it IS the renderer.** No hierarchy question arises on an
  argument that is `$this`, so nothing further has to be proven. The renderer's own class refines
  the declared `@var` where that `@var` admits it, which is the whole value of the key: the template
  class declares only `Nette\Application\UI\Control`, and every control-specific member a template
  calls (`getDir()`, `htmlId()`, `hasRewards()`, …) resolves solely because of the refinement.
- `none` → present and null, the same untyped-property rule `presenter` uses below — and with the
  same `isInitialized()` exception, a natively typed property with no default being ABSENT.
- `other` / absent → nothing claimed.

`presenter` follows from the same answer one hop further, and that hop can fail:

- `self` **and the renderer is a `Nette\Application\UI\Presenter`** → definitely present, and it IS
  the renderer: `Presenter::getPresenterIfExists()` is a final override returning `$this`. The
  renderer's own class refines the declared `@var` where that `@var` admits it.
- `self` on a plain control → **nothing claimed.** A detached control has no presenter and
  attachment is runtime state; a nullable answer would clear `variable.undefined` at the price of a
  nullability error on every `$presenter->…` a rendered control really reaches.
- `none` → present and null. The vendor declares the property untyped, so it keeps its implicit
  null and `getParameters()` still exports it — maybe-defined, never a claim that `isset()` holds.
  A natively typed property with no default is instead ABSENT, the same `isInitialized()` rule the
  dependency-backed variables use.
- `other` / absent → nothing claimed.

`control` and `presenter` are **edge-local**: unlike the ambient variables they name the renderer's
own identity, so they are dropped when a context crosses an include edge and each template answers
from its own store records. Latte 2 really does hand an include the includer's whole parameter set,
so keeping them would not be wrong — it would be one context per includer for every shared partial,
multiplying a shared layout's contexts, findings and baseline counts roughly tenfold.

**`{templateType}` overrides both, and that is a lever with a price.** `overlayFactoryVars()` is the
weakest layer in the context: it only ever fills keys nothing else declared, so a
`{templateType X}` whose `X` declares `$control` pins `$control` to `X`'s own declaration and the
factory answer is never consulted. Useful when a template wants a type of its own — but the
declaration is often strictly *wider* than what the factory resolves: declaring
`{templateType Nette\Bridges\ApplicationLatte\DefaultTemplate}` on a grid template shared by a base
grid control and its subclasses takes `$control` from that base class down to the declared
`Nette\Application\UI\Control`, losing every control-specific member, and reports one
`orisai.nette.latte.templateTypeMismatch` per renderer (they pair to the bare `Template` floor, which a
narrower declaration contradicts). To narrow rather than widen, write
`{if $control instanceof FooControl}` — an ordinary `instanceof` on the refined type, which needs no
declaration at all.

**What typing `control` surfaces.** Typing `$control` removes `Undefined variable: $control` from
every template whose renderers agree on `self`, and in exchange makes previously `mixed` expressions
concrete. Expect three kinds of new findings:

- vendor docblock imprecision — e.g. `Cannot call method addAttributes() on Nette\Utils\Html|string`,
  because `BaseControl::getControl()` is docblock-typed `@return Html|string`;
- redundancy checks on code Nette's own `FormMacros` GENERATES — `is_object($tmp = <expr>) ? $tmp :
  end(…)[$tmp]` for every `{input}`/`{label}`/`n:name`, plus the `if ($_label = …->getLabel())` a
  `{label}` expands to; once `<expr>` types concretely the check is provably redundant
  (`ternary.alwaysTrue`, `function.alreadyNarrowedType`) and no developer can act on it;
- author-written loose truthiness and wrong arity on now-typed expressions — real findings.

One real bug class is worth naming: `Kdyby\Replicator\Container::getContainers()` returns a
`CallbackFilterIterator` that is not `Countable`; SPL forwards `count()` to the *unfiltered* inner
iterator, so `getContainers()->count()` counts the replicator's own non-row children too. The
reported `Call to an undefined method Iterator<…>::count()` is correct — do **not** silence it with a
`Countable` stub.

What stays undefined, by design: partials included from a control template (`control` is edge-local,
so the includer's `$control` is dropped at the edge and the partial carries no discovery records of
its own), and templates whose renderers land on `other`/absent. The first needs per-includer
contexts (refused above), the second needs the renderers to agree on one `createTemplate()` shape.

**A finding may have two message variants depending on cache warmth.** A narrowing that `control`
feeds into a `.php` file (e.g. `Instanceof between <FormContainer subclass> and Nette\Forms\Container`)
holds only once the shared `FormShapeCache` has been warmed by a template's analysis. On a cold cache
with only that `.php` file analysed, the un-narrowed variant is reported, and PHPStan's parallel
scheduler does not guarantee that a warming template runs first even on a whole-project run. A
consumer baselining such a finding needs both variants (with `reportUnmatchedIgnoredErrors: false`
the unmatched one costs nothing); regenerating the baseline drops whichever variant that run did not
see.

**The one structural residual: the `IComponent` asymmetry.** When `ContainerModel::walk()` cannot
prove a child, the existence axis answers OPEN (correctly — no `orisai.nette.forms.noSuchComponent` or
`orisai.nette.forms.unknownAccess` arises from it) but the type axis has no equivalent: phpstan-nette's
`ArrayAccess<string, IComponent>` stub stands, and `IComponent` is a closed, narrow interface, so
"I don't know" reads as "definitely an `IComponent`" and every member access reports. `control`
routes a few more sites into that floor; it does not create it. Closing it means yielding the
replicator's inner `FormShape` as the iterator/element type instead of the bare container class.

**Version-gated.** Newer nette/application also type-checks each property before writing it, so a
template declaring `$user` as something other than the wired class would not receive it. The
installed version (v3.1.15) has no such check and this model matches the installed behaviour;
`TemplateFactoryInjectionParityTest` is the tripwire for that upgrade.

**Multiple renderers, one template: the merge rule.** A template linked from more than one
renderer class (a shared layout is the common case) resolves each variable once per renderer, then
folds the answers pairwise (`FactoryProvidedVars::intersect()`, `CommonClassAncestor::of()`).
Three cases:

- **Equal.** Both renderers agree on the exact same type string — returned verbatim, no widening.
- **Common class ancestor.** The types disagree but share a class ancestor — widen to the
  *deepest* one (`CommonClassAncestor::chain()` walks `get_parent_class()` outward from `$a`,
  whichever side that happens to be, testing each ancestor against `$b`; the walk itself is not
  "narrower first" — the result is symmetric regardless of argument order
  (`testArgumentOrderDoesNotMatter`) — so the first match found is still the tightest).
- **`mixed`.** No shared class — including two types that share only an *interface*
  (`class_exists()` answers `false` for an interface, so it can never stand in as a common
  ancestor) and non-class disagreements (array shapes, scalars, `mixed` itself).

**Soundness.** `typeOf()`/`controlArgumentTypeOf()` only ever replace a renderer's own template
class's declared `@var` with something that `@var` *admits* — a per-renderer refinement always stays
a subtype of that renderer's own declaration, never a sibling or a wider type. In general the two
merged renderers resolve to different template classes with their own separate declarations, not one
shared declaration to fall back on — but the soundness argument does not need one: each side is
independently a sound bound on its own renderer, and any common supertype of two independently sound
bounds is itself sound. The deepest common CLASS ancestor is the tightest such supertype, so it is
always at least as precise as either side's own declaration, and strictly more precise whenever the
renderers share a narrower ancestor beneath them.

**Determinism.** `chain()` walks a single-inheritance parent list, and common ancestors of a class
form a total order along that chain, so the pairwise fold is associative:
`of(of(A, B), C) === of(A, of(B, C))` (`testFoldIsAssociative`). `intersect()` folds renderers in
`SORT_STRING` class-name order, so this guarantees the merged type — and the `EdgeFingerprint`
derived from it — never depends on which renderer happened to be processed first.

**Why not a union.** A precise union is tighter still, and was rejected on message size: a layout
shared by dozens of presenters would carry a dozens-member union in its injected `@var` and in
every error message that mentions the variable. The deepest common class ancestor keeps the
answer to one name.

Typing `$presenter` on a shared layout as the module's base presenter instead of `mixed` can surface
pre-existing docblock looseness — e.g. a base presenter method declared `@return bool|string` passed
to the `|lower` filter's `string` parameter. That is a real finding, not a defect in the merge rule.

**`control` rides the identical fold, which is why a multi-renderer template gets ONE `$control`
type rather than one per renderer.** Each renderer refines `$control` to itself and `intersect()`
widens the pair through `CommonClassAncestor::of()` exactly as it does for `$presenter`; contexts
are never multiplied per renderer (`resolveContextsTracked()` drops discovery edges before any
context bookkeeping, and `overlayFactoryVars()` maps 1:1 over the existing context list and only
ever *adds* absent keys). A grid template linked from a base grid control and its subclasses resolves
`$control` to the base class in **one** context; a layout linked from many presenters resolves it to
their common base presenter, the same class its `$presenter` merges to.

**Two limitations.** Interfaces are not considered — two renderers sharing only an interface (not
a class) widen to `mixed` even though the interface would be a sound, if less precise, answer. And
a genuine disagreement with no common class at all — unrelated hierarchies, or either side not a
class — still answers `mixed`; the merge only improves the case where a common ancestor exists, it
never invents one.

A third, milder one: `ContainerModel::classComponentShape()` resolves a `createComponent*()` factory
off the exact class it is given, native-reflection-anchored (never virtual dispatch), so when the
widened common ancestor itself declares the factory and a subclass renderer OVERRIDES it, a component
reached through `$control['form']` on that template is classified against the ancestor's shape, not
the overriding subclass's — the object type stays sound, only the shape derivation is optimistic.

## Provider-availability guard (`orisai.nette.latte.providerUnavailable`)

`Nette\Bridges\ApplicationLatte\TemplateFactory::setupLatte2()` adds three more Latte providers
inside the SAME `if ($control)` conditional the `presenter` variable above is read from
(`TemplateFactory.php`, ~lines 158-169):

```php
if ($control) {
    $latte->addProvider('uiControl', $control);
    $latte->addProvider('uiPresenter', $presenter);
    $latte->addProvider('snippetBridge', new SnippetBridge($control));
}
```

`{control}`, `{link}`/n:href and `{form}` compile to `$this->global->uiControl->…`; `{plink}` and
`{ifCurrent}` to `$this->global->uiPresenter->…`; `{snippet}`/`{snippetArea}` to
`$this->global->snippetDriver->…`, a provider Latte's own `Template::doRender()` builds from
`snippetBridge` lazily (`isset($this->global->snippetBridge) && !isset($this->global->snippetDriver)`)
— so it exists under the exact same condition, one hop removed. A template rendered only through a
standalone `$factory->createTemplate()` call never gets any of the three, and the macro dereferences
null at runtime — `Undefined property` followed by `Call to a member function … on null`.

**The check.** `ProviderAvailabilityChecker` reports one of these macros when **every** renderer the
discovery store links to the template proves `PhpRenderFacts::getCreateTemplateControl() ===
CONTROL_NONE` — the exact recorded axis *TemplateFactory-provided variables* above already reads for
`presenter`, and the same all-renderers discipline `FactoryProvidedVars::resolve()` applies: a
template is rendered through every linked renderer in turn, so only one of them owning a control at
all makes the claim wrong.

**OPEN, never false-close.** No store records, any `CONTROL_SELF`, any `CONTROL_OTHER`, or no
observed `createTemplate()` call at all — all four mean silence, never a guess.

**Macro sites, read from the pipeline's own eliminators rather than a second hand-parse of the
`.latte` source.** `UiMacroEliminator` and `FormsMacroEliminator` already tell every one of these
constructs apart precisely (that is their whole job — rewriting them into an analyzable shape); this
guard's `ProviderMacroScanner` reads the same distinctions rather than re-deriving them:

- **`{form x}` vs `{form $var}`.** Both compile through a `$this->global->uiControl[…]` access
  (`{form x}` directly, `{form $var}` inside `is_object($var) ? $var : uiControl[$var]`), but only
  the first needs `uiControl` *unconditionally* — the second may always hand it an already-resolved
  object, which never touches `uiControl` at runtime. FormsMacroEliminator already rewrites the two
  to different helpers (`Helpers::form()` / `Helpers::formObject()`), so the scanner reads its
  POST-elimination output and only counts the first — reading the raw, pre-elimination
  `uiControl[…]` access instead would flag `{form $var}` too, a genuine new false-positive class.
- **`{control name}` vs `{control $var}`.** The same object-or-name ambiguity exists here
  (`is_object($var) ? $var : uiControl->getComponent($var)`), but UiMacroEliminator itself never
  keeps the two apart — both collapse into the identical `Helpers::component()` downstream. The
  scanner matches this precision exactly: every `uiControl->getComponent()` call counts, static or
  dynamic name alike.
- **`{control}`/`{link}`/n:href/`{plink}`/`{ifCurrent}`/`{snippet}`/`{snippetArea}`** have no such
  ambiguity, so the scanner reads them straight off the RAW, pre-elimination AST — necessary for
  `{snippet}` in particular, since `BlockDispatchEliminator`'s own `snippetDriver->enter()`/`leave()`
  shell is dropped entirely (only the try body survives elimination), leaving no post-elimination
  trace to read at all.

**Why `{plink}`/`{ifCurrent}` are not treated more finely.** `uiPresenter` is added inside the SAME
`if ($control)` block, from a POSSIBLY-NULL `$presenter`
(`$presenter = $control ? $control->getPresenterIfExists() : null`) — so a detached, non-`Presenter`
control (`CONTROL_SELF` on a plain `Control`) has `uiPresenter` *present but null*, which would ALSO
fatal on `->link()`/`->isLinkCurrent()`. That is exactly the presenter-availability question
*TemplateFactory-provided variables* above already declines to answer for a plain control (its own
`presenter` row: "nothing claimed" — attachment is runtime state no walked fact can prove), and this
guard declines it again rather than inventing an unproven finer condition. `{plink}`/`{ifCurrent}`
are reported on the SAME `CONTROL_NONE`-everywhere condition as every other macro here, no finer one.

**The domain is small by construction.** Most renderers either never call `createTemplate()` in a
way the walk observes at all, or share a template with a `CONTROL_SELF`/`CONTROL_OTHER` renderer —
either one, on ANY linked renderer, silences the whole template under the all-renderers rule above.
A project may well get zero findings, and that is a checked answer, not evidence the guard is
vacuous: its value is prospective, like `orisai.nette.latte.templateTypeRequired`'s. A consuming
application can pin its own domain (the templates proven `CONTROL_NONE` everywhere) in its own tests,
so that a change to the walk that silently shrinks or grows it is noticed.

## Known limitations

- **A template property declared as a SUPERTYPE of the factory's configured class gets no factory
  variables.** The gate honours the declared type, but the factory instantiates its own configured
  class. A control declaring `/** @var Template */` — the base `Template`, which declares none of
  the six — while the factory really creates `DefaultTemplate`, which declares all of them, does
  receive `$user` at runtime. So the variables are present at runtime and absent from the analysis.
  This under-claims, which is the safe direction, and it is the dominant shape of the remaining
  `Undefined variable` findings for factory variables. Closing it
  means preferring the factory's configured class when the declared type is a supertype of it AND
  the value provably came from the factory.
- **`orisai.nette.latte.templateMissing` cannot fire for a renderer that links no template at all.**
  `TemplateTypeChecker::missingByHost()` iterates only `firstLinkedTemplates()`, so the finding is
  hosted on the renderer's first linked template — and a renderer whose *only* candidate is missing
  links nothing, which is exactly the case the diagnostic describes. Hosting such findings on the
  renderer's own `.php` file would close the hole, but the first real case it surfaces is a false
  positive: a base control deriving a template that does not exist, while the class is
  concrete-but-never-instantiated (no `new`, no DI registration — it exists
  only to be extended) so it never runs as itself. Suppressing that correctly needs an
  "is this class ever instantiated" analysis — effectively-abstract detection, which is dead-code
  territory this extension deliberately leaves to shipmonk/dead-code-detector. Reporting on it
  without that capability would trade a silent miss for a false positive on correct code, which
  ranks worse. Revisit if effectively-abstract detection lands. The hole covers a control with no
  layout channel (its only possible record source is its own missing view candidate) and, because of
  *Layout reachability* (above), a presenter with a missing view whose layout link is (correctly)
  dropped: it links no template at all, per the *Consequence for `orisai.nette.latte.templateMissing`*
  note above.
- **`{default $x = expr}` and null diverge from runtime in one case.** It is modeled as
  `$x ??= expr`, but Latte's runtime `EXTR_SKIP` checks variable *existence*, not nullness: a
  variable that already exists but is `null` keeps `null` at runtime, while the analysis sees the
  default's type. Not expressible in PHP scope semantics (both `??=` and `isset()` guards drop
  null the same way).
- **A declared property is a parameter even when nothing ever writes it.** The same class of
  declaration-versus-runtime-existence gap as the `{default}`/`EXTR_SKIP` divergence above, on the
  other declaration channel. `{templateType C}` where `C` declares `public int $x;` makes `$x`
  definitely defined (*Typing templates* explains why that is deliberate) — but the runtime walk
  this models is one predicate stricter. `Nette\Bridges\ApplicationLatte\Template::getParameters()`
  (v3.1.15) iterates the very same `getProperties(ReflectionProperty::IS_PUBLIC)` set that
  `PropertyTypeResolver::resolveAllPublic()` does, and exports each entry **only** if
  `$prop->isInitialized($this)` — so an uninitialized typed property is absent from the exported
  array. If nothing ever writes `$x`, the variable does not exist at render time and Latte renders
  it empty, with zero signal from either side. A missed detection, never an invented one — the
  analysis is optimistic here, never wrong in the reporting direction.
  Pinned by `tests/Integration/Latte/Integration/Fixtures/declared-parameters.latte` and, in its
  sharpest form, by `DeclaredParametersMultiRendererTest`: one template, two linked renderers, one
  declared property written by exactly one of them and another written by neither — both stay
  defined for both renderers. That test also shows the write information is not missing, merely
  unconsulted: `PhpRenderFacts::getAssignments()` already records it per renderer (one carries the
  assignment, the other reports none), and today only `dumpLatteRenderFacts()` reads it.

  **Viable closure.** If the template class can be constructed directly (`new ExampleTemplate` — a
  strict, checkable boundary, and one PHPStan could in principle honour unaided), the writes could
  be scanned from the PHP class alone and a never-written declared property reported at its
  declaration site rather than at every use. The per-renderer assignment facts above are a second
  route, cheaper but weaker: renderer writes are not the only writes (`setParameters()`, a parent's
  `beforeRender()`, a trait), so that route would have to stay OPEN — never false-closing a
  property just because no renderer write was found. Neither is implemented; both are paths, not
  promises.
- **An uninitialized `#[TemplateVariable]` presenter property is claimed present (ruling).**
  nette/application 3.2's `Presenter::sendTemplate()` copies only initialized `#[TemplateVariable]` properties
  (`ComponentReflection::getTemplateVariables()`); `FactoryProvidedVars` claims every public non-static attributed
  property of the renderer and its parents as present, because initialization is runtime state and the canonical
  `#[TemplateVariable] public string $title;` assigned in an `action*()`/`render*()` method would otherwise be a
  systematic false `Undefined variable`. The cost is a missed report when a presenter never assigns the property.
- **Latte 3 compile limitations** — textual pairing of unknown tags, the `getFile()` origin check of vendor
  throwables, unmodelled sandbox policies — are listed in [latte-versions.md](latte-versions.md#known-limitations).
- **Filters and functions registered as anonymous closures are untyped.** `CallableTargetResolution` resolves a
  named-method or plain-function closure to its declaration; an anonymous closure (`{closure}`, and on a Latte 2
  harvest any closure) has none PHPStan could reflect — the harvested `Closure` is a runtime value only. The tables
  register `Helpers::untypedFilter()`/`Helpers::untypedFunction()` (`mixed ...$args`, returns `mixed`) for it, a
  closure a filter loader answers included: the call is known, never `orisai.nette.latte.unknownFilter`, but neither
  its arguments nor its result are checked.
- **A purely virtual `@property` tag on a `{templateType}` class contributes no parameter.** The
  declared surface is `ReflectionClass::getProperties(IS_PUBLIC)` — native reflection — so a
  class-level `@property Foo $x` with no backing property statement is invisible and `$x` is
  reported as `Undefined variable`. A real property whose type comes from its own `/** @var Foo */`
  docblock is fine; only the backing-less virtual form is affected. Pinned by the `$virtualOnly`
  row of `declared-parameters.latte` and by `PropertyTypeResolverTest`.
- **A typed `{var T $x = expr}` / `{default T $x = expr}` anchor is not expression-type-checked.**
  Placement (*Mid-file `{varType}` placement*) still applies, but `DeclarationInjector` rewrites a
  typed one into a static-property carrier (`self::$prop_N_x = expr; $x = self::$prop_N_x;`), so the
  assignment `orisai.nette.latte.varTypeNativeType`/`orisai.nette.latte.varTypeType` would read is no longer the author's
  expression. Declaring the same variable twice (once with the tag's own type prefix, once with a
  mid-file `{varType}`) is the only shape affected; the typed form's own `expr`-against-`T` check
  still runs, so nothing goes unchecked — only the redundant second declaration does.
- **A `{capture}` anchor is not expression-type-checked either.** It is a valid placement anchor,
  but the captured value is produced by output buffering, which this pipeline models as a fixed
  runtime helper call rather than the template's real content — comparing a `{varType}` against the
  model's return type would say nothing about the template.
- **Include-site literal arguments widen to their general kind** in the declaration-derived
  pathway (`x: 'abc'` types as `string`, not `'abc'`) — a safe, never-suppressing widening; the
  narrowing store pathway carries precise literals where captures exist.
- **`(expand)`-splatted include arguments contribute `mixed`** to the target's context — they
  aren't destructured statically.
- **`{embed file}` is modeled as an include-style union, but every supported Latte line isolates it** to the
  tag's own explicit args (*Include isolation* above) — an over-approximation of the
  embed target's scope that can only miss findings, never invent them. It under-reports only for a
  project that uses the tag.
- **Dynamic include/extends targets are reported, not followed** (`orisai.nette.latte.dynamicInclude` /
  `orisai.nette.latte.dynamicExtends`); the target file is never analyzed for that edge.
- **Filter availability from the engine harvest is global, not per-context.** A filter/function
  discovered via `CustomsHarvester` (*Custom filters, functions and macros* above) is treated as
  available to every template, regardless of which presenter/control actually wires it at runtime.
  Per-template customs (`{templateType}`'s own methods, same section) are the one channel that IS
  scoped per context.
- **Per-template customs are scoped to the declaring template only, by deliberate, strict design —
  see *Per-template customs* above for the full runtime-leak rationale and the documented
  recommendation for new code.**
- **Imperative render-time registration
  (`$template->addFilter(...)`/`addFunction()`, `$compiler->addFilter(...)`/`addMacro()` called
  from presenter/control PHP code, as opposed to construction-time engine wiring) is not modeled —
  phase 3 territory (needs call-site analysis of the PHP side).** These names still report
  `orisai.nette.latte.unknownFilter`/`orisai.nette.latte.unknownMacro` as unknown, same as any other unrecognized
  name; only their *typing* is unavailable, not their *reporting*. Their runtime behavior carries
  the same order-dependence landmine as the per-template leak above — a filter registered
  imperatively is available only from the point it's registered onward, in whatever order presenters
  happen to render, which is exactly why phase 3 (not this phase) is where a sound model belongs.
- **The case-mismatch scanner does not see `n:attribute` expressions.** `CaseMismatchScanner` only
  tokenizes `Latte\Token::MACRO_TAG` text; a filter/function name written exclusively inside an
  `n:attribute` value (e.g. `n:if="$x|upper"`) never reaches it — accepted, documented gap.
- **`n:attr` bodies are invisible to fact-extraction depth tracking.** `TemplateFactExtractor`
  tracks nesting depth from `MACRO_TAG` tokens only; `n:if`/`n:foreach`/... n:attribute pairs never
  emit those tokens (only `HTML_ATTRIBUTE_BEGIN`/`END`), so a `{var}`/`{default}` inside an
  `<div n:if>` body is counted as top-level, same as if it were outside the div — and a `{varType}`
  inside an n:attributed element within a block body likewise counts as body-top-level, i.e.
  contract-bearing. Affects only fact extraction (what gets exported for cross-file and block
  contracts — including the macro line map that feeds provenance tips, so code generated by an
  `n:attribute` macro never gets a macro provenance tip either), not analysis inside the file
  itself.
- **A same-file, fully param-less block's body `{varType}` gets no missing-variable checking.**
  Such a block is also reachable through real Latte's universal `get_defined_vars()` +
  `extract($ʟ_args)` prolog — ambient loop/conditional locals the includer never formally declares
  — which this pipeline's static reconstruction cannot see. Mismatch-checking stays fully live
  whenever the name is passed as an explicit arg (that visibility channel is independent of
  same-file-ness); only the missing-variable dimension is exempted, and only for a block with zero
  own params (an own param never gets this fallback at all, so it's never exempted). Example: a
  param-less `{define item}` block included inside `{foreach $items as $c}` reads the ambient `$c`.

### PHP-side bridge (render facts and pairing)

SP1/SP2 are the bridge's fact-extraction and pairing stages (*`dumpLatteRenderFacts()`* and
*Template-class pairing* above); SP3 is template-file discovery (*Template-file discovery* above,
with its own limitations below). From the pairing design spec:

- **Verdicts are class-scoped; a template file rendered by multiple classes has no merged view
  until SP3 links files.**
- **Intra-channel drift detection sees what the walk sees (bounded traversal — the SP1 walk's
  documented boundary); cross-class flows stay out.**
- **Per-site pairings record only an explicit `::class` argument. A bare factory call carries
  no per-site pairing at all — the container default surfaces only through the class-level
  `factoryDefault` rung of the ladder, resolved as a VALUE (the C1 mechanism) — and a per-site
  dynamic class argument is likewise not recorded, not even as opaque.**

And the implementation-ledgered additions:

- **Assign-form vs return-form `X::create()` asymmetry.** `$template = X::create()` records a
  `factoryStatic` per-site pairing; `return X::create()` outside a convention hook is invisible
  to per-site extraction. Since per-site pairings never enter conflict judging, the asymmetry
  can never mint a wrong conflict — the cost is only a missing per-site line in the dump.
- **Runtime×runtime disagreement is not a conflict kind.** A class carrying both a convention
  hook and an own `createTemplate()` override that name different classes raises no conflict:
  the model compares declaration↔runtime and within one channel only. The primary
  deterministically prefers the convention hook in that (rare) coexistence.
- **Candidate observation sites are line numbers without files.** Conflict/opaque anchors and
  dump site lines assume the entry class's own file; a candidate observed in a cross-file
  helper (an app-root ancestor's method) may anchor its line in the wrong file's numbering.

### Template-file discovery

- **Inherited sites are anchored by line number, not by file identity** — one SP4 item with two
  halves. An opaque discovery entry carries `{reason, line}` with no file: the rule corroborates
  the line against the analysed file's *own* `setFile` lines and falls back to the class
  declaration when it does not match, which is a heuristic — two classes in an inheritance chain
  whose `setFile` calls happen to share a line number would corroborate each other. `MutationFact` has no site file at all, so an
  ineffective `setView()`/`changeAction()` inherited from an ancestor would report at the
  ancestor's line number *in the descendant's file*. The fix for both
  halves is the same: carry `site: {file, line}` on `MutationFact` and on `DiscoveryFact`'s opaque
  entries (and bump the facts `FORMAT_VERSION`), replacing the heuristic with exact identity.
- **Abstract renderers that contribute a renderable site of their own are still diagnosed, with
  low actionability.** An abstract class never runs — Nette's `PresenterFactory` rejects an
  abstract presenter outright and no control can be instantiated — so a class with neither an own
  `setFile` nor an own view site is skipped entirely (its concrete descendants report on their own
  account). One with an own site is *not* skipped, and its finding is a true positive that the
  reader can only act on in the descendants: an abstract base whose `createTemplate()` writes a
  dynamic path really is an opaque channel, but "fix it" means fixing every concrete class below
  it. No blanket `isAbstract()` skip: most abstract presenters reverse-map cleanly and stay silent
  anyway, and an abstract control base that does report is meaningful.
- **An open view set is not diagnosed anywhere.** A dynamic `setView($x)` makes every per-view
  check skip that class silently — visible only through `dumpLatteDiscovery()`'s `open view set:
  yes` line. A class that opens its view set usually also has an opaque channel which is reported;
  one that opens it *without* any other opaque channel goes completely unremarked.
- **`orisai.nette.latte.orphanTemplate` is advisory and must never be auto-fixable.** dead-code-detector may
  auto-fix because its assumptions over-approximate *usage* (a call on `mixed` marks every
  same-named method used), which makes deletion conservative. This check has the opposite bias —
  it *under*-detects usage (opaque `setFile` arguments, dynamic includes, unassigned locators,
  channels not modelled at all) — so "we failed to detect the usage" and "certainly dead" are not
  distinguishable, and a fixer acting on it would delete live files. That is not theoretical: an
  earlier revision reported templates as dead while they were rendered in production through a
  setter variant the model did not yet follow. Structurally enforced —
  the rule carries no fix payload and `LatteFixStrippingRule` drops any that appeared — but treat
  every orphan finding as a lead to verify, never as a deletion order.
- **A `{templateType}` on an *include-only* file is never compared with the template class its
  includers are rendered with.** Its *variable* contract is checked on every include edge, as for
  any declaration (*Declarations are a checked contract* above — the declared class's public
  properties are exactly the declared variable set). What is not checked is the *class* half:
  `orisai.nette.latte.templateTypeMismatch` compares a declaration against the verdicts of the renderers the
  discovery store links to that file, and a partial no renderer renders has none, so a partial
  declaring `{templateType C}` whose only includer is rendered with an unrelated `D` passes
  silently even though it reads members `D` need not have. The includer→included template-class
  contract is a backlog item; both halves of the include edge are checked today only at variable
  level.
- **`orisai.nette.latte.includeIsolation` has no detection power over undeclared include targets.** The whole
  isolation worklist is visible as `variable.undefined` inside the targets, not through the flag,
  because `orisai.nette.latte.includeMissingVariable` fires only when a *target declares* the variable
  (*Include isolation* above has the derivation). Re-run the flag after each declaration
  wave; its findings grow exactly as `{varType}`/`{parameters}` coverage on include targets grows.
- **`{embed}` is modelled as a union although every supported Latte line isolates it** — the pre-existing
  entry above; a real over-approximation for a project that uses the tag.
- **A file reached from PHP *and* via layout stays ambiguous.** A discovery edge carries no
  variables (marker-only, by design), but a layout edge carries the child's finished main scope —
  its top-level `{var}`s included. A template that is both rendered directly by a renderer and
  reached as a layout therefore analyses under the union of a variable-less context and
  variable-carrying ones, and nothing in the model distinguishes which variables really exist at
  render time. Root-context provisioning across the PHP boundary is the scope that closes this.
- **Templates outside `orisai.nette.latte.firstPartyPaths` are analysed but never orphan-checked.** The
  reachability check is scoped by `orisai.nette.latte.firstPartyPaths` (default `%paths%`), so templates
  outside it — analysed, edge-indexed and type-checked like any other — are structurally outside the
  orphan verdict. This is the same
  boundary that keeps test fixtures out; widening it means widening the first-party root, with
  everything else that follows from it.
- **Two opaque channels remain by construction**: a `setFile()` argument that is not a static
  literal or a recognized convention call, and a presenter class whose name does not reverse-map
  through the application mapping (the round-trip guard refuses a lossy inverse rather than invent
  a name). Both are reported (`orisai.nette.latte.fileDiscoveryOpaque`), never guessed, and both close the
  per-view checks for their class rather than answering from an incomplete candidate set.
- **Multi-renderer templates are checked per renderer.** A template linked to several renderer
  classes is compared against each verdict separately; there is no merged cross-renderer view, so
  a conflict *between* two renderers of the same file surfaces only as two independent findings.
- **Layout discovery models the vendor walk only.** An override of `formatLayoutTemplateFiles()`
  would need its own vocabulary entry (`vendor-layout-walk` is the only
  applicable name today) or its layout channel goes opaque.
- **A dynamic layout argument suppresses the auto-layout edges and the runtime does not.** A
  `{layout $x}`/`{extends $x}` counts as a declared layout for `TemplateEdgeIndex::autoLayoutApplies()`,
  so no auto-layout edge attaches to that template — the owner's amendment, routing a non-literal
  argument through the same OPEN handling `dynamicExtends` already gets. Vendor disagrees, and the
  parity probe pins vendor's side deliberately
  (`LayoutSuppressionParityTest::testDynamicLayoutResolvingToNullStillFallsThroughToTheFinder()`): an
  `$x` evaluating to `null` leaves `$parentName` null and `UIRuntime::initialize()` runs the layout
  walk anyway. This is the one layout rule that is a **known divergence rather than parity**, and the
  model under-approximates: a layout reachable only through such a template loses that incoming edge,
  which can add an `orisai.nette.latte.orphanTemplate` lead but never silence one. In practice the layouts
  the walk would reach are usually live roots on their own store records regardless of the edge.
- **The SP2 carries still apply**: a return-form `X::create()` outside a convention hook records no
  per-site pairing (assign-form does), and vendor suffix-magic `formatTemplateClass()` naming is
  under-detected — both documented under *PHP-side bridge* above, both able only to omit a line,
  never to invent one.

## Operational notes

- **Naming a `.latte` file directly on the command line re-enters the parser.** PHPStan builds reflection
  source locators per analysed path: a directory gets an
  `OptimizedDirectorySourceLocator`, whose class→file map comes from tokenizing raw file text — no
  raw template declares a class, so a directory-shaped run never asks the parser about a template at
  all. A *file* gets an `OptimizedSingleFileSourceLocator`, which answers **every** identifier
  lookup by fetching that one file's nodes, and which latches its symbol set only once that fetch
  returns. Parsing a template asks the `ReflectionProvider` about classes — `PairingJudge` checks
  whether a recorded renderer's paired template class exists — so the fetch re-enters
  `LatteRoutingParser::parseFile()` for the file it is already parsing, which asks again, without
  bound. PHP 7.4 has no stack-limit detection (`zend.max_allowed_stack_size` is 8.3+), so unguarded
  re-entry overflows the C stack and kills the process with `Segmentation fault (core dumped)`, exit
  139, and no output whatsoever — before the first analysed file is reported. Only a single-file run
  such as `phpstan analyse templates/@layout.latte` can trigger it, and only for a template some
  renderer records (otherwise there is nothing to reflect). `LatteRoutingParser` guards re-entry per
  file and answers the nested fetch with the compiled
  class alone — no contexts, no injected declarations, no edge constants — which is exactly the
  question asked (*which symbols does this file export*) and nothing more. Answering `[]` instead
  terminates too but is observably wrong: the locator would latch an empty symbol set and PHPStan
  would abort the file with *Class LatteTpl_… was not found while trying to analyse it*. Nothing
  leaks across runs — `LatteReflectionCacheBypass` drops every `osfsl-*` cache key holding a
  `.latte` path — and once the outer parse returns, the parser's own memo serves every later lookup
  the full AST. Pinned by `SingleFileReentrancySpawnTest`, which asserts on the child's **signal**:
  a segfaulted child produces no output to assert on.
- **Enabling Latte analysis on an existing project** changes more than the template findings. A
  dead-code detector (shipmonk's included) sees template-side usage of PHP methods only while
  `.latte` files are analysed, so switching the flag off after a baseline was generated with it on
  reports every method called only from templates as dead. Expect the first baseline to be
  dominated by `variable.undefined` from templates with no declarations at all; declaring a
  template's type removes these incrementally (*Typing templates* above). The cold run costs
  roughly twice a PHP-only run, and each parallel worker holds its own compiled templates.
- **Determinism (amended for narrowing)**: compilation is a pure function of (template content,
  macro set, harvested-customs state, edge-index content, narrowing store content) — with the
  narrowing store held fixed, cold, warm, and no-cache runs produce identical output, checked by a
  dedicated determinism test over the fixture corpus (parallelism-independence follows from the
  store's single end-of-run writer and canonical serialization, not from a parallelism-varying
  test; the harvest is per-process-memoized, proven byte-identical across two harvests of the same
  state). PHPStan's persistent reflection caches key `.latte` entries by file content hash alone,
  while compiled templates also depend on neighbor-derived contexts, so `LatteReflectionCacheBypass`
  keeps `.latte` entries out of `cacheStorage`; without it a cross-invocation stale reflection is
  possible.
  The store itself is not an input held
  constant across a *regeneration* sequence: it is a fixpoint reached by repeated plain
  analysis runs (see *Call-site narrowing* above), and re-running at the committed
  fixpoint changes nothing — the store stays byte-identical, which a consumer's CI freshness gate
  enforces. Before narrowing existed, determinism meant cold == warm unconditionally; that
  weaker claim now holds only once the store is fixed, which is exactly what the committed,
  gated store guarantees in practice.
- **All four store-consuming diagnostics are answered at the AGGREGATE stage**, from PHPStan's
  merged collected data (`LatteTemplateGraphRule`, a `Rule<CollectedDataNode>` the finalizer runs
  after the result cache has been restored *and* saved), never from a template's own parse. That is
  an invalidation requirement, not a structuring preference. Each of the four reads a fact derived
  from files other than the template it reports on, through a channel PHPStan's result cache does
  not carry: reachability and the missing-view host are whole-graph fixpoints, and the
  `{templateType}` checks compare the declaration against the renderer's *pairing verdict* — whose
  primary channel is a `return X::class;` inside a **method body** — and against the paired class's
  own hierarchy. A body-only edit leaves every signature byte-identical, so
  `ResultCacheManager::exportedNodesChanged()` returns null and the dependent-files loop is never
  reached; the paired class is not a dependency of the template at all; and neither fact moves a
  discovery record, so the store file's `RECORDS_HASH` stays identical and the one-run-later
  recovery below never fires. Answered per-file (as `templateTypeMismatch`/`templateTypeRequired`
  were until this was fixed), the finding went permanently stale in **both** directions — a missing
  mismatch after the edit, a phantom one naming a class the renderer no longer pairs after the
  revert, both surviving repeated warm runs at *0 files will be reanalysed*. The aggregate placement
  needs no cache salt and no invalidation edge, so it costs the store's propagation granularity
  nothing. Pinned by a 24-scenario cold-vs-warm matrix
  (`tests/Integration/Latte/Invalidation/`) covering file-level events, signature-level
  edits, body-only edits at every hop distance, and inverse controls that fail if invalidation is
  ever made coarse to buy the agreement.
- **Result-cache invalidation across include edges** uses three complementary mechanisms: PHPStan's
  own per-file content hash (an edited `.latte` always re-analyzes itself); an exported edge
  fingerprint constant on each compiled class, so a change to a file's outgoing sites or
  cross-file-consumed facts propagates to its dependents; and a topology-salted
  `ResultCacheMetaExtension` that closes the one gap the other two miss — a *brand-new* include of
  an otherwise-unchanged file. That same meta extension also carries the global harvest salt (see
  *Custom filters, functions and macros* above) — both salts invalidate coarsely, the whole `.latte`
  surface at once, on the same accepted-cost basis. Topology changes are rare enough that
  invalidating the whole result cache on them is the accepted cost; content-only edits stay
  fine-grained. PHPStan's own
  propagation from a changed dependency to its dependents is **single-hop**: it fires only for files
  whose OWN content hash genuinely changed, not recursively for files that merely got swept along as
  a dependent-of-a-dependent. `{templateType C}` support therefore gives EVERY `.latte` file a direct
  dependency edge to every `C` reachable ANYWHERE in its own transitive outgoing include graph
  (`LatteRoutingParser::reachableTemplateTypeClasses()` walks `{include}`/`{layout}`/`{extends}`/
  `{embed}`/`{import}` targets recursively, cycle-safe, feeding
  `DependencyEdgeEmitter::emitTemplateTypeRef()`) — not just a class the file declares itself — and
  folds the UNION of every reachable `C`'s resolved public-property shape into the file's own
  fingerprint value (`EdgeFingerprint::compute()`'s `$templateTypeVars`). A property/method/docblock
  edit to `C` reaches every file that transitively reaches it, at any include depth. Verified
  empirically both ways: `TemplateTypeCustomsInvalidationTest` (same-file case) and
  `TemplateTypeCustomsTransitiveInvalidationTest` (A includes B, B declares `{templateType C}`, A
  never touches C's file directly — proven RED against the direct-edge-only fix, GREEN once the
  transitive walk was added).

## Outlook

- **Phase 2 — done.** Custom filters, functions, and macros are discovered statically: global
  registrations via a real, harvested Latte engine (the application's compiled DI container through
  `orisai.nette.dic.containerLoader`, or `orisai.nette.latte.engineLoader` for pure `latte/latte`), per-template registrations
  via Latte's own `{templateType}` + `processParams()` mechanism. See *Custom filters, functions
  and macros* above. Imperative render-time registration (`addFilter()`/`addFunction()`/
  `addMacro()` called from presenter/control PHP code) stays phase-3 territory, below.
- **Phase 3** — presenter/control render analysis (`$template->x` assignments, `render`/`setFile`
  resolution) to provide real root contexts across the PHP↔Latte boundary, add the convention
  edges that lift the `orisai.nette.latte.unknownBlock` suppression above, and enable sound modeling of
  imperative filter/function/macro registration (today such names report as unknown, see *Known
  limitations*).
  Three slices are shipped: per-class render-fact extraction (*`dumpLatteRenderFacts()`* above),
  template-class pairing (*Template-class pairing* above) and template-file discovery
  (*Template-file discovery* above — convention edges now exist for every linked template, which
  is what lifted the `orisai.nette.latte.unknownBlock` suppression there). What remains is the variable payload:
  root contexts carrying what a renderer actually assigns to `$template->x` across the boundary,
  plus the component-tree contexts that would make those contexts per-render-site.

## Bootstrap files and the parse-time `{templateType}` lookup

`{templateType X}` is resolved with a runtime `class_exists()` while the template is *parsed*
(`DeclarationInjector`, `DeclaredVarsResolver`, `LatteRoutingParser::templateTypeVars()`). PHPStan
2.2 defers `bootstrapFiles` to right before the analysis, but `ResultCacheManager::restore()` parses
every changed file first to diff exported nodes — for a changed `.latte` that is the whole pipeline,
before any bootstrap-registered autoloader exists. The memoised result (this process, or a worker
forked from it) then reports `orisai.nette.latte.unknownType` where a cold run does not.

`BootstrapFilesLoader` closes that gap: the routing parser runs `%bootstrapFiles%` (with PHPStan's
`$container` in scope, exactly like `CommandHelper::executeBootstrapFile()`) before the first
`.latte` parse in a process and publishes their autoloaders through
`BootstrapFilesRunner::mergeNewAutoloadFunctions()`; PHPStan's own later run of the same files is a
`require_once` no-op. Consumer-visible consequence: whenever a `.latte` is parsed in the main
process before the fork — every warm run with a changed template, and any pre-fork `LatteTpl_*`
reflection — the resources a bootstrap file opens (database connections, sockets) are inherited by
the forked workers again, which is what PHPStan's deferral was avoiding. Without the runner's
publishing hook the loader does nothing and leaves the files to PHPStan. Long-term direction:
resolve `{templateType}` class existence at analysis time (through the reflection provider) rather
than at parse time, which makes the early load unnecessary.
