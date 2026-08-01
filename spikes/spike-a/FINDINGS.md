# Spike A — Findings

**Goal:** prove PHPStan (distributed phar) + Larastan can be embedded
**programmatically, in-process** to answer "for a given Laravel controller
method, what are the types of every return path?" — the Rector-style
`NodeScopeResolver` embedding from the plan.

**Verdict: PASS on every pass criterion.** The load-bearing assumption behind
`docuccino/inference-phpstan` holds. Everything after this is engineering.

---

## Exact versions (as installed on the fixture app, this host)

| Package | Version | Plan claimed | Delta |
|---|---|---|---|
| phpstan/phpstan | **2.2.7** | 2.2.5 | +2 patch — internal API identical to what we used |
| larastan/larastan | **v3.10.0** | 3.10 | same |
| nikic/php-parser | **v5.8.0** | 5.8 | same |
| phpstan/phpdoc-parser | **2.3.3** | 2 | same major |
| laravel/framework | **v12.64.0** | 12+ | — |
| spatie/laravel-data | **4.23.0** | — | for Spikes B/C |
| spatie/laravel-query-builder | **7.3.0** | — | for Spikes B/C |

Host: macOS arm64, **PHP 8.5.9**, Composer 2.9.5.
Laravel 12 `create-project` installed cleanly on PHP 8.5.9 — no installer
resistance, no `--ignore-platform-reqs`.

---

## Real output (committed verbatim in `sample-output.txt`)

```
== Docuccino Spike A ==
Analysing: .../fixture-app/app/Http/Controllers/SpikeController.php

── method listUsers() ──
  return #0 @L23: Illuminate\Database\Eloquent\Collection<int, App\Models\User>
  throw points: 1
    throw #0 @L23: Throwable (implicit, canContainAnyThrowable=yes)

── method jsonShape() ──
  return #0 @L34: Illuminate\Http\JsonResponse<array{id: 1, name: 'x', tags: array{'a', 'b'}}>
  throw points: 2
    throw #0 @L34: Throwable (implicit, canContainAnyThrowable=yes)
    throw #1 @L34: Throwable (implicit, canContainAnyThrowable=yes)

── method resourceCollection() ──
  return #0 @L47: Illuminate\Http\Resources\Json\AnonymousResourceCollection
  throw points: 2
    throw #0 @L47: Throwable (implicit, canContainAnyThrowable=yes)
    throw #1 @L47: Throwable (implicit, canContainAnyThrowable=yes)

── method unionAction() ──
  return #0 @L57: Illuminate\Http\JsonResponse<array{ok: true}>
  return #1 @L62: App\Http\Resources\UserResource
  throw points: 5
    throw #0 @L57: Throwable (implicit, canContainAnyThrowable=yes)
    throw #1 @L57: Throwable (implicit, canContainAnyThrowable=yes)
    throw #2 @L62: Throwable (implicit, canContainAnyThrowable=yes)
    throw #3 @L62: Illuminate\Database\Eloquent\ModelNotFoundException<TModel of Illuminate\Database\Eloquent\Model (class Illuminate\Database\Eloquent\Builder, parameter)> (explicit, canContainAnyThrowable=yes)
    throw #4 @L-1: Throwable (implicit, canContainAnyThrowable=yes)

── run stats ──
wall clock (container build + analyse): 0.40s
peak memory (real): 92.5 MB
```

### Stub sub-proof (control vs treatment)

`jsonShape()` return type **with** our extension+stub vs **without**
(`DOCUCCINO_NO_STUB=1`):

```
WITH:    Illuminate\Http\JsonResponse<array{id: 1, name: 'x', tags: array{'a', 'b'}}>
WITHOUT: Illuminate\Http\JsonResponse
```

Out of the box the payload shape is lost; a ~40-line
`DynamicMethodReturnTypeExtension` (BC-stable API) + a `@template TPayload` stub
recovers the exact `ConstantArrayType`. This is the mechanism the plan's
"bundled PHPStan extensions — `response()->json([...])` payload-shape
preservation" relies on. It works, cleanly, through the normal extension surface.

---

## Verdict per pass criterion

| # | Criterion | Result |
|---|---|---|
| a | `listUsers` → `Collection<int, App\Models\User>` | **PASS** — exactly `Illuminate\Database\Eloquent\Collection<int, App\Models\User>`; Larastan generics flow |
| b | `jsonShape` → constant array shape with literal keys | **PASS** — `array{id: 1, name: 'x', tags: array{'a', 'b'}}` (only with the stub extension; control proves it) |
| c | `resourceCollection` → `AnonymousResourceCollection` | **PASS** — `Illuminate\Http\Resources\Json\AnonymousResourceCollection` (bare here; see note below on carrying `UserResource`) |
| d | Union action → distinct type per return with its own line | **PASS** — `JsonResponse<array{ok: true}>` @L57 and `App\Http\Resources\UserResource` @L62, reported separately |
| e | Throw points list appears without crashing | **PASS** — populated; `firstOrFail()` even surfaces `ModelNotFoundException` as an **explicit** throw point at the exact line |

Determinism spot-check: two consecutive runs produce **byte-identical**
type/throw output (only wall-clock/peak-memory lines vary).

---

## Internal-API signatures actually used (v2.2.7)

All matched the plan's Rector-path description. Concrete signatures verified
against the phar source:

- `PHPStan\DependencyInjection\ContainerFactory::__construct(string $currentWorkingDirectory)`
- `ContainerFactory::create(string $tempDirectory, array $additionalConfigFiles, array $analysedPaths, array $composerAutoloaderProjectPaths = [], …): Container`
  - `create()` ends by calling `postInitializeContainer()`, which registers the
    `ReflectionProviderStaticAccessor` / `PhpVersionStaticAccessor` singletons —
    so the scope machinery is live the instant `create()` returns. No manual
    accessor wiring needed.
- `Container::getByType(string $class)`, `->getService(string $name)`, `->getParameter(string $name)`
- `PHPStan\Analyser\ScopeFactory::create(ScopeContext $context, ?callable $nodeCallback = null): MutatingScope`
- `PHPStan\Analyser\ScopeContext::create(string $file): self`
- `PHPStan\Analyser\NodeScopeResolver::processNodes(array $nodes, MutatingScope $scope, callable $nodeCallback): void`
  — **signature unchanged from the plan's claim.** Callback shape
  `function(PhpParser\Node $node, PHPStan\Analyser\Scope $scope): void`.
- `NodeScopeResolver::setAnalysedFiles(array $files): void`
- Virtual node `PHPStan\Node\MethodReturnStatementsNode`:
  `getMethodName(): string`, `getReturnStatements(): ReturnStatement[]`,
  `getStatementResult(): StatementResult`, `getExecutionEnds(): array`,
  `getStatements(): Stmt[]`.
- `PHPStan\Node\ReturnStatement::getReturnNode(): PhpParser\Node\Stmt\Return_`,
  `->getScope(): Scope` (the **flow-refined** scope for that return path).
- `Scope::getType(PhpParser\Node\Expr $e): Type` + `Type::describe(VerbosityLevel::precise())`.
- `StatementResult::getThrowPoints(): ThrowPoint[]`;
  `ThrowPoint::getType(): Type`, `->getNode()`, `->isExplicit(): bool`,
  `->canContainAnyThrowable(): bool`.

**No deviation from the plan's API claims** at 2.2.7. The one adapter method
that would need per-minor attention is not a signature change but the parser
wiring (below).

---

## What was painful / non-obvious (the real spike value)

1. **`bootstrapFiles` are NOT auto-run by a raw `ContainerFactory` embed.**
   The PHPStan CLI runs neon `bootstrapFiles` via `CommandHelper`; we bypass
   that. Larastan's `bootstrap.php` is what boots the Laravel app **and defines
   `LARAVEL_VERSION`**. Skipping it fails deep inside analysis with
   `Undefined constant "Larastan\Larastan\LARAVEL_VERSION"` (from
   `LarastanStubFilesExtension`). Fix: after `create()`, read
   `$container->getParameter('bootstrapFiles')` and `require_once` each.
   → **RuntimeAdapter must own this step explicitly.**

2. **The body-stripping parser trap (the biggest gotcha).**
   `defaultAnalysisParser` → `CachedParser` → **`PathRoutingParser`**. The
   router only gives a file the *rich* (body-preserving) parse when it is in the
   router's analysed set; **every other file is routed to `CleaningParser`,
   which deletes function/method bodies.** With `analysedPaths=[]` our
   controller was body-stripped → `MethodReturnStatementsNode` reported
   `statements=0, returns=0` for every method (types silently empty, no error).
   Fix: call `setAnalysedFiles([$normalisedPath])` on **both** the
   `NodeScopeResolver` *and* the `pathRoutingParser` service (fetch it via
   `$container->getService('pathRoutingParser')`), using a path normalised
   through `FileHelper::normalizePath()` (the router normalises before lookup).
   → **RuntimeAdapter must prime the parser router per analysed file; this is
   the single most important embedding detail and deserves its own adapter
   method + regression test.**

3. **cwd sensitivity.** Larastan's `bootstrap.php` boots the app from
   `getcwd() . '/bootstrap/app.php'`. The script must `chdir()` into the fixture
   app before building the container (or otherwise arrange `getcwd()` to be the
   Laravel app root). For Docuccino proper the app is *already* the host process,
   so this is moot in-adapter — but the spike (a separate script) needs it, and
   any out-of-process worker will need to set cwd to the app root.

4. **Path normalisation must match PHPStan's.** `setAnalysedFiles` stores keys
   verbatim; the router/resolver look them up *after* `FileHelper::normalizePath`.
   Passing an unnormalised path silently misses. Use the container's `FileHelper`.

5. **Not painful, pleasantly:** memory ~**92 MB** peak, wall **~0.4s** for
   container build + single-file analyse (warm filesystem). Container build
   dominates; per-file analysis is cheap — confirms the plan's "boot once, walk
   many files" worker model is the right shape. No memory blow-ups, no phar
   autoloader conflicts with the host's `nikic/php-parser` / `phpdoc-parser`
   (they resolve from the host as the plan predicted).

---

## Notes / observations for later phases

- **Throw points are richer than hoped.** `firstOrFail()` surfaces
  `ModelNotFoundException` as an *explicit* throw point at the precise line —
  free input for Spike C's `KnownThrowers` and the exception-flow layer. Note
  most points are `implicit` `Throwable` with `canContainAnyThrowable=yes`;
  those are exactly the noise the plan says to drop/log-at-verbose. The signal
  (`isExplicit() === true`) is trivially separable.
- **`describe(precise())` renders generic type args even without a stub** (e.g.
  the `JsonResponse<…>` shape shows because we return a `GenericObjectType`).
  The `@template TPayload` stub is still worth shipping so the type is
  *legitimate* (PHPStan won't warn about arg count) and so downstream extensions
  can read the type parameter by name.
- **Criterion (c) bonus (carrying `UserResource`) is not yet realised.**
  `UserResource::collection(...)` infers as a bare `AnonymousResourceCollection`
  (no `<UserResource>` arg) out of the box — Larastan doesn't thread the resource
  class through `collection()`. Recovering it is exactly the plan's bundled
  `Resource::collection → AnonymousResourceCollection<T>` extension (Phase 4
  work); the mechanism is the same one proven here for `json()`. Flagged, not a
  blocker for Spike A.
- **`response()` is typed as the contract** `Illuminate\Contracts\Routing\ResponseFactory`
  at the call site, so the dynamic-return extension must target the **contract**,
  not the concrete `Illuminate\Routing\ResponseFactory`. (Getting this wrong = the
  extension silently never fires.)

---

## Implications for the `RuntimeAdapter` design

The plan's "all internal-API touches live in one adapter class per supported
PHPStan minor (~6 methods)" is validated, with a refined method list:

1. `buildContainer(neonPaths, phpVersion, tmpDir, autoloadProjectPaths)` — wraps
   `ContainerFactory::create`.
2. `runBootstrapFiles(container)` — **new, mandatory**; the CLI-only step we must
   replicate (finding #1).
3. `primeAnalysedFiles(container, normalisedPaths)` — sets the analysed set on
   **both** `NodeScopeResolver` and `pathRoutingParser` (finding #2). This is the
   subtle, breakage-prone one; pin it with a test asserting method bodies survive.
4. `parseFile(container, path)` — via `defaultAnalysisParser`.
5. `createFileScope(container, path)` — `ScopeFactory` + `ScopeContext::create`.
6. `walk(nodeScopeResolver, nodes, scope, callback)` — `processNodes`; callback
   translates `MethodReturnStatementsNode` → our `ActionAnalysis`
   (ReturnSite[] from `ReturnStatement` scope+node, ThrownException[] from throw
   points, filtering `implicit`/`canContainAnyThrowable` noise).

Plus one helper: `normalisePath(container, path)` via `FileHelper` (finding #4).

Everything the adapter exposes upward (`Scope`, `Type`, `describe()`, dynamic
return-type extensions) is covered by PHPStan's BC promise — so the fragile
surface really is confined to items 1–6, and of those only #2/#3 are true
"internal plumbing" that could shift between minors. The tested-minor allowlist
(`~2.2.0 || ~2.3.0`, widened as CI goes green) is the right containment.

**Bottom line: Spike A validates the load-bearing assumption. Proceed.**
