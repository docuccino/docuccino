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
  HOST's composer packages → we require `nikic/php-parser ^5`, `phpstan/phpdoc-parser ^2`.
- A scoped private PHPStan copy is IMPOSSIBLE while reusing Larastan (identical FQCNs
  can't coexist; Larastan binds unprefixed `PHPStan\*`). → **Share the host's PHPStan.**
  Larastan 3.x requires `phpstan/phpstan ^2.2`, so one version satisfies everyone.
  Composer constraint = tested-minor allowlist (`~2.2.0 || ~2.3.0`), widened per CI matrix,
  never open-ended.
- Larastan's bootstrap boots the Laravel app (from `getcwd()/bootstrap/app.php`) — doc
  generation runs in app context (same constraint Scramble has). Package is a normal
  `require-dev` of the end-user app.

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
`packages/inference-phpstan/src` that are part of this package's supported API are the ones a
consumer legitimately imports to configure and build the engine: `Analysis\EngineConfig`,
`Analysis\PhpStanEngineFactory`, `Runtime\RuntimeConfig`, `Metadata\DocBlockReader`,
`Types\TypeStringParser`, and `Types\ImportContext` (the last two colocate with the type-string
grammar whose home the design defers — §5). Everything else — the Analysis engine implementations
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

## 5. DType model + translator

Closed set: `ScalarT, LiteralT, ArrayShapeT(fields, isList), ListT/MapT, UnionT,
IntersectionT, ClassT(fqcn, typeArgs), EnumT(fqcn, cases), CallableT, NullT/VoidT/NeverT,
UnknownT(reason — always carries why)`. Nullability = `UnionT[..., NullT]`.

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
   This lives **in the engine**: it owns the phpdoc grammar and the tag is a general PHP/phpstan
   convention (Data/Resource classes documented this way benefit too), not Eloquent vocabulary. This
   is the primary, high-confidence source.
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
