# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/phpstan-nette/compare/...v1.x)

### Added

- Latte 3.0 and 3.1 support next to Latte 2.11; the installed Latte line and nette/forms bridge are detected, with
  nothing to configure (`latte/latte >=3.2` conflicts until its shapes are supported)
- nette/application and nette/forms 3.2 and 3.3, nette/di and nette/component-model 3.2, kdyby/forms-replicator 3
  support
- Latte 3: extensions are read from the harvested engine — tags (with the `n:` attributes Latte derives from them),
  filters, functions, providers and feature flags — and templates compile with them; strict types and strict parsing
  follow the engine, off without one
- Latte: filter loaders (`addFilterLoader()`) are asked for the filter names templates use, on every Latte line
- Latte: a presenter's `#[TemplateVariable]` properties are template variables (nette/application 3.2)
- Latte: `{templateType}` filters and functions follow the installed line — Latte 3.1 reads the attributes only
- Latte 3: `{linkBase}`, `{templatePrint}`, the 3.1 attribute formatters and `Feature::ScopedLoopVariables` loops are
  analysed
- Latte: a harvested Latte 3 extension is identified by its package version or by every PHP file under its directory;
  an unreadable directory there is reported as `orisai.nette.latte.customsHarvest`
- Validation: `orisai.nette.latte.enabled` rejects an unsupported `latte/latte` line and nette/forms or
  nette/application releases without the Latte 3 or 3.1 bridges
- phpstan-nette patch verified against phpstan/phpstan-nette 2.0.8–2.0.15
- nette/component-model 4 support next to 3; with 4 `getComponents()` types as an array of the children, as it is
  declared there
- shipmonk/dead-code-detector 1.x support for the DI container usage provider (`config/dic-dead-code.neon`), next
  to 0.15; the user guide documents what the provider marks as used and what it does not
- Latte discovery follows `Presenter::switch()` (nette/application 3.2) as a view mutation
- Latte: an ordinary run creates an absent narrowing store directory and removes the store files of deleted templates,
  also when it analyses only part of the paths; each store file records its including template

### Changed

- Result cache: no change discards PHPStan's whole result cache any more. Each analysed file declares what it read
  through PHPStan 2.3's dependency tracking, so only the files depending on a change are analysed again: DI files by
  the answers they got from the compiled containers (a rebuild answering alike, nette/di 3.2's random class suffix
  included, re-analyses nothing), Forms files by the files their form shapes were read from (comments and formatting
  ignored) and by the registration sets they depend on, templates by their include component, discovery records,
  harvest and template factory wiring, render-side classes by their render facts
- Latte: `bootstrapFiles` run before the template factory, the presenter mapping and the engine are read from the
  loaders, so a service needing a class only a bootstrap autoloader loads is read alike in PHPStan's main process and
  in its workers: a warm run no longer re-analyses every template and renderer when workers are spawned, and a run
  whose workers are forked no longer loses the template factory's default class and wiring
- Latte: the discovery build before each run tells classes from interfaces and traits by their declarations instead of
  reflecting every analysed class in the main process
- DI and Forms: `bootstrapFiles` also run before the DI container loader is read and before configured
  `orisai.nette.forms.catalogs` are checked, so a container or catalog class only a bootstrap autoloader loads no
  longer makes every warm run re-analyse the files asking the container, nor fails a run with spawned workers
- Latte: the discovery store no longer has to sit inside `paths` for record changes to stay granular, and narrowing
  slices outside `paths` are tracked too; `orisai.nette.latte.discovery.coarseInvalidationAccepted` has no effect
- With PHPStan's `reuseCollectedDataRuleErrors` toggle, the whole-project Latte and bridge rules and the narrowing
  store writer always run

- Latte: a run which rewrites the narrowing store reports the non-ignorable `orisai.nette.latte.narrowingStoreChanged`,
  which asks to run PHPStan again and commit the regenerated store files, so CI fails on an outdated store without
  comparing the git state; with `reuseCollectedDataRuleErrors` a run which rewrote the store is never reused
- Parameters are nested under `orisai: nette:` instead of `orisaiNette:`, and the services are named `orisai.nette.*`
- Error identifiers start with `orisai.nette.` instead of `orisaiNette.`
- `orisai.nette` is validated by the extension (nette/schema is required) rather than by PHPStan, so other orisai
  extensions can keep their options under `orisai` too; a key directly under `orisai` which no installed extension owns
  is not reported
- `symfony/polyfill-php80` is required: the extension calls `str_starts_with()` and names `Stringable` on PHP 7.4
- Development: the primary dependency set (`composer update`) is the newest one — Latte 3.1 with nette/application and
  nette/forms 3.3 — and PHPStan analyses it for PHP 8.4; the former default set is the `latte2` profile, the `latte31`
  profile is gone and a `lowest` profile installs the lowest dependencies on PHP 7.4
- `src/Latte/Version/Latte3/` no longer has to parse on PHP 7.4: it is loaded only with Latte 3, which requires PHP 8
- Minimum versions raised to the lowest set the test suite passes on: PHPStan ^2.3.5,
  latte/latte 2.11.7, nette/forms 3.1.15 and nette/component-model 3.0.3 (the extension reads
  `IComponent::NameSeparator`)
- Latte 3: tags Latte 3 dropped (`{includeblock}`, `{status}`, `{use}`, and `{ifCurrent}` with nette/application 3.3)
  are reported as `orisai.nette.latte.parseError`, never passed through as unknown macros
- Latte 2: a repeated unpaired unknown tag with no closing tag compiles and reports `orisai.nette.latte.unknownMacro`
  instead of "Missing {/foo}"
- Latte 2: `{name` inside `<script>`/`<style>` is `orisai.nette.latte.parseError` with Latte's "(in JavaScript or CSS
  …)" message instead of an unknown-macro passthrough
- Latte: generated PHP which does not parse is `orisai.nette.latte.parseError` "Error in template: …" on the template
  line, instead of a `phpstan.parse` error which hid every other finding of the run
- Latte: a throwable raised by Latte or a Latte extension while compiling (e.g. `{block html|noescape}` on Latte 2) is
  `orisai.nette.latte.parseError` "Thrown exception '…'" instead of an internal error
- Latte 2: block, define and snippet method findings are reported on the `{block}` tag line (or the body's first line)
  instead of the last line of the template body
- Latte: paired `{label}` and attributed `{input}`/`{label /}` are typed through an `Html` stand-in on every forms
  bridge
- Latte: `bootstrapFiles` run as soon as the first `.latte` file is parsed in the main process (a warm run with a
  changed template, or any pre-fork `LatteTpl_*` reflection), so resources they open are inherited by PHPStan's forked
  workers again — the behaviour PHPStan 2.2's deferred bootstrap removed
- DI: service types are read from the container's `$wiring` (nette/di 3.2 removed `$types`); on nette/di 3.1 an
  imported service whose type is not exported is known by name only
- Latte 3: a filter or function spelled in another case than registered is `orisai.nette.latte.filterCaseMismatch` or
  `orisai.nette.latte.functionCaseMismatch` naming the registered spelling, as Latte 3 resolves names case-sensitively,
  instead of being typed as the registered one
- Latte: a filter or function registered as an anonymous closure is known but untyped instead of
  `orisai.nette.latte.unknownFilter`
- Development: the timed performance budget tests, including the Forms inference on/off ratio, are replaced by phpbench
  benchmarks (`make bench`), run by hand

### Fixed

- Result cache: warm runs no longer keep results a cold run would not report
  - Forms: a shape reading a component name, a flag or a control class the scope types (`Names::FIELD`,
    `Names::name()`, `->setNullable(Flags::NULLABLE)`) depends on the files declaring them, constant initialisers
    followed through every file they read; a class a shape found missing is re-checked, so creating it re-analyses
    the readers and invalidates the stored shape
  - Forms: a file reusing a registrar body another file already read still depends on it, and a builder chain is
    always resolved by its reader rather than from whichever factory-method shape another file stored first
  - Latte: an include argument reaches templates any number of includes below its includer, and an including
    template's own narrowing captures re-analyse it
  - Latte: the render facts of a class are recomputed when a class they found missing, e.g. a paired template class,
    appears
  - Latte: `orisai.nette.latte.customsHarvest` moves to the next template when the one reporting it is deleted
- Forms: an offset write of a value no `Container::offsetSet()` accepts (`$renderer->wrappers['form']['container'] =
  null`) is no longer a mutation of a component of that name, which made every `form` component of a project
  "mutated externally" and its templates' `$form['x']` an `IComponent`
- Forms: a component shape no longer depends on which file first asked for it: names written with a variable, `self::`,
  `static::` or `parent::` are read off the building function's own code instead of the asking scope, and a nested
  container's class off the builder's own receiver; a different file count or parallel job split no longer changes
  findings
- Forms: a followed helper's early `return;` makes what it adds afterwards maybe-present instead of present
- Forms: an offset keyed by one of several literal names (`foreach (['a', 'b'] as $n) $form[$n]`) resolves to those
  children only, instead of to every child of the form
- Latte: `{input x:$key}` and `{label x:$key /}` on a control the analysis cannot resolve no longer report
  `BaseControl::getControlPart()`/`getLabelPart()` invoked with 1 parameter

- Latte: the variables Latte binds by itself (`$iterator`, Latte 2's `$iterations`, `$form`, `$formContainer`,
  `$ʟ_*` temporaries) are left out of the analysed code where nothing reads them, so PHPStan 2.3's unused-variable
  rules (bleedingEdge) no longer report them; a `{foreach}` expression is analysed once instead of twice
- Forms: `isset($container['x'])` no longer narrows the component to `IComponent` on PHPStan 2.3, which skips the
  `offsetGet()` dynamic return type there
- Forms: a narrowed `getControls()` (nette/forms 3.3) and `getComponents()` (nette/component-model 3.2) type as the
  `iterable` the methods declare instead of `Iterator`, so `->current()` on them is reported
- PHP 7.4: no crash "Class 'ReflectionAttribute' not found" with PHPStan 2.2.10+, which runs result-cache meta
  extensions before the `bootstrapFiles` loading its PHP < 8 runtime polyfills; the Latte discovery index reflects
  methods there, and an attributed one needs the polyfills
- Latte: a warm run no longer reports `orisai.nette.latte.unknownType` for a `{templateType}` class loadable only through
  a `bootstrapFiles` autoloader (PHPStan 2.2 parses changed files before running them)
- Latte: `LatteDiscovery_*`/`LatteSlice_*` store classes written after the first reflection lookup are located, so
  forked workers no longer report them as not found
- Latte 2: `{php $x = 1}` and `{do}` assignments written with spaces are declarations, and `{block foo|upper}` is
  named `foo`
- Latte 2: the unknown-tag retry compiles the original tokens (`<textarea n:foo />` no longer fails with "Unexpected
  end")
- Latte: a file include inside a block is anchored at its own statement, and a block include only where every context
  agrees on the block's types
- Latte: vendor template-surface types (e.g. nette/application's `Template`) are a floor for template-class pairing,
  not a binding
- Latte: factory-provided variables follow the installed nette/application's template class and factory
- Forms: replicator rows follow the installed kdyby/forms-replicator (`getContainers()` is an `array` on 3.x)
- Forms: the stub restates the choice controls' `$disabled` property, and the vendor freshness gate ignores Nette's
  `@property-deprecated`
- Latte 3: the template compile cache follows the installed nette/caching version, so an upgrade no longer serves
  `{cache}` code printed by the previous bridge
- Latte 3: a `{cache}` tag whose bridge print carries no quoted key keeps the bridge's own print instead of failing the
  analysis with an internal error
- Latte: a template surface of a path-repository package linked into the vendor directory stays a binding when it lies
  inside `firstPartyPaths` (a monorepo), instead of being treated as a vendor floor
- Latte 3: a discovery or narrowing store kept inside a first-party extension's directory no longer changes the
  extension's harvest salt on every run
