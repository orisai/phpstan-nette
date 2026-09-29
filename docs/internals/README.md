Maintainer documentation; the user-facing guide is ../README.md.

# Internals

## Index

Architecture, invariants, cache design, test layout and known limitations, for someone changing the code.

- [forms.md](forms.md) – form and component shape inference, the shape store and result-cache salt
- [component.md](component.md) – component attachment and the `orisaiNette.component.*` rules
- [dic.md](dic.md) – DI container analysis: registry, receiver classes, rules, type inference, dead-code usage
- [latte.md](latte.md) – Latte template analysis: compile pipeline, cross-file model, narrowing, customs, discovery
- [latte-forms.md](latte-forms.md) – the bridge checking form control names in templates

The [library-wide notes](#library-wide-notes) below cover the configuration guard, result-cache meta services, test
layout and running the gates.

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
  `tools/phpstan.latte3.neon` lists each such directory in `paths`; callers reach it only behind an
  `InstalledVersionsGuard` check. Tests exercising the Latte 2 path carry `@group latte2` (see `VersionGroupGate`).
- `tests/Doubles/` holds only doubles shared across areas (e.g. the `ApplicationForm`/`FormContainer` family the Forms
  and bridge tests both build on); `tests/Fixtures/<Area>/` holds shared fixture configs.
- `tests/Toolkit/` is the shared harness: `AnalysisRun`, `InvalidationScenario`, `ScratchProject`,
  `IsolatedPhpstanConfig`, `LattePhpstanConfig`, `FormShapeTestCase`, `ShapeSnapshotAssertions`, `TestGuard`.
- The vendor-drift tests (`VendorCatalogFreshnessTest`, `ComponentModelApiFreshnessTest`, the Latte parity probes under
  `tests/Integration/Latte/Parity/`) are ordinary tests: run the suite against the newest allowed dependencies to catch
  an upstream change.

### Running the gates

The development environment is PHP 7.4–8.3: Latte 2.11 does not install on 8.4, although consumers may run 7.4–8.4.

```
make PRE_PHP="XDEBUG_MODE=off php7.4" cs
make PRE_PHP="XDEBUG_MODE=off php7.4" phpstan
env -u CLAUDECODE -u AI_AGENT make PRE_PHP="XDEBUG_MODE=off php7.4" tests
```

Run the tests with `CLAUDECODE` and `AI_AGENT` unset. PHPStan adds error identifiers to its raw output when either
variable is set, and the snapshot tests (e.g. `tests/Integration/Latte/Integration/expected/integration.txt`) compare
that raw output.

