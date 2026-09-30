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
  an unreadable directory there is reported as `orisaiNette.latte.customsHarvest`
- Validation: `orisaiNette.latte.enabled` rejects an unsupported `latte/latte` line and nette/forms or
  nette/application releases without the Latte 3 or 3.1 bridges
- phpstan-nette patch verified against phpstan/phpstan-nette 2.0.8–2.0.12
- Latte discovery follows `Presenter::switch()` (nette/application 3.2) as a view mutation

### Changed

- `symfony/polyfill-php80` is required: the extension calls `str_starts_with()` and names `Stringable` on PHP 7.4
- Latte 3: tags Latte 3 dropped (`{includeblock}`, `{status}`, `{use}`, and `{ifCurrent}` with nette/application 3.3)
  are reported as `orisaiNette.latte.parseError`, never passed through as unknown macros
- Latte 2: a repeated unpaired unknown tag with no closing tag compiles and reports `orisaiNette.latte.unknownMacro`
  instead of "Missing {/foo}"
- Latte 2: `{name` inside `<script>`/`<style>` is `orisaiNette.latte.parseError` with Latte's "(in JavaScript or CSS
  …)" message instead of an unknown-macro passthrough
- Latte: generated PHP which does not parse is `orisaiNette.latte.parseError` "Error in template: …" on the template
  line, instead of a `phpstan.parse` error which hid every other finding of the run
- Latte: a throwable raised by Latte or a Latte extension while compiling (e.g. `{block html|noescape}` on Latte 2) is
  `orisaiNette.latte.parseError` "Thrown exception '…'" instead of an internal error
- Latte 2: block, define and snippet method findings are reported on the `{block}` tag line (or the body's first line)
  instead of the last line of the template body
- Latte: paired `{label}` and attributed `{input}`/`{label /}` are typed through an `Html` stand-in on every forms
  bridge
- Latte: `bootstrapFiles` run as soon as the first `.latte` file is parsed in the main process (a warm run with a
  changed template, or any pre-fork `LatteTpl_*` reflection), so resources they open are inherited by PHPStan's forked
  workers again — the behaviour PHPStan 2.2's deferred bootstrap removed
- DI: service types are read from the container's `$wiring` (nette/di 3.2 removed `$types`); on nette/di 3.1 an
  imported service whose type is not exported is known by name only
- Latte 3: a filter or function spelled in another case than registered is `orisaiNette.latte.filterCaseMismatch` or
  `orisaiNette.latte.functionCaseMismatch` naming the registered spelling, as Latte 3 resolves names case-sensitively,
  instead of being typed as the registered one
- Latte: a filter or function registered as an anonymous closure is known but untyped instead of
  `orisaiNette.latte.unknownFilter`

### Fixed

- Latte: a warm run no longer reports `orisaiNette.latte.unknownType` for a `{templateType}` class loadable only through
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
