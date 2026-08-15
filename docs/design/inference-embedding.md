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

## 3. Scoped analysis / containment

- Entry set = route-referenced action files only; everything else lazy via
  `ReflectionProvider` (autoloader-backed). On-demand descent into callee bodies,
  memoized per file, bounded (depth default 4, per-action file budget 40).
- Analysis runs in the calling process, one container per build. Order never affects bytes:
  every result is canonically serialized and the pipeline consumes routes in canonical order.
- Per-action try/catch → `UnknownT(reason)` + warning diagnostic. Engine boot failure → fatal
  diagnostic + `NullTypeEngine` fallback (docblock/attribute-only docs still build).
- **Two scopes, not one.** The bounds above (depth 4, file budget 40) are shared by every
  descending analysis, but the *scope* is not. Throw classification and the Query-Builder trace
  descend only into `engine.project_paths`; the response-shape refiner and its enum folder run on
  the wider PRIME scope — every primed app PSR-4 root, a modular `Modules\…` root included (§4a
  step 4). Vendor code is in neither, so it is never followed.

**Removed: the parent/worker pool.** A parent orchestrator plus K worker processes (Symfony
Process, NDJSON of already-translated results, recycling on route count and RSS watermark,
bisection on a poison action) was built and never wired into a build. It does not pay: each worker
cold-compiles its own PHPStan container (~500 ms) and keeps its own memo, so a callee reached on
two workers is analysed twice — on the fixture app, total analysis is 814 ms, less than one extra
container boot. Parallelism only wins somewhere north of a few hundred routes, and the fragment
cache plus the lazy engine already cover the incremental case that motivated it. Reach for git
history, not a rewrite, if route counts ever make it pay.

## 4. Boundary (contract in docuccino/core; zero PHPStan imports)

```php
interface TypeEngine {
    public function analyzeAction(ActionRef $a): ActionAnalysis;
    public function classMetadata(ClassRef $c): ClassMetadata;
    public function trace(ActionRef $a, TraceVisitor $v): TraceReport;
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
`uir-and-extensions.md`. Everything else — the Analysis engine implementations behind the factory,
`Runtime` (bar `RuntimeConfig`), and the
`Trace`/`Throwing`/`Translation`/`Support`/`Metadata`-factory/PHPStan-extension internals
— carries an `@internal` marker: it is an engine implementation detail, free to change between
releases, and no adapter extension imports it (the Laravel adapter reaches only the four public
classes above; the engine's own test harness may of course use the internals). A type reachable only
as a construction detail of a public class is `@internal` too — reachability is not the public
contract.

**Trace contract refinements (verified in the Phase 0 spike, all-PASS — the
2-deep-helper-chain constant recovery + custom-terminal pagination case):**
- Responsibility split: the visitor is pure semantics + harvesting (zero PHPStan imports);
  the ENGINE owns bounded depth, per-`class::method` memoization, cycle guard, callee
  resolution, per-file parser priming, deterministic ordering. `enterNode` returning `true`
  is a *request the engine may decline* (vendor/magic/over-budget).
- The engine gates CALLEES, never the ROOT: an action root may legitimately sit outside `project_paths`
  or under `vendor/` (`routes.include_vendor`), and `trace()` cannot tell an action root from one an
  extension seeded through `RouteContext::traceFrom()`. Choosing a sound arbitrary root is the CALLER's
  job — the adapter, which knows what it picked the root from.
- `ConstValue` is a closed set that MUST include a **call-descriptor variant**
  `{factory, args: ConstValue[]}` — factory statics (`AllowedFilter::exact('status')`)
  must be folded at the AST level BEFORE PHPStan collapses them to a plain object type.
- Terminal detection has two separable outputs: "reaches a paginating terminal" =
  name-match on a builder-typed receiver (works at any depth); the per-page value folds
  from the OUTERMOST terminal call's argument (lives at the call site).
- Two OPTIONAL capabilities widen a trace, both opt-in per visitor and both still bounded by depth/file
  budget and by "never into vendor" (`Core\Inference\`):
  - `FollowsReturnType` on the VISITOR — descend into a non-vendor callee OUTSIDE the configured
    project paths when the visitor recognizes its resolved return type (the modular
    `$query->query(): InvoiceQueryBuilder` hop).
  - `FoldsCallReturns` on the `TypeScope` handed to `enterNode` —
    `deferReturnFold(Node\Expr $call, callable $onFolded): bool`, the value a call RETURNS as opposed
    to the arguments it is WRITTEN with. `constantValueOf` answers the written half
    (`AllowedFilter::exact('q')` folds because the name is a literal AT the call site); when the public
    name lives in the callee BODY (`$this->termFilter()`, `ListFilters::status()`,
    `...$this->allowedFilters()`) nothing at the call site can be folded and only this can answer. The
    written-argument fold still wins wherever it succeeds — deferral runs only after it fails.
  - Only a SINGLE UNCONDITIONAL `return <expr>;` folds (`Trace\ReturnValueFolder`: one return statement
    AND `getStatementResult()->isAlwaysTerminating()`). A branching body would need an arm CHOSEN and
    there is no honest choice, so the fold declines and the visitor degrades to its own diagnostic
    (`query-builder.unresolved-entry`) — honest-permissive, as in §4a. Call-site arguments are bound to
    parameter names (a parameter's own constant default included) and both paths share one
    `Trace\ConstantFolder`, so a value folded inside a body reads identically to one folded at a call
    site. One body only: a call inside it is never chased, which is also why termination needs no cycle
    guard (`fn () => $this->itself()` simply fails to fold). The fold itself is stateless — the expensive
    half is the per-file analysis `FileAnalyzer` already memoizes.
  - Why DEFERRED, not answered in place: the fold has to analyse ANOTHER file, and doing that inside the
    live `processFile` callback would nest `processNodes` (the trap below — "collect then recurse; never
    nest `processNodes`", `Trace\Tracer`). Since 2.2 the callback scope is also a `FiberScope` that
    resolves by suspending its fiber, so it answers only while that fiber is alive — `stableScope()` →
    `toMutatingScope()` in the V2_2 `RuntimeAdapter`, or a retained scope throws "Cannot suspend outside
    of a fiber". So `Tracer::queueFold()` folds the call-site arguments THERE, on the live scope, retains
    nothing of PHPStan's, and `foldPending()` answers every request after the walk returns — inside a
    `finally`, because a visitor that reserved a slot for the answer is owed one. Contract: `false` =
    nothing queued (vendor / unresolvable / over budget), degrade now; `true` = EXACTLY ONE `$onFolded`
    call before the trace returns, possibly with nulls. The returned EXPRESSION handed back belongs to
    the callee's file: AST-readable only (a closure's `where()` column), never typed against the
    requesting scope.
  - Why OPT-IN per visitor: `constantValueOf` is shared. A visitor documenting rule names or rate limits
    wants `['name' => $this->nameRules()]` to stay honestly unrecovered rather than become a fabricated
    descriptor, so only a visitor that asks gets an answer.
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
`JsonResponse<payload, status, contentType, members>`. Five composed mechanisms, in the order they run:

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
4. **Object-payload constructor arguments.** When the body is an OBJECT rather than an array shape
   (`(new ProblemData(type: $p->value, errors: $errors ?? new Optional))->toResponse($request)`), there are
   no shape fields to pin a folded value into, so the arguments are recorded beside the class type: one
   member per argument the call site actually WROTE, folded by mechanisms 2–3 exactly as a shape member is.
   The construction site is either the receiver of the response-producing call or one project hop away
   through a factory that returns the object (`ProblemData::make(…)->toResponse(…)`); a deeper chain is a
   guess about which `new` produced it, so it declines. `??` reads through its left side, which is how an
   app spells "absent unless supplied".

   Being SUPPLIED is the fact this exists to carry, and it cuts both ways: an argument passed here puts the
   member in this response even where the class declares it optional, and an argument this call site did not
   pass leaves the map entirely rather than widening — it took its default, so it is not in this body. The
   adapter's response seam reads the map for exactly that (§6): it decides the example's membership, while
   the schema still decides what each member looks like. Nothing here expands the object — property
   semantics, name mappers and `Optional`/`Lazy` markers are the adapter's business, and the engine only
   ever says "this response carries that object, built with these arguments".
5. **Bounds, memoisation, containment.** Depth and the per-analysis file budget are the §3 bounds
   reused verbatim (default 4 / 40). Memoisation is per callee `class::method` (and per
   `(enum-case, method)` for folds) and is sound because the memoised shape is call-independent — with
   one rule: a computation whose descent hit a depth/budget CUTOFF is returned for the current analysis
   but NOT memoised, since its richness would otherwise depend on how much budget was already spent
   before that callee was first reached (route-order dependent → nondeterminism). Containment is
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
DType rather than a transient side-channel because it must survive SERIALIZATION: it rides inside the
`ArrayShapeT` payload of a `JsonResponse<…>` `ClassT` in an `ActionAnalysis`, while resolution happens
later, in the adapter. Same rationale as `uir-and-extensions.md` §8.

Translator (`TypeTranslator::translate(PHPStan\Type\Type, TranslationBudget): DType`):
ConstantArrayType → ArrayShapeT (optional keys honored, isList from accessory);
Constant scalars → LiteralT; UnionType → flatten + canonical sort; IntersectionType →
strip accessory types, collapse single survivor; ArrayType → ListT or MapT on its KEY, since
`isList()` is only MAYBE for `array<int, V>` — an int-capable key is a JSON array, the rule
`TypeStringParser::mapKeyed()` owns and both paths must answer alike; GenericObjectType/ObjectType →
ClassT/EnumT via ClassReflection (source location = provenance); TemplateType → its bound,
else UnknownT; MixedType/unknown/budget-exhausted (depth 12) → UnknownT(reason).
Translation is EAGER at query time (the result must be serializable); class expansion
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
   The same factory also refines a **declared** property from a docblock, which is what a spatie Data
   class needs: `array` and `array|Optional` are the most a promoted constructor property can say
   natively. The element type is written either in the constructor's `@param` tag or in the promoted
   parameter's **own `@var`** — PHP exposes that docblock through `ReflectionProperty::getDocComment()`,
   and it is the commoner form, because it is where the prose describing the member already sits. Both
   are read, the `@param` first; a plain property has only its `@var`.

   Refinement is one-directional, in two shapes:
   - Where the reflected type is **vague** (bare `array`, `mixed`, no type at all) a docblock type
     REPLACES it, and only when it is itself precise — so `@var array` never displaces a better native
     type and a native `string` is never second-guessed.
   - Where the reflected type is **precise but generic-blind** (a bare class: `DataCollection`,
     `Collection`, …) a docblock may only PARAMETERISE it: the arguments it states for that same class
     are grafted on and nothing else changes. A tag naming a different class — a subclass included — a
     different shape, or nothing parseable adds no arguments, and a `|null` in the tag never makes a
     non-nullable declaration nullable.

   Unqualified names in the tag resolve through the declaring file's `ImportContext`, class-level
   `@property` tags included — a short name parsed without it can never be recognised as an enum.
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

## 8. Determinism

PHPStan's own result cache is CLI-rule-oriented — unusable; we point its temp dirs into our cache
dir and leave it at that. Every engine result is canonically serialized (canonical member ordering
everywhere, no absolute paths in payloads), which is what makes the adapter's fragment cache sound.
CI invariant: cold-vs-warm byte-diff.

**Removed: the engine's own result cache.** A filesystem cache of serialized `ActionAnalysis` per
`ActionRef` and `ClassMetadata` per `ClassRef` — keyed on sha256(engine ver ‖ phpstan ver ‖
larastan ver ‖ generated neon hash ‖ composer.lock hash ‖ action file hash ‖ each descended-file
hash) — was built and never wired in. Its one benefit over the adapter's fragment cache was
surviving a tool upgrade, and that collapsed once the fragment cache's `BuildFingerprint` also
folded the app's `composer.lock` hash: a `composer update` now invalidates both, together. What
survives is narrow — a config edit (or a route rename, which `RouteDescriptor::cacheSignature()`
folds in) drops fragments whose analyses are still valid — but the container boot and the
uncacheable `trace()` calls (live nodes, stateful visitor; the majority of engine calls) are paid
either way, so the win is a fraction of one build for the price of a second on-disk cache with its
own invalidation rules sitting beside the one we do trust. Git history holds it if that changes.

## 9. Risks

| Risk | Mitigation |
|---|---|
| PHPStan internal churn | tested-minor allowlist; adapter-per-minor; CI matrix lowest+highest patch |
| Larastan can't boot user app (env issues) | fatal diagnostic + NullTypeEngine fallback; document a docuccino env |
| Memory growth | scoped entry set; file budget; `engine.memory_limit` + the out-of-memory notice |
| Dynamic chains defeat constant tracking | UnknownT(reason) + diagnostic at exact expression; attribute escape hatch |
| Throw-point noise | drop any-throwable; registry + @throws + bounded project-only descent |
| Determinism regressions | canonical serialization + the two CI diff tests |
| php-parser skew (host on 4.x) | hard require ^5 (composer surfaces at install) |
