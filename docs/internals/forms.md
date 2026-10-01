Maintainer documentation; the user-facing guide is ../README.md.

# Forms static analysis

You write normal Nette forms and components; this layer makes PHPStan understand them. It infers the **shape** of every
component — which children it has, which classes they are, and (for form controls) what value each one carries — so that
`$form['email']` resolves to the actual control class, `$form->getValues()` returns an `ArrayHash` with typed fields,
`onSuccess` callbacks get the right `Form` and typed `$values`, and writing a wrong value or reaching a child that does
not exist is caught before your tests run. It augments PHPStan and phpstan-nette; you don't annotate your forms, and
the only configuration it needs is switching off phpstan-nette's competing dynamic-return extensions (see the user
guide's quick start). The code lives in `src/Forms/`; component attachment, which it consumes, in `src/Component/`
(see [component.md](component.md)).

"Shape" just means the child-by-child type picture of a component. Everywhere a component is reachable — the
`createComponentX` that builds it, an event callback, a presenter that nests it, a helper, a Latte template — the same
shape is projected onto whatever you ask.

The documentation has two parts. **[Components](#components)** is about how the engine understands any Nette component —
reaching, removing and navigating children. **[Forms](#forms)** is about the form-specific layer on top — values,
per-control value types, validation narrowing, and the form constructs (containers, replicators, wizards, DTO mapping).

---

# Components

A component is built in a `createComponentX` method (or a factory, or by hand) and the engine infers its **shape**: the
set of named children and, for each, the concrete class. This applies to any `Nette\ComponentModel\Container` — a form,
a form container, a control that nests sub-controls, a presenter — not just forms.

## Adding a child

Three ways of adding a child are seen and treated identically — an `add*` method, an offset assignment, or
`addComponent()` — so they all contribute the same shape:

```php
$form->addText('a');                        // add* method
$form['b'] = new TextInput();               // offset assignment
$form->addComponent(new TextInput(), 'c');  // addComponent
```

The equivalence covers containers and replicators too: `$form['rep'] = new CustomReplicatorContainer($factory)` and
`$form->addComponent(new CustomReplicatorContainer($factory), 'rep')` project the item shape exactly like
`$form->addDynamic('rep', $factory)` does (the factory closure is read from the constructor call — see
[Replicators](#replicators)). Adding under a non-literal name opens the shape, the same as a dynamic `add*`.

A modifier applied in a **later statement** reaches the same slot however you get back to the control — chained
(`$form->addText('a')->setRequired()`), through a bound variable (`$c = $form->addText('a'); $c->setRequired();`), or
re-accessed by offset or `getComponent()` (`$form['a'] = new TextInput(); $form['a']->setNullable();` or
`$form->getComponent('a')->setNullable();`).

## Conditionally-added fields

An `add*` reached only when a runtime condition holds is **maybe-present**: optional in the component shape (`?`) and
widened to nullable in the values (a missing key reads as `null`). This covers a statement-level `if` / `switch` / loop
as well as an add nested in a conditional **expression** — a ternary arm, the short-circuited right of `&&` / `||`, a
`??` / `??=` fallback, a `match` arm, or a call guarded by a nullsafe `?->` receiver:

```php
$field = $c ? $form->addText('a') : $form->addText('b'); // both optional
$c && $form->addText('d');                               // d optional
$form?->addText('e');                                    // e optional (nullsafe receiver)

$form->getValues(); // => Nette\Utils\ArrayHash{a: string|null, b: string|null, d: string|null, e: string|null}
```

A modifier **chained onto** such an add still shapes the same maybe-present slot (`$c && $form->addText('a')->setRequired()`),
and so does an unconditional later-statement modifier through a bound variable — modifying the control never proves the
add ran (`$field = $form?->addText('a'); $field->setRequired();` keeps `a` maybe-present).

An `add*` hidden in a compound statement's **header** — an `if` / `switch` / `while` / `do` / `foreach` condition or
expression, or a `for`'s init/condition — is captured too, present or optional by whether that header always runs when the
statement is reached: an `if`/`switch`/`do`/`while`/`for` condition, a `foreach` expression and a `for` init all run at
least once (present), while a `for`'s step and an `elseif` condition may not (optional):

```php
if (($x = $form->addCheckbox('f')) !== null) { … } // f present
for ($form->addText('a'); $c; $form->addText('b')) { … } // a present, b optional
```

Maybe-presence widens the **value**, not the component: `$form['a']` still resolves to the plain control type, because
that spelling is the *throwing* access — it raises an exception for a name that is not attached rather than returning
`null`. Existence lives on its own axis, and you read it with the no-throw spellings:

```php
if ($c) {
	$form->addText('a');
}

$form['a'];                    // => Nette\Forms\Controls\TextInput   (not …|null)
$form['a'] ?? null;            // => Nette\Forms\Controls\TextInput|null
$form->getComponent('a', false); // => Nette\Forms\Controls\TextInput|null
isset($form['a']);             // => bool, and narrows inside the branch
$form->getValues();            // => Nette\Utils\ArrayHash{a: string|null}   (the value IS widened)
```

The same holds for a conditionally-added container or replicator. Only a name the shape can **prove** absent is
reported, and it is reported once, by `orisai.nette.forms.noSuchComponent` — never as a `null` arm you then have to guard
against on every `setDefaultValue()`, `getLabel()` or `addConditionOn()` below.

Maybe-presence is **stable**: nothing later in the body promotes it back to definite. A further `if` / `switch` /
`try` / loop anywhere below forces another branch join, and a join only ever widens presence — the only thing that
makes a maybe-present name definite again is adding it unconditionally.

## Reaching a child

`$form['name']` resolves to the child added under that name:

```php
$form = new ApplicationForm();
$form->addText('email');

$form['email']; // => Nette\Forms\Controls\TextInput
```

`getComponent()` resolves the same way for any component the engine has a shape for (the usual case — built in a
`createComponentX`, returned by a factory, reached through `$this[...]`, or received as a callback parameter). The
no-throw overload adds `null`:

```php
$form->getComponent('email');        // => Nette\Forms\Controls\TextInput
$form->getComponent('email', false); // => Nette\Forms\Controls\TextInput|null
```

(On a freshly-built local form variable, prefer array access — it always resolves; `getComponent()` specialises only for
components already in the shape store.)

These mirror `Nette\ComponentModel\ArrayAccess`, so the method and offset forms are interchangeable: `$form['x']`,
`$form->getComponent('x')` and `$form->offsetGet('x')` are the **throwing** access — each is reported when `x`
provably does not exist. `isset($form['x'])`, `$form->offsetExists('x')` and `$form->getComponent('x', false)` are the
**no-throw existence checks** — never reported, the no-throw `getComponent` resolving to `…|null`.

A name two branches fill with two *different* controls resolves to the union of both classes, because either one can be
the component attached at runtime:

```php
if ($c) {
	$form->addText('note');
} else {
	$form->addTextArea('note');
}

$form['note']; // => Nette\Forms\Controls\TextArea|Nette\Forms\Controls\TextInput
```

A control whose **value** type could not be read still resolves as a component. Handing a control to another control's
`addConditionOn()`, or assigning one in from outside, lets it escape the analysis, and the field's value becomes
unknown — but which control was added is not in doubt, so the offset keeps the control class and only the value side
degrades:

```php
$a = $form->addText('a');
$form->addText('c')->addConditionOn($a, $form::EQUAL, true)->setRequired();

$form['a'];                  // => Nette\Forms\Controls\TextInput
$form['a']->setRequired();   // fine — it is a TextInput
$form->getValues()->a;       // the VALUE side stays unknown
```

Reaching such a name — as a component or as a value — is reported once, so the unreadable value is never silent:

```
Form value 'a' has an unknown type.  [orisai.nette.forms.partiallyUnknown]
```

Nested children and cross-component hops chain the same way:

```php
$form['a']['b']['c'];            // => Nette\Forms\Controls\TextInput
$this['reports']['form']['q'];   // => the control in ReportControl::createComponentForm
```

### Which machinery answers

`$form['x']` is resolved by two independent pieces of machinery, and which one answers is not a property of the
expression:

- **the shape projector**, for a form held in a *tracked local variable* in a file that builds a form — `$form = new
  ApplicationForm(); …; $form['x']`;
- **the component-access walk**, for everything else — every `.latte`, every presenter or control that only consumes a
  form, and any `$this['xxxForm'][…]` access even inside a builder.

They agree on everything you are likely to write, including a name a **closed** shape proves absent: both resolve it to
`*ERROR*`, and `Form component 'nope' does not exist.` is the one and only report you get for it. The `*ERROR*` is what
keeps it one report — an `IComponent` in its place makes core report the same mistake a second time as a `Call to an
undefined method Nette\ComponentModel\IComponent::getValue()`, an undefined property, or a `Cannot access offset`, on top
of ours.

On the walk this needs the accessed expression's own **receiver** to carry the shape, which is what makes the absence a
proof rather than a guess:

```php
$control['form'];                     // => ApplicationForm{name: string, sub: FormContainer{inner: string}}
$control['form']['sub']['nope'];      // => *ERROR*
$control['form']['nope'];             // => *ERROR*
$control['form']['sub-nope'];         // => *ERROR*
```

The **form itself** carries its shape, which is what puts every name on it within reach of the diagnostic — the first
line is the `{var $form = $control['form']}` a template writes, and everything below it reads off that one carrier.
Anything the walk merely could not resolve — an **open** shape, a factory it cannot follow, a `createComponent<Name>()`
lookup that finds nothing — still degrades to `IComponent`, as it always has, and a form whose own origin was never read
is open by construction, so nothing is ever proven absent on it.

**A name two branches fill differently resolves to the union of both, on either channel.** One branch adding
`addContainer('bag')` and the other `addText('bag')` leaves the name in two channels of the shape at once, and nothing
afterwards decides which component was actually attached — so both are the answer:

```php
if ($flag) { $form->addContainer('bag')->addText('inner'); } else { $form->addText('bag'); }
$form['bag'];   // => TextInput|FormContainer{inner: string}
```

An arm that cannot name a class contributes nothing to that union rather than widening it to `mixed`: a container whose
reference escaped joins as its bare class (`TextInput|FormContainer`), and a slot whose *value* type could not be read
(an `addConditionOn()`-escaped control) contributes nothing at all, so the container beside it answers alone. The
**replicator** channel is not part of the union on either channel — a name it holds is only reached once the other two
have declined.

**An unresolvable container or replicator degrades identically on both.** A row factory the analysis cannot enumerate
(`addDynamic('rep', [$this, 'fillRow'])`) or a container whose reference escapes into a callee leaves a child shape with
no children *and* no way to learn any, and neither channel wraps that: the answer is the bare component class, never
`FormContainer{}` / `array<int, FormContainer{}>`. An empty shape says "this component owns nothing", which is a claim
only a **closed** shape has earned — `$form->addContainer('sub');` as a whole statement really does own nothing, keeps
its `FormContainer{}`, and is what lets `$form['sub']['nope']` be reported.

### Component paths (`a-b`)

Nette's `Container::getComponent()` splits a name on `IComponent::NameSeparator` (`-`) and descends
(`vendor/nette/component-model/src/ComponentModel/Container.php:116`), and `ComponentModel\ArrayAccess::offsetGet()`
delegates straight to it. All four spellings below are therefore **one** runtime lookup, and the extension resolves and
reports them identically — through a single shared walk (`OriPhpstan\Nette\Forms\Shape\ComponentPath`) that every consumer
uses, whether it is asking for a type, for definite existence, or about absence:

```php
$form['a-b'];
$form['a']['b'];
$form->getComponent('a-b');
$form->getComponent('a')->getComponent('b');
```

Paths mix freely with the lexical nesting a `{formContainer}` chain adds in a template, and with a replicator's own
children and rows:

```php
$form['outer-mid-a'];      // three container hops
$form['rows-addNode'];     // a control added straight onto addDynamic()'s return value
$form['rows-0-x'];         // row 0's 'x' — see below
```

A **decimal** segment past a replicator is a dynamically created **row**, never one of the replicator's own children:
Nette casts an int offset to a string before looking it up and `NameRegexp` accepts digits, so `$rep[0]`, `$rep['0']`
and the `0` inside `$rep['0-x']` are the same component. Which row it is stays unknowable, so a *name* looked up
underneath it is resolved against the row shape and never reported absent.

Because the row is created on demand, a decimal segment is also a **definite existence** claim, not merely a
non-absence one: `$rep['0']`, `$form['rows-0']` and `$form['rows-0-x']` (the last one when `x` is unconditionally in
the row) answer **Yes** to PHPStan's own offset-existence question, so `$form['rows-0-x'] ?? null` keeps the control's
type and is not widened with `null`. That claim rests on the replicator really creating an absent row rather than
failing — true for Kdyby, whose `createComponent()` builds a container for **any** name not already registered
(`vendor/kdyby/forms-replicator/src/Replicator/Container.php:124`), and *assumed* for any other class reached through
the same `addDynamic()` / `addMultiplier()` / `@form-replicator` tagging. A replicator whose factory declined for some
row names would make that Yes too strong. The unconditional Yes for an **integer** offset (`$rep[$i]`) has always
rested on the same assumption; the decimal-string spelling merely says the same thing about the same component.

An **empty** segment — what a leading, trailing or doubled separator produces (`'a-'`, `'-a'`, `'a--b'`, a bare `'-'`) —
can never name a component: `addComponent()` applies the same `NameRegexp` that `getComponent()` checks, so the lookup
throws whatever the container holds. That is the one diagnostic here which does not need the shape to be closed:

```php
$form['outer-'];
// Form component path 'outer-' has an invalid segment '';
// a component name must be a non-empty alphanumeric string.  [orisai.nette.forms.shapeInvalidComponentName]
```

**A bug this closed, worth knowing about:** before the shared walk, the template channel (`$control['form'][…]`, which
every `.latte` goes through) did not merely fail to report such a path — it actively **resolved** it to a bogus type.
An unmatched segment falls back to looking for a `createComponent<Name>` factory, and `ucfirst('')` is `''`, so an
empty segment found `Nette\ComponentModel\Container::createComponent()` *itself*: `$control['form']['outer-']` typed
as that method's own shape (`\mixed`) rather than degrading, and every method call and offset on the result then
type-checked against nothing. It now degrades to `IComponent` and is reported by the diagnostic above. Note that the
name is only ever validated on the **reading** side — see [Known limitations](#known-limitations).

A path whose intermediate segment is provably **absent** is reported exactly like an absent leaf, since Nette throws the
same `Component with name 'nope' does not exist` for both:

```php
$form['nope-x'];  // Form component 'nope-x' does not exist.  [orisai.nette.forms.noSuchComponent]
```

A path whose intermediate segment **exists but is not a container** (a control, or a value-less component known only by
class) degrades instead — the shape carries no reflection to prove that a control class is not itself an `IContainer`.

A dynamic offset (`$form[$var]` where the key is not a literal) resolves to a **union of the component's known children**
rather than collapsing to `IComponent`, so methods shared by all of them still type-check. Both spellings of the
receiver — the component the form hangs off, and the form variable itself — answer alike, and neither ever answers with
the receiver:

```php
$form = new Form();
$form->addText('a');
$form->addCheckbox('b');

$this['form'][$name]; // => Nette\Forms\Controls\Checkbox|Nette\Forms\Controls\TextInput
$form[$name];         // => Nette\Forms\Controls\Checkbox|Nette\Forms\Controls\TextInput
```

A shape with no known children has nothing to union, so the offset degrades to whatever the receiver class's own
`ArrayAccess` answers (`IComponent`).

## Iterating children

`getComponents()` (no arguments) types as a collection of the **immediate** children — a union of their types — so a
method shared by all of them, or an `instanceof` check, type-checks inside the loop:

```php
$form = new ApplicationForm();
$form->addText('name');
$form->addText('email');

foreach ($form->getComponents() as $control) {
    $control; // => Nette\Forms\Controls\TextInput
}
```

A child container is included as its own type. `getControls()` instead descends the whole tree and yields every **leaf
control** — containers and replicators are flattened away:

```php
$form->getControls(); // => iterable<int|string, Nette\Forms\Controls\TextInput> (nette/forms 3.3)
```

Both narrow only when the shape is **closed** and reached directly — through `$this[...]`, an `onSuccess` (or other
callback) form parameter, or a factory return. On an open shape, or on a bare local form variable (whose type is just
the form class — the same caveat as `getComponent()`), they keep their declared type. The filtered overload
`getComponents($deep, $filter)` is likewise left to Nette's own typing.

The narrowed type keeps the collection type the installed method declares, with the children as its value type:
`ComponentModelAccessDynamicReturnTypeExtension` reads the native return type of the called `getComponents()` or
`getControls()`. `array` gives `array<int|string, …>`, `iterable` gives `iterable<int|string, …>` and `\Iterator` gives
`Iterator<int|string, …>`; any other declaration is not narrowed. A collection type the method does not declare would
be unsound — nette/forms 3.3 declares `getControls(): iterable` and returns an `IteratorAggregate`, so a narrowed
`Iterator` let `->current()` through. The lines differ:

| Installed                      | `getComponents()` | `getControls()` |
|--------------------------------|-------------------|-----------------|
| component-model 3.0, forms 3.1 | `Iterator`        | `Iterator`      |
| component-model 3.2, forms 3.2 | `iterable`        | `Iterator`      |
| component-model 3.2, forms 3.3 | `iterable`        | `iterable`      |
| component-model 4, forms 3.3   | `array`           | `iterable`      |

On component-model 4 the unnarrowed `getComponents()` is `array<int|string, Nette\ComponentModel\IComponent>` too, with
Forms analysis on or off: phpstan-nette's `Container.stub` declares `Iterator`, which PHPStan drops against the native
`array`, leaving the children `mixed`. Composer can install component-model 3.2 with forms 3.3, but no profile does, so
that row follows from the declarations without a test. `CmGetComponentsControls`, `CmGetComponentsIterable` and
`CmGetComponentsArray` pin one of the other rows each (the last also pins `->current()` being reported);
`CmGetComponentsFormsDisabled` pins the fallback with Forms analysis off.

## Navigating across `createComponentX`

Navigation works across any `createComponentX`, not just forms — controls, presenters, factories, and sub-components all
hop:

```php
// a control that builds a form via an injected factory
protected function createComponentEdit(): ApplicationForm
{
    $f = $this->factory->create();
    $f->addText('email');
    return $f;
}
// ...
$this['edit']['email']; // => Nette\Forms\Controls\TextInput

// reaching through one control into another control's form
$this['child']['form']['id']; // => Nette\Forms\Controls\HiddenField
```

## Forms held in a property

A form does not have to live in a component slot. One held in an object property is shaped too, so `$this->form` reads
like any other form — `getValues()`, offset access and per-control `getValue()` all resolve:

```php
final class Wizard
{
    private ApplicationForm $form;

    public function __construct()
    {
        $this->form = new ApplicationForm();
        $this->form->addText('email');
        $this->form->addSelect('kind', null, ['a' => 'A', 'b' => 'B']);
    }

    public function go(): void
    {
        $this->form->getValues();      // => Nette\Utils\ArrayHash{email: string, kind: 'a'|'b'|null}
        $this->form['email'];          // => Nette\Forms\Controls\TextInput
        $this->form['kind']->getValue(); // => 'a'|'b'|null
    }
}
```

**Which methods build it.** Every method of the class that binds the property with a plain assignment
(`$this->form = …`) is a builder — sorted by name, so the answer never depends on declaration order. `__construct` is
the usual one but nothing is keyed to it: a lazy `build()`, an `init()` called from a presenter's `startup()`, or a
`setUp()` in a trait are all read the same way, and each is folded through exactly the machinery a `createComponentX`
goes through, so factory returns, parent-constructor fields, followed helpers and rebind markers behave identically.
With **two or more** builders the shape opens (reason `rebind_unproven`) and their fields join per branch — a field
only one of them adds becomes optional — because which one ran last is not a static fact.

**Whether it closes is decided by visibility**, and this is the part worth understanding:

| declared | shape | why |
|---|---|---|
| `private` | may close | PHP lets no body outside the declaring class touch it, and every body of that class is read here |
| `protected` | always open | any subclass, in any file, can write to it |
| `public` | always open | any caller anywhere can write to it |

This is not caution for its own sake. External writes are **not modelled** in this version: nothing collects
`$holder->form->addText('extra')` from the rest of the project, so a `public` property whose builder reads cleanly would
close over a field set that is missing whatever the outside added — and a closed shape is what makes
`Form component 'extra' does not exist` fire on correct code. Closing without having seen every write is exactly how a
false "this field does not exist" is born, so the shape says so instead: it renders with the usual `...<mixed>` tail and
carries the reason `property_write_unmodelled`.

That reason is one of the **lost-field** reasons, so reaching an unlisted name on such a shape is *not* reported either
(see [Reaching a field on an open shape](#whats-checked)) — an unread write can have added `extra` as easily as removed
something, so the open shape is a statement that fields were lost, not that the name you asked for is wrong. What you
keep is everything the shape does list: the visible controls, their classes, and their value types.

The same reason opens a **private** property whenever the per-class fold meets a write it cannot attribute:

* a method other than a builder **mutates** the form (`$this->form->addText('late')` in an `attached()`); a method that
  only *reads* it — `getValues()`, an offset access, a `setRequired()` on a control it pulled out — does not open it,
  which is what keeps the channel useful at all, since the consumer is nearly always a method of the same class;
* the property is **returned** (`return $this->form;`) — that hands a mutable reference to a caller, whatever the
  visibility says;
* it is reached on **another instance** (`$other->form`, legal between two instances of the declaring class);
* it is reached through a **dynamic name** (`$this->$name`), which no per-class fold can attribute;
* it is bound or aliased **by reference** (`$this->form =& $f`, `$f =& $this->form`), or bound **compoundly**
  (`$this->form ??= …`), rather than by the plain assignment the fold reads;
* a builder's body could not be read at all — its adds are missing from the join, so the surviving builders must not
  close over them.

When no method binds the property, when its declared type could never hold a `Nette\ComponentModel\Container`, or when
what it is bound to is not one, there is no shape at all and the declared type simply stands.

**`$other->form` is a non-goal here.** Reading a form off a property of *another* object resolves to nothing — the
declared types stand, `getValues()` is a bare `ArrayHash` and `$other->form['x']` a bare `IComponent`. Its owner would
have to come from the receiver's type, and every write through every other holder of that object sits outside the
per-class fold: the same question the property's own visibility answers, asked again about a receiver, and
half-answering it is a false close. Lifting both restrictions is one piece of work — an index of external write sites
for properties, the counterpart of the one the component slot already has for
`$ctrl['form']->addText(…)`. With that, a `public` or `protected` property with no external writer could close, and a
foreign receiver could be resolved from its type.

## Removing a child

`removeComponent()` and `unset()` drop the child from the shape, with a literal name. Reaching it afterwards is then
reported as `Form component '…' does not exist`:

```php
$form->addText('a');
$form->addText('b');

$form->removeComponent($form['a']); // or: $form->removeComponent($form->getComponent('a'));
unset($form['b']);

$form['a']; // Form component 'a' does not exist.
$form['b']; // Form component 'b' does not exist.
```

A removal under a condition makes the field **optional** (it may or may not be present). A removal by a non-literal name
(`unset($form[$key])`, `removeComponent($this->widget)`) **opens** the shape — the engine can't tell which child went,
so it stops treating the component as closed:

```php
if ($cond) {
    unset($form['a']);
}
$form->getValues(true); // => array{a?: string}
```

## Aliasing and escaping

A variable that merely aliases the tracked form (`$alias = $form;`, a chained assignment `$form = $alias = new Form();`,
or an array-destructuring `[$alias] = [$form];` / `list($alias) = [$form];`) keeps the shape **closed** as long as the
alias is only read. A component-adding or -removing call **through the alias** opens the shape, since the engine no
longer knows every place that can still reach the same object:

```php
$form = new ApplicationForm();
$form->addText('a');
$alias = $form;
$alias->addText('b');

$form->getValues(); // => Nette\Utils\ArrayHash{a: string, ...<mixed>}
```

Pulling a **child** out of the form into a local — `$probe = $form['a'];`, or the `$form->getComponent('a')` spelling of
the same runtime lookup — is decided by what the handle *is* and by what is *done* with it, never by the assignment
alone. `$form->addText('a')->setDisabled()` and `$probe = $form['a']; $probe->setDisabled();` are one component set
written two ways, and both keep the shape **closed**:

```php
$form = new ApplicationForm();
$form->addText('a');
$probe = $form['a'];
$probe->setDefaultValue('x');

$form['nope']; // Form component 'nope' does not exist.
```

Two things have to hold, and they are proofs rather than absences of evidence:

* **the child resolves to a single class.** A **container** or replicator handle keeps opening the shape, whatever is
  called on it, because its own later children are the half that is not read (`$section = $form['section'];
  $section->addText('late');` is the pattern this protects) — and it is this condition that refuses them, since a child
  whose own shape the form carries resolves to no single child class at all;
* **every use registers nothing.** The recognised uses are a re-read of the tracked form under the same local name, an
  `instanceof` probe (`assert($probe instanceof BaseControl);`), and a **bare-statement** call chain whose every hop is
  a name that neither registers nor creates a component — the same `add*` vocabulary that decides an add on the form
  itself, so `addRule`/`addCondition`/`addFilter` are inert and `addText` is not, **plus** `getComponent`, which that
  vocabulary passes (it names a read) while the container method of that name creates and attaches whatever it does not
  find. Every hop is checked rather than only the first, because the receiver of a later hop is whatever the earlier one
  returned and `$probe->getForm()->addText('late')` registers on the form.

The second condition used to be paired with a third — that the resolved class be no `Nette\Forms\Container` — a
hardcoded pair of class names whose only job was to keep the `getComponent` hole above out of reach. The hole is closed
where it belongs now, inside the use predicate, so it holds for every caller instead of for the one that remembered to
ask about the class.

Anything else is not proven inert, it is unanswered, and the shape opens exactly as before: a non-literal offset
(`$form[$name]`), a handle handed to a callee or stored anywhere, a `use ($probe)` capture, a dynamic method name, an
assignment that is not a statement of its own, and a chain whose **result is captured** (`$v = $probe->getValue();` —
the captured value could be the form itself, so the coarse rule refuses it). What remains trusted is exactly what the
chained spelling above has always trusted: that a leaf control's setter does not reach back through `getParent()` to
register on the form.

Passing the tracked form into `$this->helper($form)` or `Some\Class::helper($form)` is **followed**: the callee's body
is read, what it attaches to that parameter is folded in at the call site, and the shape stays closed. The helper's
contribution belongs to the helper alone, so two forms routed through one helper each get it added to their own field
set and neither can see the other's:

```php
protected function createComponentA(): ApplicationForm { $f = new ApplicationForm(); $f->addText('a'); $this->common($f); return $f; }
protected function createComponentB(): ApplicationForm { $f = new ApplicationForm(); $f->addText('b'); $this->common($f); return $f; }
private function common(ApplicationForm $form): void { $form->addText('shared'); }

// A => array{a: string, shared: string}      B => array{b: string, shared: string}
```

The call **opens the shape** — the pre-existing behaviour — whenever it cannot be followed all the way, and the list is
deliberately conservative because a wrongly-closed shape is what turns into a false report:

* a receiver that is not `$this` and not a literal class name (`$this->helper->fill($form)`, `$helper->fill($form)`,
  `self::`/`static::`/`parent::`) — typing it would need the live scope, and the shape is cached across consumers;
* a plain function call, a `new`, a named or unpacked argument, a by-ref or variadic parameter, an untyped parameter or
  one that is not a `Nette\Forms\Container` subtype, an abstract/interface method, a method whose file is unreadable;
* a callee that **subtracts** — `$form->removeComponent(…)`, `unset($form['x'])`, or a `@form-disabler` method called on
  the parameter. What is folded in at the call site is additive, so a removal has nowhere to go, and a shape closed over
  one would keep claiming a control that is gone by render time;
* a callee that is itself incomplete — its own unknown reasons come across to the caller;
* recursion — a helper reached while already being followed.

A build method called **on** the tracked form (`$form->buildEverything();`, declared on the form and registering into
its own `$this`) is followed by the same machinery, under one extra gate: the callee's OWN BODY has to register on
`$this`, read syntactically by the registration detector. That is the whole bound. A method whose body registers
nothing (every setter and accessor, project or vendor) and one whose body cannot be read at all leave the state exactly
as they found it, so nothing that reports today stops reporting because of a method call. Within the gate the ordinary
rules apply: the name is resolved when the callee's body states it, and the shape opens when it does not. A call
spelled `add*` is not routed here at all — the tagging pass already accounts for it, and folding it twice would
double-count. The gate reads the callee's own body only, so a build method that merely delegates
(`buildEverything() { $this->fillContact(); }`) registers nothing itself and is not followed.

The one body that registers and must still not be followed is Nette's own lazy read. `Container::getComponent()`
creates and attaches inside an `if` guarding a component that is not there yet, and it is exactly the read the absence
rule exists to judge — following it downgrades a correct `$form->getComponent('nope')` report to "may not exist".
That used to be kept out by demanding a registration *every path* through the callee reaches, which excluded it for the
wrong reason and excluded honest conditional builders with it. It is now decided **per call**, from the two conditions
the vendor body states:

* the child is one the state proves the container already holds — `isset($this->components[$name])` is true and the
  whole lazy block is skipped;
* or the receiver declares no `createComponent<Ucname>` for the name — `createComponent()` hands back null and nothing
  is added (the read then throws, which is the absence rule's business).

Either one alone PROVES the call attaches nothing, and the call is not followed. Anything else — a name the call does
not spell literally, a class reflection cannot resolve, a child a factory really could create — is descended into and
degrades the shape OPEN, which is the honest answer for a read that may create. A `-`-joined name is split first and
answered for its first segment, because that is the only child the receiver itself can gain.

A builder whose registrations are all conditional is therefore followed now: the descent never assumed the branch ran,
it walks the callee and meets presences at the callee's own joins, so `build() { if ($c) { $this->addText('x'); } }`
comes back with `x` present **Maybe** instead of leaving it proven absent.

A callee whose contribution is **empty** does *not* open the shape. A helper that only calls `setItems()`,
`setDefaults()` or `setPrompt()` on controls already there is read in full, found to attach nothing, and the form closes
— which is the common real case, and the one that makes helper-heavy builders checkable at all.

That conclusion is only safe because "the body attaches nothing" is kept distinct from "the body could not be read".
PHPStan hands every file outside its CLI-narrowed analysed set to a parser that rewrites method bodies, and a rewritten
body is an empty one; the callee resolver therefore proves the statements it holds are the ones the source contains —
every top-level statement must carry a source position (a rewritten body's are synthesised and carry none), and an empty
statement list is believed only when the source between the method's own braces really is empty. A body it cannot
vouch for is left unfollowed, i.e. the shape opens exactly as before.

## Callback invocation timing

A closure handed to a call, capturing the form and mutating it, raises a question the shape cannot dodge: has it run by
the time the form is returned? The answer comes from PHPStan's own `@param-immediately-invoked-callable` /
`@param-later-invoked-callable` phpdoc on the parameter the callable lands on, and the three answers are not symmetric:

| parameter | meaning | shape |
|---|---|---|
| `@param-immediately-invoked-callable` | the callback has run | its adds are in the shape |
| `@param-later-invoked-callable` | it has not run | the render-time shape — the control is genuinely absent, and the shape stays closed |
| neither (PHPStan answers MAYBE) | unknowable | the shape **opens**, reason `callback_timing` |

```php
/** @param-immediately-invoked-callable $cb */ private function now(callable $cb): void { $cb(); }
/** @param-later-invoked-callable $cb */      private function later(callable $cb): void { $this->deferred[] = $cb; }
private function whenever(callable $cb): void { $this->deferred[] = $cb; }

$this->now(function () use ($form) { $form->addText('a'); });       // => array{a: string}
$this->later(function () use ($form) { $form->addText('b'); });     // => array{} — b is not there yet
$this->whenever(function () use ($form) { $form->addText('c'); });  // => array{...<string, mixed>}
```

Claiming absence is the dangerous direction — absence is what becomes a report — so an unknown timing may never produce
one. A closure whose **parameter** is named like the tracked variable is not a capture at all (the replicator-factory
shape `addDynamic('x', function (Container $container) {…})` when the walk is resolving a container called
`$container`); those bodies are read as before.

### Callables stored on an event property

`$form->onSuccess[] = …` is a **store**, not a call, so there is no parameter for the trinary above to read an answer
off. The walk recognises the assignment grammar instead, and the answer it reaches is always the **MAYBE** row: the
shape opens with reason `callback_timing`, the deferred name degrades and nothing is reported.

MAYBE and never NO, for two independent reasons. Nette fires `onSuccess` during form **processing**, which precedes
rendering, so the registration is deferred relative to the *factory* and not relative to every reader. And the reader
is any method on the class — including one reached from inside the handler itself, or under `if ($form->isSuccess())`,
by which time the control is attached. Proving absence would need the reader set to be enumerable, which a `public`
method's is not; `private`, or `protected` in an effectively final class, is a *necessary* condition for such a proof
and still not a sufficient one, since a private method is reachable from a closure defined in the same class.

Only a callable whose body is **read** and seen to register a component reaches this arm. A handler that merely
processes submitted values — which is what nearly every one of them does — leaves the shape closed, so the absence
findings a closed shape exists to produce are kept. On a real project nearly every event-property store resolves to a
body that registers nothing, so real forms stay closed.

```php
$form->onSuccess[] = function (MyForm $f) { $f->addText('a'); };          // opens — the event's own form
$form->onSuccess[] = function () use ($form) { $form->addText('b'); };    // opens; b is dropped from the shape
$form->onSuccess[] = [$this, 'register'];                                 // opens when register() adds on its form param
$form->onSuccess[] = [$this, 'processValues'];                            // stays CLOSED — nothing is registered
```

These spellings are read: a closure or arrow-function literal, a local variable bound to one, `Closure::fromCallable()`
around either, an array callable `[$this, 'm']` / `[Foo::class, 'm']`, the first-class-callable forms `$this->m(...)`
and `Foo::m(...)`, and a whole-list assignment `$form->onSuccess = [ … ]` (every item). The event property may be any
`on<Event>` name and may live on any receiver — a captured form's registration is deferred whichever control's event
carries it (`$button->onClick[] = …`). A registration on the callable's **parameter** counts only when the store's
receiver is the tracked form itself, since that is the object the event hands its handler.

What is not read: a string callable, a callable arriving as a parameter or read off a property, one produced by a call,
and a method named on `parent::`. There the store is invisible and the shape stays closed — see
[Known limitations](#known-limitations).

A captured form's registrations are **dropped** from the walk as well as opening the shape, exactly as a proven-later
callable argument's are: a shape that listed them would be claiming a control the render-time reader cannot see.

A **by-ref closure** capturing the form (`function () use (&$form) { $form->addText('x'); }`) is only absorbed — its
body's effect on the shape applied in place — when it is provably run in the same scope: assigned to a local variable
and later invoked (`$build = function () use (&$form) {…}; $build();`), or invoked immediately
(`(function () use (&$form) {…})();`). Every other case opens the shape, since the closure may run later, elsewhere,
or never:

```php
$build = function () use (&$form): void {
    $form->addText('fromClosure');
};
$this->register($build);   // assigned, but escapes before any local invoke — opens

$this->register(function () use (&$form): void {  // never assigned — opens
    $form->addText('x');
});
$this->builders[] = function () use (&$form): void {  // stored to a property/array — opens
    $form->addText('x');
};
```

An uninvoked, non-escaping by-ref closure (assigned to a variable, never called, never passed, stored, or returned)
adds nothing — its mutations are simply dropped, and the shape stays closed.

Inside an absorbed closure body, a modifier argument that reads an outer variable captured **by value**
(`function () use (&$form, $nullable) { $form->addText('b')->setNullable($nullable); }`) is trusted only when the
outer function assigns that variable exactly once. If the outer function rebinds it — as in
`$nullable = true; $cb = function () use (&$form, $nullable) {…}; $nullable = false; $cb();` — the capture snapshots
the earlier value while the analysis reads the later one, so the argument is distrusted and the field degrades
(`setNullable($nullable)` widens to `…|null`, `setRequired`/`setDisabled` drop their narrowing). An arrow function's
implicit captures are by value too and follow the same rule. A **by-reference** capture (`use (&$nullable)`) and a
closure **parameter** keep their scope typing — the reference shares the outer variable, and a parameter is re-bound
on each call.

The same distrust applies to the **receiver** of a custom add method inside an absorbed body: when the receiver
variable is rebound in the body (or is a multi-assigned by-value capture), the receiver's reflected class may follow
a stale same-named outer binding, so only method-name catalog resolutions (`addText`, `addContainer`, a
`@form-replicator` add method, …) keep their type; a custom `add*` whose control class would come from reflecting
the receiver degrades to an unknown-typed slot and the container opens.

A container variable rebound **inside** a closure body is a fresh binding: adds on it inside the body belong to the
container it was rebound to, not to a same-named container bound outside — `$v = $form->addContainer('a');
(function () use (&$form) { $v = $form->addContainer('b'); $v->addText('x'); })();` puts `x` only under `b`.
If the closure also captured the old container by value (`use ($v)`) before rebinding it, the records inside can
refer to either binding, so the shape opens instead.

Storing the tracked form itself into a property or static property (`$this->form = $form;`), into an array
(`$arr[] = $form;`, `$this->forms[] = $form;`), or into an array literal that is then handed elsewhere
(`$pair = [$form, $meta];`) opens the shape for the same reason: the walk can no longer account for every place still
holding the object. The one offset assignment that does not open is attaching the tracked container into a form or
container the engine is composing (`$parent['sub'] = $container;` under a literal name) — that is the child-attach
`addComponent()` equivalence already models.

A **first-class callable** on the tracked receiver (`$form->addText(...)`, `$form->setDefaults(...)`,
`$form['a']->setValue(...)` — PHP 8.1 syntax, relevant once `phpVersion` moves past 7.4) is an escape too: the
resulting Closure closes over the receiver and may invoke the method later with arguments the walk cannot see, so
the shape opens. The same applies inside a modifier chain — `$form->addText('x')->setNullable(...)` /
`$c->setNullable(...)` on a bound control never folds the modifier: the control's slot opens, and where the walk sees
the chain rooted at the tracked receiver the whole shape opens with it — to a constructor-built shape whose statement
contains one, and to `$build(...)` on a by-ref-closure variable, which aliases the closure rather than invoking it. Pure pattern-matchers
(pass-through recognition, origin tracing, `setMappedType()` detection, `getComponent`/`offsetGet` access keys)
treat a first-class callable as a non-match — the call does not happen at that site. The behavior is pinned by unit
tests over synthetically-built AST nodes, so no 8.1 syntax is parsed anywhere.

## Rebinding the tracked variable

Aliasing copies a reference; **rebinding** repoints the tracked name itself at a different object. Once that happens the
fields added before the rebind no longer describe the variable, so the engine drops them. A rebind whose new value is a
provably fresh `new Form()` resets **clean** — the shape simply starts empty again:

```php
$form = new ApplicationForm();
$form->addText('a');
$form = new ApplicationForm();   // fresh instance — clean reset
$form->addText('b');
$form->getValues(); // => Nette\Utils\ArrayHash{b: string}
```

Every other rebind points the name at an object the walk cannot prove empty, so it resets **and opens** the shape (the
pre-rebind fields are dropped, later additions are recorded, and `...<mixed>` is appended). This covers a reassignment
from a method call / property / variable / clone (`$form = $this->build();`), a reference rebind (`$form =& $other;`),
a `foreach ($xs as $form)` value or key binding, a `catch (\Throwable $form)` handler, a non-literal destructure
(`[$form] = $pair;`), and a rebind buried in a condition or expression subtree (`while (($form = next()) !== null)`):

```php
$form = new ApplicationForm();
$form->addText('a');
foreach ($this->forms() as $form) {   // $form now holds a loop element
    $form->addText('b');
}
$form->getValues(); // => Nette\Utils\ArrayHash{b?: string, ...<mixed>}
```

The one exception is the interprocedural boundary: when the form is bound from a factory or a parent `createComponentX`
(`$form = $this->factory->create();`, `$form = parent::createComponentForm();`), the shape opens in the local walk but
that source is resolved by the `createComponentX` / factory-return machinery, which supplies the cross-method fields and
clears the rebind marker — those forms stay **precise**. The clear is coupled to that resolution actually succeeding,
transitively: a rebind whose target the machinery cannot follow (an unlocatable method, an undetectable builder chain)
leaves the marker in place, and a factory that *does* resolve but whose own form comes from an unresolved source — a
`return $this->prebuilt;` property fetch, or a further factory hop that fails — contributes an **open** shape, so the
openness survives the compensation instead of being stripped with the outer marker. A fresh construction resolves through the constructor whichever
way the caller holds it — returned (`return new SomeForm();`), reached as a `createComponent*` child, or bound to a
plain local (`$form = new SomeForm();`): fields added in `SomeForm::__construct` are carried over, Nette's own bare
`Form`/`Container` constructors are known to add nothing, and a constructor the resolver cannot fully read (or a class
name no static read resolves, `new $class`) keeps the shape **open** rather than closing over the controls it never
saw. The compensation also requires an unambiguous source: with more than one
binding of the same variable — of any rebind family (a later assignment `$form = $this->b->create();` or
`$form = $this->prebuilt;`, a by-ref rebind, a destructure, a `foreach` value/key binding), sequentially or in branch
arms — the final binding is control-flow dependent, so no assignment is resolved and the earlier binding's fields are
never claimed. The same
rule gates the parent compensation: `parent::createComponentForm()` followed by any other rebind stays open. The
rebind carries its own dedicated marker, so only it is cleared: any other unknown accumulated in the same method
survives the compensation. Pulling a child out of the form after the rebind
(`$form = parent::createComponentForm(); $sub = $form['fromParent'];`) therefore keeps the shape open — the walk's own
state holds no child under that name after a rebind, so the handle resolves to no class and the leaf proof
[a pulled child needs](#aliasing-and-escaping) cannot be made; the parent's fields cannot be claimed complete relative
to the pulled-out child's possible mutations. A rebind from a source the
machinery cannot resolve (e.g. a form read in an `onSuccess` handler off a local factory variable) likewise stays open.

## Non-form subcomponents

A form may contain a non-form subcomponent; it is tracked as a known child with its declared type (its inner value is
simply unknown). A control may itself contain a form. Both are visible to `dumpComponent` (see
[Debugging](#debugging)).

---

# Forms

On top of the component model, the form layer types each control's value and the form's projected values.

## Values

`getValues()` returns an `ArrayHash` whose fields are typed, and the same value is also assignable wherever a plain
`ArrayHash` is expected:

```php
$form->addText('name');
$form->addCheckbox('agree');

$values = $form->getValues();
$values;        // => Nette\Utils\ArrayHash{name: string, agree: bool}
$values->name;  // => string
```

The fields come off the receiver's shape, so the projection happens wherever the form carries one — including a
template's `{var $form = $control['form']}`, where `getValues()` used to hand back a flat `ArrayHash`. A form the
analysis could not read carries no shape and stays flat: there is nothing to project.

`getValues(true)` (and `getUntrustedValues()` / the deprecated `getUnsafeValues()`) give you a typed array instead:

```php
$form->getValues(true); // => array{name: string, agree: bool}
$form->getValues(true)['name']; // => string
```

Naming the crate explicitly is the same call, and `stdClass` is typed exactly as `ArrayHash` is — both are bags Nette
fills with the form's own fields, so the fields are what carries the type:

```php
$form->getValues(ArrayHash::class);       // => Nette\Utils\ArrayHash{name: string, agree: bool}
$form->getValues(stdClass::class);        // => stdClass{name: string, agree: bool}
$form->getValues(stdClass::class)->name;  // => string
```

A **mapped DTO** is not a crate and is deliberately left as the bare class: it declares its own properties, PHPStan
types those natively, and `orisai.nette.forms.mappedTypeWrite` checks the write side instead.

```php
$form->getValues(Dto::class);       // => Dto
$form->getValues(Dto::class)->name; // => string   (from Dto's own declared property)
```

`$form['field']->getValue()` returns exactly the matching field type:

```php
$form['name']->getValue();  // => string
$form['agree']->getValue(); // => bool
$form['count']->getValue(); // => int|null  (from addInteger)
```

## Disabled and omitted fields

A `setDisabled()` or `setOmitted()` control is dropped from `getValues()` — Nette's `isOmitted()` excludes it — but it
stays a reachable component, so array / `getComponent()` access still resolves it:

```php
$form->addText('name');
$form->addText('token')->setDisabled();
$form->addText('note')->setOmitted();

$form->getValues(); // => Nette\Utils\ArrayHash{name: string}
$form['token'];     // => Nette\Forms\Controls\TextInput  (still reachable)
```

`setDisabled()` also clears the value, so whether a default still renders depends on call order (`setDisabled()` then
`setDefaultValue('x')` keeps `'x'`; the reverse clears it) — but either way the field is absent from `getValues()`.
Because `isOmitted()` is `omitted ?? disabled`, `setOmitted(false)` puts a disabled field back into the values and
`setDisabled(false)` never omits:

```php
$form->addText('a')->setDisabled()->setOmitted(false); // back in getValues()
$form->addText('b')->setDisabled(false);               // never omitted
```

A custom method that disables the whole container — one tagged
[`@form-disabler`](#supporting-your-own-controls-and-components) — omits every descendant control added before it is
called, so `$form->setDisabled()` empties the projected values.

Presence (is the component attached) and omission (does `isOmitted()` drop it from `getValues()`) are tracked as two
independent axes. A `setDisabled()`/`setOmitted()` reached only on some branch leaves the component **definitely
attached** — the component shape stays `a:` (not `a?:`) and `$form['a']` resolves non-null — while the *value* widens
to nullable, since the field is genuinely absent from `getValues()` on the branch that disabled it:

```php
$control = $form->addText('a');
if ($cond) {
    $control->setDisabled();
}

$form->getValues(); // => Nette\Utils\ArrayHash{a: string|null}
$form['a']; // => Nette\Forms\Controls\TextInput  (still reachable, definite)
```

When the disable flag itself is a value the analysis cannot read — a body-bound variable inside an absorbed closure,
for instance — omission is **unknown** rather than opaque: the control type is kept and the value widens to nullable,
the same conservative result as a conditional disable.

```php
(function () use (&$form): void {
    $disabled = takesUserInput();
    $form->addText('b')->setDisabled($disabled);
})();

$form->getValues(); // => Nette\Utils\ArrayHash{b: string|null}
$form['b']; // => Nette\Forms\Controls\TextInput  (control type kept)
```

## Buttons

Submit-style buttons set `omitted` on themselves, so they're absent from `getValues()`: `addSubmit()`,
`addImageButton()`, `addImage()`, `addProtection()`, and `addReCaptcha()` resolve to their component class with no value.
`addProtection()` takes no name; its control is recorded under `Form::PROTECTOR_ID`, so `$form['_token_']` is
`Nette\Forms\Controls\CsrfProtection`.

Carrying no value means such a button is recorded only in the shape's component-class registry, not in any of the
three channels that hold a value shape — so *reaching* it is a different lookup from reaching a text input, and it
used to be answered by fewer of them. It is now resolved wherever any other child is: as a bare leaf, at the end of a
`-`-joined path, on a replicator holder, and inside a replicator **row**:

```php
$form->addSubmit('save', 'Save');
$rep = $form->addDynamic('rows', static function (FormContainer $row): void {
    $row->addSubmit('removeNode', 'Remove');
});
$rep->addSubmit('addNode', 'Add');

$form['save'];              // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton
$form['rows-addNode'];      // => the holder's own button
$form['rows-0-removeNode']; // => row 0's button
```

Existence is a separate axis and deliberately weaker: the registry records a name from **any** branch that added it,
so a conditionally-added button is indistinguishable from an unconditional one and neither answers **Yes** to
PHPStan's offset-existence question (`$form['save'] ?? null` keeps the `|null`). See
[Known limitations](#known-limitations).

A plain `addButton()` does **not** omit itself, so it stays in `getValues()` — the button that submitted the form holds
its value (the caption it was added with, which Nette renders as the button's `value` attribute, a string), and every
other button is `null`, so the field is typed `string|null`:

```php
$form->addButton('act', 'Go');
$form->getValues(); // => Nette\Utils\ArrayHash{act: string|null}
```

## Event callbacks (`onSuccess` and the others)

Both callback styles are typed: the `Form` parameter is the form the callback was registered on, and the
`ArrayHash $values` parameter is **identical** to what `getValues()` returns for that form. This works for a closure, an
arrow function, and a `[$this, 'method']` array callable:

```php
$form->addText('name')->setRequired();

$form->onSuccess[] = function (RawForm $form, ArrayHash $values): void {
    $values; // => Nette\Utils\ArrayHash{name: non-empty-string}
};

$form->onSuccess[] = [$this, 'handleSuccess'];
// ...
public function handleSuccess(RawForm $form): void
{
    $form->getValues(); // => Nette\Utils\ArrayHash{name: non-empty-string}
}
```

The handler parameter is shaped as the form **only when the handler is bound on the form that
`createComponentX` returns**. If a factory builds several forms and binds a handler on one it does
not return, that handler's parameter stays open — the analysis never attributes the returned
component's shape to a handler it cannot prove belongs to it:

```php
protected function createComponentMain(): ApplicationForm
{
    $filter = new ApplicationForm();
    $filter->addText('filterField');
    $filter->onSuccess[] = [$this, 'filterSubmitted']; // bound on $filter, not the returned form

    $main = new ApplicationForm();
    $main->addText('mainField');

    return $main;
}

public function filterSubmitted(ApplicationForm $f): void
{
    $f['mainField']; // => Nette\ComponentModel\IComponent — $main's shape does not leak here
}
```

A handler that **registers a component** on its form parameter is a different question, answered on the builder's side:
the registration is deferred past the factory, so the built form's shape opens instead of claiming the control absent —
see [Callables stored on an event property](#callables-stored-on-an-event-property).

## Multiple returns

A factory (or `createComponentX`) that returns a different form in different branches is shaped as
the **join** of those branches: a field present in only one branch degrades to maybe-present and
reads back as nullable in `getValues()`, exactly like a conditionally-added field.

```php
protected function createComponentThing(): ApplicationForm
{
    if ($this->cond()) {
        $a = new ApplicationForm();
        $a->addText('alpha');

        return $a;
    }

    $b = new ApplicationForm();
    $b->addText('beta');

    return $b;
}
// $this['thing']->getValues(); // => Nette\Utils\ArrayHash{alpha: string|null, beta: string|null}
```

## Read type — the value a control carries

The **read type** is what `getValue()` and the values projection give you. Every control maps to one:

| Control                                             | Read type                           |
|-----------------------------------------------------|-------------------------------------|
| `addText`, `addPassword`, `addEmail`, `addTextArea` | `string`                            |
| `addColor`                                          | `string`                            |
| `addInteger`                                        | `int\|null`                         |
| `addFloat`                                          | `float\|null`                       |
| `addCheckbox`                                       | `bool`                              |
| `addHidden`                                         | `string\|null`                      |
| `addSelect`, `addRadioList`                         | `int\|string\|null`                 |
| `addMultiSelect`, `addCheckboxList`                 | `list<int\|string>`                 |
| `addUpload`                                         | `Nette\Http\FileUpload\|null`       |
| `addMultiUpload`                                    | `list<Nette\Http\FileUpload>\|null` |
| `addDate`, `addTime`, `addDateTime`                 | `DateTimeImmutable\|null`           |

These are sound regardless of submission state: an un-submitted form returns `null` for the nullable controls, and
`setRequired()` alone does not strip `null` (it narrows only in a validated context — see
[Validation narrowing](#validation-narrowing)). A nullable text is `non-empty-string|null`, not `string|null`, because
Nette maps an empty submission to `null`.

Three controls change their read type through a method — they are covered in their own sections:
[date `setFormat`](#date-formats), choice [`setItems`](#choice-items), and the per-control modifier methods documented
under [custom controls](#supporting-your-own-controls-and-components).

### Where these vendor facts live, and how they are kept fresh

Nothing in that table is a hardcoded name switch. It has two halves, from two different sources:

* the **control class** behind each factory is `Nette\Forms\Container::<method>()`'s own **declared return type**, read
  live. `addSelect()` says `Controls\SelectBox`, and that is what the model records — the class is never restated
  anywhere;
* the **read type** — and, for `addInteger()`/`addFloat()`, the write spec — comes from `@form-read-type` /
  `@form-write-spec` tags on `OriPhpstan\Nette\Forms\Catalog\Stub\FormValueTypeCatalog`, a declaration-only interface
  mirroring Nette's factory names.

The value half has to be **method-keyed** rather than class-keyed, which is why that catalog exists at all:
`addText()`, `addPassword()` and `addEmail()` all return a `TextInput` reading `string`, `addInteger()` returns one
reading `int|null` and `addFloat()` one reading `float|null`; `addUpload()` and `addMultiUpload()` are both an
`UploadControl` reading different things. A control class's own `@form-read-type` cannot express that. Carrying a
catalog entry is also what *makes* a vendor method a value factory, as against `addContainer()`, `addSubmit()` and
`addButton()`, which are resolved by the branches below it.

**Why the vendor factories are not delivered as a `Nette\Forms\Container` stub.** Moving these tags onto stubbed
`Container` methods would let vendor and user code share one mechanism, and it does not work — measured, not assumed:

* `phpstan/phpstan-nette` already ships `stubs/Forms/Container.stub`, and a **second** stub of the same class makes
  PHPStan emit a **non-ignorable** `class.duplicate`. Non-ignorable means it can be neither baselined nor ignored, so
  a consumer's analysis could never be green again.
* Four more non-ignorable `class.notFound` errors follow, on the stubbed `addSelect()`/`addRadioList()`/
  `addCheckboxList()`/`addMultiSelect()` return types, which stop resolving once `ChoiceControl` is itself redeclared
  by `control-value-types.stub`.
* Five non-ignorable `missingType.*` errors follow for the parameters Nette itself leaves untyped — `addHidden()`'s
  `$default` and the four `?array $items` — fixable only by writing `@param` tags that then differ from the vendor
  docblock the stub is supposed to be reproducing verbatim.
* And `StubPhpDocProvider` resets a class's whole method/property/constant map every time a stub file declares that
  class, so the second stub would also silently discard phpstan-nette's own `@property`/`@property-read` tags on
  `Container` unless it copied them, with the winner decided purely by stub-file ordering.

**`VendorCatalogFreshnessTest`** (`tests/Unit/Forms/`, part of the ordinary test suite) — the freshness gate over both
halves. Both are derived from vendor and both rot silently on a dependency update, so a CI run against the newest
allowed `nette/forms` is what catches them:

* `control-value-types.stub` redeclares vendor control classes, and a stub **replaces** the docblock it redeclares
  rather than merging with it, so a `@property`/`@method` tag Nette adds to a redeclared class is dropped unless the
  stub restates it. The gate reports every vendor member tag the stub does not restate — by **name** only, so the
  deliberate retyping (`array<int|string, mixed>` where Nette writes a bare `array`) stays free. Nette's own
  `@property-deprecated` (nette/forms 3.3 demotes `$selectedItem` and `$selectedItems` to it) is no PHPStan tag, so a
  redeclaring stub drops nothing there and the gate ignores it;
* the catalog is keyed by Nette's factory **method names**, and the control class is read off those methods' return
  types, so a factory Nette renames, drops, or stops giving a control return type turns its entry inert. The gate
  reports each one.

Both sources are read as **source text**. Reflection is used only to locate a vendor file, never to answer a docblock
question: once a stub is applied PHPStan's reflection answers with the stub's own docblock, so a reflection-based check
would be comparing the stub against itself and could never fail. What the gate deliberately does *not* report is a
vendor factory the catalog does not list — Nette gaining an `add*` method is a feature request, not a stale copy, and
the control degrades honestly meanwhile.

## Write type — what `setValue()` accepts

The **write type** is the value `setValue()` (and `setDefaultValue()` / `setDefaults()` / `setValues()`) accept; it is
generally broader than the read type, because Nette coerces scalars on input. Writing a value outside the accepted set
is reported (see [What's checked](#whats-checked)).

| Control                             | Write type (`setValue()` accepts)                       |
|-------------------------------------|---------------------------------------------------------|
| text-like (`addText`, `addInteger`, `addHidden`, …) | `scalar\|Stringable\|null`              |
| `addCheckbox`                       | `scalar\|null`                                          |
| `addSelect`, `addRadioList`         | `BackedEnum\|int\|string\|null` (the key domain)        |
| `addMultiSelect`, `addCheckboxList` | a scalar or an `iterable` of scalars/`BackedEnum`       |
| `addDate`, `addTime`, `addDateTime` | `DateTimeInterface\|int\|string\|null`                  |
| `addUpload`, `addMultiUpload`       | nothing — `setValue()` is rejected (the value comes only from the submission) |

Two interactions with the read sections above: choice [`setItems`](#choice-items) narrows the **read** type to the item
keys but leaves the write type at the key domain; date [`setFormat`](#date-formats) changes the **read** type and does
not widen the write type.

## Validation narrowing

In a context where the form is known to have passed validation, every **required** field narrows to its filled type —
non-null; a `string` becomes `non-empty-string`; an `int|null` becomes `int`; a single-choice value drops `null`; a
list (multi-choice, multi-upload) becomes a non-empty list. Outside such a context the type stays broad (sound). Three
contexts narrow:

```php
$form->addText('name')->setRequired();
$form->addText('note');
$form->addInteger('age')->setRequired();
$form->addSelect('kind')->setRequired();      // single choice
$form->addMultiSelect('tags')->setRequired(); // multi choice

// 1. inside an onSuccess body / its $values parameter
$form->onSuccess[] = function (RawForm $form, ArrayHash $values): void {
    $values;
    // => Nette\Utils\ArrayHash{
    //      name: non-empty-string, note: string, age: int,
    //      kind: int|non-empty-string, tags: non-empty-list<int|string>
    //    }
};

// 2. after an isValid()/isSuccess() guard
if ($this['form']->isValid()) {
    $this['form']->getValues(); // required fields narrowed
}

// 3. a bare getValues() stays broad
$this['form']->getValues(); // => {name: string, note: string, age: int|null, kind: int|string|null, tags: list<int|string>}
```

`onError`/`onValidate`/`onSubmit` keep the broad type.

## Rules and conditions

An unconditional `addRule()` casts the value type the same way the dedicated `add*` method would:

```php
$form->addText('i')->addRule(Form::Integer); // => ''|int   (same as addInteger minus its setNullable)
$form->addText('f')->addRule(Form::Float);   // => ''|float
$form->addText('i')->setNullable()->addRule(Form::Integer); // => int|null  (== addInteger)
$form->addText('i')->setRequired()->addRule(Form::Integer); // => ''|int
```

A **conditional** rule may not run, so it widens instead of casting; `endCondition()` returns to unconditional:

```php
$form->addText('c')->addConditionOn($form['x'], Form::Equal, 1)->addRule(Form::Integer); // => int|string
$form->addText('c2')->addCondition(Form::Filled)->addRule(Form::Float);                  // => float|string
$form->addText('c')->addConditionOn($form['x'], Form::Equal, 1)->endCondition()->addRule(Form::Float); // => ''|float
```

These fold identically whether the rule is chained inline, applied as a separate statement on a control variable, or
applied through a `Rules` variable:

```php
$c = $form->addText('c');
$c->addRule(Form::Integer);                  // => ''|int

$rules = $form->addText('c')->getRules();
$rules->addRule(Form::Integer);              // => ''|int

$c = $form->addText('c');
$rules = $c->addConditionOn($form['x'], Form::Equal, 1);
$rules->addRule(Form::Integer);              // => int|string
```

## Date formats

`setFormat()` on a date control changes the (read) value type:

```php
$form->addDate('ts')->setFormat(DateTimeControl::FormatTimestamp); // => int|null
$form->addDate('obj')->setFormat(DateTimeControl::FormatObject);   // => DateTimeImmutable|null
$form->addDate('str')->setFormat('Y-m-d');                         // => string|null
```

## Choice items

`setItems()` on a choice control narrows the (read) value to the item keys (closed). A multi-choice narrows to a list of
keys:

```php
$form->addSelect('s')->setItems(['a' => 'A', 'b' => 'B']);    // => 'a'|'b'|null
$form->addMultiSelect('m')->setItems(['x' => 'X', 'y' => 'Y']); // => list<'x'|'y'>
```

Passing the items inline to `addSelect`/`addMultiSelect` narrows the same way:

```php
$form->addSelect('status', 'Status', ['draft' => 'Draft', 'live' => 'Live']); // => 'draft'|'live'|null
```

With `setRequired()` in a validated context, a closed single choice drops `null` and a closed multi-choice becomes a
non-empty list of keys (`'a'|'b'` / `non-empty-list<'x'|'y'>`).

`$control->items`, and (for a single choice) `$control->selectedItem` / (for multi-choice)
`$control->selectedItems`, read via the vendor's own `@property`/`@property-read` tags declared directly
on `ChoiceControl`/`MultiChoiceControl`. A PHPStan stub that redeclares a vendor class — the extension's
own catalog stub (`control-value-types.stub`) does, for `ChoiceControl`/`MultiChoiceControl`, purely to carry the custom
`@form-read-type`/`@form-choice` tags those two classes need — replaces that class's resolved docblock
outright rather than merging it with the real one, so a stub with an otherwise-empty body silently drops
any class-level `@property` tag the vendor declares on that same class. `BaseControl`'s own
`@property-read string $error` is unaffected (`BaseControl` isn't itself stubbed); only the two choice
classes needed their vendor tags restored — semantically, not verbatim: the vendor declares plain
`array $items` / `array $selectedItems`, and the stub types them `array<int|string, mixed>` instead,
deliberately, so the property itself doesn't trip `missingType.iterableValue`. nette/forms 3.2 adds
`@property bool|array<bool> $disabled` to both classes (a choice control disables single items); the stub restates
it, which is accurate on nette/forms 3.1 too. That restatement is what
[`VendorCatalogFreshnessTest`](#where-these-vendor-facts-live-and-how-they-are-kept-fresh) gates: a
`@property` Nette adds to a redeclared class later would otherwise be dropped as silently as these were.

## Containers

`addContainer()` nests a typed shape; a conditionally-added field becomes optional (`?`):

```php
$a = $form->addContainer('a');
$a->addText('b');
$form->getValues(); // => Nette\Utils\ArrayHash{a: Nette\Utils\ArrayHash{b: string}}
$form['a']['b'];    // => Nette\Forms\Controls\TextInput

if ($cond) {
    $form->addText('p');
}
$form->getValues(true); // => array{p?: string}
```

A container (or a replicator's item shape) built differently across branch arms doesn't claim every arm's children —
each field seen on only one arm becomes optional, same as a top-level conditional field:

```php
if ($cond) {
    $sub = $form->addContainer('sub');
    $sub->addText('x');
} else {
    $sub = $form->addContainer('sub');
    $sub->addText('y');
}
$form->getValues(); // => Nette\Utils\ArrayHash{sub: Nette\Utils\ArrayHash{x: string|null, y: string|null}}
```

## Replicators

Kdyby's `addDynamic`, contributte's `addMultiplier`, or any tagged replicator (
see [annotations](#supporting-your-own-controls-and-components)) project to a list of item shapes; `createOne()` and
`getContainers()` are typed, and offset access returns one item. The add-method must be a **real, declared method** on a
known/tagged container class — a replicator added through a magic `extensionMethod()` is not seen (see
[magic methods](#magic-add-methods-are-not-supported)):

```php
$form->addDynamic('rep', static function (FormContainer $c): void {
    $c->addText('x');
});

$form['rep'];                  // => array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}>
$form['rep'][0];               // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}
$form['rep']->createOne();     // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}
$form['rep']->getContainers(); // => Iterator<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}>
$form->getValues()->rep;       // => array<int, Nette\Utils\ArrayHash{x: string}>
```

`getContainers()` follows the installed kdyby/forms-replicator: version 2 declares `getContainers(): Iterator` and
gets `Iterator<int, Row>`, version 3 declares `: array` and gets `array<int, Row>`
(`ReplicatorMethodReturnTypeExtension` reads the native return type of the `Kdyby\Replicator\Container` ancestor
through PHPStan's reflection, memoised; any other declared type falls back to `Iterator`).

Instantiating the replicator container directly — by offset assignment or `addComponent()` — is equivalent; the factory
closure is read from the constructor:

```php
$form['rep'] = new CustomReplicatorContainer(static function (FormContainer $c): void {
    $c->addText('x');
});
$form['rep'][0]; // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}
```

A **bare replicator leaf** — the expression ending at the replicator itself, nothing chained after
it (`$form['rep']`, not `$form['rep'][0]`) — types as the replicator's own class wrapping the item
shape, not as the item shape directly (that is the `array<int, …>` rendering above). That distinction
is what keeps `createOne()`/`getContainers()` callable on the bare expression, and it is also why a
runtime int index off that same expression still answers with the item shape: the replicator type
carries an "any offset answers with the item shape" override that a plain item-shape type does not —
the latter would instead answer a string offset with one of the item's own fields, which is the wrong
question for an integer runtime index.

The row class comes from the item factory's own **declared** parameter type. A factory that declares
nothing — `static function ($row) { … }` — leaves it unknown, and an unknown row class is not a row
type: `$form['rep']` then resolves to the replicator's own class alone, and an offset off it falls to
that class's `ArrayAccess<string, IComponent>` — the same answer a factory that cannot be enumerated at
all (`[$this, 'fillRow']`) gets. The row's fields are still collected; they simply have no class to hang
on, and a type over an invented class name would be worse than no type, because PHPStan takes such a
class literally and reports `class.notFound` against every later member access on it.

```php
$form->addDynamic('rep', static function ($row): void { // no declared row type
    $row->addText('x');
});

$form['rep'];    // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer
$form['rep'][0]; // => Nette\ComponentModel\IComponent
```

The same replicator can be reached by genuinely different machinery depending on where it sits. One
added directly on the tracked root goes through the component-access walk (a method-call-shaped
extension resolving `$form->offsetGet('rep')`/`$form['rep']` from the walker's own bare-leaf branch).
One reached through an intermediate container — `$form->addContainer('outer')->addDynamic('rep', …)`,
then read back as `$form['outer']['rep']` — goes instead through the direct array-access resolver, which
types a bracket expression ahead of any method-call extension and builds the replicator type in its own,
separate branch. Both branches read the replicator's own class from the same underlying shape data and
build the same kind of type from it, but they are two independent pieces of code, so the same class of
mistake (pairing the replicator type with the item's class instead of the replicator's own) can exist in
one without the other.

Kdyby Replicator's convention of naming its remove/add buttons `removeNode` and `<name>-addNode` (bound
via `n:name` in the row/holder templates) is ordinary application code, not something the vendor injects
on your behalf: `addRemoveOnClick()`/`addCreateOnClick()` only attach extra `onClick` behaviour to a
button your own `addSubmit()` call already added, so the button is a normal, traceable component like
any other. What actually made these two names notable is unrelated to replicators as such: a control
that contributes no *value* (`addSubmit()`, `addImageButton()`, `addImage()`, `addReCaptcha()` with a
literal name) still **exists as a component** — reaching it (`$form['removeNode']`) is not the same
question as reading its value (`$form->getValues()->removeNode`), and the existence check used to
answer both questions the same way, off containers/replicators/slots alone, never off the registry a
value-less control is recorded in instead. Every named submit-like control in every form misread as
absent under a component-side access; replicator row/holder templates just reference `removeNode`/
`addNode` constantly, which is why it surfaced there first.

A control added straight onto the replicator **container itself** — the value `addDynamic()`/
`addMultiplier()` returns, e.g. `$rep = $form->addDynamic('rep', …); $rep->addSubmit('addNode', …);` —
is a different thing from a control added inside the item-factory closure: the former is the
replicator holder's own child (this is where the `<name>-addNode`/`removeNode` naming convention
described above — ordinary application code, not something Kdyby Replicator injects — lives), the
latter is a child of every row. `FormReplicatorType` resolves both: an integer offset is always a row, a constant
string matching an own child resolves to that child's own type — never the row, never `ErrorType` —
and anything else degrades to whatever the wrapped class alone would answer (`IComponent`).

An offset with **no constant value at all** is a row too whenever PHPStan can prove its *type* is a
decimal-integer string — `$rep[(string) $i]`, or a variable inside a `ctype_digit()` guard, both of
which reach `decimal-int-string` with no annotation. So `$rep[(string) $i]['field']` resolves the
field instead of degrading to `IComponent` (and losing the following offset access with it). Only a
proof counts: a plain `string`, a `numeric-string` (which covers `'1.5'`) and anything else stay at
`IComponent`. The reverse proof — a string PHPStan knows can *not* collapse to an int key,
`non-decimal-int-string` — is deliberately not acted on: knowing an offset is not a row still yields
no *name* to look up among the own children, so there is nothing better to answer.
`FormShapeUnknownAccessRule` classifies existence against the replicator's own-children set, including
through Nette's own `-`-separated path form (`$form['outer']['rep-addNode']`, equivalent to
`$form['outer']['rep']['addNode']`, per `Container::getComponent()`'s own recursive split —
`vendor/nette/component-model/src/ComponentModel/Container.php:116`). Only a directly-assigned capture
of the add call (`$rep = $form->addDynamic(…)`) is tracked for further own-child additions — a
discarded or fluently-chained return value (`$form->addDynamic('rep', fn)->addSubmit('addNode', …);`,
or `$form['rep']->addSubmit('addNode', …);` without ever binding `$form['rep']` to a variable) leaves
the holder's own shape **open** rather than closed: the walk cannot see what, if anything, was added
onto an escaped reference, so the rule degrades (reports nothing) for any name reached through it,
rather than misreporting a real addition as absent. A plain container answers the same uncaptured-
return-value gap the same way: `$form->addContainer('a')->addText('z');`,
`$this->sub = $form->addContainer('a');` and `helper($form->addContainer('a'));` all open the
container instead of closing it empty. The one uncaptured shape that still closes is the add call
that IS the whole statement — `$form->addContainer('a');` drops the reference on the floor, so
nothing can ever have added to it and `$form['a']['nope']` is still reported.

A replicator whose **item factory is not an inline closure** (a callable array, a variable holding a
closure, a first-class callable) opens its ROW the same way — the row's children cannot be enumerated
at all, so `$form['rep'][0]['q']` degrades rather than reporting `q` as absent.

Both of the two machineries above answer that own-child access, but only one of them is available
everywhere. The direct array-access resolver is gated on the file *being analysed* containing tracked
form-building code, so it never runs for a `.latte` (a template only reads a form built elsewhere) nor
for any PHP file that merely consumes a form. Those are served by the component-access walk instead,
which resolves the mid-chain hop — a replicator segment followed by a final own-child name, in either
the `['rep']['addNode']` or the `-`-joined `['rep-addNode']` spelling — by delegating to the same
projector the resolver uses, so the two cannot drift apart. A name that projector cannot prove falls
back to the walk's ordinary arms and ends at `IComponent`, never at an error type.

```php
$rep = $form->addDynamic('rep', static function (FormContainer $c): void {
    $c->addText('field');
});
$rep->addSubmit('addNode', 'Add');

$form['rep']['addNode'];       // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton
$form['rep'][0]['field'];      // => Nette\Forms\Controls\TextInput
```

## Wizards

A forms-wizard component projects `getValues()` to its steps, keyed by step number (each step optional):

```php
$wizard->getValues();              // => array{1?: Nette\Utils\ArrayHash{username: string}, 2?: Nette\Utils\ArrayHash{email: string}}
$wizard->getValues()[1]->username; // => string
```

A wizard's step shape comes from its own `createStep1()`, `createStep2()`, … methods, so it is projected wherever the
wizard is reached — a `createComponentWizard()` return, a callback parameter, or even reached as a child it was added to
(`$form['wiz'] = new MyWizard(); $this['form']['wiz']->getValues()`). Unlike a replicator (whose item shape is read from
the factory closure passed at the add site), a wizard needs no add-site handling: the steps are intrinsic to the class.

## DTO mapping

`setMappedType(Dto::class)` (or `getValues(Dto::class)`) returns the DTO, so you read its declared, typed properties
directly. The form-to-DTO mapping itself is validated by a rule (see [What's checked](#whats-checked)):

```php
$form->setMappedType(MappedFormDto::class);
$form->addText('name');
$form->addInteger('age');

$this['form']->getValues();       // => Tests\...\MappedFormDto
$this['form']->getValues()->name; // => string
$this['form']->getValues()->age;  // => int|null

// getValues(true) / getUntrustedValues() ignore the mapped type:
$this['form']->getValues(true);   // => array{name: string, age: int|null}
```

---

# Supporting your own controls and components

To make the engine understand a custom control or component, add a tag to its PHPDoc. No configuration, no rule
changes — the tag is picked up by class. For a third-party class you don't own, ship the same tag via a PHPStan stub for
that class; the engine reads it the same way.

There are eleven tags. Ten are for your own classes; the eleventh, `@form-write-spec`, exists only for the vendor
catalog and is documented last.

**`@form-read-type <type>`** / **`@form-write-type <type>`** — the value a control reads (its `getValue()` / values
projection) and the value `setValue()` accepts:

```php
/**
 * @form-read-type 'a'|'b'|'c'
 * @form-write-type 'a'|'b'|'c'
 */
final class RatingControl extends BaseControl
{
}

// $form['rating']->getValue();  // => 'a'|'b'|'c'
```

**`@form-modifier nullable|required`** — a fluent method on the control that flips its read type to nullable or
required, exactly like `setNullable()`/`setRequired()`:

```php
/** @form-modifier nullable */
public function asNullable(): self
{
    return $this;
}

// $form->addRating('x')->asNullable();  // => 'a'|'b'|'c'|null
```

A full custom control, exercised through a factory method, an offset assignment, and a modifier:

```php
$form->addRating('viaMethod')->setRequired();
$form['viaOffset'] = new RatingControl();
$form->addRating('viaModifier')->asNullable();

$form->getValues();
// => Nette\Utils\ArrayHash{viaMethod: 'a'|'b'|'c', viaOffset: 'a'|'b'|'c', viaModifier: 'a'|'b'|'c'|null}
```

**`@form-read-by-arg <key>=<type>; *=<type>`** — a method whose argument selects the read type (the key may be a
class-constant reference; `*` is the fallback):

```php
/** @form-read-by-arg Sample\ModeControl::ModeInt=int; Sample\ModeControl::ModeJson=array<string, mixed>; *=string */
public function withMode(string $mode): self
{
    return $this;
}

$form->addMode('asInt')->withMode(ModeControl::ModeInt);   // value => int|null
$form->addMode('asJson')->withMode(ModeControl::ModeJson); // value => array<string, mixed>|null
$form->addMode('asFallback')->withMode('whatever');        // value => string|null
$form->addMode('dyn')->withMode($this->dynamicMode);       // non-literal arg => mixed
```

**`@form-rule-cast <ruleId> <readType> <writeSpec>`** — teaches a validation rule the cast it applies (this is how
`Form::Integer` becomes `int`); put it on a method whose tag carries the rule identifier:

```php
/** @form-rule-cast :integer int integer */
public function integer(): void;

/** @form-rule-cast :float float float */
public function float(): void;
```

**`@form-choice <single|multi> <key-domain>`** and **`@form-choice-open <extra-type>`** — make a control behave like a
choice: `setItems()` narrows its value to the item keys (closed). `@form-choice-open` widens that to the keys plus an
extra type (for controls that also accept free input):

```php
/**
 * @form-read-type string|null
 * @form-choice single string
 */
final class ClosedStringControl extends BaseControl {}
// ->setItems(['a' => 'A', 'b' => 'B'])  =>  'a'|'b'|null

/**
 * @form-read-type int|string|null
 * @form-choice single int
 * @form-choice-open string
 */
final class OpenRatingControl extends BaseControl {}
// ->setItems([1 => 'One', 2 => 'Two'])  =>  1|2|string

/**
 * @form-read-type list<int|string>
 * @form-choice multi int
 * @form-choice-open string
 */
final class OpenTagsControl extends BaseControl {}
// ->setItems([1 => 'One', 2 => 'Two'])  =>  list<1|2|string>
```

**`@form-replicator <factoryArgPos> [containerClass]`** — marks a container class as a replicator; `<factoryArgPos>` is
the position of the item-factory callable in the add-method. This is how a third-party multiplier is supported — tag the
container class, and `addMultiplier(...)`/offset/`getValues()` all resolve:

```php
/** @form-replicator 1 */
final class Multiplier extends Container {}

$form->addMultiplier('rep', static function (Container $c): void {
    $c->addText('x');
});
$form['rep'][$i];        // => Nette\Forms\Container{x: string}
$form->getValues();      // => Nette\Utils\ArrayHash{rep: array<int, Nette\Utils\ArrayHash{x: string}>}
```

**`@form-wizard [stepPrefix]`** — marks a component as a forms-wizard; its `createStep1()`, `createStep2()`, … methods
become the numbered steps (override the `createStep` prefix with the optional argument):

```php
/** @form-wizard */
final class MyWizard extends Wizard
{
    protected function createStep1(): Form { /* ... */ }
    protected function createStep2(): Form { /* ... */ }
}

/** @form-wizard buildStage */
final class CustomStepPrefixWizard extends Control {} // steps come from buildStage1(), buildStage2(), …
```

**`@form-disabler`** — marks a container method that disables the whole container (typically by looping `setDisabled()`
over `getComponents(true)`); calling it on the form omits every descendant control from `getValues()`, while leaving the
components reachable:

```php
/** @form-disabler */
public function setDisabled(): void
{
    foreach ($this->getComponents(true, BaseControl::class) as $control) {
        $control->setDisabled();
    }
}

$form->addText('a');
$form->setDisabled();
$form->getValues(); // => Nette\Utils\ArrayHash{}
$form['a'];         // => Nette\Forms\Controls\TextInput  (still reachable)
```

**`@form-adds $name [ControlClass]`** — declares what a **generic** `add*` helper registers: a method on a form or
container whose component name arrives as a **parameter** rather than as a literal in the body. This is the one shape
the walk cannot resolve from the declaration alone, because at the declaration site there is no name to record:

```php
final class ApplicationForm extends Nette\Application\UI\Form
{
    /** @form-adds $name */
    public function addThing(string $name): TextInput
    {
        return $this->addText($name);
    }
}

$form->addThing('q');
$form['q'];   // => Nette\Forms\Controls\TextInput
```

- **`$name` names the parameter carrying the component name**, which need not be the first one. At the call site that
  argument is read as a constant string — positionally or as a named argument. A **non-constant** argument degrades
  (the shape opens); it is never guessed at.
- **The class operand is optional.** Omitted, the class comes from the declared return type — the two are two sources
  of one thing, and naming the class a method already returns resolves to exactly the same shape. Spell it out when the
  method **does not return the control**, which is the case the return type cannot cover:

  ```php
  /** @form-adds $name Nette\Forms\Controls\TextArea */
  public function addAttached(string $name): void
  {
      $this->addTextArea($name);
  }
  ```

- **The tag is repeatable**, for a method adding several components under distinct name parameters:

  ```php
  /**
   * @form-adds $first Nette\Forms\Controls\TextInput
   * @form-adds $second Nette\Forms\Controls\SelectBox
   */
  public function addPair(string $first, string $second): void
  ```

- **The value type is not part of this tag.** It follows from the control class's own `@form-read-type`, so an
  annotated helper registering a custom control picks that control's types up unchanged. (Nette's own factories are
  the exception, and the reason the vendor catalog is method-keyed — `addInteger()` and `addText()` are the same
  control class reading different things. See
  [where the vendor facts live](#where-these-vendor-facts-live-and-how-they-are-kept-fresh).)

The class must be written **fully qualified** — a docblock tag is not resolved against the file's `use` statements.

The annotated method must still be **named `add*`**. Whether a call registers anything is decided by the method name,
which this tag does not yet override, so a tag on `attachThing($name)` is accepted as valid and then registers nothing
— and the form's shape stays closed, which proves the component absent. Name the helper `addAttached($name)` and the
tag takes effect.

The tag is valid **only on a method declared on a `Nette\Forms\Container` subclass** (a form or a container). A
form-building helper declared on a Control or Presenter (`$this->addUserContactInputs($form)`) is out of scope and
carrying the tag there is reported. An invalid tag is always **reported rather than silently ignored** — see
[What's checked](#whats-checked).

**`@form-write-spec <spec>`** — the eleventh tag, and the one you will never write. It names the built-in
accepted-value spec a **vendor** factory's control uses where that is not the spec its control class implies, and it is
read only from `FormValueTypeCatalog`:

```php
/**
 * @form-read-type int|null
 * @form-write-spec integer
 */
public function addInteger(): void;
```

`addInteger()` and `addFloat()` build a `TextInput`, which on its own would be typed `text`; the spec records what the
input really accepts. To declare a custom accepted type on a control **you** own, use `@form-write-type`, which takes a
type rather than a spec name. See [where the vendor facts live](#where-these-vendor-facts-live-and-how-they-are-kept-fresh).

## Where the tag is asked for, and where it is the only channel

The tag matters in two different ways depending on which side of the **analysed paths** the declaration is on, and the
line is drawn from the config's declared `paths` with realpath containment — never from a `vendor/` spelling, and never
from PHPStan's CLI-narrowed file list, so a single-file or IDE run draws it exactly where a whole-project run does.

**Inside the analysed paths — a hint, and nothing opens.** A container method that registers exactly one component
whose name arrives as a **parameter** is reported as `orisai.nette.forms.unannotatedRegistrar`:

```
Acme\Form\ContainerSharedMethods::addEmail() registers one component under $name and does not declare it
with @form-adds $name; the registration is then visible only while this class stays inside the analysed paths.
```

Nothing is wrong with the shape today — the declaration is right there to be read. What the report is about is the day
the class is extracted into a package: the declaration leaves the analysed paths, nothing can read it any more, and the
registration disappears with no diagnostic at all. One tag makes it survive the move.

Only methods a tag could actually **describe** are reported, which is what keeps a specific form's build method out of
it. A method registering several components, or under a name the declaration already fixes, or on some paths only, is
not annotatable — extraction loses information there too, but no tag could have carried it, so asking would be noise.
Neither finality nor visibility is consulted; both say nothing, a build method being as often non-final and public as a
generic adder. A **trait**'s method is reported against the trait, not against each class using it.

The rule is on by default and switched off with `orisai.nette.forms.reportUnannotatedRegistrars: false`.

**Outside the analysed paths — the tag is the only channel, and its absence opens.** There the body is not the
project's to read, so the walk falls back on its own convention: one component, named by the call's **first argument**.
When that convention is *refuted* — the declaration registers under some other parameter, or registers more than one
component — and no `@form-adds` says what it really does, the component name is dropped and the shape **opens** with
`unannotated_registrar`:

```php
// in a package, unannotated
public function addLabelled(string $label, string $name): TextInput { … }

$form->addLabelled('E-mail', 'email');
$form['email'];   // may not exist; the form shape is open
```

Reading argument 0 there would register a component called `E-mail` and prove `email` absent, which is worse than not
knowing. Annotating the method (or, for a package you do not own, a stub of it) resolves it exactly as an analysed
declaration resolves.

The body is read only to **disprove** the convention, never to resolve the registration — a body the parser cannot see,
or one that registers nothing through `$this[$name] = …`, `$this->addComponent(…, $name)` or an `add*` call on `$this`,
leaves the convention standing and the shape closed. An **undetected** registration in a package therefore still
closes, and that cost is accepted rather than paid by degrading every third-party call.

## An `add*` helper with no declared return type

The control class an `add*` method registers is normally read off its **declared return type**, which is what makes a
custom helper shape like a vendor one. A helper that declares nothing is read off its own **body** instead — PHPStan
reflects the declared return and infers nothing from a body, so the reflected answer there is `mixed`, but the class is
still sitting in the returns waiting to be read:

```php
public function addThing(string $name)      // no declared return type
{
    $control = new TextInput();
    $this[$name] = $control;

    return $control;
}

$form->addThing('q');
$form['q'];   // => Nette\Forms\Controls\TextInput
```

The reading is the scope-free resolution the walk already applies to a form variable's defining site: a `new X()`, a
local bound to one, a typed parameter, `$this`, and another method's declared return (`return $this->addText($name);`)
all resolve. The whole ladder then treats the answer exactly as a declared return — a body returning a
`@form-replicator` container makes the helper a replicator, one returning an annotated custom control picks up its
`@form-read-type`.

A method that names **any** class in its return type is not read this way — natively or in a docblock, precisely or
widened to a base control. A declaration is a statement, and widening one to `BaseControl` is a deliberate act of
imprecision rather than an omission, so the declared answer stands and the field degrades exactly as it did before.

The body has to **prove** it, and proves nothing in these cases, each of which leaves the slot exactly as opaque as it
was — present, `mixed`, one `orisai.nette.forms.partiallyUnknown`:

- returns that disagree on the class (`if ($long) { return new TextArea(); } return new TextInput();`);
- a last statement that is not a return, so some path falls through to `null`;
- a return this cannot read (a property fetch chain, a call on an untypeable receiver);
- a body the parser stripped — every vendor file outside the analysed set, which is why this never invents a class for
  a third-party helper.

Presence is a separate axis, decided by the rule in the next section.

## Whether a call registers a component at all

The name is what puts a call in front of the question — `add*` on a container, or `$form['x'] = …` — and it is not what
answers it. On a method declared by a `Nette\Forms\Container` subclass the answer is read, in order, from:

1. **a `@form-adds` tag**, which registers whatever it declares, under the parameter it names;
2. **the declared return type**, when it names an `IComponent`: the method hands a component back, so it registered one;
3. **the body**, when the declared return type says the method returns no component at all — `void`, `null`, a scalar,
   or an object that is not an `IComponent`.

Step 3 is where a name-only rule used to be wrong in both directions, and the body settles it:

| The body… | The call… |
|---|---|
| registers one component, unconditionally, named by argument 0 | **registers it**, with no value type — a `void` return says nothing about *what* was registered, so the field is present and `mixed` |
| registers nothing | **registers nothing**, and the shape stays **closed**. This is why Nette's own `Form::addError()` and `Form::addGroup()` are inert, and why a project method shaped like them is inert too — no list of names says so |
| registers under a name argument 0 does not carry | **degrades**: nothing is recorded and the shape **opens**. Reading argument 0 would register the error message or the caption and prove the real component absent |
| cannot be read at all | **degrades** the same way — an unreadable body proves nothing in either direction |

Two consequences worth spelling out:

- **Writing `: void` on a helper that really registers no longer hides it.** Before this rule the more informative
  declaration produced the worse answer: the name was dropped from a shape that then closed, so `$form['slot']` reported
  that a component the helper had just added did not exist. It now resolves exactly as the same helper with **no**
  declared return type does — present, `mixed`, one `orisai.nette.forms.partiallyUnknown`.
- **The body only ever disproves.** It is never asked which parameter names the component; that is what `@form-adds`
  is for, and what `orisai.nette.forms.unannotatedRegistrar` asks you to write. A registrar naming its component from some other
  parameter degrades until the tag is there.

The body is read syntactically, by the same detector the analysed/vendor split uses: `$this[$name] = …`,
`$this->addComponent(…, $name)` and an `add*` call on `$this`, with closure interiors excluded. A registration spelled
any other way is not seen, and a body registering only that way reads as registering nothing.

## Existence checks whose answer is already known

`isset($form['x'])` is not a passive question. `Nette\ComponentModel\ArrayAccess::offsetExists()` is

```php
return $this->getComponent($name, false) !== null;
```

so it runs the same lazy **create-and-attach** block every read runs. Two things follow, and the
second is the one that reads backwards:

- a child the shape holds makes the check constantly **true**, as it would for a plain array;
- **a `createComponentX()` factory also makes it constantly true**, because the check *builds and
  attaches* the child. `createComponent()` refuses to let a factory neither return nor attach a
  component — it throws instead — so there is no path on which a declared factory leaves the name
  unheld.

The interesting always-**false** case is therefore *neither held nor buildable*, never merely "not
attached". `orisai.nette.forms.constantExistenceCheck` reports both:

```php
$form = new ApplicationForm();
$form->addText('a');

isset($form['a']);       // Form component 'a' in isset() always exists.
isset($form['nope']);    // Form component 'nope' in isset() never exists.
                         // 💡 The form declares no createComponentNope(), so the check cannot build one either.
```

and, on a form that declares `createComponentSub()`:

```php
isset($form['sub']);     // Form component 'sub' in isset() always exists.
```

even though `sub` is absent when the check starts — **the check attaches it**. `$form->offsetExists('x')`
spelled out answers identically.

This is the other half of `orisai.nette.forms.noSuchComponent`. An `isset()` marks its access as an existence
*check*, which is exactly why the absence rule stays silent on it (see EC-01), so a name gets at most
one report from the pair and the two can never disagree: both read presence through the same
`ComponentPath` authority, whose NEVER is the same closed-and-absent proof.

### What it deliberately does not report

- **A Maybe.** An open shape, a child added on one arm only, a non-constant offset, an unresolvable
  receiver.
- **`empty()` and `??`.** `empty()` is `isset()` plus a truthiness test and `??` is a value read, so
  neither answer is the check's. Both stay marked as existence checks and stay silent.
- **A `-`-joined path.** Nette resolves it hop by hop; the presence this reads describes one child.
- **A `$values` property** (`isset($v->nope)`), which is a data hash, not a component tree.
- **The side effect on its own.** An `isset()` that runs a factory attaches a component, and where
  the answer is provably true that is what the report says. An `isset()` whose *result is discarded*
  is not called out separately — nor does the shape walk model the attachment, exactly as it does not
  for `$form['x']`.

# Magic `add*` methods are not supported

The engine reads the `add*` calls in your form-building code, so the methods must be **real, declared methods** that
PHPStan can see by reflection. Methods registered at runtime via `Nette\Forms\Container::extensionMethod()` (the old
`__call`-based mechanism — e.g. raw Kdyby `addDynamic`/`addRemoveOnClick`) are not real methods: PHPStan reports the
call as undefined and no shape is inferred.

Add the component through a real mechanism instead, so there is no magic method to resolve. Two patterns:

- **Offset assignment** — add the container directly, with no magic `add*` call:

  ```php
  $form['replicator'] = new Kdyby\Replicator\Container($factory, ...);
  ```

  This removes the undefined-method error and tracks the component as a child. Added this way, though, a replicator's
  inner item shape is **not** projected (the constructor's factory argument isn't read — `$form['replicator'][0]` stays
  `IComponent`); for typed item access use the pattern below.

- **Extend and share via a trait** — the shared-trait pattern, which gives full inference. Extend both the form and
  the container, and declare the custom `add*` methods on a shared trait so they are real on both (and on replicator
  containers):

  ```php
  final class ApplicationForm extends Nette\Application\UI\Form { use FormSharedMethods; }
  final class FormContainer extends Nette\Forms\Container { use ContainerSharedMethods; }
  // a replicator container that still has every custom add-method:
  final class CustomReplicatorContainer extends Kdyby\Replicator\Container { use ContainerSharedMethods; }

  // FormSharedMethods uses ContainerSharedMethods, which declares addDynamic(): CustomReplicatorContainer,
  // addContainer(): FormContainer, addEmail(), addSubmit(): CustomSubmitButton, …
  ```

  Because `addDynamic()` (and friends) are declared methods with concrete return types, `$form->addDynamic('rep', …)`
  shapes like any other control. This also keeps presenter-attached forms on `Nette\Application\UI\Form` (see
  [What's checked](#whats-checked)).

# Debugging

`dumpType($expr)` prints the precise inferred type, including the inner shape of a form or container — use it on any
access to see what the engine resolved.

`dumpComponent($component, ?int $depth = null, bool $formValues = true)` prints the **component shape** of any component
— a control containing a form, a container, a replicator, or a wizard — as a PHPStan-shape block: a form/container is
`ClassName{ field: …, … }`, an input is `ControlClass<write-type, read-type>`, a replicator is
`ReplicatorClass<array<int, item>>`, and an open shape carries a trailing `...<IComponent>`. Optional children get a `?`.

Two parameters tame deep trees: `$depth` limits nesting (`null` = unlimited; `0` = the root only; a node whose children
are cut off renders as `ClassName{ … }`), and `$formValues = false` drops the `<write, read>` generics, leaving just the
structure (`name: ControlClass`).

```php
dumpComponent($this['form']);          // full shape
dumpComponent($this['form'], 1);       // root + immediate children only
dumpComponent($this['form'], null, false); // structure, no value types
```

A flat form:

```
Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm{
  age: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, int|null>,
  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
  save: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
}
```

A replicator (its container class with the item list as a generic):

```
Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm{
  items: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
    label: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
  }>>,
}
```

A wizard (an unresolvable step renders open with `...<IComponent>`):

```
Tests\...\MyWizard{
  step 1: Nette\Application\UI\Form{
    username: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
  },
  step 2: Nette\Application\UI\Form{
    email: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
  },
}
```

`assertComponent($component, string $expected, ?int $depth = null, bool $formValues = true)` is the test counterpart of
`dumpComponent` (mirroring PHPStan's `assertType`): it renders the same block and reports an error unless it equals
`$expected`, so a fixture pins a component's shape and any regression surfaces as a failing assertion. `$depth` and
`$formValues` behave exactly as on `dumpComponent`.

```php
assertComponent($this['form'], <<<'OUTPUT'
Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm{
  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
}
OUTPUT);
```

`dumpFormValues($component)` and `assertFormValues($component, string $expected)` are the read-values-only pair: they
print/assert what `$component->getValues()` yields — the form's `ArrayHash{field: …, …}` crate with control classes
dropped, nested containers as nested `ArrayHash{…}`, and an open shape carrying `...<mixed>`. Use them when only the
value structure matters; use `dumpComponent`/`assertComponent` when you need the control classes and write types.

```
Nette\Utils\ArrayHash{name: string, address: Nette\Utils\ArrayHash{city: string, zip: string}}
```

All four helpers live in `OriPhpstan\Nette\Forms\Testing` and are no-ops at runtime (they only do anything under PHPStan),
so you can leave them in while iterating. Like `getValues()`, they are context-aware: inside a validated branch
(`if ($form->isValid()) { … }`) or an onSuccess handler the read values narrow to their post-validation form — a
required `addText()` reads `string` normally but `non-empty-string` there.

# Cache invalidation

Two caches sit under this analysis and they answer different questions: the **shape store**, which remembers what a
form resolved to, and PHPStan's own **result cache**, which decides whether a file is re-analysed at all. Both are
described below, because the second one has a cost you will feel.

## The shape store

Inferred shapes persist to disk under `%tmpDir%/form-shape-cache/v<code-version>[-<catalogs>]/`, content-validated
rather than cleared between runs — a `.ser` entry is only ever served when every input that shaped it is unchanged.

The `v<code-version>` directory rotates (isolating entries from a prior run without deleting them) whenever any of the
following changes (`FormsCodeVersion`):

- Any `.php` file under `src/Forms/` or `src/Component/Attachment/` (`FormsCodeVersion::roots()` — every source tree
  a persisted shape can have been derived by; adding a tree there costs one full invalidation).
- The `orisai.nette.forms.defaultContainerClass` parameter — the class a custom container/replicator falls back to when its
  concrete type can't be resolved from the call site.
- The declared `paths` (`FormsCodeVersion::pathsDigest()`: resolved, deduplicated, sorted). `AnalysedPaths::isAnalysed()`
  decides which files count as project code — `ContainerModel`'s vendor-method gates, the registrar convention check in
  `NetteEffectiveControlValueTypeResolver` and `IndexShapeResolver`'s containment gate branch on it — and the shapes
  derived under one answer persist under content-only keys, so a directory moving in or out of `paths` over unchanged
  files would otherwise be served stale. A respelling of the same universe keeps the store.
- The installed `phpstan/phpstan` version — its serialized `Type` layout can change across releases.

The `-<catalogs>` suffix is `CatalogIdentity`: a sha1 over the sorted `orisai.nette.forms.catalogs` class names, each
with `sha1_file()` of its declaring file. It is empty without user catalogs. It is resolved on first use, not at
container build, because the reflection provider knows analysed classes only once analysis runs. The same identity is
folded into `FormsResultCacheMeta`, so editing a catalog also discards PHPStan's result cache.

Only the main process prunes directories of other versions, once per run; parallel workers share the run's directory.

Within a version directory, each entry additionally records every file it read while computing the shape, and is
recomputed the moment any of them changes — not just the analysed file itself. This includes:

- The analysed file's own content hash (the entry's primary key).
- A class referenced via reflection while resolving a variable's class scope-free (e.g. a factory method's declared
  return type, a typed parameter, a property type) — the referenced class's declaring file.
- A class constant read for a component name or a choice control's item keys (`SomeClass::NAME`, `SomeClass::ITEMS`)
  — the constant's declaring file, which may differ from the referencing class when inherited.
- Every class (and its parents, interfaces, and traits) referenced by the resulting shape — its class name, mapped
  DTO type, and every slot's control/value classes.
- For a [form held in a property](#forms-held-in-a-property), the file of **every** method of the declaring class — the
  ones that build it, and every other body that could write to it — since which methods are builders, and whether any
  other body mutates the form, are themselves part of the answer. A trait-declared builder brings the trait's file, a
  visibility change on the declaration brings the declaring class's.

These are the store's records: they decide whether a *shape* is recomputed. They do not decide whether a *file* is
re-analysed — that is the result cache's job, and it tracks a different thing.

## PHPStan's result cache

PHPStan re-queues a changed file's dependents only when that file's *exported nodes* — its declarations — change.
Nearly every cross-file fact this extension derives is a method **body** fact instead: `$form->addText('id')` inside a
`createComponentX()` or a form subclass's constructor. Adding, removing or retyping a field there leaves every exported
node byte-identical, so a file elsewhere that reads the resulting shape is never re-analysed. It keeps a shape whose
input is gone, and with it swallows real errors — typically
`Call to an undefined method Nette\ComponentModel\IComponent::setRequired()` on a field that no longer exists.

To close that, the extension registers a `ResultCacheMetaExtension` (`FormFactSalt`) over the **shape-affecting
subset** of the analysed files. Change any file in that subset and the whole result cache is discarded and the project
re-analysed from scratch. There is no finer granularity to be had — a `ResultCacheMetaExtension` is a whole-cache
mechanism by construction — so this is a real trade, not a free correctness win.

**What is in the subset.** A file whose source carries a construct that can move a shape: an `->add*()`/`::add*()`
call, a `createComponentX()` or `createStepN()` name, `$form['x'] = …`, `unset()`, one of the component and value
mutators (`setRequired()`, `setNullable()`, `setOmitted()`, `setDisabled()`, `setMappedType()`, `setItems()`,
`setParent()`, `removeComponent()`, `offsetSet()`, `offsetUnset()`, `monitor()`, `createOne()`), or a `@form-*`
annotation — plus any file declaring a class constant one of those reads, since
`$form->addText(FieldNames::ID)` makes that constant's literal a shape input. On a real application that is roughly a
quarter of the analysed files, and far more where form work actually happens — so an edit inside a form-heavy
directory often turns the next warm run into a cold one. That is the price of a warm run reporting exactly what a cold
one does, and it is worth knowing before you wonder why the run got slow.

**What stays cheap.** The digest is taken over a normalised token stream, so a comment-only edit, a reformat or a
docblock reindent inside a form-bearing file keeps the whole cache (a `@form-*` annotation is real content and does
not). Editing any file outside the subset keeps it too, and a run in which nothing changed pays only a `sha1_file`
sweep of the universe — about 50 ms.

**Known limitation.** The subset is recognised from the token stream, so a shape input that is neither one of the
constructs above, nor an exported node, nor a class constant reached through them is invisible to it — a PHP 8
attribute, for instance, which the PHP 7.4 tokeniser running the analysis lexes as a comment. If you teach the
extension to read a new shape-affecting construct, teach `FormFactSalt` about it in the same commit;
`FormFactSaltTest` drift-checks its vocabulary against the method names the extension spells and will fail if you
forget.

# What's checked

Beyond inference, a few rules flag misuse:

- **Writing an incompatible value** — a write whose value is not in the control's accepted write type, on any of
  `setValue()`, `setDefaultValue()`, `setDefaults()`, `setValues()` (e.g. `addText` accepts `scalar|Stringable|null`, so
  `setValue([1, 2])` is reported; `setValue()` on an upload control has no effect and is reported too).
- **Reaching a component that doesn't exist** — `$form['nope']`, `$form->getComponent('nope')` or
  `$form->offsetGet('nope')` (or after a removal) when the shape proves there is no such child:
  `Form component 'nope' does not exist.` (the no-throw `getComponent('nope', false)` / `offsetExists` checks are not
  reported.) A control that contributes no *value* (`addSubmit()`, `addImageButton()`, `addImage()`,
  `addReCaptcha()` with a literal name) still counts as existing here — only a *values*-side read of it
  (`getValues()`, `$form->getValues()->save`) is reported as missing, since it is the value that is
  genuinely absent, not the component. This also covers a control added straight onto a replicator holder
  (`$form['rep']['addNode']`, see [Replicators](#replicators)), including Nette's `-`-separated path form
  (`$form['outer']['rep-addNode']`, equivalent to `$form['outer']['rep']['addNode']`) — provided the holder was
  captured to a variable when the control was added; a discarded or fluently-chained holder opens instead (see
  the known-limitations table below). A `-`-separated path is reported on whichever segment the lookup provably
  fails at, not only on the last one: `$form['nope-x']` reports `Form component 'nope-x' does not exist.`, naming
  the full path the author wrote (see [Component paths](#component-paths-a-b)).
- **Reaching a component through an invalid name** — a `-`-separated path with an empty segment (`$form['outer-']`,
  `$form['-a']`, `$form['a--b']`, `$form['-']`) is reported as `Form component path 'outer-' has an invalid segment
  ''; a component name must be a non-empty alphanumeric string.` Unlike every other rule here this one does not
  need the shape to be closed: `Container::addComponent()` applies the same `NameRegexp` that `getComponent()`
  checks, so no unenumerated build step could ever have registered such a name and the lookup throws regardless.
- **Registering a component under an invalid name** — the write-side counterpart of the previous rule.
  `Container::addComponent()` applies the same `NameRegexp` on the way in that `getComponent()` applies on the way
  out and **throws** `Nette\InvalidArgumentException` for a name that fails it, so `$form->addText('bad name')`,
  `$form->addText('first-name')` (the `-` is the path separator — legal *inside* a lookup path, never inside a
  name) and `$form->addContainer('')` are guaranteed runtime failures. Each is reported as
  `Component name 'bad name' is rejected by Nette at registration: addComponent() accepts a non-empty name of
  [a-zA-Z0-9_] only, and throws otherwise.` A call qualifies when the receiver is a `Nette\ComponentModel\Container`,
  the method is `add*` with a parameter called `$name`, and it **returns the component it registered** — which
  covers Nette's whole factory surface, `addComponent()`'s second parameter, and a project's own
  `addContainer()`/`addDynamic()` trait wrappers, while excluding `addRule()`/`addCondition()`/`addFilter()`/
  `addError()`/`addGroup($caption)` and look-alikes such as `Ublaboo\DataGrid\DataGrid::addColumnText($key, $name)`,
  whose `$name` is a human-readable column title. Only a name written as a single constant string is judged; a
  computed one is never guessed at.
- **An invalid `@form-adds` tag** — the tag declares a registration the engine then relies on, so one that cannot be
  honoured is reported instead of quietly doing nothing. Every occurrence the reader refuses produces exactly one
  error, and an occurrence that is reported is never also used:
  `orisai.nette.forms.outsideContainer` (the method is not declared on a `Nette\Forms\Container` subclass — including a tag on a
  free function, a closure or an arrow function), `orisai.nette.forms.malformed` (the tag does not parse as
  `@form-adds $parameterName [FullyQualifiedControlClass]`), `orisai.nette.forms.unknownParameter` (`$name` names no parameter of
  the method), `orisai.nette.forms.duplicateParameter` (two occurrences name the same parameter, so one of them is a copy-paste),
  `orisai.nette.forms.unknownControlClass` (the named class does not exist — most often a short name that was not written fully
  qualified), `orisai.nette.forms.invalidControlClass` (the named class is not a `Nette\ComponentModel\IComponent`, so nothing
  could register it as a child), `orisai.nette.forms.missingControlClass` (the tag names no class and the declared return type is
  `void`/`null`/a scalar/the receiver itself, leaving the tag no class at all) and `orisai.nette.forms.returnTypeContradiction`
  (the named class is not a subtype of the declared return type — narrowing a declared return is the point of the
  operand, contradicting it is a bug). Validation runs on the **declaration**, so an inherited tag is reported where it
  is written rather than again on every override, and a helper nothing calls is validated all the same.
- **A generic adder that has not declared what it adds** — `orisai.nette.forms.unannotatedRegistrar`, on a container method
  inside the analysed paths that registers exactly one component under one of its own parameters and carries no
  `@form-adds`. Nothing is wrong with the shape; the report is that the registration stops being visible the day the
  class is extracted into a package. Only annotatable methods are reported — several components, a literal name or a
  conditional registration is a build method no tag could summarise — and a trait's method is reported against the
  trait. See [Where the tag is asked for](#where-the-tag-is-asked-for-and-where-it-is-the-only-channel); switched off
  with `orisai.nette.forms.reportUnannotatedRegistrars: false`.
- **Reaching a field on an open shape** — when the shape could not be fully enumerated (an `extensionMethod()` /
  undefined-method call, a dynamic name, an unfollowed helper), an unknown name is reported as `Form value 'nope' may
  not exist; the form shape is open.` with a **tip** naming why it opened (e.g. `Form shape opened by: extension_method`)
  — since the open shape itself now renders as a standard unsealed `...<mixed>` that has no room for the reason.
- **A `createComponent*` returning a bare `Nette\Forms\Form`** instead of a `Nette\Application\UI\Form` — only `UI\Form`
  wires presenter signal handling and submission, so a bare form attached to a presenter silently loses them.
  Presenter-attached forms must extend `Nette\Application\UI\Form` (e.g. `Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm`).
- **An invalid `setMappedType` / `getValues(Dto::class)` mapping** — the DTO is not instantiable; a field has no
  matching property (written as a *dynamic property* — deprecated since PHP 8.2, a fatal error from 9.0 — unless the
  class is `#[AllowDynamicProperties]`) or no matching constructor parameter; a **required member is left unset** (a
  constructor parameter with no field, or a typed property with no default and no field — Nette leaves it uninitialized,
  so the first read throws *"must not be accessed before initialization"*); the target property is non-public/read-only;
  or a field's value type is not accepted by the property's declared type. The check uses the property's phpdoc type, so
  a possibly-empty `string` field mapped into a `non-empty-string` property is reported. Nested containers and
  self-mapped replicator items recurse to any depth.

# Supported vs. not supported

What works (each shown above): adding via `add*` / offset assignment / `addComponent()` (equivalently); literal, nested
and dynamic-offset component access; `getComponent()`; `getComponents()` / `getControls()` iteration; `removeComponent`
/ `unset` removal; cross-control and cross-presenter hops; `getValues()` / `getValues(true)` / `getUntrustedValues()`
and per-field `getValue()`; both `onSuccess` callback styles with typed form and values; read and write value types for
the full control catalog; validation narrowing of required fields (via `onSuccess`, `isValid()`, `isSuccess()`);
unconditional and conditional rules and `endCondition()` — chained, as separate statements, or via a `Rules` variable;
date `setFormat`; choice `setItems` (open and closed); containers with optional fields; library-agnostic replicators;
wizards; DTO mapping; forms held in an object property (`$this->form`); generic `add*` helpers declared via
`@form-adds`; and custom controls/components via the eleven annotations.

## Known limitations

| Limitation                                                | Cause                                                                                                                                                                                                                                           | Workaround                                                                |
|-----------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------|
| Magic `add*` methods via `extensionMethod()`              | Runtime `__call` registrations aren't real methods, so PHPStan can't see them and no shape is inferred.                                                                                                                                          | [Add a real component](#magic-add-methods-are-not-supported) — offset assignment or extend + shared trait. |
| Dynamic / non-constant component names                    | `addContainer($name)` / `addComponent(new Foo(), $bar)` with a non-literal name can't be keyed; the child resolves to `unknown`. A removal by non-literal name opens the shape.                                                                 | Use a constant string name.                                               |
| `setMappedType()` with a non-literal or conditional class | Only a direct `setMappedType(Dto::class)` statement with a class-constant literal is captured; otherwise the container falls back to the crate/array shape.                                                                                     | Call it unconditionally with a `Dto::class` argument.                     |
| `eval()` / `extract()` in a form-building method          | The body can't be analysed and the shape opens.                                                                                                                                                                                                 | Don't use them in a builder.                                              |
| Reading a divergent field inside a shared helper                | A helper typed `function(Form $f)` is analysed once: the shapes from its call sites are merged into their **union**, so a field present at any site counts as present. This only matters when the helper **reads** a field whose *presence* differs between sites — that read isn't flagged as possibly-absent. Passing a form/container around otherwise (logging it, adding the same container to several forms, bulk-setting inputs nullable) is unaffected, since those don't depend on a per-site-divergent field. | Split the helper along the divergence, or guard the read with `isset()`. |
| Anonymous-class controls referenced from another file     | The anonymous-class reference isn't serialisable across files; the slot exists but its control class falls back to a generic ancestor.                                                                                                          | Move it to a named class.                                                 |
| A **container** pulled into a local, or a leaf handle used in an unrecognised way | `$section = $form['section'];` opens the whole form's shape: a container handle can gain fields whatever is called on it, and `getComponent()` on one registers through `createComponent()`. A LEAF handle keeps the shape closed, but only while every use of it is a re-read, an `instanceof` probe or a bare-statement non-registering call chain — a handle passed to a callee, captured in a `use ()`, called under a dynamic name, or a chain whose result is assigned (`$v = $probe->getValue();`) all still open it. | Add to the container in the builder that owns it, and keep a pulled-out control's uses to statement-level calls. |
| A **narrowing modifier** on an already-added control inside a conditional **expression** | `$c && $control->setDisabled()` / `$c ? $control->setRequired() : null` opens the field to `mixed` rather than merging the modified and unmodified branches. (A conditional *add* is handled; only a standalone modifier reached through a short-circuit/ternary loses precision.) | Apply the modifier in a statement-level `if`, where the branch join keeps the field's type. |
| A **container** `add*` in a compound-statement **header** | A container created in a header expression (`foreach ($form->addContainer('rows')->getComponents() as …)`) is registered, but the header pass doesn't resolve its class or compose its children, so it stands as a classless container whose children are **unknown** (open). It used to stand closed and empty, which claimed the container has no children at all and made any child added in that same header read as absent. | Add and populate the container in statements. |
| An **uncaptured** container, or a replicator whose item factory is not an inline closure | A container whose `add*` return is never bound to a plain local variable (chained onto — `$form->addContainer('a')->addText('z');` —, assigned to a property or array dim, or handed to a callee) is **open**: the walk cannot read what was added through the escaped reference. So is the ROW of a replicator whose factory argument is a callable array, a variable or a first-class callable rather than an inline closure. Both used to close **empty**, which claimed no children at all and made a genuinely added child read as absent. The cost of opening is that a genuinely *missing* name reached through one of them is no longer reported either. The one uncaptured form that still closes — and still reports — is the add call that IS the whole statement (`$form->addContainer('a');`), where the reference is dropped on the floor. | Bind the container to a variable and populate it in following statements; pass the item factory as an inline closure. |
| A `createComponentX` that **returns an unreadable builder chain** | `protected function createComponentGrid(): GridControl { return $this->factory->create(); }` declares the component's class but never builds it here, so when the chain's terminal cannot be followed (an interface method, a receiver typed `mixed`, a hop through a method whose body is unreadable) the shape carries the declared class and stays **open**. It used to close **empty**, which claimed the declared component has no children at all - the same false proof the property-fetch return (`return $this->prebuilt;`) has always avoided, in the same method, for the same reason. The same holds when the chain is bound to a **local** first (`$form = $this->factory->create()...; return $form;`): every resolution channel declines, the declared return class is substituted for one the walk could not name, and that substitution is the tell that the origin was never read - so a shape that recorded no child at all opens too. The typical site is a `createComponentX()` returning a control factory's product. | None needed - openness is the honest answer. Return a locally built component if you want its children resolved. |
| An `add*` helper whose **body** proves no single control class | The class of a helper that declares no return type is read off its returns (see [An `add*` helper with no declared return type](#an-add-helper-with-no-declared-return-type)). Returns that disagree, a last statement that is not a return, a return that cannot be resolved scope-free, or a body the parser stripped all leave the slot **present but opaque** — `mixed`, one `orisai.nette.forms.partiallyUnknown`. Presence is unaffected either way: it comes from the `add*` name, so a helper that registers nothing still records a slot and a helper that registers under a name the call site does not state still does not. | Declare the return type. |
| A **nested container** whose receiver form is untypeable at the `add*` site | The concrete subclass of a component is read scope-free from the `add*` method's declared return (`addContainer(): FormContainer`, `addSubmit(): CustomSubmitButton`, `addDynamic(): CustomReplicatorContainer`) or, for the top form, from its `new` expression. When the receiver form itself can't be typed at that call (reached only through an untyped pass-through), a nested container falls back to the configured default container class instead of the receiver's `addContainer()` return, so a receiver whose container class differs from the default is mislabelled in that one case. | Keep the form variable's type resolvable at the builder site. |
| **Component labels in a handler follow the declared class when the registered class can't be resolved uniquely** | A bare `Form`/`Container`-typed handler param is narrowed to the class every registration proves it is bound on, so the builder's `add*` returns are read on that constructed class rather than the declared one. When the handler is registered from more than one site and those sites construct **different** classes, or a contributing site's registering method/class/constructed form can't be located at all, narrowing declines and the handler keeps rendering labels off the **declared** class (base form, `Nette\Forms\Container`, `SubmitButton`, `Kdyby\Replicator\Container` for a base-typed param). Field sets and open/closed state are unaffected — the coarser labels are supertypes of the runtime classes (sound, never a false claim). | Register the handler from a single, resolvable construction site, or declare the handler param with the concrete form class directly. |
| A **nested** container variable mutated after a branch join | A container bound to a variable inside an `if`/`switch`/loop arm and referenced again after the join opens its inner shape (the later mutation isn't traced). This follows only containers added directly on the tracked form; one bound to a container nested a level deeper keeps its arm-local shape. | Build and populate a container within a single scope. |
| A **by-value captured** variable rebound by the outer function | Inside an absorbed closure body, a value modifier reading an outer variable captured by value (`use ($x)`, or an arrow function's implicit capture) is distrusted when the outer function assigns `$x` more than once, since the capture snapshot can differ from the analysed value — the field degrades (nullable widened, narrowing dropped) rather than claim the stale value. The receiver of a custom `add*` degrades the same way (unknown-typed slot). A single-assigned capture, a by-ref capture, or a parameter keeps full precision. | Capture by reference (`use (&$x)`) — a closure only, arrow functions cannot — or assign the value once. |
| A by-value capture mutated through a **separate by-ref closure** | A value variable assigned once at the outer level but rebound through another closure's `use (&$x)` is still counted single-assigned, so its capture stays trusted even though the by-ref closure may have changed it first — and the field then takes the type implied by the **pre-mutation** value, which can be a **wrong value type** (e.g. rendered `string` when the by-ref write set the flag that makes it `string\|null`). The single-assignment count doesn't see the by-ref closure's write. | Assign the value directly in the builder body. |
| A **by-ref captured container** variable rebound inside the body | A container captured by reference (`use (&$form, &$v)`) and rebound inside the absorbed body (`$v = $form->addContainer('b'); $v->addText('x')`) still attributes the post-rebind adds to the container `$v` named **before** the closure ran, not to the freshly-created one — unlike a by-value `$v` rebind, which is treated as a fresh binding (or opens the shape). | Bind the inner container to a fresh local name, or don't capture the outer container by reference. |
| A helper that **removes** a component does not close the call | What a followed callee contributes is merged in additively, so `$form->removeComponent(…)`, `unset($form['x'])` or a `@form-disabler` called on the parameter has no representation. Rather than close over a shape that would still claim the removed control, such a helper is left unfollowed and the form stays open — including when it also adds. The match is syntactic and over-eager: a same-named variable inside a nested closure counts too. | Remove the component in the builder body, where the walk models it. |
| A form-registering callable stored on an event property whose body cannot be **read** | The store opens the shape only when the callable resolves to a body seen to register a component (see [Callables stored on an event property](#callables-stored-on-an-event-property)). A string callable, one arriving as a parameter or read off a property (`$form->onSuccess[] = $this->handler;`), one produced by a call, and a method named on `parent::` all resolve to nothing, so the store is invisible and the shape stays **closed** — a control such a handler attaches is then proven absent, `*ERROR*` with one `orisai.nette.forms.noSuchComponent`. Reading a body is what keeps the ordinary value-processing handler from opening every form in a project, so the two directions are the same decision. | Attach the component in the builder, or name the handler as a closure literal, a local variable holding one, or `[$this, 'method']`. |
| A closure **parameter** shadowing a bound container's variable name | Adds inside `function (FormContainer $c) { $c->addText('x'); }` are attributed to a same-named container bound outside (the Nette handler convention, where the callback receives that same object). When the closure is instead invoked with a *different* container, the attribution over-claims. | Name the parameter differently from outer container variables. |
| A **nested anonymous class** inside a scope-free walk | `$this` inside an anonymous class declared in a builder body is that class's own instance, but the scope-free walks read the body as one scope, so `$this->form->addText('x')` there is attributed to the OUTER `$this->form` — the shape claims a control that was never added to it. The property channel inherits this from the walk it shares: `returnedContainerClass` and `soleTrackedAssign` (which skip `Closure`/`ArrowFunction` bodies, not anonymous-class ones) are exposed the same way, so it is a property of the pattern rather than of this channel. | Name the class, or build the form outside the anonymous class. |
| An **integer runtime index** on a **plain container** (never resolved to `FormReplicatorType`) reports a native, vendor-level false positive | `$c[$i]` is genuinely how an index-named child is read, but a plain container's type doesn't override PHPStan's own offset-existence check, which falls back to the wrapped class's declared `ArrayAccess::offsetSet()` parameter type; that check can decide an int key "does not exist" independently of anything this extension infers. Pre-existing and unrelated to any container shape defect — reproduces on a bare, natively-typed `Nette\Forms\Container` parameter with no Forms-bridge involvement at all. `FormReplicatorType` (a genuine `addDynamic`/`addMultiplier`/tagged replicator) closed this for an **integer** offset — see `hasOffsetValueType()`, which answers `isInteger()->or(parent::hasOffsetValueType())`: Yes for an int, and for anything else exactly what the wrapped class alone would have answered (Maybe for a string matching the stub's declared key type — silent, never reported — since the core check doesn't report a Maybe on a non-array `ArrayAccess` receiver). A **string** offset on a bare replicator leaf (e.g. `$form['rep']['addNode']`, a control added straight onto the replicator holder) now resolves to that control's own type and is classified for existence the same as any other container child (see [Replicators](#replicators)) — closed by a later increment, not by this integer-offset fix. A container populated by, say, `addContainer($i)` inside a hand-written loop never becomes a `FormReplicatorType` at all (dynamic/non-literal names aren't tracked, per the row above), so it stays on the vendor-level fallback regardless of offset type. | Baseline it. Closing it for real needs recognising a manually-built, dynamically-indexed container as replicator-like, which isn't done. |
| A replicator's own child added on a **discarded or fluently-chained** `addDynamic()`/`addMultiplier()` return value | Only a directly-assigned capture of the add call (`$rep = $form->addDynamic(…)`) is tracked for further own-child additions. Chaining straight off the call (`$form->addDynamic('rep', fn)->addSubmit('addNode', …);`), or discarding the return value and reaching the holder later through an offset (`$form['rep']->addSubmit('addNode', …);`), leaves the replicator's own shape **open** rather than closed-and-empty — a closed empty shape would tell the rule a genuinely-added own child does not exist, which is exactly the false positive an earlier draft of this fix produced. Open means the rule degrades (reports nothing) for every name reached through that holder, not just the one actually added — a real typo on such a holder goes uncaught. | Capture the return value in a variable before adding to it. |
| **Offset presence is not answered on a form reached in the SAME expression** | `$this['form']['x'] ?? null` keeps a null arm even for an unconditionally added control, and `isset($this['form']['x'])` stays `bool`. The Maybe does not come from the form: it comes from `$this`'s OWN offset, a `Control`, which phpstan-nette's `Container` stub answers `Maybe` for - and PHPStan asks the outer question of the whole chain. Bind the form first and the shape answers: `$form = $this['form']; $form['x'] ?? null` is non-nullable and reports *always exists*, which is the `{var $form = $control['form']}` every template already writes. TYPE resolution is unaffected in both spellings. | Bind the form to a variable before offsetting it. |
| A value-less control accessed with `??`/`isset()` in a builder answers **Yes** only when the presence axis proves it | `componentTypes` - the sole record of a submit-like control - carries a presence axis (`FormShape::componentTypePresence()`), met by `CompositionState::joinComponentTypes()`/`foldComponentTypes()` and `FormShape::joinBranch()`/`merge()` exactly as the shaped channels are, and read by `ComponentPath::hasDefiniteChild()` as its LAST arm so that a name a shaped channel also holds is answered by that channel instead. An unconditionally added `addSubmit('save', …)` therefore drops the null arm from `$form['save'] ?? null`; a conditionally added one (an `if`, a loop body, one arm of a `return`) keeps it. Both ends are pinned by `ComponentTypesOnlyOffset`, including the path (`$form['outer-save']`) and replicator-own-child (`$form['rows-addNode']`) spellings, and the MAYBE cases are the guard against closing this the naive way - answering off the mere EXISTENCE of a `componentTypes` entry flips the conditional cases too and misinforms core's `issetCheck()`. A key with no presence recorded reads as `MAYBE`, the opposite default from `getContainerPresence()`, because a wrong `Yes` has no `|null` redundancy behind it. `FormShapeUnknownAccessRule` deliberately still reads `componentTypes` for EXISTENCE only and ignores the axis - staying silent is the safe direction there. | None. `ConstructorFormShapeResolver` records no `componentTypes` entry for a value-less control at all, so a constructor-built form answers nothing about its submit on either axis - a separate gap, not this one. |
| A component path whose intermediate segment **exists but is not a container** | The shared walk descends containers and replicators; a segment that is a control, or a value-less component known only by class, stops it and everything past that point degrades (reports nothing, resolves to the native stub's `IComponent`). Nette throws `Component with name 'a' is not container and cannot have 'x' component` for a component that really is not a container, but the shape carries no reflection to prove a control class is not itself an `IContainer` — so the walk declines rather than condemn. (A **missing** intermediate segment, by contrast, IS reported on a closed shape, and an **invalid** one — an empty segment from a stray separator — is reported even on an open one; see [Component paths](#component-paths-a-b).) | None — a typo whose first segment happens to name a real control goes uncaught; this is the accepted false-negative direction. |
| A component path reaching **past** a replicator's own child | `$form['rep-addNode-x']` stops at `addNode` (a control, not a container) by the row above. A path whose remainder is neither a decimal row key nor a resolvable own-child path degrades. | None needed — the runtime lookup throws, but proving it needs the own child's class. |
| `ComponentClassResolver::lookupChild()` path support is not fixture-covered | The AST-based class resolver behind `$this['x']['y']` now descends component paths through the same shared walk, but it only runs when `ContainerModel` has already declined to resolve the offset — a state no fixture reproduces, so the path branch there is exercised only through the shared walk's own unit tests. It degrades to `null` (the native stub's answer) on anything it cannot prove, so the widening is one-directional. | None needed. |
| The **shape** still records an invalid literal name as a real child | The registration is now REPORTED (see [What's checked](#whats-checked)), but the walk itself is unchanged: `$form->addText('a-b')` is still recorded as a slot named `a-b`, a shape no form can actually have, and everything downstream (values, DTO mapping, path resolution) reasons over a field that cannot exist. Since the code also carries a `orisai.nette.forms.invalidComponentName` error at the registration line, the downstream noise is attached to an already-failing build rather than to a silently-wrong one. | Fix the name; the diagnostic points at the exact registration. |
| Only a **constant** invalid name is reported at registration | `ComponentNameRegistrationRule` judges a name the call site states as a single constant string and degrades silently on anything else, so `addText($bad)` or `addText('a-' . $i)` is never reported (nor guessed at). It also needs the `add*` method to return the component it registered, which is what keeps a look-alike such as `Ublaboo\DataGrid\DataGrid::addColumnText($key, $name)` — whose `$name` is a column title — out of the rule; the cost is that a wrapper which registers a component but returns something else (`void`, `$this` on a non-container, a builder object) is not judged either. An `extensionMethod()`-registered `add*` (Kdyby's own `addDynamic`) has no resolvable signature at all and is skipped, as is an offset assignment `$form['a-b'] = …`. On real code the rule rarely fires, so its proof is carried by fixtures. | Use a constant name; wrap registrations in a method that returns the registered component. |
| A declaration under a name the tagging pass does not mark resolves **no name** | Which nodes are component-affecting is decided by a syntactic pass (`ComponentAffectingNodeVisitor::tagsMethodName()`: `add*`, `removeComponent`, offset write/unset) that reads no types and no reflection — deliberately, because the node ids it mints are the walk's cache key and an id that moved with a type would let one consumer poison another's entry. A container method named otherwise therefore has no tagged node for its `@form-adds` to be resolved against, however valid the tag is. What the tag still does there is stop the shape CLOSING: `$form->attachThing('ghost')` opens the shape with `declared_add_unread` rather than proving `ghost` absent. | Name an annotated helper `add*`; then the name and class both resolve. |
| An **unannotated** registrar returning no component opens the whole form | Step 3 of [Whether a call registers a component at all](#whether-a-call-registers-a-component-at-all) records the component but has no value type and no control class to give it, and a field of unknown type opens the shape the same way an `add*` helper with no declared return type does. So one such call costs the whole form its absence reporting: every other `$form['typo']` degrades to *may not exist*. | Annotate it — `orisai.nette.forms.unannotatedRegistrar` already asks — or return the control. |
| A registration the body detector does not recognise, in a method returning no component, reads as **no registration** | The body is read syntactically: `$this[$name] = …`, `$this->addComponent(…, $name)` and an `add*` call on `$this`, with closure interiors excluded. A method declared `: void` that registers through a property write, a helper of its own or `setParent()` reads as registering nothing, so the shape stays **closed** and the component is proven absent — the same accepted cost the vendor-convention read carries, now reachable inside the analysed paths too. | Annotate it with `@form-adds`, which beats every body read. |
| A constructor-built form's **value-less** controls are missing from an otherwise closed shape | `ConstructorFormShapeResolver` drops a control whose value resolution is `KIND_OMITTED` (a submit, a `setOmitted()`/`setDisabled()` one) without recording it on the `componentTypes` axis either, so a form whose constructor is otherwise fully read closes over the rest and `$form['send']` is reported absent. The same shape answers the same way through every spelling that reaches the resolver — a `return new X()` factory arm, a `createComponent*` child, a plain local. It is the constructor resolver's own gap (see the `componentTypes` row above), not the walk's. | Add the submit outside the constructor, or read it as `$form->getComponent('send')`. |
| A `getComponent()` read the state cannot prove inert **opens** the form | The descent is refused only where the vendor body's own two conditions prove nothing is attached: the child is already there, or the receiver declares no `createComponent<Ucname>` for it. A read under a name the call does not spell literally, on a class reflection cannot resolve, or of a child a factory really could create is followed instead, and `Container::getComponent()`'s body registers under a parameter name — so the shape opens and the whole form stops reporting absence. That is the honest answer for a read that may create, but it is coarse: nothing records WHICH child was created. | Read with a literal name; a name Nette can have no factory for (already capitalised) is proven inert outright. |
| A build method called on the tracked form that only **delegates** is not followed | The descent is gated on the callee's own body registering a component on `$this`, so `$form->build();` where `build()` does nothing but call `$this->fillContact()` folds in nothing and the shape stays closed — the controls the inner hop registers are reported absent. The gate is deliberately not transitive: `$this`-rooted reachability drags in vendor readers (`Container::getComponent()` registers through `createComponent()`) whose walk would open the shape at every call site. Inside a descent the same rule applies again, so only the ENTRY hop is affected. | Register in the method the caller names, or pass the form as an argument. |
| A `@form-adds` tag is **not checked against the body** | Validation judges the tag against the signature (the parameter exists, the class exists, is an `IComponent` and does not contradict the declared return) and stops there — nothing verifies that the method really registers that class, or registers anything. An annotation is taken as a declaration, exactly like a declared return type, so a tag that lies produces a confidently wrong shape rather than a diagnostic. | Keep the tag next to the registration it describes. |
| A **non-constant** name argument at an annotated call site | `$form->addThing($dynamic)` degrades the same way every other dynamic name does: the shape **opens** rather than guessing a key. The same applies when the named parameter cannot be matched to an argument at all — a call that spreads an array (`$form->addThing(...$args)`), or a position filled only because the positional arguments ran out. | Pass a constant string name. |
| An **undetected** registration in a package still closes | Outside the analysed paths the walk keeps its argument-0 naming convention unless the declaration's body **refutes** it, and the body is read syntactically: only `$this[$name] = …`, `$this->addComponent(…, $name)` and an `add*` call on `$this` count. A package that registers through a property write, a helper of its own, a `setParent()` or anything else is not detected, so the convention stands and the shape stays **closed** — the component argument 0 names is recorded and the one really registered is proven absent. The pre-gate is coarser still: a method reached through a class whose own file is inside the analysed paths is never body-read, so a **vendor trait composed into a project class** keeps the convention too. | Annotate the package method with `@form-adds` (a stub of it, if you do not own it). |
| The nag rule does not reach a **trait nobody uses**, nor a package declaration | A trait body is analysed only in the context of a class using it, so a shared registrar trait with no users is never seen. And the rule deliberately reports nothing outside the analysed paths, where a missing tag is answered by the shape opening instead. | None needed. |
| A `@form-adds` on a **trait** resolves its declared-return contract against the **using class** | Omitting the class operand makes the tag read the declared return type, and for a trait method PHPStan attributes the declaring class to whichever class uses it. A trait method declared `addContainer($name): FormContainer` therefore looks like a fluent adder returning the receiver — and so like a tag with no class at all, reported as `orisai.nette.forms.missingControlClass` — in exactly the one using class that IS `FormContainer`. | Spell the class out on a trait's tag: `@form-adds $name Acme\Form\FormContainer`. |
| A chained modifier after a **repeated** `@form-adds` call | `$form->addPair('a', 'b')->setRequired()` applies the chain to the control the call **returns**, which is at most one of the registered components, so the chain is folded into the first occurrence only and the further ones are recorded unmodified. Folding it into all of them would state a modifier that never ran. | Apply modifiers to the individual components. |
