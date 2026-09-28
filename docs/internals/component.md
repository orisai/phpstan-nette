Maintainer documentation; the user-facing guide is ../README.md.

# Component attachment (`Nette\ComponentModel`)

Static analysis of one question: **is this component attached to a parent at this program point?**

The answer is three-valued — **Yes**, **No**, **Maybe** — and every unproven edge is Maybe. The
machinery lives in `src/Component/Attachment/` and is switched by `orisaiNette.component.enabled`.
The extension consumes it in three places today: to decide whether a `getComponent()` read registers
a child, to decide whether an
[existence check is constant](forms.md#existence-checks-whose-answer-is-already-known)
(both in [Forms](forms.md#whether-a-call-registers-a-component-at-all)), and for the diagnostic
below.

The Component area imports exactly one Forms class, `OriPhpstan\Nette\Forms\Shape\Certainty`, the
vocabulary its answers are expressed in; `ComponentDependsOnFormsOnlyThroughCertaintyTest` pins that
no other Forms import creeps in. `FormsCodeVersion` digests
`src/Component/Attachment/` together with `src/Forms/`, so an edit to the attachment machinery
rotates the persisted Forms shape cache.

Three classes are read by code outside the library. `FixSupport` lives in `src/Latte/` and is the
only public helper. Beyond it, a consuming application's own component rules may read the node
attribute constants of `OriPhpstan\Nette\Component\NullComparisonParentVisitor` and
`OriPhpstan\Nette\Component\StatementExpressionVisitor`; treat those constants as an internal
contract — renaming one breaks such a rule without any error in this library.

## `orisaiNette.component.unattachedParentAccess`

> Component is not attached to a parent here, so `getForm()` always throws `Nette\InvalidStateException`.

`Component::lookup()` walks upward from `$this->parent`. On a component with no parent the walk never
starts and, with `$throw` left at its default, it **raises** rather than answering null. So this is a
guaranteed runtime exception, not a style preference — which is why the rule is **on by default**.

The commonest place to hit it is a factory, where the component being built is attached by the
**caller**:

```php
protected function createComponentEditForm(): ApplicationForm
{
    $form = new ApplicationForm();
    $form->getPresenter()->redirect('this');   // ← throws: nothing has added $form yet
    return $form;
}
```

The subject is always the **created** component, never `$this`. A container's own chain is normally
attached, so a rule that read the container would fire on nearly every factory in a project.

### When it fires

Both halves must be provable, and each degrades on its own:

1. **the receiver is provably detached** — it came from a zero-argument `new X()`, a
   `removeComponent()` or a `setParent(null)`, on **every** path reaching the call, and nothing in
   between could have moved it;
2. **the accessor provably throws here** — the method resolves (by *declaring* class) to one that
   reaches `lookup()`, and its `$throw` argument at this call site is `true` or absent.

| accessor | declared on | `$throw` | no-throw spelling |
| --- | --- | --- | --- |
| `lookup($type)` | `Nette\ComponentModel\Component` | argument 1 | `lookup($type, false)` |
| `lookupPath($type)` | `Nette\ComponentModel\Component` | argument 1 | `lookupPath($type, false)` |
| `getForm()` | `Nette\Forms\Controls\BaseControl`, `Nette\Forms\Container` | argument 0 | `getForm(false)` |
| `getPresenter()` | `Nette\Application\UI\Component`, `Nette\Application\UI\Form` | argument 0 | `getPresenterIfExists()` |
| `getUniqueId()` | `Nette\Application\UI\Component` | none — always throws | *(none)* |

Nette **overrides** several of these to stop throwing — `Nette\Forms\Form::getForm()` and
`Presenter::getPresenter()` / `getPresenterIfExists()` / `getUniqueId()` all answer themselves — and
resolving by declaring class is what keeps those, and any override of your own, out of the rule.

`Nette\Application\UI\Component::getPresenter()` is the awkward one: as installed it **declares no
parameters and takes one anyway**, through `func_get_arg(0)`, so `getPresenter(false)` is legal (with
a deprecation) while reading like an arity error. The whole table is therefore a vendor fact *with a
version*, and every entry is re-derived behaviourally by `ComponentModelApiFreshnessTest`
(`tests/Unit/Component/Attachment/`, part of the ordinary test suite): it detaches a real component,
calls the accessor and asserts it raises. The same test scans the accessor classes for any public
method reaching `lookup()` that the table does **not** hold, so a lookup-backed accessor added by a
future Nette fails the suite rather than going unreported.

### What it deliberately does not report

Everything below is a **Maybe**, and a Maybe is silent. A false positive here would claim correct
code throws, so the bar is one-sided on purpose.

- **`$this`.** The factory's own container is not the subject and nothing is claimed about it.
- **A reference that escaped** — stored in a property or an array, handed to a callee the walk cannot
  read, captured by a closure, bound by reference, or aliased (`$b = $a` degrades **both**).
- **Paths that disagree.** A component attached inside an `if` and accessed after it is Maybe.
- **A construction handed arguments.** `new Form($parent, $name)` attaches from inside the
  constructor, so only the zero-argument form claims No. `new TextInput('Label')` therefore reports
  nothing, although it plainly attaches nothing either — the restriction is about what the walk can
  *prove* without reading the constructor.
- **A loop, `switch` or `try` entered with the answer already established outside it.** The body is
  re-entered from its own exit (and `switch` cases fall through), so the entry is widened. A
  construction *inside* the body re-establishes the answer immediately.
- **A closure or arrow-function interior**, relative to the enclosing body's state. Each function-like
  is walked on its own, so a construction and an access *both* inside one still report.
- **The type looked up.** The rule answers "attached to *a* parent". A control attached to a bare
  `Nette\Forms\Container` that is itself detached still throws on `getForm()`, and that is beyond
  what attachment answers.
- **A nullsafe call** (`$c?->getForm()`). A project may forbid nullsafe on containers with a rule of
  its own.

## The nullable return types that are not nullable

`getForm(): ?Form`, `getPresenter(): ?Presenter` and `lookupPath(): ?string` all describe the call
site that passes `false`. On the default path they cannot answer null — `lookup()` throws instead —
so the extension removes the null **per call site**, wherever `$throw` reads as true or is absent:

```php
$form->getPresenter()->flashMessage('saved');   // no "on Presenter|null" any more
$path = $this->lookupPath();                    // string, not string|null
```

`getPresenterIfExists()` is untouched: it is the honestly nullable member of the family and the one
the rule's tips point at.

**Known limitation:** `lookup()` itself is left to phpstan-nette's own
`ComponentLookupDynamicReturnTypeExtension`, which is registered for the same class and answers
unconditionally, so whichever of the two the container ordered first would win. Standing aside makes
the outcome deterministic; the cost is that `lookup($type)` keeps its declared `|null` while
`lookup($type, true)` does not, although both throw.
