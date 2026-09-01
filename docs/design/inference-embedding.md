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

**One pass per file, replayed (`Runtime\FileWalks`).** Resolving scope over a file is the expensive half of
an analysis, and several consumers want the SAME walk of it: the method/closure/assignment harvest
(`FileAnalyzer`), then the Query-Builder trace, the inline-rules trace and a pagination trace of every route
the file holds. `FileWalks` runs `processFile` once per file, records the `(node, scope)` sequence in
`NodeScopeResolver`'s own callback order, and replays it verbatim to every later ask. Measured on the fixture
app: 313 passes over 30 files → 30, and ~28% off the analysis wall (pass count falls further than time
because PHPStan's parser/reflection caches already made a repeat pass cheaper than a cold one).

The invariant it rests on: **a replay and the walk that recorded it are indistinguishable.** Every consumer,
the recording one included, is handed the STABILISED scope (`stableScope()` → `toMutatingScope()`), deduped by
scope object identity — many nodes share one scope instance, which is what keeps stabilising every node's
scope near-free (+4.6% on a pass, against +14% undeduped). Stabilisation is UNIFORM across all three paths:
recording, replay, and the plain live pass a declined recording falls back to. Which of the three answered a
question therefore cannot move a byte, and a build stays deterministic whatever the recorder decides to keep.

Uniform is not identical to the RAW callback scope, and the gap was measured rather than assumed (on a
fiber-era 2.2.x): 1 divergence in 2945 fixture-app queries —
`AllowedFilter::callback('tag', $this->tagFilter(...))` in
`modules/Billing/ChargeListQuery.php`, where the raw scope types the first-class callable `Closure(...)` and
the stabilised one `mixed`. Benign (no golden moved: a callback filter's column is recovered from the callee's
AST, not from that argument's type) and narrow — the class is "a first-class-callable argument widens". The
fixture group asserts that filter's recovery explicitly (`QueryBuilderTraceTest`, `trace-qb-enrich`) so the
class cannot grow silently.

Under-recording is the sanctioned degradation, and the invariant above is what licenses every form of it: a
missing recording is pure COST — one more live pass — never a different answer.

- A pass that threw records nothing; a truncated recording would answer a later consumer with less than a
  live pass does.
- No recording is built from inside another walk. The nesting itself is the caller's problem ("collect then
  recurse, never nest `processNodes`", §4) — the fallback IS a nested pass; what the guard buys is that an
  outer recording can never be interleaved with an inner walk's nodes.
- Recording is abandoned MID-PASS the moment it crosses its budget, dropping what it accumulated, and the
  file is remembered so later asks go straight to a live pass with no accumulation at all — otherwise one
  huge file materialises hundreds of MB before being discarded, and re-pays that on every later ask. Two
  bounds, because nodes are only a proxy for the real risk: 100k retained nodes (~1.7 KB each as measured, so
  ~170 MB; the whole fixture app is ~7.5k nodes, a 500-file application extrapolates to ~60k) and
  `memory_get_usage()` reaching 70% of the process's own `memory_limit`. When the total retained would exceed
  the node budget the WHOLE store is cleared rather than evicted file by file: a cleared file is walked live
  and re-recorded, so the cheap reset is a correct one and the replay path keeps no ordering to maintain.
- A recording is stamped with the SIZE of the adapter's analysed-file set and discarded when that set has
  grown since. This is the one way a recording could answer with less than a live pass: PHPStan gates trait
  inlining on the analysed set, so a file primed after the recording was made would make a fresh pass over
  the same file richer. Growth after boot is rare — boot primes every project and prime root, so only a walk
  of a file outside all of them grows the set — but without the stamp one route's richness would depend on
  which unrelated route ran first. So warm equals cold not "by construction" but by this stamp plus the
  uniform stabilisation above, and it costs nothing measurable: the fixture app still walks 1.0 passes/file.

Closure harvesting is the one walk that may not come through here. The reason is NOT that a closure cannot be
replayed — a stabilised arrow-function scope answers after its pass like any other — but that those visitors
are handed the raw scope; §4b.

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
  diagnostic + `NullTypeEngine` fallback (docblock/attribute-only docs still build). The failure rides
  on the returned engine (`Core\Inference\ReportsBootFailure`), because a host may defer the build to
  the first question a route asks and nothing else survives that far. It arrives after the fragment
  cache has keyed the build on the engine that was going to answer, so a boot-failed build STOPS
  persisting fragments rather than filing degraded ones under the real analyser's key
  (`DocumentGenerator::degraded()` owns the rule).
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

`location()` reports the file a node was WRITTEN in, which is not always the file being analysed: a trait
body is analysed once per using class, so its nodes carry the TRAIT's line numbers while the analysed file
is the class's. Reporting the class there hands a visitor a file+line pair naming a line nobody wrote —
and a visitor correlating that line against a declaration's span (`RequestPageSizeReader`) then reads a
trait's code as some unrelated method of the using class. The trait's own file goes with its lines.

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
    nest `processNodes`", `Trace\Tracer`). The callback scope is the walk's own, and in PHPStan 2.2.0–2.2.9
    it resolves by suspending a fiber, so it answers only while that fiber is alive — `stableScope()` →
    `toMutatingScope()` in the V2_2 `RuntimeAdapter`, or a retained scope throws "Cannot suspend outside
    of a fiber". 2.2.10 dropped fibers for a callback scope that answers a retained ask itself, where the
    same call is the identity; the adapter's docblock owns which era needs what, and `AnalyserDriftTest`
    fails when the analyser is neither. So `Tracer::queueFold()` folds the call-site arguments THERE, on
    the live scope, retains nothing of PHPStan's, and `foldPending()` answers every request after the
    walk returns — inside a `finally`, because a visitor that reserved a slot for the answer is owed one.
    Contract: `false` = nothing queued (vendor / unresolvable / over budget), degrade now; `true` =
    EXACTLY ONE `$onFolded` call before the trace returns, possibly with nulls. The returned EXPRESSION
    handed back belongs to the callee's file: AST-readable only (a closure's `where()` column), never
    typed against the requesting scope.
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

   A return site may also NAME the response first (`$r = Problem::make(…); $r->headers->add(…); return $r;`
   — what a renderer writes as soon as the protocol headers an exception carries have to survive), and the
   variable's own type is the same erased class, so the shape lives only in what was assigned to it. The
   assignment is refined instead, IN THE SCOPE IT WAS WRITTEN IN — read at the return, an expression binds
   whatever its arguments hold by then, which is how a shape stops being true. Only for a local the method
   assigns EXACTLY ONCE: two branches writing one variable are described by neither, and picking one would
   publish a body the other branch never sends. Naming a value is not a call hop, so it costs no depth.

   "Once" has to mean once in the language, not once in one node type. `Docuccino\Core\Inference\LocalWrites`
   is the single grammar both this harvest and the adapter's page-size reader ask — the plain `=` is the only
   form with an expression to serve, and compound/reference assignment, `++`/`--`, `list()`/`[…]`
   destructuring however nested or keyed, a `foreach` value AND key binding, `static`/`global`, `unset()`
   and a `catch` binding all retire the local. `FileAnalyzer` adds the one write no expression shows — an
   argument bound to a by-REFERENCE parameter, which it resolves through PHPStan's own reflection (an
   unresolvable callee cannot write one either: `__call` is handed a copy) — and a write naming no single
   local (`$$name = …`, `extract()`) retires every local of that scope.

   Both harvests are keyed per file by `Class::method`, and so is the method-body harvest itself: one file
   can declare a name twice (a renderer beside the inline one it falls back to), and a bare method name
   there answers one class's return with the other's body. Where a caller has no class to name, the by-name
   lookup answers only while the file declares that name once.
   A return site may also STAMP the response after building it — `response()->json($body)->setStatusCode(202)`
   — and that tail is peeled before anything else runs (`FluentResponseChain`). It has to be, twice over: the
   setter is vendor code, so the descent in the paragraph above declines it and the whole shape with it; and
   `setStatusCode()` returns `static`, so the `JsonResponse<…, 200>` our own extension resolved for
   `response()->json()` survives the call unchanged and would publish a 200 the endpoint never sends. So the
   erased-generic gate is not "does the type carry arguments" but "does the refiner read this expression
   better than its type does" (`ResponseShapeRefiner::outranksResolvedType()`) — true for a `new` and for a
   peelable chain, since the stub's `@template` bounds parameterise a plain `new JsonResponse(…)` with
   nothing at all.

   The grammar is one rule, and the guard is the same function as the fold: a call whose RECEIVER is one of
   the four response classes is a MUTATOR, peeled only when it is `setStatusCode`, `header` or `withHeaders`
   AND the installed framework declares it returning the object it was called on (native `static`, or the
   `@return $this` the header setters carry) — read off the resolved vendor code, so a release that moves
   either fact degrades rather than publishing this major's dialect. Any other mutator refuses the WHOLE
   chain: `->setData($other)` returns `static` exactly as the status setter does, and passing the receiver's
   payload through it would publish a body that was replaced. A call whose receiver is not a response is a
   producer — that is the base, and mechanism 1 takes it from there. Peeling is not a call hop, so it costs
   no depth and adds no dependency file: the only thing newly read is vendor reflection, which
   `BuildFingerprint` already covers through the app's `composer.lock`.
   `withStatus()` is deliberately absent (PSR-7's setter; no response class here declares it), and so are
   SUBCLASSES of the four — a strict receiver check is what keeps `(new StreamedResponse(…))->setStatusCode(202)`
   out, since everything recovered here is emitted as a `JsonResponse` and a streamed body is not one.
   A status that will not fold refuses the chain rather than guessing; a header link that will not read
   reports its media type UNKNOWN instead, which drops whatever the receiver carried but keeps the status,
   because a header is the one thing that cannot have touched it.
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
   pass leaves the map entirely rather than widening — it took its default, so it is not in this body. A
   third answer is neither: an argument whose TYPE admits an omission marker renders the key on some runs
   and nothing at all on the others, so its member is recorded OPTIONAL and asserts nothing. Only an
   argument that IS the awaited value settles one — the callee's `$param ?? new Optional`, met with a value
   that is neither null nor a marker of its own. A tail that reads THROUGH the argument
   (`$p->detail() ?? new Optional`) is never settled from out there however solid the receiver: what the
   caller proved is that the receiver exists, and the key hangs on what the read answers. The adapter's
   response seam reads the map for exactly that (§6): it decides the example's membership, while the schema
   still decides what each member looks like. Nothing here expands the object — property semantics, name
   mappers and `Optional`/`Lazy` markers are the adapter's business, and the engine only ever says "this
   response carries that object, built with these arguments".
5. **Bounds, memoisation, containment.** Depth and the per-analysis file budget are the §3 bounds
   reused verbatim (default 4 / 40). Memoisation is per callee `class::method` (and per
   `(enum-case, method)` for folds). A recovered shape is call-independent but NOT bound-independent, so
   the memo is gated in BOTH directions, or a route's body would depend on which unrelated route ran
   first:
   - a computation whose descent hit a depth/budget CUTOFF is returned for the current analysis but NOT
     memoised, since its richness would otherwise depend on how much budget was already spent before that
     callee was first reached;
   - and an entry is only SERVED to a caller that could have computed it: each entry records what its
     descent cost — every file it touched and how many depth levels it used below the callee — and a
     caller without that much headroom left recomputes instead, which truncates honestly. Cheap entries
     (the common case: a helper one hop from a leaf) stay hits everywhere; only a callee reached at
     genuinely different remaining budgets pays.

   A truncation is reported, not swallowed: the analysis carries an `inference.response-shape-truncated`
   info diagnostic counting the bound hits, since a response that quietly lost its body reads as a
   deliberate bare `JsonResponse`. Containment is
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
scope. An arrow function's RAW scope is lazy — a fiber scope on 2.2.0–2.2.9 — and cannot type expressions
once the pass has ended, so nothing may be deferred until after the walk.
`ClosureReturnStatementsNode::getStatementResult()->isAlwaysTerminating()` distinguishes a conditional
(fall-through) closure body from an unconditional one; a limiter that does not always return is left
unrecovered rather than half-folded.

The "raw" qualification is load-bearing, and it is also why `traceClosure()` is the one walk that bypasses
`FileWalks` (§2). Stabilising lifts exactly that limit — a stabilised arrow-function scope answers after its
pass like any other, verified — so it is not that closures cannot be replayed. It is that these visitors take
the raw scope, `$statement->getScope()` for a full closure and the callback scope itself for an arrow
function, because a return's flow refinement lives only there; a recording holds nothing but stabilised
scopes, so it has nothing to hand them. Every `ClosureReturnStatementsNode`/`InArrowFunctionNode` consumer
takes a live `processFile`. The decision is owned by `PhpStanTypeEngine::traceClosure()`'s docblock.

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
ConstantArrayType → ArrayShapeT (optional keys honored, isList from the accessory OR derived from a
`0..n` key sequence, which is what makes a docblock tuple an array too);
Constant scalars → LiteralT; UnionType → flatten + canonical sort; IntersectionType →
strip accessory types, collapse single survivor; ArrayType → ListT or MapT on its KEY, since
`isList()` is only MAYBE for `array<int, V>` — an int-capable key is a JSON array, the rule core's
`ArrayKey` owns and BOTH paths call (uir-and-extensions.md §8); GenericObjectType/ObjectType →
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
2. `KnownThrowers` registry (engine-owned and `@internal` — NOT a user surface; §7 is the
   sanctioned escape hatch), keyed on the callee NAME and gated on the
   RESOLVED callee — **dual role**: (a) *enrich* explicit stubbed points with a status
   (`authorize` → 403, `validate` → 422), (b) *rescue* still-implicit forwarders by callee name
   (static `Model::findOrFail` surfaces only as implicit bare Throwable — unlike Builder
   `firstOrFail` — the registry restores ModelNotFoundException/404 as `likely`).
   `abort/abort_if/abort_unless` → HttpException with status via constantValueOf (arg 0 for
   abort, arg 1 for abort_if/unless). Route-model binding → 404 (pipeline supplies).
   **The gate**: a bare name is a guess, so the registry speaks only for callees this build
   cannot read — vendor, a stub, a trait method (which resolves to the using class's file
   without being declared there), a magic forward, an unresolved receiver. Where the callee
   resolves to a project method whose body the analysis really holds, layers 1 and 3 read what
   it actually throws and layer 2 stands down: an application's own `validate()` throwing its
   own exception is that exception, never a 422 `ValidationException`.
3. Bounded descent into project-code callees lacking `@throws` (**depth 3** — observed max
   real depth 2; the vendor-file gate, not depth, does the real containment), memoized,
   cycle-guarded; vendor never descended. **Trap: descent targets must be added to the
   analysed set on BOTH parser+resolver BEFORE first parse**, or CachedParser caches a
   body-stripped copy and descent silently reads zero throw points.

### The status an `HttpException` subclass carries

A subclass that IS a status states it in its own `parent::__construct()`, which no name-keyed table can
see, so three reads sit under layer 1 and answer in this order:

- **What the class pins on every instance** (`HttpExceptionStatus`) — a constant reaching the parent call,
  or a constructor parameter with a constant default that only this class can fill (private constructor, no
  trait, no in-class `new self(...)` writing the slot, no write to the parameter before it is forwarded).
- **What THIS construction passes** — the argument a `throw new X(…)` writes into the slot the class
  forwards, the constructor default where it writes none.
- **What the factory the throw names builds with** (`FactoryStatus`) — one hop, no further.

The second and third are one rule (`ConstructionStatus`), because they are the same construction one hop
apart: a `throw new X` and a `throw X::make()` whose factory is `return new self` must publish the same
status, and they once did not. Every fold happens in the scope at the CALL, never a body's end scope — a
constructor that reassigns its status parameter after forwarding it makes the two disagree, and the end
scope names a value the callee never received.

Only PROJECT files are read. PHPStan strips an unprimed file's bodies, so a vendor subclass's
`parent::__construct(409, …)` arrives as an empty statement list and the read declines anyway — measured
against Symfony's own `ConflictHttpException`, whose harvested `__construct` has zero statements — while
asking for it primes that file, grows the analysed set and discards every walk the replay layer had
recorded. A folded value outside `100..599` is refused for the same reason a missing one is
(`HttpStatusCode`): it would become a response key no consumer can read.

Where nothing folds, the status is null — "an HTTP error whose status did not fold", which is neither the
500 that means "not an HTTP error at all" nor evidence of one. A class the build could not read is
therefore not automatically a Signal: `ThrowSignal` demotes a foreign declaration whose status nothing
could read, HttpException subclass or otherwise.

**The `inference.http-exception-status-unread` notice.** Where it fires is what earns it its place. The
firing population was measured against one real application's 47 `HttpException` subclasses: reading only
what a class pins on ITSELF left 10 unread, and 9 of those were the static-factory idiom — correct
idiomatic code with nothing for the author to change, which is the shape that trains a reader to ignore the
channel. That is the population the factory hop is written for. The construction read removes another part
of it, the class that defaults its status and is built with the argument left off. What remains is the part
an author CAN act on: a status chosen at run time, a construction behind an unreadable spread, a factory
that builds the class two ways. The notice is gated on the exception class being the project's, because the
remedy it names is an edit to the class — advice nobody can take for a class they do not own.

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
- User's own PHPStan extensions improve their docs with zero Docuccino-specific API — headline
  feature, and the one sanctioned way to teach the analysis about a project's own code (which is
  why §6's registry stays internal). The wiring, end to end:
  `engine.neon` (adapter config, a path relative to the app base path) → `EngineNeon::path()` →
  the core `TypeEngineBuilder::build(configFile:)` seam → `RuntimeConfig::$userNeon` → an extra
  entry under `includes:` in the generated neon, after Larastan's. So the file is a normal PHPStan
  config: whatever it registers (dynamic return-type extensions, stub files, parameters) is in play
  for every question the engine answers, and a project usually just points the key at the
  `phpstan.neon` it already has.
  Two consequences the wiring owes: the file's CONTENT joins `BuildFingerprint`, since an edited
  extension changes inferred types with no analysed file moving; and a configured path that names
  no file is skipped by the engine and reported by the adapter as one
  `config.engine-neon-missing` warning — the build is honest without it, just vaguer than
  configured, so it degrades rather than failing.

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
