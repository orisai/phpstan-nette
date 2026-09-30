Maintainer documentation; the user-facing guide is ../README.md.

# Internals

## Index

Architecture, invariants, cache design, test layout and known limitations, for someone changing the code.

- [forms.md](forms.md) – form and component shape inference, the shape store and result-cache salt
- [component.md](component.md) – component attachment and the `orisaiNette.component.*` rules
- [dic.md](dic.md) – DI container analysis: registry, receiver classes, rules, type inference, dead-code usage
- [latte.md](latte.md) – Latte template analysis: compile pipeline, cross-file model, narrowing, customs, discovery
- [latte-versions.md](latte-versions.md) – Latte 2/3 version seam, shape families, the Latte 3 compile, the upstream
  template corpus
- [latte-forms.md](latte-forms.md) – the bridge checking form control names in templates

The [library-wide notes](#library-wide-notes) below cover the configuration guard, result-cache meta services, test
layout, dependency profiles and running the gates.

## Library-wide notes

The notes below concern every area.

### Configuration guard

`ConfigurationGuard` (`src/Configuration/`, service `orisaiNette.configurationGuard`) holds the cross-field checks the
schema in `extension.neon` cannot express; the user guide lists their messages. It is also the one place services ask
whether an area is on (`isFormsEnabled()`, `isLatteDiscoveryEnabled()`, `isBridgeEnabled()`, …), so a new switch
belongs there rather than in a service argument.

- `validate()` runs in rule constructors (`ConfigurationGuardRule` exists only for that). PHPStan builds rules when
  analysis starts, so an invalid config surfaces as an internal error on the first analysed file — and a run answered
  entirely from the result cache does not re-check.
- Type extensions validate on **first use**, after their node-kind filter, never in the constructor. PHPStan builds
  them in the stub-validator container too, before the analysed classes exist, where a catalog class check would fail
  spuriously. `validate()` may still throw inside that container for a future stub expression that passes a node
  filter; if a stub ever trips it, move the call further behind the filter.
- Collectors take the guard but do not validate: they are built before rules, and the guard rule reports.
- The Latte version rows (a supported `latte/latte` line, then the nette/forms and nette/application releases Latte 3
  and 3.1 need) run only with `orisaiNette.latte.enabled` on and read the installed versions through
  `ProjectInstalledVersions` (service `orisaiNette.installedVersions`), which tests override with
  `ProjectInstalledVersions::fromRawData()`.
- The routing parser and the template source locator take plain `%orisaiNette.latte.*%` parameters instead of the guard.
  Injecting the guard there created a DI cycle through the reflection provider.

### Result-cache meta services

Each area folds its cache identity into PHPStan's result-cache meta through its own `ResultCacheMetaExtension`:
`FormsResultCacheMeta` (service `formsResultCacheMeta`: `FormFactSalt` plus `CatalogIdentity`),
`LatteResultCacheMeta` (edge topology, harvest salt, discovery store) and `ContainerResultCacheMetaExtension`
(compiled container files). The `formsResultCacheMeta` service name is internal: one invalidation fixture overrides its
`enabled` argument to switch the salt off (see [latte-forms.md](latte-forms.md#invalidation)). Renaming the service
breaks that fixture, not users.

### Test layout

- `tests/Unit/<Area>/` and `tests/Integration/<Area>/`, where an area is `Component`, `Configuration`, `Dic`, `Forms`,
  `Latte` or `LatteForms`.
- Fixtures are colocated in a `Fixtures/` directory next to the tests using them, and every such directory is listed in
  `excludePaths` of `tools/phpstan.neon`.
- Test code that needs Latte 3 (or nette/application 3.3) at class-load time lives in a `Latte3/` directory
  (`tests/**/Latte3/`, e.g. `tests/Toolkit/Latte3/`): `tools/phpstan.neon` scans but does not analyse it, and
  `tools/phpstan.latte3.neon` lists each such directory in `paths` (by hand — the list does not glob); callers reach
  it only behind an `InstalledVersionsGuard` check. Only there may test code use PHP 8 syntax.
- Tests are gated by version groups (see [dependency profiles](#dependency-profiles)); a test exercising the Latte 2
  path carries `@group latte2`.
- `tests/Doubles/` holds only doubles shared across areas (e.g. the `ApplicationForm`/`FormContainer` family the Forms
  and bridge tests both build on); `tests/Fixtures/<Area>/` holds shared fixture configs.
- `tests/Toolkit/` is the shared harness: `AnalysisRun`, `InvalidationScenario`, `ScratchProject`,
  `IsolatedPhpstanConfig`, `LattePhpstanConfig`, `FormShapeTestCase`, `ShapeSnapshotAssertions`, `TestGuard`.
- The vendor-drift tests (`VendorCatalogFreshnessTest`, `ComponentModelApiFreshnessTest`, the Latte parity probes under
  `tests/Integration/Latte/Parity/`) are ordinary tests: run the suite against the newest allowed dependencies to catch
  an upstream change.

### Dependency profiles

The default set is Latte 2.11 with nette/application and nette/forms 3.1 and kdyby/forms-replicator 2, on PHP 7.4–8.3.
`composer.json` alone does not pin it — on PHP 8 its constraints also resolve Latte 3 — so `make install-default`
(also `make update`) runs `composer update` with `--with latte/latte:^2.11.6 --with nette/application:~3.1.15 --with
nette/forms:~3.1.11 --with kdyby/forms-replicator:^2.0.0` (`DEFAULT_SET` in the `Makefile`), and the CI `tests`,
`static-analysis` and `coding-standard` jobs install through it. The other supported sets are profiles in
`tools/profiles/<name>.json`, each a set of constraint overrides:

| Profile          | Installs                                                              | PHP (CI)                         |
|------------------|-----------------------------------------------------------------------|----------------------------------|
| (default)        | Latte 2.11, nette/application and nette/forms 3.1, forms-replicator 2 | 7.4–8.3                          |
| `latte2-nette32` | Latte 2.11, nette/application 3.2, nette/forms 3.2                    | 8.3                              |
| `latte30`        | Latte 3.0, nette/application 3.2, nette/forms 3.2                     | 8.2, 8.3                         |
| `latte31`        | Latte 3.1, nette/application 3.3, nette/forms 3.3, nette/caching 3.4  | 8.3, 8.4                         |
| —                | Latte 3.0, nette/application and nette/forms 3.1                      | guard-allowed, not covered by CI |

All three profiles install kdyby/forms-replicator 3. `make profile PROFILE=<name>` writes the git-ignored
`composer.<name>.json` (`tools/profile.php`) and runs `composer update` into `vendor-<name>/`; every other target takes
the same `PROFILE=` and runs against that vendor directory (`COMPOSER` and `COMPOSER_VENDOR_DIR` set). A Latte 3
profile analyses with `tools/phpstan.latte3.neon`: `src/Latte/Version/` (without `Latte2/`) and the `tests/**/Latte3/`
directories only.

Version groups gate tests at runtime (`VersionGroupGate`, `InstalledVersionsGuard::GROUPS`): `latte2`, `latte3`,
`latte30`, `latte31`, `nette32`, `nette33`. A test carrying a group is skipped, visibly, when the installed versions do
not match it, so each suite runs everything its versions support — a Latte 3 profile skips about 760 tests. An unknown
group name throws.

CI runs cs (PHP 8.3) and phpstan (PHP 7.4: `tools/phpstan.neon` analyses for PHP 7.4 up, and on PHP 8 the default set
installs no `symfony/polyfill-php80`) on the default set, phpstan on `latte30` and `latte31`, the tests on every row above, the
corpus gate per profile (see [latte-versions.md](latte-versions.md#upstream-template-corpus)) and `make lint`.

### PHP 7.4 syntax

`src/` must parse on PHP 7.4, including `src/Latte/Version/Latte3/` (the default-profile factory tests autoload it).
PHPStan and phpcs cannot tell — they accept PHP 8 syntax which is a fatal error on 7.4 — so `make lint` runs
`php -l` over `src/` with `LINT_PHP` (default `php7.4`; the CI `lint` job runs it on PHP 7.4). PHP 8 syntax is
allowed only under `tests/**/Latte3/`.

### Running the gates

The default set develops on PHP 7.4–8.3: Latte 2.11 does not install on 8.4, although consumers may run 7.4–8.4. The
Latte 3 profiles need PHP 8.2 or newer.

```
make install-default PRE_PHP="php7.4"
make PRE_PHP="XDEBUG_MODE=off php7.4" cs
make PRE_PHP="XDEBUG_MODE=off php7.4" phpstan
make lint LINT_PHP=php7.4
env -u CLAUDECODE -u AI_AGENT make PRE_PHP="XDEBUG_MODE=off php7.4" tests

make profile PROFILE=latte31 PRE_PHP="php8.4"
make phpstan PROFILE=latte31 PRE_PHP="XDEBUG_MODE=off php8.4"
env -u CLAUDECODE -u AI_AGENT make tests PROFILE=latte31 PRE_PHP="XDEBUG_MODE=off php8.4"

make corpus-harvest PROFILE=latte31 PRE_PHP="php8.4"
make corpus-manifest PROFILE=latte31 PRE_PHP="XDEBUG_MODE=off php8.4"
make smoke-dmonitor PRE_PHP="XDEBUG_MODE=off php8.4"
```

Run one suite at a time: the performance-budget tests are timed and flake under a concurrent suite.

Run a single test class through make, e.g. `make tests PROFILE=latte31 ARGS=tests/Unit/Toolkit/ScratchProjectVendorTest.php`:
the target sets `COMPOSER` and `COMPOSER_VENDOR_DIR` for the profile. `tests/autoload.php` stops a PHPUnit started
from a `vendor-<profile>/` directory without `COMPOSER_VENDOR_DIR`, which would otherwise load `vendor/`, and
`ScratchProject` hands spawned analyses the profile's `composer.<profile>.json` (`VendorDirectory::composerFile()`), so
PHPStan reflects vendor classes from the profile's vendor directory.

Run the tests with `CLAUDECODE` and `AI_AGENT` unset. PHPStan adds error identifiers to its raw output when either
variable is set, and the snapshot tests (e.g. `tests/Integration/Latte/Integration/expected/integration.txt`) compare
that raw output.

