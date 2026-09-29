# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/phpstan-nette/compare/...v1.x)

### Added

- phpstan-nette patch verified against phpstan/phpstan-nette 2.0.8–2.0.12
- Latte discovery follows `Presenter::switch()` (nette/application 3.2) as a view mutation

### Fixed

- Latte: a warm run no longer reports `orisaiNette.latte.unknownType` for a `{templateType}` class loadable only through a `bootstrapFiles` autoloader (PHPStan 2.2 parses changed files before running them)

### Changed

- Latte: `bootstrapFiles` run as soon as the first `.latte` file is parsed in the main process (a warm run with a changed template, or any pre-fork `LatteTpl_*` reflection), so resources they open are inherited by PHPStan's forked workers again — the behaviour PHPStan 2.2's deferred bootstrap removed
- Latte: `LatteDiscovery_*`/`LatteSlice_*` store classes written after the first reflection lookup are located, so forked workers no longer report them as not found
