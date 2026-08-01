# Spike B — Findings

**Goal:** prove interprocedural **constant-value recovery through a 2-deep user
helper chain** — the exact case Scramble Pro fails on — plus **pagination-terminal
detection** behind a custom terminal. This validates the plan's
`TypeEngine::trace(ActionRef, TraceVisitor)` / `TypeScope::constantValueOf`
boundary shape (the "Scramble-Pro-beater").

**Verdict: PASS on every pass criterion.** The `TraceVisitor` / `TypeScope`
contract survives contact with reality, with a few refinements documented below.

Reuses Spike A's embedding harness verbatim (ContainerFactory + generated neon +
Larastan bootstrap + the parser-priming trap) and layers a prototype tracer over
the top. Same host / versions as Spike A (PHP 8.5.9; phpstan 2.2.7, larastan
3.10.0, php-parser 5.8.0, spatie/laravel-query-builder 7.3.0).

---

## The fixture (mirrors the Eos pattern that defeats Scramble Pro)

Three files, **zero doc annotations**, added on top of the shared fixture app
(canonical copies in `spikes/spike-b/fixture-src/`):

- `app/Support/ListQueryBuilder.php` — `final class ListQueryBuilder extends
  Spatie\QueryBuilder\QueryBuilder` with a custom `paginateList(int $perPage = 15)`
  terminal that internally calls `$this->paginate($perPage)`.
- `app/Queries/UserIndexQuery.php` — `final readonly class` whose `query()` builds
  `ListQueryBuilder::for(User::class)->allowedFilters([...])->allowedSorts([...])
  ->defaultSort('name')`. **The chain is built INSIDE this class, not the controller.**
- `app/Http/Controllers/UserListController.php` — `listUsers()` does
  `return (new UserIndexQuery())->query()->paginateList(25);`.

From the action the `allowedFilters` literals are **two calls deep**
(`listUsers -> query() -> chain`) and pagination is behind a custom terminal one hop
away (`listUsers -> paginateList -> vendor paginate`).

---

## Real output (committed verbatim in `sample-output.txt`)

```
-- allowedFilters recovered (3) --
  [0] scalar      'name'
  [1] descriptor  AllowedFilter::exact('status')
  [2] descriptor  AllowedFilter::partial('email')

-- allowedSorts recovered (2) --
  [0] scalar      'name'
  [1] scalar      'created_at'

-- defaultSort recovered (1) --
  [0] scalar      'name'

-- pagination detection --
  paginates: YES
  per_page recovered: 25
  terminal hits (2):
    #0 paginateList(perPage=25) on ListQueryBuilder  @ app/Http/Controllers/UserListController.php:27
    #1 paginate(perPage=unresolved) on ListQueryBuilder  @ app/Support/ListQueryBuilder.php:33

-- descent chain (depth accounting) --
  [0] UserListController::listUsers  [(entry action)]
      [1] UserIndexQuery::query  [->query()]
      [1] ListQueryBuilder::paginateList  [->paginateList()]
          [2] ListQueryBuilder::paginate  [->paginate()]  (vendor/forwarded terminal — matched by name, not descended)

  max descent depth reached: 2 (>=2 hops required)
```

### Control (the Scramble-Pro failure mode)

`DOCUCCINO_NO_DESCENT=1` sets `maxDepth = 0` (look only at the action body, never
descend):

```
-- allowedFilters recovered (0) --
-- allowedSorts recovered (0) --
-- defaultSort recovered (0) --
  paginates: YES        <- paginateList still name-matched at the call site
  per_page recovered: 25
  max descent depth reached: 0
```

The literals are **unrecoverable without interprocedural descent** — exactly why
Scramble Pro hand-documents them with `#[QueryParameter]` in Eos. Descent is the
whole game. (Pagination is a partial exception: the custom terminal is detected by
*name* at the call site even at depth 0; descent is what additionally proves it
reaches the real `paginate`.)

---

## Verdict per pass criterion

| Criterion | Result | Evidence |
|---|---|---|
| allowedFilters recovered with correct shapes (string vs factory descriptor), 2-deep, zero annotations | **PASS** | `'name'` scalar + `AllowedFilter::exact('status')` / `partial('email')` descriptors |
| allowedSorts + defaultSort recovered | **PASS** | `['name','created_at']`; defaultSort `'name'` |
| Pagination detected through custom `paginateList` terminal, per-page 25 | **PASS** | terminal hit `paginateList(perPage=25)` + descent reaches vendor `paginate` |
| Deterministic across runs | **PASS** | 5 cold-tmpDir runs + warm runs all **byte-identical** (perf lines excluded) |

---

## Does `enterNode(Node, TypeScope): bool` survive contact? — Yes, with a split

The plan's `interface TraceVisitor { enterNode(Node, TypeScope): bool; // true =
descend }` holds up, but reality forces a **clean responsibility split** that the
plan should make explicit:

- **The visitor is pure semantics + harvesting.** It inspects a node, harvests
  into its result (allowedFilters/Sorts, terminal hits) via `TypeScope`, and
  returns "should we descend here?". It imports **zero PHPStan** — everything it
  needs comes through `TypeScope`. `PhpParser\Node` crossing the boundary is
  exactly right and sufficient.
- **The engine (Tracer) owns all the plumbing** the visitor cannot: bounded depth,
  memoisation per `class::method`, cycle guard, **callee resolution via
  reflection** (node -> `ReflectionMethod` -> declaring class + file), **per-file
  parser priming**, and **deterministic descent ordering**. The engine re-analyses
  the callee file with the same `NodeScopeResolver` machinery and recurses.
- **`enterNode`'s bool is advisory, not authoritative.** The engine still declines
  descent the visitor requested when the callee is vendor / unresolvable / over
  budget. Recommend documenting: *"true = request descent; the engine may decline
  (vendor, magic/forwarded, unresolvable, or depth/budget exceeded)."* In practice
  the visitor returns true for any app-code call and the engine filters — that
  division kept the visitor trivial.

One consequence: `enterNode` returning a bare `bool` means the engine must
**re-derive** the callee from the node (it already has the node + scope, so this is
cheap). An alternative shape — `enterNode` returns an optional descent request
object — would avoid the re-derivation but couples the visitor to callee
resolution. The bool won; keep it.

## What `constantValueOf` actually had to handle

`TypeScope::constantValueOf(Node\Expr): ?ConstValue` needed **three** cases, in
this precedence (the ordering is load-bearing):

1. **Array literal** -> recurse per item (`ConstValue::array`). Walk the
   `Array_->items` at the **AST level**, because...
2. **Factory static-call** (`AllowedFilter::exact('status')`) -> a **call
   descriptor** `{factory, args}`, folding each arg. This MUST be special-cased
   *before* asking PHPStan for the type: PHPStan would (correctly) tell you the
   *type* is `Spatie\QueryBuilder\AllowedFilter`, collapsing away the call the docs
   actually need. **The descriptor variant is the crux of the whole spike** and
   will recur for `AllowedSort::field()`, `AllowedInclude::relationship()`, etc.
3. **Genuine literal** reached through any expression -> defer to PHPStan constant
   folding: `Type::getConstantStrings()` (single => string value) and
   `Type::isConstantScalarValue()->yes()` + `getConstantScalarValues()` (single =>
   int/bool/etc.). Returns `null` when nothing constant is recoverable.

So the plan's `ConstValue` **closed set must include a call-descriptor variant**,
not just literals + arrays. That's the single most important refinement to the
data model.

`typeOf` (plan: returns `DType`) was **not** needed in full for this integration —
the QB visitor only ever needs "what single class does this receiver resolve to?"
for terminal/builder matching and descent. A convenience `objectClassOf(Expr):
?string` (built on `Type::getObjectClassNames()`) covered every use. Full `DType`
translation can stay lazy; the trace path doesn't force it.

`location(Node): SourceLocation` was trivial but **essential for determinism** —
see trap #5.

---

## Traps found (the real spike value)

1. **`PHPStan\Parser\Parser`, not `PhpParser\Parser`.** The container's
   `defaultAnalysisParser` service is a `PHPStan\Parser\Parser` (concretely
   `CachedParser`), *not* nikic's parser. Type-hinting the tracer's constructor
   with `PhpParser\Parser` throws a `TypeError` at wire-up. (`PhpParser\Node` is
   still nikic's — only the *parser service* is PHPStan's wrapper.)

2. **Re-priming per descended file works — and is mandatory.** Spike A's
   body-stripping trap applies to *every* file, not just the entry. Before each
   file's `processNodes`, call `setAnalysedFiles([normalisedPath])` on **both**
   `pathRoutingParser` **and** `NodeScopeResolver`. Re-priming a second/third file
   yields rich (body-preserving) parses every time — verified: controller -> 2 call
   nodes, `UserIndexQuery` -> 6 call nodes, all with intact bodies.

3. **Sequential `processNodes` is fine; nested is asking for trouble.** Running
   `processNodes` repeatedly on the same resolver (once per descended method) works
   without state bleed. The discipline that makes it safe: **collect descent targets
   during the walk, then recurse *after* `processNodes` returns** — never start a
   new file's walk from inside a running callback.

4. **Scope-based method scoping needs no parent pointers.** A whole-file walk is
   trivially confined to one method with
   `$scope->getClassReflection()?->getName()` + `$scope->getFunction()?->getName()`.
   This also *correctly excludes closures* (their `getFunction()` name won't match
   the target method) — no manual method/closure stack required.

5. **Descent order must be sorted by source position for determinism.** PHPStan's
   node-callback order for a chained expression (`(new X)->query()->paginateList()`)
   is *not* the left-to-right source order. Sorting collected descent targets by
   `Node::getStartFilePos()` makes the descent chain both deterministic across runs
   *and* logically ordered (`query()` before `paginateList()`). Without it, output
   ordering is at PHPStan's mercy.

6. **Magic/forwarded terminals resolve to "don't descend" for free.** Spatie's
   `paginate` is forwarded via `ForwardsCalls::__call`, so
   `ReflectionMethod(ListQueryBuilder, 'paginate')` **throws** — which is exactly
   the right signal: it's a vendor terminal, matched by *name* against the builder
   receiver, never descended. No special vendor allow-list needed; reflection
   failure *is* the boundary. (`ListQueryBuilder::for()` similarly reflects to the
   vendor `QueryBuilder` file -> not descended, as intended.)

7. **Larastan's generics carry the receiver type with no custom stub.**
   `ListQueryBuilder::for(User::class)` returns `static<T>`; PHPStan/Larastan
   resolve it to `ListQueryBuilder<User>` out of the box, so every subsequent
   `->allowedFilters()/->paginateList()` sees a `QueryBuilder`-subclass receiver.
   Unlike Spike A's `response()->json()` (which needed a stub), **the QB chain needs
   nothing bundled** — the receiver-type inference the whole trace depends on is
   free.

8. **`tmpDir` must be isolated per invocation.** PHPStan writes its compiled
   container + result cache there. One early run produced an empty harvest that I
   could **not reproduce** after (5 cold-tmpDir runs + warm runs are all
   byte-identical); the most likely cause was opcache serving a stale tracer file
   mid-edit rather than tmpDir state. Still: give each engine invocation/worker its
   own `tmpDir` and don't share it across concurrent processes.

---

## Performance

| Metric | Value |
|---|---|
| Wall clock (container build + full trace, 3 files) | **0.5-0.9 s** |
| Peak memory (real) | **~90 MB** |

Same envelope as Spike A — container build dominates; the interprocedural walk
over 3 files is negligible on top. Confirms the plan's "boot once, walk many"
worker model: descent cost is trivial relative to boot.

---

## Implications for the plan's `trace()` design

1. **The boundary shape is validated.** `trace(ActionRef, TraceVisitor)` +
   `TypeScope{typeOf, constantValueOf, location}` is the right cut.
   `PhpParser\Node` crosses; PHPStan does not leak past `TypeScope`.

2. **`ConstValue` closed set must include a call-descriptor variant**
   `{factory, args: ConstValue[]}` alongside scalar / array / unknown. This is
   non-negotiable for QB filters and recurs across the QB allow-lists. Fold factory
   calls at the AST level *before* PHPStan type collapse.

3. **Split the responsibilities explicitly in the contract.** Visitor = semantics
   + harvest; engine = depth/memo/cycle-guard/reflection-descent/parser-priming/
   deterministic ordering. Document that `enterNode`'s `true` is a *request* the
   engine may decline.

4. **`ActionAnalysis::$dependencyFiles`** falls out naturally: the set of files the
   trace located/analysed (`UserListController`, `UserIndexQuery`, `ListQueryBuilder`
   here). Collect it from the engine's `visited`/located map for the fragment cache
   key — sound even for a Query class three calls deep, as the plan claims.

5. **Terminal handling has two distinct outputs** the integration must not
   conflate: (a) *"does the call graph reach a paginating terminal?"* — satisfied by
   a **name match** on a builder receiver, works even at depth 0; (b) *the per-page
   value* — folded from the **outermost** terminal call's first arg (the `25` lives
   in the controller, not inside `paginateList`). Read per-page at the call site,
   not at the terminal definition. The configurable terminal list (incl.
   `paginateList`) is the right knob.

6. **Determinism is an engine responsibility, not a serialization afterthought.**
   Sort descent by source position at walk time; the plan's canonical-ordering
   guarantees start here, upstream of the emitter.

**Bottom line: Spike B validates the `TraceVisitor`/`TypeScope`/`constantValueOf`
boundary and the Scramble-Pro-beater mechanism. Proceed to Phase 2's `trace()`
with the ConstValue call-descriptor and the responsibility split baked in.**
