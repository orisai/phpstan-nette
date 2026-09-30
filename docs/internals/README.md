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
layout, dependency profiles, static analysis and running the gates.

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
  `excludePaths` of `tools/phpstan.common.neon`.
- Test code that needs Latte 3 (or nette/application 3.3) at class-load time lives in a `Latte3/` directory
  (`tests/**/Latte3/`, e.g. `tests/Toolkit/Latte3/`), test code built on Latte-2-only symbols (`Latte\Parser`,
  `Latte\MacroTokens`, `Latte\Macros\*`, …) in a `Latte2/` directory (`tests/**/Latte2/`). Each config scans the
  other line's directories but does not analyse them (see [static analysis](#static-analysis)); callers reach them
  only behind an `InstalledVersionsGuard` check.
- A test which loads `src/Latte/Version/Latte3/` without Latte 3 installed (the adapter factory tests construct the
  Latte 3 adapter by class name) requires PHP 8 (`@requires PHP >= 8.0`): those sources need not parse on PHP 7.4.
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

The primary set is the newest one `composer.json` allows: `make update` (a plain `composer update`) installs Latte 3.1
with nette/application and nette/forms 3.3, nette/caching 3.4 and kdyby/forms-replicator 3 into `vendor/`, on PHP 8.3
or 8.4. The CI `coding-standard`, `static-analysis` and `tests` jobs install it the same way. The other supported sets
are profiles in `tools/profiles/<name>.json`, each a set of constraint overrides (`require`, `require-dev`), Composer
update flags (`update-flags`), ignored platform requirements (`ignore-platform-req`) and a PHP ceiling
(`php-ceiling`). Each profile runs in CI on its highest compatible PHP:

| Profile          | Installs                                                                   | PHP (CI) |
|------------------|----------------------------------------------------------------------------|----------|
| (primary)        | Latte 3.1, nette/application and nette/forms 3.3, nette/caching 3.4        | 8.3, 8.4 |
| `lowest`         | the lowest dependencies (`--prefer-lowest --prefer-stable`)                | 7.4      |
| `latte2`         | Latte 2.11, nette/application and nette/forms 3.1, forms-replicator 2      | 8.3      |
| `latte2-nette32` | Latte 2.11, nette/application 3.2, nette/forms 3.2                         | 8.3      |
| `latte30`        | Latte 3.0, nette/application 3.2, nette/forms 3.2                          | 8.4      |

Latte 3.0 with nette/application and nette/forms 3.1 is allowed by the guard but not covered by CI. `lowest` resolves
the floors `composer.json` declares — Latte 2.11.7, nette/application and nette/forms 3.1.15, phpstan/phpstan 2.2.0 on
PHP 7.4 — which are the lowest versions the suite passes on: raise a floor rather than let the `lowest` suite fail. A
test whose expectation needs a newer PHPStan skips by capability (e.g.
`BootstrapFilesRunner::mergeNewAutoloadFunctions()`, `decimal-int-string` typing) or by version
(`InstalledVersionsGuard::requirePhpstan()`, e.g. the forms-helper string arguments printed differently before 2.2.9). The `nikic/php-parser` floor (require-dev) follows the php-parser the
lowest PHPStan phar bundles, as PHPUnit loads the vendor copy first.

The primary set, `latte2-nette32` and `latte30` install kdyby/forms-replicator 3. The Latte 2 sets cap at PHP 8.3
(Latte 2.11.7, nette/forms 3.1.15 and nette/utils 3.2 do): their `php-ceiling` is 8.3, and `tools/profile.php --flags`
adds `--ignore-platform-req=php` only when the running PHP is above it, so they install on PHP 8.4 locally while CI on
8.3 resolves what Composer would really install there. `make profile PROFILE=<name>` writes the git-ignored
`composer.<name>.json` (`tools/profile.php`) and runs `composer update` into `vendor-<name>/`; every other target takes
the same `PROFILE=` and runs against that vendor directory (`COMPOSER` and `COMPOSER_VENDOR_DIR` set).

Version groups gate tests at runtime (`VersionGroupGate`, `InstalledVersionsGuard::GROUPS`): `latte2`, `latte3`,
`latte30`, `latte31`, `nette32`, `nette33`. A test carrying a group is skipped, visibly, when the installed versions do
not match it, so each suite runs everything its versions support — the primary set skips about 760 tests. An unknown
group name throws.

Coverage (`make coverage-clover`) does not process uncovered files (`processUncoveredFiles="false"` in
`tools/phpunit.xml`): loading them would include the other Latte line's classes.

### Static analysis

PHPStan analyses for one fixed PHP version per config, the highest the analysed set supports, so it sees every feature
the code and the vendor it calls use; PHP 7.4 compatibility is covered by the `lowest` tests, not by PHPStan, and
`make phpstan PROFILE=lowest` refuses to run.

- `tools/phpstan.neon` (primary, `phpVersion` 8.4, `make phpstan` and `make phpstan PROFILE=latte30`) analyses
  `src tests tools/corpus` and the smoke scripts against Latte 3. It excludes from analysis (still scanned)
  `src/Latte/Version/Latte2/` and the `tests/**/Latte2/` directories. Everything built on Latte 2 internals (the
  Latte 2 compiler and its macros, `DeclarationScanner`, `TemplateFactExtractor`, `MacroPairing`,
  `CaseMismatchScanner`) lives in `src/Latte/Version/Latte2/`; what both lines share (`BlockBodyTracker`,
  `PairedTags`) stays outside it and is analysed by both configs.
- `tools/phpstan.latte2.neon` (`phpVersion` 8.3, `make phpstan PROFILE=latte2|latte2-nette32`) analyses the same
  paths against Latte 2 and excludes `src/Latte/Version/Latte3/` and the `tests/**/Latte3/` directories.
- Both include `tools/phpstan.common.neon` (level, excluded fixtures, shared ignores) and their own baseline
  (`phpstan.baseline.neon`, `phpstan.latte2.baseline.neon`; `make phpstan-baseline [PROFILE=…]` writes the matching
  one). A finding only one set of a config reports (e.g. a deprecation only nette/forms 3.3 declares) is an ignore with
  `reportUnmatched: false` in the config, not a baseline entry. The `(string) substr()` casts PHP 7.4 needs are
  ignored per file with a count.
- The result cache is kept per vendor directory (`var/tools/PHPStan/resultCache.<vendor-dir>.php`, set by
  `tools/phpstan.cache.php` from `COMPOSER_VENDOR_DIR`, `vendor` without it), so the sets do not evict each other.

CI runs cs, phpstan and the tests on the primary set, phpstan on `latte2`, `latte2-nette32` and `latte30`, the tests on
every profile row above, the corpus gate per profile (see
[latte-versions.md](latte-versions.md#upstream-template-corpus)) and `make lint`.

### PHP 7.4 syntax

`src/` must parse on PHP 7.4, except `src/Latte/Version/Latte3/`, which is loaded only with Latte 3 installed and so
only on PHP 8. PHPStan and phpcs cannot tell — they accept PHP 8 syntax which is a fatal error on 7.4 — so
`make lint` runs `php -l` over the rest of `src/` with `LINT_PHP` (default `php7.4`; the CI `lint` job runs it on
PHP 7.4). Test code runs on PHP 7.4 in the `lowest` suite, which is what catches a test loading PHP 8-only code
there.

### Running the gates

The primary set and `latte30` develop on PHP 8.4; the `lowest` profile on PHP 7.4; the Latte 2 profiles on PHP 8.3,
or on PHP 8.4, above their ceiling, with the php platform requirement ignored. Coverage runs with pcov on the default
`php`: `make coverage-clover ARGS=<test>`.

```
make update PRE_PHP="php8.4"
make PRE_PHP="XDEBUG_MODE=off php8.4" cs
make PRE_PHP="XDEBUG_MODE=off php8.4" phpstan
make lint LINT_PHP=php7.4
env -u CLAUDECODE -u AI_AGENT make PRE_PHP="XDEBUG_MODE=off php8.4" tests

make profile PROFILE=lowest PRE_PHP="php7.4"
env -u CLAUDECODE -u AI_AGENT make tests PROFILE=lowest PRE_PHP="XDEBUG_MODE=off php7.4"

make profile PROFILE=latte2 PRE_PHP="php8.4"
make phpstan PROFILE=latte2 PRE_PHP="XDEBUG_MODE=off php8.4"
env -u CLAUDECODE -u AI_AGENT make tests PROFILE=latte2 PRE_PHP="XDEBUG_MODE=off php8.4"

make corpus-harvest PROFILE=latte2 PRE_PHP="php8.4"
make corpus-manifest PROFILE=latte2 PRE_PHP="XDEBUG_MODE=off php8.4"
make smoke-dmonitor PRE_PHP="XDEBUG_MODE=off php8.4"
```

Run one suite at a time: the performance-budget tests are timed and flake under a concurrent suite.

Run a single test class through make, e.g. `make tests ARGS=tests/Unit/Toolkit/ScratchProjectVendorTest.php`, or
`make tests PROFILE=latte2 ARGS=tests/Unit/Toolkit/ScratchProjectVendorTest.php` for a profile: the target sets
`COMPOSER` and `COMPOSER_VENDOR_DIR` for the profile. `tests/autoload.php` stops a PHPUnit started
from a `vendor-<profile>/` directory without `COMPOSER_VENDOR_DIR`, which would otherwise load `vendor/`, and
`ScratchProject` hands spawned analyses the profile's `composer.<profile>.json` (`VendorDirectory::composerFile()`), so
PHPStan reflects vendor classes from the profile's vendor directory.

Run the tests with `CLAUDECODE` and `AI_AGENT` unset. PHPStan adds error identifiers to its raw output when either
variable is set, and the snapshot tests (e.g. `tests/Integration/Latte/Integration/expected/integration.txt`) compare
that raw output.

