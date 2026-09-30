Maintainer documentation; the user-facing guide is ../README.md.

# Latte + Forms bridge (control names in templates)

`src/LatteForms/` is the one area spanning both the Forms and the Latte extension. It answers two
questions about every control a template names: **does it exist on the form that template renders**,
and — once it does — **is the macro naming it one that component can answer?**

```latte
{form userForm}
	<input n:name="email">      {* fine - the builder adds it *}
	<input n:name="e-mail">     {* Control 'e-mail' does not exist on form 'userForm' (UserControl). *}
	{input address}             {* Component 'address' on form 'userForm' is a container, not a control (UserControl). *}
	{label save}                {* Control 'save' on form 'userForm' is a ...\SubmitButton, which renders no label (UserControl). *}
{/form}
```

Nothing about it is new machinery: the template side reads the Latte extension's discovery store
(*Template-file discovery* in [latte.md](latte.md#template-file-discovery)) for the renderer classes linked
to a `.latte` file, and the form side reads the Forms extension's shape resolver
([forms.md](forms.md)) for what those classes build. The bridge is
the join, and it is a separate area: it depends on both extensions, neither depends on it, and
both stay independently extractable. The form-macro token scan itself lives with the Latte version
adapters (`Latte\Version\Latte2\FormSiteScanner`, value objects `Latte\Forms\FormSite` and
`ControlReference`), because it is written against a Latte version's own tokens; the bridge's
`FormMacroCollector` reads it as `ExtractedFacts::getFormSites()`.

The check is **one-sided by construction**. It reports a name only when it can prove the name is
absent; every situation it cannot resolve is silent. Read *Silence, and what it does not mean*
below before you draw any conclusion from a clean run.

## Enabling it

The bridge has no flag of its own. `ConfigurationGuard::isBridgeEnabled()` answers
`orisai.nette.forms.enabled && orisai.nette.latte.enabled`, and both `LatteFormsRule` and
`FormMacroTypeResolver` additionally require `isLatteDiscoveryEnabled()`:

| Parameter | Library default | Why the bridge needs it |
|---|---|---|
| `orisai.nette.forms.enabled` | `true` | the shapes the join reads come from the Forms index |
| `orisai.nette.latte.enabled` | `false` | without it no `.latte` file is analysed, so there is no reportable template set |
| `orisai.nette.latte.discovery.enabled` | `true` | the store holds the template → renderer links the join starts from |

```neon
parameters:
	fileExtensions: [php, latte]
	orisai:
		nette:
			latte:
				enabled: true
```

Both services are registered either way — the aggregate-rule discipline the Latte extension keeps —
but return before they read the store, scan a template or resolve a shape, so analysis output with
any of the three off is byte-identical to the bridge being absent.

## The five diagnostics

| Identifier | Meaning | Reported at | Example message |
|---|---|---|---|
| `orisaiNette.latteForms.unknownControl` | the referenced control is absent from **every** linked renderer's form | the reference's own `.latte` line | `Control 'nope' does not exist on form 'simpleForm' (X).` |
| `orisaiNette.latteForms.unknownForm` | `{form X}` names a component every linked renderer resolved, and none of them resolved to a form | the `{form}` opener's line | `Component 'grid' is not a form (X).` |
| `orisaiNette.latteForms.containerAsControl` | `{input}` / `{label}` / `{inputError}` / `n:name` names a **container** (or a replicator) | the reference's line | `Component 'address' on form 'userForm' is a container, not a control (X).` |
| `orisaiNette.latteForms.controlAsContainer` | `{formContainer}` / `n:formContainer` names a **control** | the reference's line | `Component 'email' on form 'userForm' is a control, not a container (X).` |
| `orisaiNette.latteForms.labellessControl` | `{label}` / `n:label` names a control whose `getLabel()` is vendor's label-bypassing one | the reference's line | `Control 'save' on form 'userForm' is a Nette\Forms\Controls\SubmitButton, which renders no label (X).` |

All five are ordinary, ignorable and baselinable, and all are reported on the `.latte` file and line —
never on the PHP builder. `X` is the linked renderer class list, sorted and comma-separated; a
template rendered by three classes names all three.

The last three are the **type-aware** half, and they check a different property under a different
gate: see *Is the macro right for the component* below.

A nested reference prints its full path with dots:
`Control 'address.street' does not exist on form 'userForm' (UserControl).`

`unknownForm` says *is not a form*, never *does not exist*. A component the analyser could not
resolve is a question it could not answer, not a missing component — see *One unresolvable renderer
silences the whole site* below.

## What counts as a form scope, and what counts as a reference

**Openers** — `{form X}`, `{formContext X}` and `<form n:name="X">` all open a form scope. `n:name`
on any other element is a control *reference*, not an opener; the two are told apart by tag.

**References** — `{input X}`, `{label X}` / `n:label="X"`, `{inputError X}`, `<el n:name="X">` and
`{formContainer X}` / `n:formContainer="X"`. All of them are the same runtime read
(`Container::getComponent()`), so all of them are checked the same way.

**Nesting composes, and it composes the way vendor does.** A reference's full path is the lexical
`{formContainer}` chain it sits inside, followed by the `-` segments Nette's own
`Container::getComponent()` explodes out of the macro argument. `{formContainer address}{input
address-street}` therefore resolves `address` → `address` → `street`, exactly as at runtime — there
is no de-duplication between the two sources.

**Skip, never guess**, at the granularity where the uncertainty lives:

- `{form $var}`, `{formContainer $var}`, `{input $var}` — that scope or that one reference is
  skipped; its siblings are still checked.
- A template linked to no renderer is skipped.
- A template no renderer resolves a form for is skipped.

## Silence, and what it does not mean

This is the section to read before treating a clean run as a clean codebase. **Three properties of
the design are honest limits, not bugs:**

**1. One unresolvable renderer silences the WHOLE site, not merely its own share.** Both identifiers
require that *every* linked renderer resolved the named component. If a template is linked to three
renderer classes and one of them does not resolve `userForm` — the builder is a `createComponent*`
the walk cannot follow, the component is added by a parent, the class does not exist any more — then
the site is skipped entirely. You lose the checking the two renderers that *did* resolve would have
given, not just the third's share. This is deliberate: that renderer may render this template with a
form of its own, so the forms that did resolve are a subset, and a name absent from a subset is
absent from nothing.

**2. Silence is indistinguishable from clean.** There is no "this site was skipped" signal, no
counter, no advisory identifier. A template with no findings may be fully checked and correct, or it
may be a template the join gave up on at the first hop. Nothing in the output tells the two apart.
If you need to know which one you are looking at, the answer has to come from
`dumpLatteDiscovery()` (are the links there?) and the Forms extension's own shape dumps (is the form
closed?), not from the absence of an error.

**3. A wrong discovery-store entry costs detection, silently.** The derived store
(`orisai.nette.latte.discovery.storePath`) links templates to renderer classes. An entry naming a class that
no longer exists, or a link that should never have been written, makes that class unresolvable —
which by rule 1 silences the whole site rather than mis-reporting it. That is the right direction to
fail in, but it means a bad link shows up as *lost checking*, never as a wrong finding, and never as
a message. Store DRIFT is no longer among the ways to get one: the store is rebuilt from the
configured universe before every run's first parse, so it can never be older than the sources it
describes.

### The multi-renderer rule

A name is reported only when it is absent from **every** linked renderer's form. A name valid in at
least one linked form is a legitimate shared-partial pattern the analysis cannot distinguish from a
bug, so it is silent.

The consequence is worth stating plainly: **a shared partial rendered by many forms loses detection
precisely where a rename is most likely to break one path.** Rename `email` to `mail` in one of five
forms that render the same partial, and the reference stays valid in the other four, so nothing is
reported. That is the accepted cost of never reporting a correct shared partial.

### The certainty gate

The bridge reports "absent" only against a form whose shape is **CLOSED** — one where the Forms
analyser enumerated *every* component the builders add. A form is not closed, and is therefore not
checked at all, when any of these hold:

- **Any build step could not be enumerated.** A component added under a computed name, a builder
  call the walk could not follow, an extension `add*` method, a container handed off by reference, a
  rebind it could not prove — each is recorded as an unknown reason, and any one of them means the
  set of names is not known to be complete.
- **The component may be mutated from outside its builder** — see *The mutation surface* below.
- **The shape holds nothing the analyser modelled.** `$form->addComponent($this->factory->create(),
  'phone')` records a container with an empty interior and no unknown reason attached; emptiness is
  the only evidence that nothing was ever walked, so an empty shape is never a closed one.

The gate is re-applied at **every hop** of a nested path, and completeness accumulates downwards: an
open root can hide components of any child below it, so a reference inside a closed container under
an open root is still unresolved. Membership works the other way — a name an *incomplete* shape does
list really is there, so such a reference is silent, never reported.

**A conditionally-built form is not checked. A conditionally-added field is not a finding.** These
are two different things and the distinction matters in practice:

```php
// conditionally ADDED field - the name IS in the shape, the reference is silent
if ($this->user->isAdmin()) {
	$form->addCheckbox('promote');
}

// conditionally BUILT form - the walk cannot enumerate the names, the whole form is skipped
foreach ($this->fieldNames as $name) {
	$form->addText($name);
}
```

Presence and name-knowledge are independent axes. The first form is fully checked and `{input
promote}` resolves as present; the second is not checked at all and every reference in it is silent.

One asymmetry the example does not show: the same conditionality applied to a **container** is not
free. A container slot the analyser only knows *may* be there answers "unresolved" for the whole hop,
so every reference below it — `{formContainer address}{input street}` — goes silent, not just the
container's own name. A conditionally added *field* costs nothing; a conditionally added *container*
costs everything inside it.

**The reach cliff worth knowing about.** A form built as a bare `new Nette\Application\UI\Form()`
raises the `constructor_build` marker (vendor's constructor calls `monitor()`), which opens the shape
and leaves it unchecked. A form subclass which declares no constructor of its own (like the test
double `Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm`) never raises it — but a project
whose forms are plain `Nette\Application\UI\Form` gets **zero** closed forms and, with them, zero
checking.

## Is the macro right for the component

Three diagnostics, one question: the name resolves, but can the component it names answer the macro
that named it? Every verdict is read off **vendor's own compiled output**, which is what makes each
of them definite rather than stylistic. The three generations of the bridge — Latte 2 `FormMacros`,
Latte 3 `FormsExtension` with nette/forms 3.1.7–3.2 and nette/forms 3.3's `FormsExtension` — differ
only in how they reach the control: `<X>` below is `end($formsStack)[X]`,
`Runtime::item(X, $this->global)` or `$this->global->forms->get(X)` respectively, and the pipeline
reduces all three to the same `Helpers::formField(X)`:

| Macro | compiles to | so it needs |
|---|---|---|
| `{input X}` | `<X>->getControl()` (`->getControlPart(…)` with a `:`-part; with attributes `->addAttributes([…])` follows and the control read reduces to `Helpers::formInput(X[, part])`) | a control |
| `{label X}` / `n:label` | `if ($l = <X>->getLabel()) echo $l` (Latte 3: `($ʟ_label = <X>->getLabel())?->startTag()`; a paired label reduces to `$latteLabel = Helpers::formLabel(X)`) | a control **that renders a label** |
| `{inputError X}` | `<X>->getError()` | a control |
| `<el n:name="X">` | `<X>->getControlPart()->attributes()` | a control |
| `{formContainer X}` / `n:formContainer` | pushes X on `$formsStack` (3.3: `forms->begin(forms->get(X, Container::class))`); every reference under it offsets it | a container |

`Nette\Forms\Container` declares none of those four control methods, and its `__call()` falls through
to `Nette\SmartObject`'s strict one — so a control macro on a container is a runtime error, not a
no-op. In the other direction `Nette\Forms\Controls\BaseControl` is no `ArrayAccess`, so the first
reference inside a `{formContainer}` that names a control fatals; a `{formContainer}` body with no
reference in it is inert markup, and is reported anyway, because scoping inner references is the only
thing the macro does.

**The labelless set is exactly two vendor hierarchies**, and it is a `getLabel()` question rather than
an ancestry one. `Nette\Forms\Controls\Button::getLabel()` and
`Nette\Forms\Controls\HiddenField::getLabel()` are overrides whose whole body is `return null`,
commented *"Bypasses label generation"* — so `{label send}` on a submit button renders **nothing at
all** (`<label n:name="send">` is worse: it calls `->attributes()` on that null and fatals, but the
collector does not record the host tag, so that spelling is not checked — see *Known limitations*).
Their subclasses inherit it, `SubmitButton`, `ImageButton` and `CsrfProtection` included, and an
application's own `CustomSubmitButton extends SubmitButton` with them. A subclass that **overrides `getLabel()`
again** has its label back and is never reported: the check asks which class *declares* the method,
not which classes it descends from. `Checkbox`, `RadioList` and `CheckboxList` declare their own and
are never in the set.

### The identity gate — a different gate from the certainty one

The absence check needs a **complete name set**: one unenumerated build step and a missing name proves
nothing. The type checks need something weaker and different — that a name the shape *does* list still
means what it says. No amount of unenumerated **adding** can change that, because
`Container::addComponent()` throws on a duplicate name, so a build step the walk could not read cannot
rebind a name the walk did read. What *can* is a step that **lost** one, and the Forms extension
already names that set: `UnknownReason::LOST_FIELD_UNKNOWN_REASONS` (a container pulled out of the
form, a constructor-built form, an aliased form, a first-class callable, a dynamic method, an
unattributable removal, an unproven rebind), plus the unresolved-origin reason its own does-not-exist
rule adds inline — together with the **external mutation** the same index answers, because a
`removeComponent()` reaching in from outside the builder is a rebind like any other.

Everything else is unchanged and shared with the absence check: a hop through a container that is not
definitely attached resolves nothing below it, a name two branches attached *differently* (a control
on one path, a container on the other) has no knowable kind, an UNRESOLVED identity on **any** linked
renderer blocks the whole reference, a mismatch must be the **same** mismatch on every linked
renderer, and a class the analyser did not record — or recorded only as an interface, or as something
that could still be a container — proves nothing. Guarded references (`n:ifset`) are exempt here too.

Two consequences worth stating:

- **A form too open to prove absence in still answers what its known components are.** A builder with
  a `$form->addText($dynamic)` in it can never report `{input nope}`, and still reports
  `{input someContainer}`. That is where most of the extra reach comes from.
- **Presence is not part of it.** A container the builder only *sometimes* attaches is still a
  container on every path that has it, and `{input}` can address it on none of them.

### Guarded references (`n:ifset`)

A reference wrapped in an existence check is never reported:

```latte
{form uploadForm}
	<input n:name="files_submit" n:ifset="$form['files_submit']">
{/form}
```

`offsetExists()` throws for nobody, so this is deliberate defensive markup, and reporting it would
be noise. `n:ifset="$form['x']"` and `{ifset $form['x']}` both count. Three rules:

- A guard covers a reference when its literal offset chain is a **suffix** of the reference's full
  path — `{ifset $form['x']}` covers `{input x}`, and inside `{formContainer c}` a check on
  `$x['street']` covers `c-street`. A guard naming a *different* component covers nothing.
- An expression that is not a variable-with-offset (`{ifset $var}`, `n:ifset="$item->x"`) is not a
  component check and guards nothing.
- A component check whose path cannot be read (`$form[$dyn]`, a compound condition) suppresses
  **everything it encloses** — the conservative fallback.

`n:ifset` marks the references of its own element retroactively as well as prospectively, because
Latte streams `n:name` and `n:ifset` in source order and either may come first.

## The mutation surface

The closed/absent verdict rests on one contract: a component whose interior can be changed from
outside its builder is **not** closed. The recording layer that decides this lives in the Forms
extension's registration index, and this is the positive statement of what it detects.

**A component is treated as mutated from outside its builder when:**

1. **A literal-named access is mutated.** `$this['form']->addText('x')`,
   `$ctrl['form']->addHidden('y')`, `$this['ctrl']['form']->addText('z')`, `getComponent('form')` in
   place of any offset — any tree-mutating call (`add*`, `addComponent`, `removeComponent`, offset
   assignment) on such an access.
2. **Through any number of direct local copies of such an access.** `$f = $ctrl['form']; $g = $f;
   $g->addHidden('late')` — the copy chain is closed transitively.
3. **Through a getter hop off such an access.** `$this['ctrl']->getForm()->addHidden('late')` names
   no component, so it is recorded as a **wildcard** mutation *owned by* the accessed control's
   class: every component of that class is a candidate, no other class is.
4. **By handing a `$this`-rooted access to a callee that provably registers on it.**
   `$this->decorate($this['ctrl']['form'])` counts when some `decorate()` in the analysed universe
   registers on its parameter 0, directly or by handing it on further (the closure resolves two-step
   helper chains and terminates on read-only self-recursion). The callee may be a class method or a
   project-declared **free function** — the key is a bare `name \0 paramIdx`, so both are looked up
   the same way. The callee's own side follows its parameter through **local copies**
   (`function d(Form $f) { $c = $f; $c->addHidden('late'); }` counts) exactly as the access side does.
5. **Inside a literal array argument.** `$this->registerAll([$this['ctrl']['form']])` is unwrapped
   element by element, still conditional on the same callee position — so a callee that registers on
   the array's elements (`$forms[0]->add*()`, `foreach ($forms as $f) $f->add*()`) counts, while an
   `addProvider('formsStack', [$this['form']])` call does not, because `Latte\Engine` is
   vendor and no body of it is ever read.
6. **By handing it to a PROPERTY invoked as a method.** `$this->onReload($this['dataGrid'])` where
   the class declares `$onReload` dispatches to whatever a caller subscribed, so it is recorded
   **unconditionally** — there is no callee body that could clear it.
7. **Keyed to a class, matching that class's whole ancestry line.** A fact keyed to a class opens the
   same component on its subclasses *and* on the ancestors that declare it — never on siblings.

An access the recording layer sees is what makes a shape **open**: `{input nope}` on it answers
"unresolved" rather than "does not exist". Everything below reads as *non-mutating*, which means a
name the builder does not list is reported **absent**.

### The residuals

All reachable, none theoretical. Each is a way to make a component's interior change without the
recording layer seeing it, which is the shape a false `does not exist` would take. Two earlier
entries are gone because they are now detected: a callee's local copy of its parameter, and an
event-property dispatch.

| # | Residual | Why | Avoid it by |
|---|---|---|---|
| R1 | A hand-over to a callee whose **body the fold never reads** — a `vendor/` method, a magic `__call` target, a method reached only through an inheritance chain the syntactic fold cannot resolve | The callee key is `name \0 paramIdx` and is decided against the bodies the analysed universe actually contains. No body ⇒ no `paramMutation` fact ⇒ the hand-over is dropped. This is the one place where "no fact" means "assumed clean", and it is deliberate: flipping it would make every hand-over into vendor code a mutation, which costs a large share of the closed pairs and closed templates on a real project. `Unit\LatteForms\FormPairingTest::testFormHandedToAnUnscannedCalleeKeepsItsGate` pins the decision | Register the component in a callee the analysis can read, or add the field in the builder |
| R2 | The **wildcard's owner** depends on `createComponent<Name>` having a declared return type that is provably a single `IComponent` | A missing type, a non-`IComponent` type or a union resolves to no owner, and a null owner matches **every** class — one such site can take an owner-scoped mechanism's precision to zero over whatever the analysis actually folds. This one costs REACH, not correctness: the failure is over-flagging, and an over-open shape answers "unresolved", which reports nothing | Declare a concrete component return type on every `createComponent*` |
| R3 | **Dynamic offsets** — `$form[$key]->addText('x')` — and an access **rooted in a local** passed as an argument (`$ctrl = $f->create(); $this->decorate($ctrl['form'])`) or hopped through a getter (`$ctrl->getForm()->addText()`) | Nothing at the site names a component or an owner; recording them would mean treating every ordinary array read as a component mutation, or a universal wildcard, i.e. zero reach | Use a literal name and root the access at `$this` |
| R4 | A **two-step array chain** — `registerAll(array $forms) { $this->doAll($forms); }`, where the registering call is one hop past the array parameter | The parameter side follows an array parameter's ELEMENTS (by offset, by `foreach`, through copies) but does not record the whole array being handed on: that would emit a fact for every `foo($arrayParam)` call in the project, a fold-volume cost with no known instance to justify it | Register on the elements in the callee that receives the array |

Vendor code mutating a component *directly* — not through a hand-over from project code — is the
pre-existing blind spot behind R1: nothing in `vendor/` is in the folded universe at all.

Closing the four residuals this list no longer carries was measured to cost **nothing**: closed
templates, closed pairs and present references were byte-identical before and after, down to the
sha1 of the closed-pair name set.

## Invalidation

The rule runs at PHPStan's **aggregate stage** (a `Rule<CollectedDataNode>`, the placement
`LatteTemplateGraphRule` uses and for the same reason): PHPStan runs those rules in
`AnalyserResultFinalizer` after the result cache is restored *and* saved, so the join against the
discovery store and the Forms index re-runs in full on every analysis, while the per-template macro
scan stays content-addressed on the template source.

**The guarantee, and it holds:** removing a control from a form builder's method body — no signature
change anywhere — makes the finding appear on the next warm run, identical to a cold one, on a
template file whose own bytes never moved. That is the cross-extension body-level edit that once went
silently stale in all three extensions, and it is pinned rather than assumed
(`tests/Integration/LatteForms/LatteFormsBridgeInvalidationTest.php`).

**What delivers it is the Forms extension's whole-cache salt, not this placement.** A builder-body
edit moves `FormFactSalt`'s digest, so the first run after it throws the whole result cache away
(`Result cache not used because the metadata do not match: metaExtensions`) and every file is
recomputed — confirmed by the control: with `FormsResultCacheMeta` switched off, so that it hashes a
constant instead, the identical edit restores the cache. The test fixture
`tests/Integration/LatteForms/Fixtures/invalidation-forms-salt-off.neon` does that by overriding
the `formsResultCacheMeta` service's `enabled` argument — the service name is internal, used only by
that fixture, and may change. Read the CI-time consequence from
[forms.md](forms.md#phpstans-result-cache), not from this page: an edit inside the
form-fact-bearing subset costs a full re-analysis.

**What the aggregate placement buys is the guarantee surviving without that salt.** Two cases, both
pinned:

- a **discovery-link change** — a `setFile()`/formula edit that re-points a renderer, leaving the
  Forms subset untouched. The Latte result-cache meta deliberately excludes per-template records, so
  the cache is restored and only the edited file re-analysed; the finding still follows the changed
  link on the warm run. (The store record's own exported constant carries that one too: the compiled
  template fetches it, and PHPStan re-queues a fetcher when a constant's value moves.)
- a **form-fact edit under a narrower salt.** `FormFactSalt` covers only the form-fact-bearing subset
  and only for as long as the Forms consumers are wired; a perf optimisation that narrows it would
  remove the whole-cache discard silently. The suite runs that configuration on purpose — the salt
  switched off, the bridge untouched — and asserts the body-level finding still appears warm with the
  cache RESTORED and exactly one file (the edited builder) re-analysed. That row is the one thing in
  the suite that fails if this rule ever leaves the aggregate stage; every other row passes from a
  per-file placement, because their edits either trip the Forms salt, move the template's own bytes,
  or move a store record.

The bridge adds **no** whole-cache salt and **no** new dependency edge of its own: a comment-only
edit to a form file re-analyses that file and nothing else. Both halves are asserted in the same
suite — the freshness rows and an inverse control that bounds the reanalysed-file count — because a
fix that bought freshness by invalidating coarsely would pass the first half alone.

## Error surface

All five identifiers are ordinary PHPStan errors: ignorable, baselinable, reported on `.latte` lines.

| Identifier | Reported at |
|---|---|
| `orisaiNette.latteForms.unknownControl` | the reference's line (`{input}`, `{label}` / `n:label`, `{inputError}`, `n:name`, `{formContainer}` / `n:formContainer`) |
| `orisaiNette.latteForms.unknownForm` | the `{form}` / `{formContext}` / `<form n:name>` opener's line |
| `orisaiNette.latteForms.containerAsControl` | the reference's line |
| `orisaiNette.latteForms.controlAsContainer` | the reference's line |
| `orisaiNette.latteForms.labellessControl` | the reference's line |

There is deliberately no auto-fix: the check under-detects by construction, so an automated edit
would delete markup that is correct.

## Reach

Reach is a property of the analysed project, not of the library. The type checks reach strictly
further than the absence check: every reference the absence check protects is identifiable, plus
those blocked only by `valueUnknown`-style reasons, which block absence and not identity. What
blocks identity is external mutation, an unproven rebind, a container pulled out of the form, an
untraversable container hop, an aliased form, a not-definitely-attached hop and a name no shape
lists.

A site the join gave up on looks exactly like a clean one, so a resolution regression, a store that
stopped being regenerated or a refactor that hides a `createComponent*` behind a factory would cost
all of this reach without a single gate turning red. A consuming application should therefore pin
its own reach floor in a test — closed templates, closed pairs, unguarded references proved
PRESENT, references whose component the join can identify — measured over its own corpus with
`FormPairing`. The last number decays **independently** of the other three: a builder that starts
losing a component costs identity without costing closure. If such a floor trips, find the cause
before moving the floor.

On a healthy project the bridge usually reports nothing, and that is **not** evidence that the check
works: its value is *regression protection* — a rename or a typo in a builder turns a present
reference into an absent one, and turning an `addText('x')` into an `addContainer('x')` turns every
`{input x}` into a mismatch. The invalidation suite and the rule's own mutation-tested fixtures are
the evidence.

## Typing inside a form scope

The five diagnostics above ask whether a name exists. The same join also answers what it *is*, and
that answer types the compiled template:

- `{form x}` / `<form n:name="x">` binds `$form` to the paired form's own class carrying its shape,
  so `$form['years']->getItems()` resolves to the checkbox list's method instead of an undefined
  method on `Nette\ComponentModel\IComponent`.
- `{input x}`, `{label x}`, `{inputError x}`, `<el n:name="x">` and `<el n:name="x:part">` type the
  control as the class the builder attached (`CheckboxList`, `TextInput`, …) instead of the generic
  `BaseControl`, so `getControlPart($key)` on a checkbox list stops being "invoked with 1 parameter".
- `{formContainer c}` types the container the same way.

Every rule is one-sided, like the diagnostics: a renderer the pairing cannot resolve, a template with
two resolved forms for the same name, a dynamic name, or two renderers that attached different
classes under one name all keep the wide type. Absence claims (the Forms extension's own "does not
exist" / "never exists") are only ever made for a container the bridge can prove complete, at every
level: a form or nested container it cannot prove complete is handed out open, so a read of a
component the builder never mentions stays silent. A form mutated outside its builder, at the root
or at any nested container, is handed out as its bare class, so `$form['x']` reads on it are not
typed from the shape. That bare-object gate is external mutation alone; the diagnostics' identity
gate also refuses on the Forms extension's lost-field reasons, so on such a form a control macro
stays `BaseControl` while `$form['x']` is still typed from the shape. Body-only edits to a builder
reach the template at most one warm run later (the discovery store's own lag); signature-level edits
reach it on the same run.

Typing trades findings. The `IComponent`/`BaseControl` floor on manually rendered controls
disappears (undefined `getItems()`, `hasErrors()`, `getError()`, and
`getControlPart($key)`/`getLabelPart($key)` "invoked with 1 parameter"), and in exchange vendor
docblock imprecision surfaces (`getText()` on the `Html|string|null` `getLabel()`, echoes of the
`object|string` `getCaption()`), provably redundant checks appear (mostly the `is_object(…) ? … : …`
shell Nette's `FormMacros` generates for `{input $var}`, and `n:ifset` guards the shape can now
decide), and strict rules start reporting values that only now have a type. Genuine template defects
show up too — e.g. a guard around a control the builder no longer adds, so the block never renders.

## Known limitations

From the design spec:

- **A control rendered manually** (`$form['x']` in PHP, or a template the discovery store cannot
  link) is invisible to the join; such templates are skipped, not reported.
- **Shared partials rendered by many forms lose detection** precisely where a rename is most likely
  to break one path — the accepted cost of the multi-renderer rule.
- **The bridge inherits both extensions' resolution limits**: whatever Forms cannot shape, and
  whatever discovery cannot link, the bridge cannot check.

And from the implementation:

- **A form subclass declaring its own `createComponent*` methods would produce false positives.** A
  class hop (`$form['sub']` resolving through a `createComponentSub()` on the *form's* own class) is
  not implemented, and it would need a reflection dependency the bridge
  otherwise does not have.
- **A container that loses a chained replicator** (`$form->addContainer('a')->addDynamic('items',
  …)`) is mis-shaped by the Forms analyser: the replicator is dropped and the following registrations
  are attributed to the container. The bridge inherits it. It is held off producing false positives
  today only by the "nothing modelled" half of the certainty gate; a container that lost a replicator
  *and* also has a real modelled slot would still answer absent.
- **Two store records spelling the same class with and without a leading backslash** would both
  resolve and list the renderer twice in the message. The derived store is not known to produce such
  records.

And from the type checks in particular:

- **`<label n:name="X">` is not label-checked.** It compiles to `getLabelPart()`, which on a button or
  a hidden field returns null and then fatals on `->attributes()` — a *worse* failure than `{label X}`'s
  silent nothing — but the collector records `n:name` without its host tag, so the check cannot tell
  that spelling from `<input n:name>`. Under-detection only. Recording the tag would mean a new
  collector field and a cache-version bump.
- **The recorded class is the builder's, not necessarily the runtime object's.** `add*` return types
  are equal to or wider than what is really attached, so a subclass that adds the method a container
  lacks, or takes a label back that its parent bypassed, would be reported wrongly. Both are one
  ignorable error, not broken analysis; the label direction is the reachable one and is the reason the
  check asks for `getLabel()`'s *declaring* class rather than for ancestry.
- **`{formContainer X}` on a control with an EMPTY body is inert markup, and is still reported.** The
  macro's only purpose is to scope inner references; nothing legitimate spells it this way.
- **Containers inside replicator rows get no bridge gate.** They are reached through
  `FormReplicatorType`, which carries no shape of its own to gate, so neither the completeness gate
  nor the external-mutation gate applies to them when a template types `$form['items'][0]['x']`.
- **A `{define}` block inside `{form a}` that is `{include}`d from `{form b}` is attributed
  lexically to form a.** This was already true of the diagnostics; the typing path now reads the same
  attribution.
- **A paired label with a non-literal part (`{label x:$part}…{/label}`) drops the part expression.** The
  `Helpers::formLabel()` stand-in takes the control name only, so the `$part` expression is not analysed.
- **nette/forms 3.3 deprecates `CsrfProtection`.** A typed read of the `addProtection()` control
  (`$form['_token_']`) is reported by a deprecation rule such as phpstan-deprecation-rules; that is vendor truth, not
  a bridge finding.

## Not shipped (recorded, not dropped)

- **Unrendered controls** — the reverse direction ("this control is built but no template renders
  it"). Open-world, and needs the orphan-style care the Latte extension's `orisaiNette.latte.orphanTemplate`
  took.
- **`{formPrint}`** — not modelled; it renders no user-written control names.

## For maintainers

- **The package's five classes are the whole surface.** `FormMacroCollector` (per-template,
  content-addressed shell over the adapter's `FormSite` facts — no PHP class is consulted), `FormPairing` (the join:
  discovery store → renderer classes → Forms shapes), `ResolvedForm` (`lookup()` for existence,
  `identify()` for kind), `MacroSuitability` (vendor's macro semantics, and the only place PHPStan
  reflection is used) and `LatteFormsRule` (the five diagnostics). The type checks added **no**
  cross-extension method: they read the same `FormShape` accessors the absence check already read,
  plus the public `UnknownReason::LOST_FIELD_UNKNOWN_REASONS` constant.
  Its cross-extension reach is eight methods on four services and one collector's collected data —
  `DiscoveryStore::recordsForTemplate()`; `IndexShapeResolver`'s `bindScope()`,
  `classComponentShape()` and `componentMayBeMutatedExternally()`; `LatteUniverse`'s `files()`,
  `relativePath()` and `projectRoot()`; `LatteAnalysisCache::rememberContentAddressed()`; and
  `LatteAnalyzedFileMarkerCollector`'s per-file markers for the reportable template set — and nothing
  else of either extension. Keep it that way: it is what keeps both extensions independently
  extractable.
- **`ResolvedForm::isClosed()` is a ROOT-level answer, not a whole-tree one.** Use `lookup()` as the
  absence oracle; `isClosed()` alone is wrong for a nested reference, and the rule never calls it.
- **`identify()` is NOT `lookup()` with a different return type.** They answer different questions
  under deliberately different gates, and merging them costs either soundness or every reference only
  identity can reach. If a
  new consumer needs "does it exist" it wants `lookup()`; if it needs "what is it" it wants
  `identify()`, and it must treat null the way every other unresolved answer here is treated.
- **`LOOKUP_UNRESOLVED` blocks, it does not mean "not present".** Every place the join accumulates
  evidence, unresolved has to stop the accumulation, not be skipped over; skipping it is
  exactly the false-positive shape.
- **Acceptance for any change here must be mutation-based.** A real project's finding delta is
  typically 0, so a green analysis says nothing. Rename a control in a builder and the finding must
  appear; turn one into a container, or point a `{label}` at a button, and the type checks must fire.
  The one gate that says something about reach without a mutation is a consuming application's own
  reach-floor test (see *Reach*) — treat a drop in its numbers as the finding it is, not as a floor
  to re-baseline.
