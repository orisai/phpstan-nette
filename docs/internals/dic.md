Maintainer documentation; the user-facing guide is ../README.md.

# Nette DI container analysis

Components in `src/Dic/` teach PHPStan the shape of an application's compiled Nette DI containers,
so calls like `$container->getService('foo')` or `$container->getByType(Bar::class)` get precise
return types and container misuse (missing services/types/tags, drift between the application's
runtime profiles) is caught statically instead of surfacing as a runtime `MissingServiceException`.

## What is analysed

The loader configured in `orisaiNette.dic.containerLoader` returns one compiled container per
runtime *profile* — e.g. one for the console and one for HTTP, or one per API variant. Every query
is answered per profile and reported with the profiles it concerns, so a service registered only in
the HTTP container is caught where console code asks for it.

How the application builds those containers is its own business. What the analysis assumes is only
what `ContainerRuntimeParityTest` pins against real Nette DI: `initialize()` is never called by the
loader, so extensions that only run on `initialize()` (e.g. session start, request-bound setup) leave
no trace in the analysed container.

### Loader contract

The loader file returns `array<string, Nette\DI\Container>` keyed by profile name.
`MultiContainerRegistry` also accepts a bare `Nette\DI\Container` as shorthand for
`['default' => $container]`; `DiscoveryResolver::doResolveMapping()` and
`TemplateFactoryDefaultResolver` (the Latte side, which reads
`orisaiNette.latte.templateFactoryContainerLoader`) accept the same two shapes. A relative loader path
resolves against the working directory, not against the config file. The loader may be required more
than once per process, so it must be idempotent.

This feature's `getByType()` typing (`TypeLookupReturnTypeExtension`) replaces phpstan-nette's own
`ServiceLocatorDynamicReturnTypeExtension`. A consumer with phpstan-nette installed disables it via
the `netteServiceLocatorDynamicReturnType: false` parameter, which the patch shipped in
`patches/phpstan-nette-conditional-dynamic-return-types.patch` adds — otherwise two competing
`getByType()` extensions coexist with undefined ordering. The library's own config does **not** set
the switch: that would make phpstan-nette a hard requirement. The same holds for the Forms switches
(`NoConfigurationTest::testOutOfTheBoxPhpstanNetteShadowsFormsTyping` pins what an unpatched setup
looks like).

### Cache freshness

Two mechanisms keep analysis results in sync with DI config changes; both were added after a
stale-cache incident (a freshly registered service reported as `orisaiNette.dic.typeNotFound`):

- **The loader must rebuild stale containers.** This is the consumer's side of the contract.
  Vanilla `Nette\Bootstrap\Configurator` couples `autoRebuild` to debug mode, so a loader that
  builds with debug mode off never recompiles its cached containers after NEON edits. A loader that
  wants debug mode off (no debug-only services, a stable container cache key) has to force
  `autoRebuild: true` itself, e.g. by overriding `Configurator::loadContainer()`. Cost: one recompile
  per profile on the first run after a config/DI-relevant class change.
- **PHPStan's result cache keys on compiled container content.**
  `Cache/ContainerResultCacheMetaExtension` (tag `phpstan.resultCacheMetaExtension`) hashes the
  files from `MultiContainerRegistry::getContainerFilePaths()` into the result-cache meta, so a
  container content change invalidates the whole result cache — including files PHPStan would
  otherwise replay from cache (e.g. a service *removed* from NEON without touching any analysed
  PHP now re-flags its call sites). A NEON edit that recompiles to identical container code (e.g.
  comment-only) produces the same hash and keeps the cache valid. The registry loads containers
  lazily at meta-computation time — after the loader has rebuilt them — so the hash always
  reflects current config. With no loader configured (`orisaiNette.dic.containerLoader: null`) the hash is the
  constant `inactive`.

## Components

- `Metadata/ContainerMetadata` (+ `ContainerMetadataFactory`) — a per-profile snapshot built via
  reflection on a live `Container` instance: service names, resolved types, aliases, tags, wiring
  buckets, and `getParameters()`. A service's type is its `createService*()` method's return type;
  where nette/di < 3.2 compiles no usable one (the `container` service returns the compiled
  subclass, imported services return `void`), it is the most specific class listing the service in
  any `$wiring` bucket. `$types` (removed in nette/di 3.2) is never read, so services added at
  runtime via `Container::addService()` are not indexed.
- `Metadata/MultiContainerRegistry` — loads all profiles once (lazily, memoized), classifies
  receiver types into profile subsets (`resolveProfiles()`, see *Receiver classes*) and answers
  profile-subset queries: service/type/tag existence per profile, merged service/tag/parameter
  types.
- `Metadata/ParameterTypeWidener` — widens a runtime parameter value to a PHPStan `Type`.
- `Cache/ContainerResultCacheMetaExtension` — `phpstan.resultCacheMetaExtension` implementation
  feeding the compiled containers' content hash into PHPStan's result-cache meta (see *Cache
  freshness*).
- `Metadata/TypeLookupResult` — per-profile result of a wiring lookup: whether the type is known,
  the bucket-0 (autowired) candidate names, and all candidate names across buckets.
- `Type/*ReturnTypeExtension` (7 classes) — `phpstan.broker.dynamicMethodReturnTypeExtension` /
  `expressionTypeResolverExtension` implementations typing `Container`'s dynamic methods and the
  `->parameters` property.
- `Rule/*CallRule` (3 classes) — `Rule<MethodCall>` implementations flagging container misuse.
- `DeadCode/ContainerUsageExtractor` (+ `ContainerUsageVisitor`) and `DeadCode/DicUsageProvider` —
  a `shipmonk.deadCode.memberUsageProvider` that marks constructors/setup methods invoked by the
  compiled containers as used.

## Receiver classes

Compiled containers are regenerated on every app change and never hand-edited, so a generated
container class is effectively *final*: its class name identifies exactly one snapshot the registry
already holds. `MultiContainerRegistry::resolveProfiles()` classifies every receiver type by its
object class names into three categories:

1. **Registry-known generated class** — the receiver's class name equals a loaded profile's
   container class name: every query (existence, types, tags, parameters) is answered from *that
   profile alone*. Messages name only the relevant profile (`… not registered in any analysed
   container (alpha).`), `hasService()` constant reports become exact single-container statements,
   `getService()` types drop the cross-profile union, and `getParameters()`/`->parameters` return
   that profile's widened shape with no optional-key merging. A union of known classes resolves to
   the union of their profiles, with subset semantics everywhere.
2. **Base class `Nette\DI\Container` exactly** — all profiles, the application convention: at
   runtime such a handle always points at one of the generated containers.
3. **Unknown container** — any proper subclass of `Nette\DI\Container` whose class name the
   registry doesn't know, whether it matches the generated naming pattern
   (`Container_[0-9a-f]{10}`, i.e. a generated container from a *foreign* build) or is a
   hand-written subclass. The registry says nothing about its service universe: all rules bail
   (reporting registry facts against a foreign container would be a false positive), all
   registry-based type extensions defer to native types, and the `hasService()` specifier plants
   no narrowing. Both flavours behave identically; the naming pattern only matters for describing
   the situation.

A **mixed union** containing any unknown member bails entirely — answering from partial knowledge
would misreport the unknown member's side. Guard-narrowing accessories don't disturb
classification: a marker or `hasMethod(...)` intersection on a base-class receiver still resolves
to all profiles, and one on a known-class receiver stays with that class's profile.

## Rules

All three rules only activate when `$registry->isActive()` (i.e. `orisaiNette.dic.containerLoader` is
configured), only match `MethodCall`s on a receiver typed `Nette\DI\Container` (or a subtype),
resolve the receiver's profile subset per *Receiver classes* above (bailing on unknown
containers), and skip first-class callables (`$container->getService(...)`) — the callable escapes
and its argument can't be inspected.

### ServiceNameCallRule

Covers `getService`, `getByName`, `createService`, `getServiceType`, `isCreated`, `hasService`
(case-insensitive method match).

The `serviceNotFound` / `serviceNotInAllContainers` identifiers below cover the five throwing
methods (`getService`, `getByName`, `createService`, `getServiceType`, `isCreated`); `hasService`
gets its own two identifiers, described after the table.

| Identifier | When | Example message |
|---|---|---|
| `orisaiNette.dic.dynamicServiceName` | first argument has no constant-string type | `Dynamic service name in Nette\DI\Container::getService() cannot be analysed. Provide a literal service name.` |
| `orisaiNette.dic.serviceNotFound` | literal name missing from every profile | `Service 'foo' is not registered in any analysed container (alpha, beta).` |
| `orisaiNette.dic.serviceNotInAllContainers` | literal name missing from some but not all profiles | `Service 'foo' is not registered in container(s): beta.` |
| `orisaiNette.dic.hasServiceAlwaysFalse` | (`hasService` only) literal name missing from every profile | `Service 'foo' is not registered in any analysed container (alpha, beta); hasService() always returns false.` |
| `orisaiNette.dic.hasServiceAlwaysTrue` | (`hasService` only) literal name present in every profile | `Service 'foo' is registered in every analysed container (alpha, beta); hasService() always returns true.` |
| `orisaiNette.dic.serviceMissingInBranch` | (five throwing methods) call inside the negative branch of a `hasService()` guard on the same resolved service | `Service 'foo' is excluded by the hasService() guard in this branch; the call always throws here.` |

#### `hasService()` reporting and guard narrowing

`hasService()` never reports `serviceNotFound` / `serviceNotInAllContainers`. Instead it reports a
constant result only when the outcome is the same in every profile: `hasServiceAlwaysFalse` when the
name resolves nowhere (a dead branch) and `hasServiceAlwaysTrue` when it resolves everywhere (a
redundant guard). A *partially* present name (some profiles have it, some don't) is intentionally
**not** reported — the call is exactly the boolean existence probe `hasService()` is for.

The return type of `hasService()` is folded **asymmetrically** by `HasServiceReturnTypeExtension`:
a literal name that exists (single-hop, matching the runtime lookup) in *every* profile of the
receiver's resolution types as constant `true`; everything else — partial existence, a name present
nowhere, dynamic names, unknown containers — stays plain `bool`. The asymmetry is a monotonicity
argument over the runtime API: `hasService()` checks the compiled `createService*` method set plus
registered instances (`Container::hasService`), `addService()` only *adds* (a closure lands in
`$methods`, an object in `$instances`), and `removeService()` unsets `$instances` only — the
compiled method set never shrinks, so all-profiles presence can never become `false` at runtime.
Absence is *not* monotone: `addService()` can make a nowhere-name available (on base-typed and
concrete generated containers alike), so `false` is never folded, for any receiver category.

Two consequences to be aware of: conditions probing always-present names may now get PHPStan's
*native* always-true condition reports in addition to this feature's `hasServiceAlwaysTrue` — that
is intended redundancy (the rule report was explicitly requested and names the analysed
containers), not duplication to suppress. And the fold does not disturb the guard narrowing below —
the type specifier runs on the call expression regardless of its constant-typed result.

A `hasService()` *guard* narrows the container variable inside the guarded scope, so the guarded
service calls become analysis-clean. `HasServiceTypeSpecifyingExtension`
(`phpstan.typeSpecifier.methodTypeSpecifyingExtension`) mirrors PHPStan's own `method_exists()`
narrowing: for a constant name it resolves the single-hop alias (matching the runtime `hasService()`
lookup), computes `Container::getMethodName()`, and intersects the receiver with
`hasMethod(createService<Name>)` in the truthy branch. The narrowing is alias-aware and keys on the
resolved *method name*, so a guard on an alias covers calls on the underlying service (guarding
`hasService('fooRealAlias')` clears `getService('foo')`). Because the join key is the compiled
`createService…()` method, it flows through every construct that carries a truthy scope:

```php
if ($c->hasService('optional')) { $c->getService('optional'); }        // clean
if (!$c->hasService('optional')) { return; } $c->getService('optional'); // clean
$c->hasService('optional') ? $c->getService('optional') : null;          // clean
$c->hasService('optional') && doSomething($c->getService('optional'));   // clean
```

`ServiceNameCallRule` consumes the narrowing by checking `$receiverType->hasMethod($method)->yes()`
before reporting any name: a plain `Nette\DI\Container` receiver answers `no()` for a
`createService…()` method (the base class has no per-service methods), while a receiver narrowed by a
guard answers `yes()`, suppressing the report. When multiple constant names reach the guard, the
extension only narrows if they all resolve to the same method name.

#### Negative-branch reporting (`serviceMissingInBranch`)

The *negative* branch of a guard cannot be narrowed by subtraction: `hasMethod(...)` is a
non-removeable accessory type and the base `Container` class never had the per-service method, so
PHPStan's falsy-branch removal is a no-op and the type would collapse back to plain
`Nette\DI\Container`, indistinguishable from unguarded code. Instead, the extension *positively*
specifies the negative branch with a marker type: `ContainerMissingServicesType extends ObjectType`
(`Nette\DI\Container` plus a sorted list of excluded `createService…()` method names, rendered as
`Nette\DI\Container~service:createServiceFoo` by `describe()`). The falsey-context invocation
returns `$typeSpecifier->create($receiver, $marker, TypeSpecifierContext::createTruthy(), $scope)`
— a *sure* (positive) specification applied while building the falsy scope.

The marker survives scope flow because its `isSuperTypeOf()` answers `maybe` (not `yes`) for plain
`Container`, so `TypeCombinator::intersect(Container, marker)` eliminates the plain type and keeps
the marker; between two markers it orders by excluded-set inclusion, so nested negative guards merge
into one marker carrying both names. In a union (e.g. after the `if`/`else` join, or the negation of
`&&` where either operand may have failed) the marker is only a union member — never a guarantee —
and the rule ignores it there.

`ServiceNameCallRule` detects the guard with a semantic probe: a call on `getService`/`getByName`/
`createService`/`isCreated`/`getServiceType` (never `hasService` itself) reports
`serviceMissingInBranch` when `(new ContainerMissingServicesType([$resolvedMethod]))
->isSuperTypeOf($receiverType)->yes()` — true for a marker receiver listing the method and for any
intersection containing one, never for plain `Container`, its subclasses, or unions. The name is
resolved with the *calling* method's own hop semantics, so the report only fires when the guard's
single-hop resolution and the call's resolution land on the same compiled method — a
`hasService()`-false guard on a multi-hop alias correctly does *not* condemn a recursive-resolving
`getService()` on the same name.

The composition extends to `elseif` chains automatically (an `elseif` is a nested `if`/`else` to
the TypeSpecifier): each `elseif` branch carries the accumulated markers of every earlier failed
condition plus its own guard's `hasMethod(...)`, and the final `else` carries one merged marker
listing every name from the chain. A contradictory re-check
(`if ($c->hasService('x')) { … } elseif ($c->hasService('x')) { … }`) produces a scope carrying
*both* the marker and `hasMethod(...)` for the same method — the rule checks truthy suppression
before the marker probe, so calls in such dead branches stay deliberately *silent* rather than
reporting about unreachable code.

Safety property: the only origin of the marker is the falsey specification of a `hasService()`
guard, and the rule fires only when the marker is provably present with the method name in its
list. Complex flows (unions, `??`, loops, reassignment) may silently *normalize the marker away* —
that direction only loses negative-branch reports; it can never fabricate one on unguarded code.

### TypeLookupCallRule

Covers `getByType`, `findByType`, `createInstance`.

| Identifier | When | Example message |
|---|---|---|
| `orisaiNette.dic.dynamicType` | first argument has no constant-string type | `Dynamic type in Nette\DI\Container::getByType() cannot be analysed. Provide a literal ::class type.` |
| `orisaiNette.dic.typeAmbiguous` | (`getByType` only) more than one autowired candidate in a profile | `Type Foo\Bar is ambiguous in container(s): alpha (FooImpl, BarImpl); getByType() throws.` |
| `orisaiNette.dic.typeNotFound` | type unknown in every profile | `getByType`: `Type Foo\Bar is not registered in any analysed container (alpha, beta).` — `findByType`: `Type Foo\Bar is never resolvable; findByType() always returns an empty array.` |
| `orisaiNette.dic.typeNotAutowired` | (`getByType` only) type registered but `autowired: false` in every profile that has it | `Type Foo\Bar is registered but not autowired in container(s): alpha; getByType() throws.` |
| `orisaiNette.dic.typeNotInAllContainers` | mixed failure across profiles (some unknown, some not autowired) or (`findByType`) unknown in some but not all | `getByType`: `Type Foo\Bar is not autowirable in container(s): alpha; getByType() throws there.` — `findByType`: `Type Foo\Bar is not registered in container(s): alpha.` |

Any `getByType($type, ...)` second argument that isn't *definitely* `true` — a literal `false`,
a `bool`-typed variable, anything short of the constant `true` — suppresses the not-found/not-
autowired checks, mirroring the runtime `$throw` parameter — but **not** the ambiguity check,
since `getByType()` throws on ambiguity regardless of `$throw`. `createInstance()` only gets the
dynamic-type check: unlike the other two methods it doesn't consult the container's wiring at
all — it autowires the constructor of *any* instantiable class, registered or not — so registration
status is irrelevant to its misuse.

### TagCallRule

Covers `findByTag`.

| Identifier | When | Example message |
|---|---|---|
| `orisaiNette.dic.dynamicTag` | first argument has no constant-string type | `Dynamic tag in Nette\DI\Container::findByTag() cannot be analysed. Provide a literal tag name.` |
| `orisaiNette.dic.tagNotFound` | literal tag present in no analysed container | `Tag 'foo' is not present in any analysed container (alpha, beta).` |
| `orisaiNette.dic.tagNotInAllContainers` | literal tag present in some but not all containers | `Tag 'foo' is not present in container(s): beta.` |

That's 14 identifiers in total.

## Type inference

| Method | Return type |
|---|---|
| `getService`/`getByName`/`createService` | union of the service's `ObjectType` across the profiles that have it (imported services are typed from the container's `$types`, same as the reflected-method fallback) |
| `getByType($literal::class)` | the literal type; `\|null` added unless the second argument is a definite `true` — or unless the type has exactly one autowired candidate in *every* profile of the receiver's resolution, in which case the lookup can never miss and `\|null` is dropped even with `$throw = false` (`$wiring` is compiled metadata no runtime Container API mutates — `addService()` touches `$methods`/`$instances`/`$types` only; partial, absent, or ambiguous types keep `\|null`, and ambiguity still gets its rule report; with no loader configured the extension keeps the plain behavior) |
| `getByType($dynamic)` | `object`, narrowed via the argument's class-string object type — a `class-string<T>` parameter types the call as `T` |
| `createInstance($literal::class)` | the literal type, never nullable (it either returns an instance or throws) |
| `findByType` | a constant list of service names when every profile agrees on the set, else `list<string>` |
| `findByTag` | `array<string, T>` where `T` is the widened union of that tag's attribute values across profiles; `array{}` when the literal tag exists in no analysed container; `array<string, mixed>` for a dynamic tag argument |
| `getServiceType` | union of `ConstantStringType` per profile that has the service; falls back to the native `string` return type if the name is dynamic or unknown everywhere (existence misuse for `getServiceType` is reported by `ServiceNameCallRule`, not a separate identifier) |
| `getParameters()` / `->parameters` | the merged parameter shape (see widening policy below) |

## Widening policy

Parameter and tag-attribute values are read once from the live containers, so an unwidened type
would leak concrete literals — including secrets — into rule messages, `assertType` fixtures, and
a committed PHPStan baseline. `ParameterTypeWidener` widens every scalar leaf to its plain type
(`string`, `int`, `float`, `bool`, `null`) — never a `ConstantStringType`/`ConstantIntegerType`/…
— so no value ever appears verbatim in analysis output.

Shape (keys), by contrast, is kept precise, because keys are configuration structure, not secret
values:

- An empty array (in every profile, or as a single profile's value) widens to plain `array`
  (`ArrayType<mixed, mixed>`) — the analysis never assumes an array stays empty.
- A list value (sequential integer keys) widens to `list<T>` with `T` the union of its widened
  members.
- A non-list array widens to a constant shape (`array{key: T, ...}`) with each value recursively
  widened.
- Merging the *same* parameter across multiple profiles keeps working with constant shapes: a key
  present in every profile stays required, a key present in only some profiles becomes optional
  (`key?: T`) rather than collapsing to `list<T>` or a bare `array`.

## Dead-code coverage

`DicUsageProvider` (tagged `shipmonk.deadCode.memberUsageProvider`) parses each profile's compiled
container file with `nikic/php-parser` (`ContainerUsageExtractor` + `ContainerUsageVisitor`) and
marks as used:

- constructors of every `new`-ed class, including classes declared inline (the anonymous
  factory/accessor classes Nette compiles for `implement`-based factories and accessors);
- `$service->method(...)` calls, tracked through simple local `$var = new Foo(...)` assignment
  (used for the container's own setup calls, e.g. `(new FooService)->setBar($x)`);
- static calls (`Foo::method(...)`), skipping special class names (`self`/`static`/`parent`).

This replaces a blanket `#^Unused ...__construct$#` ignore that would otherwise suppress *every*
unused-constructor finding project-wide. Consumers include it through `config/dic-dead-code.neon`,
which is kept out of `extension.neon` so that shipmonk/dead-code-detector stays optional.

`ContainerUsageVisitor` tracks local variable-to-class bindings with a scope stack, not one flat
map: entering a `ClassMethod` (including an inline anonymous class's methods) pushes a fresh empty
scope so a nested method body can't wipe or leak into the enclosing one; entering a `Closure`
pushes a scope copied from only the variables named in its `use()` clause; entering an
`ArrowFunction` pushes a full copy of the enclosing scope (its implicit capture semantics); in
both cases the closure's own parameter names are then unset from the copy so a same-named
parameter shadows rather than inherits the outer binding. Each is popped, restoring the exact
prior scope, on leaving the node.

## Known limitations

- **Container-derived state outside the compiled container files is invisible to the result
  cache.** `ContainerResultCacheMetaExtension` hashes the compiled container `.php` files only
  (see *Cache freshness*); anything else derived from the containers (e.g. a Symfony console
  application loader) keeps its own invalidation caveats.
- **Runtime `addService()`/`removeService()` calls are not tracked.** The registry is a static
  snapshot of what the compiled containers declare; services added or removed dynamically at
  runtime aren't reflected.
- **Chained aliases (`alias → alias → service`) are modeled per-method with runtime-exact
  semantics.** `Container::getService()`/`getByName()`/`getServiceType()` resolve `$aliases`
  recursively at runtime; `hasService()`/`isCreated()`/`createService()` resolve a single hop and
  throw `MissingServiceException` (`isCreated()`/`createService()`) or return `false`
  (`hasService()`) on a chain deeper than one hop. `ContainerMetadata` exposes both: the existing
  `hasService()`/`getServiceTypeName()` stay single-hop, and `hasServiceRecursive()`/
  `getServiceTypeNameRecursive()` walk the chain with a cycle guard — a cyclic alias chain (which
  would recurse forever at runtime) is treated as unregistered rather than looping.
  `MultiContainerRegistry::getServiceExistence()`/`getServiceType()`/`getServiceTypeNames()` take a
  `$recursiveAliases` flag, and `ServiceNameCallRule` / the type extensions pick the flag that
  matches each method's runtime behavior.
- **Generated locators and `OriNette\DI\Services\ServiceManager`-style parametric `get($name)` /
  `create($name)` accessors are not modeled.** Only direct calls on `Nette\DI\Container` (or a
  subtype) are analysed.
- **`hasService()` partial-existence is intentionally unreported.** A name missing in only some
  profiles is a legitimate boolean check there and isn't flagged (see `ServiceNameCallRule`
  above).
- **Negative-branch (`serviceMissingInBranch`) reports are best-effort.** The marker type that
  carries the guard through the negative branch (see *Negative-branch reporting* above) may be
  silently normalized away by complex flows — unions, `??`, loops, reassignment through merge
  points. When that happens the report is simply not produced; the safe failure direction is
  losing a report, never fabricating one on unguarded code.
- **Dead-code usage resolves through the class hierarchy.** `ContainerUsageExtractor` records
  `new`/setup-call usages under the `new`-target (concrete) class, but `shipmonk`'s
  `ReflectionBasedMemberUsageProvider` only invokes the hook once per method, keyed by the method's
  actual *declaring* class. `DicUsageProvider::shouldMarkMethodAsUsed()` bridges the two: if the
  declaring class has no direct usage entry, it walks the recorded usages for any class that both
  has the method used and `is_a()`-satisfies the declaring class (a "constructed subclass declares
  no override" case — an inherited constructor or setup method, e.g. `new ChildService(...)` where
  `ChildService extends ParentService` and doesn't redeclare `__construct` or the setup
  method). Results are memoized per (declaring class, method name). A usage map key that fails to
  autoload is skipped for that walk rather than passed to `is_a()`.
- **Dynamic `Nette\DI\Definitions\Statement`-generated code inside a container body isn't
  statically resolvable.** `ContainerUsageVisitor` only recognises literal `new`/method-call/
  static-call syntax; any construction or call assembled at compile time through indirection it
  doesn't parse (e.g. variable variables, `call_user_func`) leaves no trace to mark as used.
- **The library analyses its own tests without a container loader.** The fixtures under
  `tests/Unit/Dic/**/Fixtures` are in `excludePaths` of `tools/phpstan.neon`: they reference
  services and types that exist only in the fixture containers, so they are exercised through
  `RuleTestCase`/`TypeInferenceTestCase` with the fixture loader, never through the library's own
  analysis.
- **Analysis reflects the machine's config.** A loader that reads machine-local NEON files the way
  the application does makes parameter shapes and rule findings differ between machines or CI when
  those files differ.

## Testing

Unit tests live under `tests/Unit/Dic/`, the runtime parity and invalidation tests under
`tests/Integration/Dic/`. Fixtures under `tests/Unit/Dic/Fixtures/App/` use their own namespace:

- **Fixture containers** — two profiles, `alpha` and `beta`, compiled at test time from fixture
  NEON configs into `var/tools/PHPUnit.DicFixtures/`, exercising dotted/numeric-ish service names,
  aliases, tags, imported services, factory/accessor definitions, non-autowired services,
  ambiguous types, and profile-only services. The `ContainerLoader` key is fixed per profile
  (`['dic-fixture', $profile]`), so the generated class names are stable
  (`FixtureContainerFactory::AlphaClassName` / `BetaClassName`, drift fails loudly in both the
  factory and the parity test) and fixtures can type receivers with them for receiver-class tests;
  `autoRebuild` still recompiles the same class on NEON changes via the `.meta` mtime check.
- **`ContainerRuntimeParityTest`** — plain PHPUnit assertions against the *live* fixture
  containers, pinning every analysis assumption (method-name mapping, existence matrix, service
  types, `getByType`/`findByType`/`findByTag` behavior, alias resolution, parameter merge order,
  and that the loader never calls `initialize()`) to actual Nette DI runtime behavior. It lives in
  `tests/Integration/Dic/`.
- **Per-component unit tests** for the `Metadata/`, `DeadCode/` and `Cache/` classes.
- **`DicTypeInferenceTest`** — `assertType()` fixtures per return-type extension.
- **Three `RuleTestCase` suites**, one per rule, asserting exact identifier + message + line.
