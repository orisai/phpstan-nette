# PHPStan Nette

PHPStan understands Nette DI containers, Nette Forms, Latte templates and how they connect

## Content

- [Why do you need it?](#why-do-you-need-it)
- [Quick start](#quick-start)
	- [Patch phpstan-nette](#patch-phpstan-nette)
	- [Set up DI](#set-up-di)
	- [Set up Forms](#set-up-forms)
	- [Set up Latte](#set-up-latte)
	- [Set up Bridges](#set-up-bridges)
- [Configuration](#configuration)
	- [DI options](#di-options)
	- [Forms options](#forms-options)
	- [Latte options](#latte-options)
	- [Bridges options](#bridges-options)
	- [Loader paths](#loader-paths)
	- [Narrowing store lifecycle](#narrowing-store-lifecycle)
	- [Dead code detection](#dead-code-detection)
	- [Validation](#validation)
- [Debugging functions](#debugging-functions)
	- [Forms debugging](#forms-debugging)
	- [Latte debugging](#latte-debugging)
- [Custom code support](#custom-code-support)
	- [DI loader contract](#di-loader-contract)
	- [Forms annotations](#forms-annotations)
	- [Forms catalogs](#forms-catalogs)
	- [Latte engine loader](#latte-engine-loader)
	- [Latte template customs](#latte-template-customs)
	- [Latte discovery formulas](#latte-discovery-formulas)
	- [Bridges extension points](#bridges-extension-points)
	- [Rules with fixes](#rules-with-fixes)
- [Features](#features)
	- [DI features](#di-features)
	- [Forms features](#forms-features)
	- [Latte features](#latte-features)
	- [Bridges features](#bridges-features)
- [For maintainers](#for-maintainers)

## Why do you need it?

PHPStan knows PHP. Nette keeps a lot of its meaning in strings, conventions and generated code, and PHPStan cannot
read those on its own:

- `$form['email']` is an `IComponent`, so every method of the text input is unknown
- `$form->getValues()` is an `ArrayHash` of `mixed`, so every value read is untyped
- `$container->getService('mailer')` is an `object`, and a typo in the name is found only at runtime
- `.latte` templates are not analysed at all, so an undefined variable or a renamed method breaks a page in production

Orisai PHPStan Nette closes these gaps:

- [DI](#di-features) – reads your compiled containers, types every service lookup and reports missing services, types
  and tags
- [Forms](#forms-features) – infers the shape of every form and component, types controls and values and reports
  missing components and wrong writes
- [Latte](#latte-features) – analyses every `.latte` template like a `.php` file, on the template's own lines
- [Bridges](#bridges-features) – links templates to the presenters and controls rendering them and to the forms they
  render, so a renamed control is reported in the template

On top of that:

- [debugging functions](#debugging-functions) show what the analysis knows about a form or a template
- [annotations](#custom-code-support) teach it your own controls, filters and template conventions
- [invalid configuration](#validation) is rejected with a clear message

## Quick start

Install with [Composer](https://getcomposer.org):

```sh
composer require --dev orisai/phpstan-nette
```

The package requires `nette/forms`, `nette/application`, `nette/di`, `nette/component-model`, `nette/utils` and
`latte/latte` 2.11 — Composer installs them with it. The extensions load classes from all of them at start, whether the
matching feature is on or off.

With [phpstan/extension-installer](https://github.com/phpstan/extension-installer) the extension is registered
automatically. Otherwise, include it in your PHPStan config:

```neon
includes:
	- vendor/orisai/phpstan-nette/extension.neon
```

### Patch phpstan-nette

> [!IMPORTANT]
> If you use [phpstan/phpstan-nette](https://github.com/phpstan/phpstan-nette) (extension-installer loads it
> automatically), Forms and DI typing need a small patch of it and three parameters that switch its own extensions off.
> phpstan-nette answers first — `IComponent`, `ArrayHash`, `mixed` — and would silently shadow the types this package
> infers. Without phpstan-nette, skip this step — the parameters exist only in the patched phpstan-nette.
>
> Declare the patch in your root `composer.json`: this package declares it in `extra.patches-file`, which
> [cweagans/composer-patches](https://github.com/cweagans/composer-patches) reads only from the root package, so declare
> the patch yourself. Allow the plugin too — Composer 2.2+ blocks plugins not listed in `config.allow-plugins`:
>
> ```json
> "require-dev": { "cweagans/composer-patches": "^1.7.0" },
> "config": { "allow-plugins": { "cweagans/composer-patches": true } },
> "extra": { "patches": { "phpstan/phpstan-nette": {
> 	"Make component-model, form values and service-locator dynamic return types switchable": "vendor/orisai/phpstan-nette/patches/phpstan-nette-conditional-dynamic-return-types.patch"
> } } }
> ```
>
> And switch phpstan-nette's extensions off:
>
> ```neon
> parameters:
> 	netteComponentModelDynamicReturnType: false
> 	netteFormContainerValuesDynamicReturnType: false
> 	netteServiceLocatorDynamicReturnType: false
> ```
>
> The patch is verified against phpstan/phpstan-nette 2.0.8 – 2.0.12.

The patch path above points inside `vendor/`. On a fresh checkout the file does not exist yet when patches are
applied, so `composer install` has to run twice.

Copy the patch file into your project instead (e.g. `patches/`) and point the root entry at the copy — this is the
recommended way:

```json
"extra": { "patches": { "phpstan/phpstan-nette": {
	"Make component-model, form values and service-locator dynamic return types switchable": "patches/phpstan-nette-conditional-dynamic-return-types.patch"
} } }
```

This package does not set the three parameters itself. They exist only in the patched phpstan-nette, and setting them
would make phpstan-nette a hard requirement.

### Set up DI

DI analysis is off until you tell it where your containers come from.

Create a loader file which returns your application's compiled container (e.g. `tests/phpstan/container-loader.php`):

```php
<?php declare(strict_types = 1);

use App\Bootstrap;

require __DIR__ . '/../../vendor/autoload.php';

$configurator = Bootstrap::boot();

return $configurator->createContainer();
```

Does your application build different containers, e.g. for web and console? Return all of them, keyed by a profile
name of your choice — build each the way your application does:

```php
return [
	'web' => Bootstrap::boot()->createContainer(),
	'console' => Bootstrap::bootConsole()->createContainer(),
];
```

All profiles are analysed together: every lookup on a `Nette\DI\Container` is checked against each profile, so a
service registered only for the web is reported as missing in the console profile. A receiver typed as one profile's
compiled container class is checked against that profile alone.
A single returned container is a shorthand for `['default' => $container]`.

Register the loader:

```neon
parameters:
	orisaiNette:
		dic:
			containerLoader: %currentWorkingDirectory%/tests/phpstan/container-loader.php
```

The whole contract is in [DI loader contract](#di-loader-contract).

### Set up Forms

Nothing to configure. Forms and component analysis is on by default. If you use phpstan/phpstan-nette, the
[patch and its three switches](#patch-phpstan-nette) are required.

### Set up Latte

Latte analysis is off by default. Add `latte` to the analysed file extensions and enable it:

```neon
parameters:
	fileExtensions: [php, latte]
	orisaiNette:
		latte:
			enabled: true
```

Every `.latte` file in your analysed paths is now analysed.

Templates of presenters are linked to their presenters through the application's presenter mapping, which the
analysis reads from a container. Point `templateFactoryContainerLoader` at a loader file — the DI loader works:

```neon
parameters:
	orisaiNette:
		latte:
			templateFactoryContainerLoader: %currentWorkingDirectory%/tests/phpstan/container-loader.php
```

Without it, presenter templates are linked only through [discovery formulas](#latte-discovery-formulas), and a
presenter whose name cannot be resolved is not reported.

Optionally, turn on call-site narrowing — an included template is then analysed with the types proven at each
`{include}`, not only with its own declarations. Narrowing needs a store directory which you commit:

```neon
parameters:
	orisaiNette:
		latte:
			narrowing:
				enabled: true
				storePath: %currentWorkingDirectory%/tests/phpstan/latte-narrowing
```

Create the directory and commit it together with the files the analysis writes into it. See
[narrowing store lifecycle](#narrowing-store-lifecycle).

### Set up Bridges

Nothing to configure. The Latte and Forms bridge runs as soon as Forms, Latte and template discovery are all on —
Forms and discovery are on by default, so enabling Latte is enough.

Run the analysis:

```sh
vendor/bin/phpstan analyse
```

Good to go!

## Configuration

All options live under one `orisaiNette` key. Defaults are shown in each block. Unknown keys and values of a wrong
type are rejected by PHPStan when the config is loaded.

### DI options

```neon
parameters:
	orisaiNette:
		dic:
			containerLoader: null
```

- `containerLoader` – PHP file returning your compiled containers; DI analysis is off while it is `null`
  (see [DI loader contract](#di-loader-contract))

### Forms options

```neon
parameters:
	orisaiNette:
		forms:
			enabled: true
			defaultContainerClass: Nette\Forms\Container
			reportUnannotatedRegistrars: true
			catalogs: []
			internals:
				indexShadowCompare: false
		component:
			enabled: true
```

- `forms.enabled` – form and component shape inference, typing and every Forms rule; `false` switches all of them off
  (the stub adding `@form-read-type` tags to Nette's own controls stays loaded, it only adds tags)
- `forms.defaultContainerClass` – container class assumed when the class of a nested container cannot be read from its
  `add*` method, e.g. your application's own container subclass
- `forms.reportUnannotatedRegistrars` – asks for `@form-adds` on a generic `add*` helper inside your analysed paths
  (see [Forms annotations](#forms-annotations))
- `forms.catalogs` – interfaces describing third-party controls and methods (see [Forms catalogs](#forms-catalogs))
- `forms.internals.indexShadowCompare` – maintainer diagnostics comparing two resolution strategies; keep it off
- `component.enabled` – component attachment understanding: `getPresenter()`/`getForm()` typed as non-nullable where
  they throw instead of returning null, and its two rules

### Latte options

```neon
parameters:
	orisaiNette:
		latte:
			enabled: false
			narrowing:
				enabled: false
				storePath: %currentWorkingDirectory%/phpstan-latte-store
			discovery:
				enabled: true
				storePath: %tmpDir%/orisai-nette/latte-discovery
				coarseInvalidationAccepted: false
				formulas: []
			engineLoader: null
			templateFactoryContainerLoader: null
			firstPartyPaths: %paths%
			templateTypeRequired: false
			includeIsolation: false
			allowNarrowingOverride: false
			reportWrongPhpDocTypeInVarType: true
			reportAnyTypeWideningInVarType: true
```

- `enabled` – analyses `.latte` files; requires `latte` in `fileExtensions`
- `narrowing.enabled` – analyses an included template with the types proven at its `{include}` sites; requires
  `orisaiNette.latte.enabled`
- `narrowing.storePath` – committed directory holding the captured types (see
  [narrowing store lifecycle](#narrowing-store-lifecycle))
- `discovery.enabled` – links templates to the presenters and controls rendering them; it does nothing while Latte is
  off
- `discovery.storePath` – derived store of those links; it is rebuilt before every run and never committed
- `discovery.coarseInvalidationAccepted` – silences the note printed when no `paths` are declared in the config; without
  declared paths any change of the store discards the whole result cache
- `discovery.formulas` – assigns a formula to your own template locators (see
  [Latte discovery formulas](#latte-discovery-formulas))
- `engineLoader` – PHP file returning a `Latte\Engine` with your filters, functions and macros (see
  [Latte engine loader](#latte-engine-loader))
- `templateFactoryContainerLoader` – PHP file returning your containers, read for the presenter mapping, the template
  factory's default template class and the variables it provides (`$user`, `$baseUrl`, `$basePath`, `$flashes`); it
  may be the same file as `orisaiNette.dic.containerLoader`
- `firstPartyPaths` – classes and templates checked by the template-linking rules; code outside is used, never reported
- `templateTypeRequired` – reports a linked template without `{templateType}` whose renderer uses the default template
  class
- `includeIsolation` – analyses file includes with explicit arguments only, the way Latte 3 isolates them; a local
  migration aid, not a gate
- `allowNarrowingOverride` – allows a `{varType}` to narrow the template's native declaration of the same variable
- `reportWrongPhpDocTypeInVarType` – compares a mid-file `{varType}` also with the PHPDoc type of the assigned
  expression
- `reportAnyTypeWideningInVarType` – reports a `{varType}` which widens a precisely inferred expression

### Bridges options

The bridge has no options of its own. It runs when `orisaiNette.forms.enabled`, `orisaiNette.latte.enabled` and
`orisaiNette.latte.discovery.enabled` are all on.

### Loader paths

`orisaiNette.dic.containerLoader`, `orisaiNette.latte.engineLoader` and
`orisaiNette.latte.templateFactoryContainerLoader` are read as plain file paths. A relative path resolves against the
directory PHPStan runs from, not against the config file. Anchor them:

```neon
parameters:
	orisaiNette:
		dic:
			containerLoader: %currentWorkingDirectory%/tests/phpstan/container-loader.php
```

A Latte loader (`engineLoader`, `templateFactoryContainerLoader`) that throws still counts as configured — the file is
readable — and yields no engine, mapping or factory default; nothing is reported, by design. Run the file with `php`
when a Latte feature stays silent. A throwing `dic.containerLoader` fails the analysis.

### Narrowing store lifecycle

The narrowing store is versioned like a baseline: generated by ordinary runs, committed with the change that moved it,
conflicts resolved by regenerating it.

- Create the directory at `orisaiNette.latte.narrowing.storePath`. The analysis never creates it — without it nothing
  is written.
- Run the analysis. The first run writes an empty file for every including template.
- Run it again until `git status` on the directory is clean, then commit it.

A captured type reaches its template at most one warm run later, because a type proven during one run can only feed
the next run's analysis. A missing or outdated entry falls back to the template's own declarations — never an error.

In CI, run the analysis and fail when the directory has uncommitted changes.

### Dead code detection

With [shipmonk/dead-code-detector](https://github.com/shipmonk-rnd/dead-code-detector), include the container usage
provider, so constructors and setup methods your containers call are not reported as dead:

```neon
includes:
	- vendor/orisai/phpstan-nette/config/dic-dead-code.neon
```

It needs `orisaiNette.dic.containerLoader`.

### Validation

Checks beyond the schema run when the first file is analysed. Each rejection is a one-sentence message:

- `orisaiNette.latte.enabled requires "latte" in fileExtensions.`
- `orisaiNette.latte.narrowing.enabled requires orisaiNette.latte.enabled.`
- `orisaiNette.latte.enabled requires a supported latte/latte version (2.11, 3.0 or 3.1); installed <v>.`
- `Latte 3 requires nette/forms >= 3.1.7 (FormsExtension); installed <v>.` — the Latte 3 rows are checked only with
  `orisaiNette.latte.enabled` on and Latte 3 installed
- `Latte 3 requires nette/application >= 3.1.6 (UIExtension); installed <v>.`
- `Latte 3.1 requires nette/forms >= 3.2.7 and nette/application >= 3.2.7; installed <v>/<v>.`
- `orisaiNette.dic.containerLoader "<path>" is not a readable file.` — the same for `orisaiNette.latte.engineLoader`
  and `orisaiNette.latte.templateFactoryContainerLoader`
- `orisaiNette.forms.catalogs: class "<name>" does not exist.`
- `orisaiNette.forms.catalogs: method "<method>" is declared by both <catalog> and <catalog>.` — method names are
  compared case-insensitively and inherited methods count, so a catalog extending a built-in one is rejected too
- `orisaiNette.latte.discovery.formulas: unknown formula "<name>" for <class>.`
- `orisaiNette.latte.discovery.formulas: unknown option "<option>" for <class>.` — only `formula`, `sharedFallback` and
  `nameProperty` are read
- `orisaiNette.latte.discovery.formulas: formula "<name>" for <class> requires option "<option>".` — `sharedFallback`
  for `dirname-templates-lcfirst-fallback`, `nameProperty` for `dirname-property-lcfirst`

PHPStan reports the rejection as an internal error and exits with code 1:

```
Internal error: orisaiNette.latte.enabled requires "latte" in fileExtensions. while analysing file /path/to/src/a.php
```

Fix the configuration; the suggestion to report the internal error to PHPStan does not apply. The checks do not run
again on a run which is fully answered from the result cache.

## Debugging functions

Debugging functions print what the analysis knows. Call one where you want to look, run PHPStan and read the error it
reports — the same way PHPStan's own `\PHPStan\dumpType()` reports `Dumped type: …`.

The functions do not exist at runtime; PHPStan reports every leftover call with a non-ignorable error, so a forgotten
call never stays in the code.

### Forms debugging

```php
use function OriPhpstan\Nette\Forms\Testing\dumpComponent;
use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

dumpComponent($form);
dumpFormValues($form);
```

`dumpComponent($component, ?int $depth = null, bool $formValues = true)` prints the inferred component tree
(`orisaiNette.forms.componentShapeDump`). Each control shows its accepted write type and its read type:

```
App\Forms\SignForm{
  email: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
}
```

`$depth` limits nesting, `$formValues: false` leaves the value types out. An open shape — one the analysis could not
fully enumerate — ends with a `...` member.

`dumpFormValues($component)` prints what `getValues()` returns (`orisaiNette.forms.formValuesDump`):

```
Nette\Utils\ArrayHash{name: string, address: Nette\Utils\ArrayHash{city: string, zip: string}}
```

`assertComponent($component, string $expected, ?int $depth = null, bool $formValues = true)` and
`assertFormValues($component, string $expected)` compare the same output with a string and report only a mismatch
(`orisaiNette.forms.componentShapeAssert`, `orisaiNette.forms.formValuesAssert`). Use them in your own tests of
custom controls:

```php
use function OriPhpstan\Nette\Forms\Testing\assertFormValues;

assertFormValues($form, 'Nette\Utils\ArrayHash{name: string}');
```

### Latte debugging

In a template, call them bare — no namespace needed:

```latte
{do dumpLatteIncluders()}
{do dumpLatteVarOrigin($user)}
```

All of them report `orisaiNette.latte.debugDump`.

- `dumpLatteIncluders()` – every `{include}`, `{extends}`, `{import}`, `{embed}` and `{sandbox}` site reaching the
  current template, one per line with its number of contexts, e.g. `app/templates/Home/default.latte:12 (include) - 1 context(s)`
- `dumpLatteVarOrigin($var)` – the variable's type in every context the template is analysed in and where it comes from
  (`declared`, `arg`, `captured`, `topLevel`, `default`), plus the union of all contexts
- `dumpLatteCustoms()` – filters, functions and macros read from your engine, plus the current template's
  `{templateType}` filters and functions — or a note that no engine source is configured

Three more describe a PHP class — a presenter or a control. The argument must be a `::class` constant:

```latte
{do dumpLatteDiscovery(\App\Presentation\Home\HomePresenter::class)}
```

- `dumpLatteRenderFacts(Foo::class)` – what the class assigns to its template, which files it sets and which template
  class it uses
- `dumpLattePairing(Foo::class)` – the template class the class renders with, which channel decided it, and conflicts
- `dumpLatteDiscovery(Foo::class)` – the class's views, candidate template files for each view (existing, missing,
  chosen), layout candidates and anything the discovery could not resolve

These three work from PHP too:

```php
use function OriPhpstan\Nette\Latte\Testing\dumpLatteDiscovery;

dumpLatteDiscovery(HomePresenter::class);
```

## Custom code support

### DI loader contract

`orisaiNette.dic.containerLoader` is a PHP file which returns either:

- `array<string, Nette\DI\Container>` – containers keyed by profile name; all of them are analysed together
- `Nette\DI\Container` – a shorthand for `['default' => $container]`

The file may be required several times per run — DI, Latte customs and template discovery each read it. Keep it
idempotent: no function or class declarations and no side effects which must not repeat. Returning a fresh container
each time and returning a memoized one both work.

Build the containers the way your application does, for every profile which runs (web, console, API, …):

```php
<?php declare(strict_types = 1);

require __DIR__ . '/../../vendor/autoload.php';

return [
	'web' => App\Bootstrap::boot()->createContainer(),
	'console' => App\Bootstrap::bootConsole()->createContainer(),
];
```

The result cache depends on the compiled container files, so a change of the DI config invalidates it.

The same file may serve `orisaiNette.latte.templateFactoryContainerLoader`. Latte also reads your filters, functions
and macros from the first container with a `Nette\Bridges\ApplicationLatte\ILatteFactory` service.

### Forms annotations

Your own controls, containers and form helpers are understood from their code. Where code alone is not enough, add a
`@form-*` tag. Class names in tags must be fully qualified — `use` statements are not applied to them.

For a class you do not own, put the same tag into a [PHPStan stub](https://phpstan.org/user-guide/stub-files) of it,
or into a [catalog](#forms-catalogs).

**`@form-read-type <type>`** and **`@form-write-type <type>`** – the value a control reads and the value `setValue()`
accepts:

```php
/**
 * @form-read-type 'a'|'b'|'c'
 * @form-write-type 'a'|'b'|'c'
 */
final class RatingControl extends BaseControl
{
}
```

**`@form-modifier nullable|required`** – a control method which makes the value nullable or required, like
`setNullable()` and `setRequired()`:

```php
/** @form-modifier nullable */
public function asNullable(): self
```

**`@form-read-by-arg <key>=<type>; *=<type>`** – a control method whose argument selects the read type; a key may be a
class constant, `*` is the fallback:

```php
/** @form-read-by-arg App\Forms\ModeControl::ModeInt=int; *=string */
public function withMode(string $mode): self
```

**`@form-rule-cast <rule> <readType> <writeSpec>`** – the cast a validation rule applies, the way `Form::Integer`
makes a value `int`. It is read from a [catalog](#forms-catalogs):

```php
/** @form-rule-cast :integer int integer */
public function integer(): void;
```

**`@form-choice single|multi <keyType>`** and **`@form-choice-open <extraType>`** – a choice control; `setItems()`
narrows the value to the item keys, `@form-choice-open` adds a type for free input:

```php
/**
 * @form-read-type int|string|null
 * @form-choice single int
 * @form-choice-open string
 */
final class OpenRatingControl extends BaseControl
```

**`@form-replicator <factoryArgument> [ContainerClass]`** – a replicator container; the number is the 0-based
position of the item factory in the `add*` method:

```php
/** @form-replicator 1 */
final class Multiplier extends Container
```

[kdyby/forms-replicator](https://github.com/Kdyby/FormsReplicator) is supported out of the box.

**`@form-disabler`** – a container method which disables every control in the container; the controls are then
omitted from `getValues()`:

```php
/** @form-disabler */
public function setDisabled(): void
```

**`@form-adds $name [ControlClass]`** – a generic `add*` helper which registers a component under the name passed in
a parameter. The class is optional — without it, the declared return type is used. The tag is repeatable, and it is
valid only on a method of a `Nette\Forms\Container` subclass:

```php
/** @form-adds $name */
public function addPhone(string $name): TextInput
{
	return $this->addText($name)->addRule(Form::Pattern, null, '\+?[0-9 ]+');
}
```

Inside your analysed paths, an unannotated helper registering one component under a parameter is reported
(`orisaiNette.forms.unannotatedRegistrar`). It works today, but the day the class moves into a package its body is not
read any more and the registration disappears. Turn the report off with `orisaiNette.forms.reportUnannotatedRegistrars`.

**`@form-wizard [stepPrefix]`** – a forms wizard; its `createStep1()`, `createStep2()`, … methods are its steps and
`getValues()` returns their values keyed by step number. The optional argument replaces the `createStep` prefix:

```php
/** @form-wizard */
final class RegistrationWizard extends Wizard
```

**`@form-write-spec <spec>`** – the accepted-value spec of a Nette factory method; it is used by the built-in catalog
only. For your own control use `@form-write-type`.

### Forms catalogs

A catalog describes third-party controls and methods by method name, through an interface with annotated methods.
Register it:

```neon
parameters:
	orisaiNette:
		forms:
			catalogs:
				- App\PHPStan\FormsCatalog
```

```php
namespace App\PHPStan;

interface FormsCatalog
{

	/** @form-replicator 1 Vendor\Forms\Multiplier */
	public function addMultiplier(): void;

	/** @form-read-by-arg Vendor\Forms\DateInput::FormatObject=DateTimeImmutable; *=string */
	public function setValueFormat(): void;

}
```

The interface is never implemented — only its methods and their tags are read.

- Built-in catalogs for nette/forms and kdyby/forms-replicator load first. Your catalog may add methods, but declaring
  a method a built-in catalog already declares — including by extending it — is a configuration error.
- `@form-read-type` and `@form-write-spec` entries only take effect for methods `Nette\Forms\Container` itself
  declares. Use catalogs for `@form-rule-cast`, `@form-read-by-arg` and `@form-replicator` entries; describe a control's
  value with `@form-read-type` on the control class (or its stub).
- Changing a catalog invalidates the analysis caches.

### Latte engine loader

Filters, functions and macros registered on your Latte engine are read from the engine itself — calls through them are
checked against their real signatures.

With `orisaiNette.dic.containerLoader` set, the engine comes from the container's
`Nette\Bridges\ApplicationLatte\ILatteFactory`. Without nette/application, return the engine from a file — it is used
when no container provides one:

```php
<?php declare(strict_types = 1);

require __DIR__ . '/../../vendor/autoload.php';

$latte = new Latte\Engine();
$latte->addFilter('money', [App\Latte\Filters::class, 'money']);

return $latte;
```

```neon
parameters:
	orisaiNette:
		latte:
			engineLoader: %currentWorkingDirectory%/tests/phpstan/latte-engine-loader.php
```

With neither, only Latte's built-in filters, functions and macros are known. Filters added at render time
(`$template->addFilter()`) are not seen.

### Latte template customs

A template declaring `{templateType}` gets that class's public methods tagged `@filter` or `@function` as its own
filters and functions — the way Latte's `processParams()` registers them:

```php
final class ProductTemplate extends Template
{

	/** @filter */
	public function price(int $cents): string
	{
		// ...
	}

}
```

```latte
{templateType App\Presentation\Product\ProductTemplate}
{$product->cents|price}
```

On PHP 8, the `#[TemplateFilter]` and `#[TemplateFunction]` attributes work too, if PHPStan's `phpVersion` is 8.0 or
higher. The customs apply only to templates declaring that class — never to other templates, even though at runtime
they may leak there depending on render order.

### Latte discovery formulas

Templates are linked to the presenters and controls rendering them. Nette's own `formatTemplateFiles()` and
`formatLayoutTemplateFiles()` are recognized without configuration. An override of either, and a control method
deriving its template path by convention, is your code — assign it a formula:

```neon
parameters:
	orisaiNette:
		latte:
			discovery:
				formulas:
					App\Presentation\TemplateLocator: samedir-single
					App\Component\BaseControl: dirname-lcfirst
					App\Component\BaseGridControl:
						formula: dirname-templates-lcfirst-fallback
						sharedFallback: '@@grid.latte'
					App\Component\ContentControl:
						formula: dirname-property-lcfirst
						nameProperty: layout
```

The key is the class declaring the method — for a method declared in a trait, the trait.

Examples use a presenter `app/Ui/Admin/UserPresenter.php` (presenter `Admin:User`, view `edit`, with an
`app/Ui/Admin/templates/` directory) and a control `app/Ui/UserGrid.php`.

| Formula                              | Derives                                                                                                                  | Example                                   |
|--------------------------------------|--------------------------------------------------------------------------------------------------------------------------|-------------------------------------------|
| `vendor-two-candidate`               | `<dir>/templates/<Presenter>/<view>.latte`, then `<dir>/templates/<Presenter>.<view>.latte`; `<dir>` moves one level up when it has no `templates/` | `app/Ui/Admin/templates/User/edit.latte`  |
| `vendor-layout-walk`                 | `<dir>/templates/<Presenter>/@layout.latte`, `<dir>/templates/<Presenter>.@layout.latte`, then `templates/@layout.latte` upwards, one level per module | `app/Ui/Admin/templates/User/@layout.latte` |
| `samedir-single`                     | `<dir>/<Presenter>.<view>.latte`                                                                                         | `app/Ui/Admin/User.edit.latte`            |
| `dirname-lcfirst`                    | `<dir>/<lcfirst class name>.latte`                                                                                       | `app/Ui/userGrid.latte`                   |
| `dirname-templates-lcfirst`          | `<dir>/templates/<lcfirst class name>.latte`                                                                             | `app/Ui/templates/userGrid.latte`         |
| `dirname-templates-lcfirst-fallback` | as `dirname-templates-lcfirst`, or `<declaring dir>/templates/<sharedFallback>` when that file does not exist            | `app/Ui/templates/@grid.latte`            |
| `dirname-property-lcfirst`           | `<dir>/<default of the nameProperty property>.latte`; for a `null` default, `<dir>/<lcfirst directory name>.latte`       | `app/Ui/default.latte` (default `'default'`) |

`sharedFallback` is required by `dirname-templates-lcfirst-fallback` only, `nameProperty` by `dirname-property-lcfirst`
only; `<declaring dir>` is the directory of the class declaring the override. A leading `@` in a value is written as
`@@`, otherwise it is read as a service reference. `nameProperty` is read from the rendered class, so a subclass
overriding the property's default gets its own template.

An override without a formula is reported (`orisaiNette.latte.fileDiscoveryOpaque`) and never guessed.

### Bridges extension points

Nothing to add. The bridge uses what Forms and Latte know — annotations and formulas improve it too.

### Rules with fixes

Writing your own PHPStan rule which offers a fix (`fixNode()`)? Code analysed from a `.latte` file has no source to
rewrite, so fixes on template code are dropped. Check `OriPhpstan\Nette\Latte\FixSupport::supportsFixes($scope)` to
skip building them.

## Features

### DI features

Service lookups on `Nette\DI\Container` are typed from your compiled containers:

- `getService()`, `getByName()`, `createService()` – the service's class, across every profile which has it
- `getByType(Foo::class)` – `Foo`; with a `$throw` which is not `true`, `Foo|null` unless every profile has exactly
  one autowired `Foo`; a `class-string<T>` argument gives `T`
- `createInstance(Foo::class)` – `Foo`
- `findByType()` – the list of service names, exact when every profile agrees
- `findByTag()` – `array<string, T>` of the tag's attribute values
- `getServiceType()` – the service's class name
- `getParameters()` and `->parameters` – the shape of your parameters; scalar values are widened to their types, so no
  value (e.g. a password) appears in errors or a baseline
- `hasService()` – narrows a later `getService()` in the same branch

| What is checked or typed                                        | Error identifier                              | Notes                                   |
|-----------------------------------------------------------------|-----------------------------------------------|-----------------------------------------|
| Service name not registered in any container                    | `orisaiNette.dic.serviceNotFound`             | `getService()`, `getByName()`, …        |
| Service name missing in some profiles                           | `orisaiNette.dic.serviceNotInAllContainers`   | names the profiles                      |
| Service name is not a literal                                   | `orisaiNette.dic.dynamicServiceName`          |                                         |
| `hasService()` is always true                                   | `orisaiNette.dic.hasServiceAlwaysTrue`        | partial existence is not reported       |
| `hasService()` is always false                                  | `orisaiNette.dic.hasServiceAlwaysFalse`       |                                         |
| Lookup of a service the `hasService()` guard excluded           | `orisaiNette.dic.serviceMissingInBranch`      | best-effort                             |
| Type not registered in any container                            | `orisaiNette.dic.typeNotFound`                | `getByType()`, `findByType()`           |
| Type missing or not autowirable in some profiles                | `orisaiNette.dic.typeNotInAllContainers`      |                                         |
| Type has several autowired services                             | `orisaiNette.dic.typeAmbiguous`               | `getByType()` throws                    |
| Type registered but not autowired                               | `orisaiNette.dic.typeNotAutowired`            | `getByType()` throws                    |
| Type is not a literal `::class`                                 | `orisaiNette.dic.dynamicType`                 |                                         |
| Tag not present in any container                                | `orisaiNette.dic.tagNotFound`                 | `findByTag()`                           |
| Tag missing in some profiles                                    | `orisaiNette.dic.tagNotInAllContainers`       |                                         |
| Tag is not a literal                                            | `orisaiNette.dic.dynamicTag`                  |                                         |
| Constructors and setup methods called by the container are used | –                                             | [dead code detection](#dead-code-detection) |

#### DI limitations

- Only direct calls on `Nette\DI\Container` are analysed — generated accessors and parametric service managers are not.
- Services added or removed at runtime (`addService()`, `removeService()`) are not tracked.
- The analysis sees the containers your loader builds — local config files included, so results may differ between
  machines.
- Guard-based reports (`serviceMissingInBranch`) may be lost in complex flows; they are never made up.

### Forms features

The shape of a form — its children, their classes and values — is inferred wherever the form is reachable: the
`createComponent*()` building it, an `onSuccess` callback, a presenter nesting it, a helper, a template.

- `$form['email']` and `getComponent('email')` – the control's class; paths such as `$form['address-city']` too
- `getValues()`, `getUntrustedValues()`, `getValue()` – typed values; disabled and omitted controls and buttons are
  handled
- `onSuccess` and other callbacks – the form and `$values` parameters typed
- validation – `setRequired()` and rules narrow values in `onSuccess` and after `isValid()`/`isSuccess()`
- choice controls – `setItems()` narrows the value to the item keys
- date controls – `setFormat()` selects the value type
- containers, replicators (including kdyby/forms-replicator), wizards
- `setMappedType(Dto::class)` and `getValues(Dto::class)` – the DTO
- `addProtection()` – the `_token_` control
- `getPresenter()`, `getForm()`, `lookupPath()` – not nullable when called with the default `$throw`

| What is checked or typed                                                      | Error identifier                                  | Notes                                                   |
|-------------------------------------------------------------------------------|---------------------------------------------------|---------------------------------------------------------|
| Component which does not exist                                                | `orisaiNette.forms.noSuchComponent`               | only on a fully known shape                             |
| Component which may not exist, on an open shape                               | `orisaiNette.forms.unknownAccess`                 | the tip says why the shape is open                      |
| Value of an unknown type                                                      | `orisaiNette.forms.partiallyUnknown`              | the tip says why                                        |
| Component path with an empty segment (`$form['a--b']`)                        | `orisaiNette.forms.shapeInvalidComponentName`     | Nette throws                                            |
| Component registered under an invalid name (`addText('first-name')`)          | `orisaiNette.forms.invalidComponentName`          | Nette throws                                            |
| `isset()`/`??` on a component whose presence is known                         | `orisaiNette.forms.constantExistenceCheck`        | always or never exists                                  |
| Incompatible value written by `setValue()`, `setDefaults()`, `setValues()`, … | `orisaiNette.forms.writeType`                     |                                                         |
| `setValue()` on an upload control                                             | `orisaiNette.forms.writeNoEffect`                 |                                                         |
| Form values not mappable to the mapped type                                   | `orisaiNette.forms.mappedTypeWrite`               | missing property, required member unset, type mismatch  |
| `createComponent*()` returning `Nette\Forms\Form` instead of a UI form        | `orisaiNette.forms.createComponentNonUiForm`      | signals and submission would be lost                    |
| Generic `add*` helper without `@form-adds`                                    | `orisaiNette.forms.unannotatedRegistrar`          | `orisaiNette.forms.reportUnannotatedRegistrars`         |
| `@form-adds` outside a `Nette\Forms\Container` subclass                       | `orisaiNette.forms.outsideContainer`              |                                                         |
| `@form-adds` which does not parse                                             | `orisaiNette.forms.malformed`                     |                                                         |
| `@form-adds` naming no parameter of the method                                | `orisaiNette.forms.unknownParameter`              |                                                         |
| `@form-adds` naming a parameter twice                                         | `orisaiNette.forms.duplicateParameter`            |                                                         |
| `@form-adds` naming a class which does not exist                              | `orisaiNette.forms.unknownControlClass`           | write the class fully qualified                         |
| `@form-adds` naming a class which is not a component                          | `orisaiNette.forms.invalidControlClass`           |                                                         |
| `@form-adds` without a class and without a usable return type                 | `orisaiNette.forms.missingControlClass`           |                                                         |
| `@form-adds` class contradicting the declared return type                     | `orisaiNette.forms.returnTypeContradiction`       |                                                         |
| `dumpComponent()`, `assertComponent()` output                                 | `orisaiNette.forms.componentShapeDump`, `orisaiNette.forms.componentShapeAssert` | [debugging](#forms-debugging)                           |
| `dumpFormValues()`, `assertFormValues()` output                               | `orisaiNette.forms.formValuesDump`, `orisaiNette.forms.formValuesAssert`         | [debugging](#forms-debugging)                           |
| Divergence of two resolution strategies                                       | `orisaiNette.forms.shadowDivergence`              | maintainers only, `forms.internals.indexShadowCompare`  |
| `getPresenter()`, `getForm()` and similar on a component not attached yet     | `orisaiNette.component.unattachedParentAccess`    | always throws; `component.enabled`                      |
| `action*()`, `render*()`, `handle*()` returning a value                       | `orisaiNette.component.magicMethodReturnType`     | must return `void` or `never`; `component.enabled`      |

#### Forms limitations

- `add*` methods registered through `extensionMethod()` are not real methods — no shape is inferred for them. Add a
  real method instead.
- A component name which is not a constant string opens the shape; nothing is reported on an open shape.
- A helper typed `function (Form $form)` is analysed once for all its callers; a field present at any call site counts
  as present in it.
- A container pulled into a variable and passed around (`$section = $form['section']`) opens the whole form's shape.
- `@form-adds` is checked against the method's signature, not its body — a wrong tag gives a wrong shape.

### Latte features

Every `.latte` file is compiled with the real Latte compiler and analysed like a `.php` file, with errors on the
template's lines. PHPStan's own rules (`variable.undefined`, `method.notFound`, `argument.type`, …) work in templates.

- `{templateType}`, `{varType}`, `{parameters}` and typed `{define}` parameters type the template
- `{include}`, `{extends}`, `{layout}`, `{import}` and `{embed}` pass types between templates, checked against what the
  target declares
- filters and functions are checked against their real signatures, including your own
  ([engine loader](#latte-engine-loader), [template customs](#latte-template-customs))
- the template factory's variables (`$user`, `$baseUrl`, `$basePath`, `$flashes`) are provided where the factory
  provides them
- templates are linked to the presenters and controls rendering them; each render method of a control links its own
  template

- errors caused by generated code carry a tip naming the filter or macro which produced it

The package replaces PHPStan's `defaultAnalysisParser` and `cacheStorage` services for every consumer, Latte on or
off; both delegate to PHPStan's own services for everything but `.latte` files.

| What is checked or typed                                                 | Error identifier                                | Notes                                              |
|--------------------------------------------------------------------------|-------------------------------------------------|----------------------------------------------------|
| Template which does not compile                                          | `orisaiNette.latte.parseError`                  |                                                    |
| Unknown macro or `n:` attribute                                          | `orisaiNette.latte.unknownMacro`                |                                                    |
| Unknown filter                                                           | `orisaiNette.latte.unknownFilter`               |                                                    |
| Unknown class in `{templateType}` or `{varType}`                         | `orisaiNette.latte.unknownType`                 |                                                    |
| Include target not statically known                                      | `orisaiNette.latte.dynamicInclude`              |                                                    |
| Extends or layout target not statically known                            | `orisaiNette.latte.dynamicExtends`              |                                                    |
| Included file does not exist                                             | `orisaiNette.latte.unknownInclude`              |                                                    |
| Included block exists in no reachable template                           | `orisaiNette.latte.unknownBlock`                |                                                    |
| Include cycle                                                            | `orisaiNette.latte.includeCycle`                |                                                    |
| Include passes a type the target does not accept                         | `orisaiNette.latte.includeTypeMismatch`         |                                                    |
| Include misses a variable the target declares                            | `orisaiNette.latte.includeMissingVariable`      | `latte.includeIsolation` widens it                 |
| `{varType}` repeating the native declaration                             | `orisaiNette.latte.duplicateDeclaration`        |                                                    |
| `{varType}` narrowing the native declaration                             | `orisaiNette.latte.narrowingOverride`           | `latte.allowNarrowingOverride`                     |
| `{varType}` wider than or unrelated to the native declaration            | `orisaiNette.latte.impossibleOverride`          |                                                    |
| Mid-file `{varType}` not directly above an assignment                    | `orisaiNette.latte.varTypeMisplaced`            |                                                    |
| Mid-file `{varType}` naming another variable than the assignment         | `orisaiNette.latte.varTypeDifferentVariable`    |                                                    |
| Mid-file `{varType}` naming none of the assigned variables               | `orisaiNette.latte.varTypeVariableNotFound`     |                                                    |
| `{varType}` conflicting with the assigned expression's native type       | `orisaiNette.latte.varTypeNativeType`           | `latte.reportAnyTypeWideningInVarType`             |
| `{varType}` conflicting with the assigned expression's PHPDoc type       | `orisaiNette.latte.varTypeType`                 | `latte.reportWrongPhpDocTypeInVarType`             |
| Filter spelled in a different case than registered                       | `orisaiNette.latte.filterCaseMismatch`          | breaks in Latte 3                                  |
| Function spelled in a different case than registered                     | `orisaiNette.latte.functionCaseMismatch`        | breaks in Latte 3                                  |
| Template writing `$this->…` of the compiled template class                | `orisaiNette.latte.internalAccess`              |                                                    |
| Deprecation raised by Latte while compiling                              | `orisaiNette.latte.deprecated`                  |                                                    |
| Invariant of the analysis itself broken                                  | `orisaiNette.latte.internalError`               | please report it                                   |
| Template class conflict between declaration and creation (PHP side)      | `orisaiNette.latte.pairingConflict`             | reported on the class                              |
| Template class not statically resolvable (PHP side)                      | `orisaiNette.latte.pairingOpaque`               | reported on the class                              |
| Template file not resolvable (PHP side)                                  | `orisaiNette.latte.fileDiscoveryOpaque`         | e.g. an override without a [formula](#latte-discovery-formulas) |
| `setView()` or another template write made when it has no effect (PHP side) | `orisaiNette.latte.ineffectiveTemplateMutation` |                                                    |
| `{templateType}` other than the renderer's template class                | `orisaiNette.latte.templateTypeMismatch`        | needs discovery                                    |
| View with no existing template file                                      | `orisaiNette.latte.templateMissing`             | needs discovery                                    |
| Linked template without `{templateType}`                                 | `orisaiNette.latte.templateTypeRequired`        | needs discovery, `latte.templateTypeRequired`      |
| Template no render, include or layout reaches                            | `orisaiNette.latte.orphanTemplate`              | needs discovery; advisory                          |
| `{control}`, `{link}`, `{snippet}`, … where no control renders the template | `orisaiNette.latte.providerUnavailable`       | needs discovery                                    |
| Debugging function output                                                | `orisaiNette.latte.debugDump`                   | [debugging](#latte-debugging)                      |

#### Latte limitations

- Only Latte 2.11 is supported; Latte 3 is not (the package conflicts with `latte/latte >=3.0`). Latte 2.11 does not
  install on PHP 8.4, so Latte analysis needs PHP 7.4 – 8.3, while DI and Forms run on PHP 7.4 – 8.4.
- The `slice` filter keeps value types, but keys widen to `int|string`.
- `{default $x = …}` is analysed as `$x ??= …`; at runtime an existing `null` variable keeps `null`.
- Filters added at render time (`$template->addFilter()`) are not seen — they are reported as unknown.
- A template property declared with a supertype of the factory's template class gets no factory variables.
- `orisaiNette.latte.templateMissing` is not reported for a renderer which links no template at all.

### Bridges features

The bridge joins template discovery with form shapes. For every template, it finds the forms its renderers build and
checks the controls the template names:

```latte
{form userForm}
	<input n:name="e-mail">  {* Control 'e-mail' does not exist on form 'userForm' (App\UserControl). *}
	{input address}          {* Component 'address' on form 'userForm' is a container, not a control (App\UserControl). *}
{/form}
```

Inside `{form}`, `$form` is typed as the paired form with its shape, and `{input}`, `{label}`, `n:name` and
`{formContainer}` type their control as the class the builder added.

| What is checked or typed                                              | Error identifier                              | Notes                              |
|-----------------------------------------------------------------------|-----------------------------------------------|------------------------------------|
| Control absent from the form of every linked renderer                 | `orisaiNette.latteForms.unknownControl`       | reported on the template line      |
| `{form x}` naming a component which is not a form                     | `orisaiNette.latteForms.unknownForm`          |                                    |
| `{input}`, `{label}`, `n:name` naming a container                     | `orisaiNette.latteForms.containerAsControl`   |                                    |
| `{formContainer}` naming a control                                    | `orisaiNette.latteForms.controlAsContainer`   |                                    |
| `{label}` on a control which renders no label (e.g. a submit button) | `orisaiNette.latteForms.labellessControl`     |                                    |

Changes of a form builder's body reach its templates at most one warm run later; changes of signatures reach them in
the same run.

#### Bridges limitations

- The check reports only what it can prove; a template rendered by an unresolvable renderer, a dynamic name or a form
  built outside its `createComponent*()` is silent.
- A template rendered by several forms reports a control only when it is absent from all of them.
- Containers inside replicator rows are not checked for completeness — `$form['items'][0]['x']` is typed, never
  reported.
- A `{define}` inside `{form a}` which is included from `{form b}` is attributed to `a`, where it is written.
- `<label n:name="x">` is not checked for a label-less control.

## For maintainers

Architecture, invariants and test layout are described in [internals](internals/).
