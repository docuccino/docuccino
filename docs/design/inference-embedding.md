# Design: PHPStan/Larastan Embedding (docuccino/inference-phpstan)

Status: approved (2026-08-01); load-bearing assumptions PROVEN in the Phase 0 spike —
the PHPStan phar + Larastan were embedded programmatically against a provisioned
Laravel 12 / Larastan fixture app (phpstan 2.2.7, larastan 3.10.0, php-parser 5.8.0,
PHP 8.5.9) and answered "for a given controller method, the type of every return path"
on every pass criterion. The one adapter method needing per-minor attention is the
parser wiring, not any analysed signature (the internal-API signatures matched this
design unchanged at 2.2.7). Where the spike found deviations, the spike wins; its
findings are inlined below.

## 1. Verified ground truth

- The phpstan/phpstan phar exposes **unprefixed `PHPStan\*` classes** via its
  `PharAutoloader` (`vendor/phpstan/phpstan/bootstrap.php`) — programmatic use of
  `ContainerFactory`, `NodeScopeResolver`, `ScopeFactory`, `PHPStan\Type\*` is the same
  mechanism Rector and every extension test harness use. Only PHPStan's third-party deps
  are PHP-Scoper-prefixed. `PhpParser\*` and `PHPStan\PhpDocParser\*` resolve from the
  HOST's composer packages → we require `nikic/php-parser ^5`; `phpstan/phpdoc-parser ^2` arrives
  through `docuccino/core`, which owns the phpdoc grammar.
- A scoped private PHPStan copy is IMPOSSIBLE while reusing Larastan (identical FQCNs
  can't coexist; Larastan binds unprefixed `PHPStan\*`). → **Share the host's PHPStan.**
  Larastan 3.x requires `phpstan/phpstan ^2.2`, so one version satisfies everyone.
  Composer constraint = tested-minor allowlist (`~2.2.0 || ~2.3.0`), widened per CI matrix,
  never open-ended.
- Larastan's bootstrap boots the Laravel app (from `getcwd()/bootstrap/app.php`) — doc
  generation runs in app context (same constraint Scramble has). Package is a normal
  `require-dev` of the end-user app: since 2026-08-09 `docuccino/inference-phpstan` hard-requires
  `larastan/larastan` (it cannot analyse a Laravel app without it) and `docuccino/laravel` only
  SUGGESTS the engine, so a production install carries neither.

## 2. Embedding mechanics (+ Spike A traps — MUST honor)

```php
$factory = new ContainerFactory($projectRoot);           // cwd MUST be the Laravel app root
$container = $factory->create($cacheDir, [$generatedNeon], $entryFiles);
```

Generated neon includes: host's `larastan/extension.neon` (absolute path), our bundled
extensions, optional user `docuccino.neon`; sets `phpVersion`, `resultCachePath`, empty paths.

Per file: parse with `defaultAnalysisParser`, then
`NodeScopeResolver::processNodes($nodes, ScopeFactory::create(ScopeContext::create($file)), $callback)`.
Harvest the virtual node `MethodReturnStatementsNode`: `getReturnStatements()` (each return
paired with flow-refined scope — `$scope->getType($expr)` per return path) and
`getStatementResult()->getThrowPoints()` (escaping exceptions, caught subtracted, `@throws`
consulted).

**Spike A traps (regression-test each):**
1. `bootstrapFiles` are NOT auto-run by a raw ContainerFactory embed — read
   `getParameter('bootstrapFiles')` and `require_once` each, or Larastan fails with
   `Undefined constant LARAVEL_VERSION`.
2. **Body-stripping parser**: `PathRoutingParser` rich-parses only files in its analysed
   set; others go through `CleaningParser` which DELETES method bodies →
   `MethodReturnStatementsNode` silently reports zero returns. Call `setAnalysedFiles()`
   on BOTH the `NodeScopeResolver` and the `pathRoutingParser` service — for EVERY file
   analysed, including descent targets.
3. Normalise all paths through the container's `FileHelper`.
4. Dynamic-return-type extensions must target contracts (e.g.
   `Illuminate\Contracts\Routing\ResponseFactory`), not concrete classes, or they never fire.

All internal-API touches live in ONE adapter class per supported PHPStan minor
(`Runtime/V2_2/RuntimeAdapter`, ~6 methods + parser-priming helper). Extension APIs
(`Scope`, `Type`, dynamic return types) ARE covered by PHPStan's BC promise.
Spike A perf reference: ~0.4s wall / ~92 MB for container + one controller; deterministic.

## 3. Scoped analysis / parallelism / containment

- Entry set = route-referenced action files only; everything else lazy via
  `ReflectionProvider` (autoloader-backed). On-demand descent into callee bodies,
  memoized per file, bounded (depth default 4, per-action file budget 40).
- Parent orchestrator + K workers (Symfony Process, NDJSON of already-translated results);
  recycle workers after N routes (50) or RSS watermark (1 GiB). Parent sorts results by
  canonical action id — scheduling never affects bytes.
- Per-action try/catch → `UnknownT(reason)` + warning diagnostic. Worker fatal → re-queue
  batch with size 1 (bisection isolates the poison action). Engine boot failure → fatal
  diagnostic + `NullTypeEngine` fallback (docblock/attribute-only docs still build).
- **Two scopes, not one.** The bounds above (depth 4, file budget 40) are shared by every
  descending analysis, but the *scope* is not. Throw classification and the Query-Builder trace
  descend only into `engine.project_paths`; the response-shape refiner and its enum folder run on
  the wider PRIME scope — every primed app PSR-4 root, a modular `Modules\…` root included (§4a
  step 4). Vendor code is in neither, so it is never followed.

## 4. Boundary (contract in docuccino/core; zero PHPStan imports)

```php
interface TypeEngine {
    public function analyzeAction(ActionRef $a): ActionAnalysis;
    public function classMetadata(ClassRef $c): ClassMetadata;
    public function trace(ActionRef $a, TraceVisitor $v): void;
}
final readonly class ActionRef { public string $file; public ?string $class; public string $method; public int $line; }
final readonly class ActionAnalysis {
    /** @var list<ReturnSite> */    public array $returns;
    /** @var list<ThrownException> */ public array $throws;
    /** @var list<Diagnostic> */    public array $diagnostics;
    /** @var list<string> */        public array $dependencyFiles;   // depfile → fragment cache key
}
interface TraceVisitor { public function enterNode(PhpParser\Node $n, TypeScope $s): bool; } // true = descend
interface TypeScope {
    public function typeOf(Node\Expr $e): DType;
    public function constantValueOf(Node\Expr $e): ?ConstValue;  // literals + call descriptors (AllowedFilter::exact('status'))
    public function location(Node $n): SourceLocation;
}
```

`PhpParser\Node` crosses the boundary (stable shared lib); `PHPStan\Type\*`/`Scope` never do.

**Public surface (`@internal` convention).** The only classes in
`php/inference-phpstan/src` that are part of this package's supported API are the ones a
consumer legitimately imports to configure and build the engine: `Analysis\PhpStanTypeEngineBuilder`
(the entry point an adapter probes for by string — core's `Inference\TypeEngineBuilder` seam),
`Analysis\EngineConfig`, `Analysis\PhpStanEngineFactory` and `Runtime\RuntimeConfig`. The phpdoc
grammar readers that used to sit here (`DocBlockReader`, `TypeStringParser`, `ImportContext` and the
shared parser stack) now live in `Docuccino\Core\TypeGrammar` — see the type-grammar entry in
`uir-and-extensions.md`. Everything else — the Analysis engine implementations
behind the factory, the whole `Orchestration`/`Runtime` (bar `RuntimeConfig`) worker machinery, and
the `Trace`/`Throwing`/`Translation`/`Support`/`Cache`/`Metadata`-factory/PHPStan-extension internals
— carries an `@internal` marker: it is an engine implementation detail, free to change between
releases, and no adapter extension imports it (the Laravel adapter reaches only the six public
classes above; the engine's own test harness may of course use the internals). A type reachable only
as a construction detail of a public class (e.g. an `OrchestrationConfig` folded inside `EngineConfig`)
is `@internal` too — reachability is not the public contract.

**Trace contract refinements (verified in the Phase 0 spike, all-PASS — the
2-deep-helper-chain constant recovery + custom-terminal pagination case):**
- Responsibility split: the visitor is pure semantics + harvesting (zero PHPStan imports);
  the ENGINE owns bounded depth, per-`class::method` memoization, cycle guard, callee
  resolution, per-file parser priming, deterministic ordering. `enterNode` returning `true`
  is a *request the engine may decline* (vendor/magic/over-budget).
- `ConstValue` is a closed set that MUST include a **call-descriptor variant**
  `{factory, args: ConstValue[]}` — factory statics (`AllowedFilter::exact('status')`)
  must be folded at the AST level BEFORE PHPStan collapses them to a plain object type.
- Terminal detection has two separable outputs: "reaches a paginating terminal" =
  name-match on a builder-typed receiver (works at any depth); the per-page value folds
  from the OUTERMOST terminal call's argument (lives at the call site).
- Traps: the parser service is `PHPStan\Parser\Parser` (CachedParser), not
  `PhpParser\Parser`; re-prime `setAnalysedFiles` on both parser+resolver before EVERY
  descended file; collect descent targets and recurse AFTER the walk returns (never nest
  `processNodes`); sort descent by `Node::getStartFilePos()` (callback order ≠ source
  order); `ReflectionMethod` throwing on forwarded/magic terminals (`__call`) is itself
  the correct "vendor terminal, don't descend" signal; Larastan resolves `static<T>`
  factories (`ListQueryBuilder::for()`) with no custom stub.

## 4a. Response-shape refinement: the folding arc (the inferred-error-examples chain)

The engine recovers a response shape that the DECLARED return type erased. A renderer builds its
response through a helper — `__invoke` → `renderNotFound()` → `ProblemResponse::make(…): JsonResponse` —
and the bare `JsonResponse` hint erases the payload/status generic at every call site, so PHPStan hands
the harvest a shapeless class. `ResponseShapeRefiner` follows the indirection and substitutes a richer
`JsonResponse<payload, status, contentType>`. Four composed mechanisms, in the order they run:

1. **Helper-indirection descent.** At each harvested return site whose translated type is a bare
   response class: fold `new JsonResponse($body, $status, [headers])` arguments directly; read an
   already-resolved generic (`response()->json([...], 422)`) straight off the type; or resolve the
   callee and analyse ITS return sites, first documentable return winning. A `return null`/void arm is
   FRAMEWORK DELEGATION — neither a response nor a fold failure.
2. **Value-flow / status provenance.** A callee's recovered shape is CALL-INDEPENDENT: a status that is
   not a literal is recorded as the `ParamAccessor` it reads from (the parameter itself, `->value`,
   `->name`, or a no-arg `->method()`), and each body member's provenance is recorded the same way. The
   call site then binds them: a constant-foldable argument pins the member to a literal (a per-arm
   `type` URI becomes a `const`), a caller parameter RE-HOMES the accessor one hop out, and anything
   else drops the provenance and leaves the member widened. A member reading the SAME accessor that
   drives the HTTP status becomes a `StatusMarkerT` (§5) for the response seam to fill.
3. **Enum-case accessor folding** (the final hop). When the call site binds a concrete enum case,
   `EnumAccessorFolder` resolves the accessors the callee applied to it: `->value`/`->name` from the
   case by reflection — VENDOR-SAFE, no body analysed; a no-arg `->method()` only for a PROJECT enum, by
   analysing one method body (a `match ($this)` arm naming the case, or a plain constant return). A
   computed body, a vendor enum's method, or an un-pinnable argument folds NOTHING — honest-permissive,
   never guessed. A folded `->status()` supplies the response status as a literal, which the response
   builder prefers over the exception's throw-status hint.
4. **Bounds, memoisation, containment.** Depth and the per-analysis file budget are the §3 bounds
   reused verbatim (default 4 / 40). Memoisation is per callee `class::method` (and per
   `(enum-case, method)` for folds) and is sound because the memoised shape is call-independent — with
   one rule: a computation whose descent hit a depth/budget CUTOFF is returned for the current analysis
   but NOT memoised, since its richness would otherwise depend on how much budget was already spent
   before that callee was first reached (worker-/route-order dependent → nondeterminism). Containment is
   the PRIME scope, not the descend scope: helpers in ANY primed app source root fold (including a
   modular `Modules\…` root), while vendor — never a primed root — is never followed. Cache soundness:
   every descended helper file and every folded enum's file is reported into `dependencyFiles`,
   re-contributed on a memo hit, so editing any of them invalidates the route fragment.

Bounds are shared with §3 (bounds only — §3's scope note); the marker type is §5. "Folded status beats
the throw hint" is deliberate: the renderer's own status is what the client actually receives.

## 4b. The narrowing half: choosing a renderer's return site

§4a folds a shape once a return site is chosen. Choosing it is the other half, and it is where the
honesty rules live.

Each of a renderer's return sites is paired with the caught-variable class guard that makes it
reachable, and two source shapes need different machinery. An `if ($e instanceof X) return …;` chain
takes its guard from PHPStan's per-return flow narrowing. A
`return match (true) { $e instanceof X => …, default => … }` renderer collapses to a SINGLE return
whose scope leaves `$e` un-narrowed, so its arms are decomposed off the AST instead, reading each arm's
own `instanceof` conditions (walking `&&`/`||`, so a compound condition contributes every class named).
Selection is source-order-first-match either way — the runtime semantics of both shapes.

Two honesty rules ride on top:

- **Delegation honesty.** A broad `return null` early-out that does not branch on `$e` — the
  `if (! $request->expectsJson()) return null;` shape — must not shadow a later per-type response arm:
  the documented API path is the response, not the framework fall-through. A broad delegation site
  therefore loses to any response-producing site; only a genuine per-type null arm (an exact guard) or
  an all-delegating renderer resolves to delegation.
- **Narrowing honesty.** When the chosen site is a broad guard that shadowed a later EXACT `instanceof`
  match, or when two arms claim the type exactly, an `inference.ambiguous-narrowing` info diagnostic is
  raised rather than presenting the recovered shape as certain.

**Closure harvesting (verified PHPStan behaviour).** When a closure is harvested by line — the
`RateLimiter::for` limiters — the visitor must be driven INSIDE the `processNodes` pass, on the live
scope. An arrow function's scope is a lazy fiber scope that cannot type expressions once the pass has
ended, so nothing may be deferred until after the walk.
`ClosureReturnStatementsNode::getStatementResult()->isAlwaysTerminating()` distinguishes a conditional
(fall-through) closure body from an unconditional one; a limiter that does not always return is left
unrecovered rather than half-folded.

## 5. DType model + translator

Closed set: `ScalarT, LiteralT, ArrayShapeT(fields, isList), ListT/MapT, UnionT,
IntersectionT, ClassT(fqcn, typeArgs), EnumT(fqcn, cases), CallableT, NullT/VoidT/NeverT,
StatusMarkerT, UnknownT(reason — always carries why)`. Nullability = `UnionT[..., NullT]`.

`StatusMarkerT` is the sole non-language member: a resolution SIGNAL ("this body member echoes the
response's own HTTP status") synthesised by the response refinement (§4a) and resolved to a `LiteralT`
by the adapter's response builder. The translator NEVER produces it — PHPStan has no such type. It is a
DType rather than a transient side-channel because it must survive the CACHE and WORKER boundaries: it
rides inside the `ArrayShapeT` payload of a `JsonResponse<…>` `ClassT` cached in an `ActionAnalysis`,
while resolution happens later, in the adapter. Same rationale as `uir-and-extensions.md` §8.

Translator (`TypeTranslator::translate(PHPStan\Type\Type, TranslationBudget): DType`):
ConstantArrayType → ArrayShapeT (optional keys honored, isList from accessory);
Constant scalars → LiteralT; UnionType → flatten + canonical sort; IntersectionType →
strip accessory types, collapse single survivor; GenericObjectType/ObjectType →
ClassT/EnumT via ClassReflection (source location = provenance); TemplateType → its bound,
else UnknownT; MixedType/unknown/budget-exhausted (depth 12) → UnknownT(reason).
Translation is EAGER at query time (serializable across workers/cache); class expansion
is LAZY via `classMetadata()` (memoized per class per run).

### Eloquent column source (Wave A, 2026-08-05)

A real Eloquent model declares **no** PHP column properties — attributes are magic (`$attributes`) —
so `ClassMetadataFactory`'s public-property enumeration yielded zero columns for every realistically
authored model (AUDIT-eloquent Finding 0). The column universe is now a **union, most-authoritative
first**, split across the placement boundary:

1. **`@property` / `@property-read` class docblock tags** — the ide-helper convention. Read by
   `DocBlockReader::properties()` and typed through the shared `TypeStringParser` grammar, appended
   as `PropertyMetadata` in `ClassMetadataFactory` (a native public property of the same name wins).
   The reading lives **in the engine's** `ClassMetadataFactory` (it owns class metadata) over the
   grammar in `Core\TypeGrammar`: the tag is a general PHP/phpstan convention (Data/Resource classes
   documented this way benefit too), not Eloquent vocabulary. This is the primary, high-confidence
   source.
2. **Floor sources** — `$casts` keys (a cast key IS a column, typed by its cast via `CastSchema`),
   `$dates` entries (date-time), and `$fillable`-only names (permissive `{}` at lowered confidence).
   These are Eloquent vocabulary, so they live **in the adapter** (`ModelSchema` unions them over the
   engine metadata; `EloquentModelReflector` reflects them without booting the model). A cast/date
   floor column is treated as serialised (required); an untyped `$fillable`-only one stays optional.

When **no** source yields a column the empty-object behaviour is kept, but `ModelSchema` raises the
`eloquent.no-columns` info diagnostic telling the author to add `@property` tags — never silent.

**Source (2b) — Larastan schema knowledge — was investigated and deliberately skipped.**
`ClassMetadataFactory` is a native-reflection + docblock component that never enters PHPStan's
analysis scope. Larastan's `ModelPropertyExtension` / `SchemaAggregator` resolve a model property
lazily *during analysis of a `$model->column` member-access expression* and expose no
enumerate-all-columns entry point we can call without reaching into private analysis internals — which
the brief forbids. Revisit only if a first-class schema-introspection embedding is added later.

The `casts()` **method** form (Laravel 11+) is not yet folded into the floor casts (it needs method
analysis, unlike the reflected `$casts` property) — tracked as AUDIT-eloquent gap #2 (separate M
effort); the `$casts` property form is recovered today.

## 6. Exception flow (3 layers) — CORRECTED per the Phase 0 spike (8/8 fixture cases PASS)

1. PHPStan throw points (free). **Noise rule (corrected): drop `!isExplicit()` points**
   (always bare `Throwable`) — `canContainAnyThrowable` is NOT a discriminator (nearly all
   points, including real signal, flag it). Dropped/demoted points are counted + verbose-logged.
2. `KnownThrowers` registry (user-extensible), keyed on resolved callee symbol — **dual role**:
   (a) *enrich* explicit stubbed points with a status (`authorize` → 403, `validate` → 422),
   (b) *rescue* still-implicit forwarders by callee name (static `Model::findOrFail` surfaces
   only as implicit bare Throwable — unlike Builder `firstOrFail` — the registry restores
   ModelNotFoundException/404 as `likely`). `abort/abort_if/abort_unless` → HttpException
   with status via constantValueOf (arg 0 for abort, arg 1 for abort_if/unless).
   Route-model binding → 404 (pipeline supplies).
3. Bounded descent into project-code callees lacking `@throws` (**depth 3** — observed max
   real depth 2; the vendor-file gate, not depth, does the real containment), memoized,
   cycle-guarded; vendor never descended. **Trap: descent targets must be added to the
   analysed set on BOTH parser+resolver BEFORE first parse**, or CachedParser caches a
   body-stripped copy and descent silently reads zero throw points.

Result model: `ThrownException{exceptionFqcn, httpStatusHint: ?int, callChain: list<Frame>,
confidence: certain|declared|likely, disposition: signal|internal|dropped}` —
vendor-declared 500-class exceptions are demoted to `internal`, project-declared kept.
**Exception identity = (fqcn, httpStatusHint)**: two aborts (403/404) are two responses;
never dedupe by fqcn alone. Engine stops at "exceptions + status hints"; response bodies
are the pipeline's ExceptionToResponse job. Known limitation (accepted): an incomplete
`@throws` docblock suppresses descent, hiding deeper exceptions — docblock is trusted.

## 7. Bundled PHPStan extensions (BC-stable APIs)

- `ResponseJsonReturnTypeExtension` on `Illuminate\Contracts\Routing\ResponseFactory::json`
  + `JsonResponse<TPayload>` stub → payload ConstantArrayType survives to return harvest
  (proven in Spike A).
- `Data::from/collect` precision; `Resource::collection` → `AnonymousResourceCollection<T>`
  (threading the inner resource type through anonymous collections needs a dedicated
  extension — flagged by Spike A as not-free; Phase 4).
- User's own PHPStan extensions (via `docuccino.neon` include) improve their docs with
  zero Docuccino-specific API — headline feature.

## 8. Engine result cache & determinism

PHPStan's own result cache is CLI-rule-oriented — unusable; we point its temp dirs into
our cache dir and build our own: per-ActionRef serialized ActionAnalysis + per-ClassRef
ClassMetadata. Key = sha256(engine ver ‖ phpstan ver ‖ larastan ver ‖ generated neon hash ‖
composer.lock hash ‖ action file hash ‖ each descended-file hash). Canonical member
ordering everywhere; no absolute paths in payloads. CI invariants: 1-vs-8 workers
byte-diff; cold-vs-warm byte-diff.

## 9. Risks

| Risk | Mitigation |
|---|---|
| PHPStan internal churn | tested-minor allowlist; adapter-per-minor; CI matrix lowest+highest patch |
| Larastan can't boot user app (env issues) | fatal diagnostic + NullTypeEngine fallback; document a docuccino env |
| Memory growth | worker recycling (count + RSS watermark); scoped entry set; file budget |
| Dynamic chains defeat constant tracking | UnknownT(reason) + diagnostic at exact expression; attribute escape hatch |
| Throw-point noise | drop any-throwable; registry + @throws + bounded project-only descent |
| Determinism regressions | canonical serialization + the two CI diff tests |
| php-parser skew (host on 4.x) | hard require ^5 (composer surfaces at install) |
