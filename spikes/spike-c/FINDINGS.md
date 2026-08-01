# Spike C — Findings

**Goal:** evaluate the plan's 3-layer exception-flow design (§"Exception flow
(3 layers)") against reality on the fixture Laravel app, and *measure* NOISE
(useless "anything can throw" points) vs MISSES (real exceptions not surfaced),
then tune noise-dropping and descent depth.

**Verdict: PASS on all 8 fixture cases** (`sample-output.txt`, "OVERALL: PASS").
The 3-layer model is sound — but the spike surfaced **four design corrections**
that the plan and the `ThrownException` model must absorb before Phase 2. None
is a blocker; each is a concrete, evidenced tweak.

Reused Spike A's embedding harness verbatim and honoured every documented trap
(manual `bootstrapFiles`; `setAnalysedFiles` on **both** `NodeScopeResolver` and
`pathRoutingParser`; cwd = fixture app root; `FileHelper::normalizePath`). Plus
one **new** trap for descent (below).

Versions unchanged from Spike A (phpstan 2.2.7, larastan 3.10.0, php-parser
5.8.0, laravel 12.64.0, PHP 8.5.9).

---

## Per-case scorecard

| # | Case | Expected | Result |
|---|------|----------|--------|
| 1 | `abort(404)` + `abort_if($c,403)` | HttpException 404 **and** 403, statuses constant-folded | **PASS** — both folded (`abort` arg0=404, `abort_if` arg1=403) |
| 2 | `$this->authorize('update',$u)` | AuthorizationException 403 | **PASS** — surfaces **explicit** via Larastan stub; Layer 2 adds 403 |
| 3 | `User::findOrFail($id)` | ModelNotFoundException 404 | **PASS** — but **implicit** (see finding B); Layer 2 **rescues** it |
| 4 | inline `$request->validate([...])` | ValidationException 422 | **PASS** — surfaces **explicit** (macro is stubbed); Layer 2 adds 422 |
| 5 | 2-deep service, no `@throws` | OutOfStockException **and** RuntimeException, with chains | **PASS** — both via Layer 3 descent (depths 1 & 2) |
| 6 | same as 5 but `@throws OutOfStockException` | OutOfStockException via docblock, no descent | **PASS** — explicit at call site; **RuntimeException knowingly hidden** (finding C) |
| 7 | vendor "any-throwable" call | noise identified + counted, not silently lost | **PASS** — implicit `Throwable` dropped **and** a vendor-500 (`Psr…InvalidArgumentException`) demoted; both counted |
| 8 | try/catch, one caught one escapes | caught subtracted, escaping present | **PASS** — `OutOfStockException` caught → subtracted; `RuntimeException` escapes → surfaced |

Determinism: two consecutive runs are **byte-identical** except the wall/peak
lines (verified via `diff`).

---

## The four design corrections (the real spike value)

### A. `canContainAnyThrowable` is the WRONG noise discriminator

The plan (§"Exception flow", layer 1) says *"`canContainAnyThrowable` noise
dropped"*. **Do not key on that flag.** In practice **almost every throw point
has `canContainAnyThrowable = yes`, including all the signal** — the explicit
`HttpException`, `AuthorizationException`, `ValidationException`,
`OutOfStockException`. Dropping on that flag would delete the entire useful
output.

Root cause, confirmed in the phar source (`Analyser/ThrowPoint.php`):
`createImplicit()` always builds `type = Throwable, explicit = false,
canContainAnyThrowable = true`; `createExplicit()` passes
`canContainAnyThrowable` through independently (usually still true).

**The real discriminator is `isExplicit() && type ≠ Throwable`:**
- **implicit** points are *always* bare `Throwable` → the "any-throwable" noise;
- **explicit** points carry a concrete type → signal.

In this fixture that split is clean: 13 implicit `Throwable` points (dominated by
`response()->json()` — 2 per action — plus constructor calls) are all noise; every
concrete type is real. **Action:** rewrite the plan's layer-1 rule to
"drop `isExplicit()===false` (always `Throwable`) points; log at verbose".

### B. Larastan stubs shrink Layer 2 — but `findOrFail` still needs rescue

Because Larastan ships stubs, `abort`/`abort_if`, `authorize`, and the
`$request->validate()` *macro* all surface as **explicit, concretely-typed**
throw points (see `sample-output.txt` "RAW THROW POINTS"). So Layer 1 already
gives the exception **type** for these; Layer 2's remaining jobs are only:
1. **status extraction** — constant-fold `abort`'s status arg
   (`ConstantIntegerType` off `$scope->getType($arg)`), fixed map for the rest;
2. **rescue of the one that stays implicit: `findOrFail`.**

`User::findOrFail()` (static Model forwarder) yields an **implicit bare
`Throwable`** — *not* `ModelNotFoundException`. Contrast Spike A, where
`User::query()->firstOrFail()` (on the *Builder*) yielded an **explicit**
`ModelNotFoundException`. **Identical logical operation, different throw-point
shape depending on call form.** So the KnownThrowers registry keyed on the
callee name is essential precisely for the static forwarders, and its result is
lower-confidence (`likely`, not `certain`) because PHPStan didn't corroborate a
type. **Action:** the registry must cover the static `findOrFail/firstOrFail/
sole` forwarders, and Layer 2 has a dual role — *enrich* explicit points with a
status, and *rescue* implicit ones by callee.

### C. `@throws` is trusted verbatim → incomplete docblocks silently truncate

Case 6: `@throws OutOfStockException` on `placeDeclared()` makes PHPStan surface
`OutOfStockException` as an **explicit** throw point at the *call site* — **no
descent needed** (Layer 1 docblock trust works). **But** the deeper, undeclared
`RuntimeException` (thrown by `reserve()`, depth 2) is **completely hidden** —
the incomplete `@throws` truncates the flow. The forced-descent diagnostic in
`run.php` proves the hidden set is `{OutOfStockException, RuntimeException}`.

This is a genuine tradeoff, not a bug: trusting `@throws` is cheap and matches
author *intent*, but under-reports when a docblock is incomplete. **Action:**
default to trusting `@throws` (documents intent, low noise); expose a strict
"descend even through declared callees" mode for completeness audits, and record
`confidence: declared` so the SaaS can flag "declared, not verified".

### D. Dedup identity is (fqcn, **status**), not fqcn

Two `abort()`s in one action with statuses 403 and 404 are **two distinct API
error responses**. An initial dedup keyed on exception FQCN alone collapsed them
to one (403), producing a spurious MISS. Keying dedup/merge on
**(exceptionFqcn, httpStatusHint)** fixes it. This aligns with the plan's
"collections merge by identity key … responses by status" — confirm the *error*
merge also treats status as part of identity, and that `HttpException` is not
one component but a family keyed by status.

---

## What the `ThrownException{exceptionFqcn, httpStatusHint, callChain, confidence}` model needs

- **Identity = (exceptionFqcn, httpStatusHint).** Status is part of identity, not
  a payload field (finding D).
- **`httpStatusHint` must be nullable.** A bare `HttpException` without a
  constant-foldable arg has a *known family* but *unknown status* — model
  `null` + a fallback (500), don't force an int. (Not hit in the fixture because
  every `abort` had a literal, but production won't be so tidy.)
- **Add an `origin`/`disposition` classifier**, three-valued as observed:
  - `signal` — document as an API error (registry hit, literal throw, or
    project-declared exception);
  - `internal` — concrete but HTTP-irrelevant, map→500 and **demote** (e.g.
    PSR-16 `InvalidArgumentException` from `Cache::get`);
  - `dropped` — implicit bare `Throwable`, logged at verbose only.
  The discriminator for `internal` vs `signal` among *declared* exceptions is
  **project-code vs vendor callee**: a vendor call's `@throws` is plumbing; a
  project method's `@throws` is intent.
- **`confidence` observed values:** `certain` (literal `throw`, or registry hit
  PHPStan corroborated with a matching explicit type), `declared`
  (`@throws`/stub-sourced), `likely` (registry rescue of an implicit point, e.g.
  `findOrFail`). A fourth `uncertain` for dropped noise is only needed if verbose
  logging wants a level.
- **`callChain` is a list of frames** (`symbol` + `file:line`), not a single
  source — Layer 3 emits multi-frame chains (case 5's `RuntimeException` has 3
  frames). The model already implies this; the spike confirms the shape.

---

## Recommended default descent depth: **3**

Evidence: the fixture's real domain chain (`action → place → reserve`) needed
**depth 2**, and that already reached the deepest hand-thrown exception. Beyond
that, code trends toward vendor/framework, and **descent auto-stops at the first
vendor-declared method** (gated on the declaring class's file being under
`app/`) — so `Model::findOrFail` is never descended even though the receiver
(`App\Models\User`) is project code, because the *method* is vendor-declared.
That gate is the real bound; depth is a backstop.

Recommend **default 3 for exception-flow descent** (one hop of headroom over the
observed 2; controller→service→repository is the common ceiling before vendor).
This is *independent* of the plan's inference-side descent default (4) — exception
flow sees steeper diminishing returns because domain throws cluster within 2 hops
and the vendor gate does most of the containment. Keep the per-action file budget
(plan's 40) and per-file memoization; both were trivially satisfied here (only
**2 files** parsed for the whole run including descent).

---

## Performance

| Metric | Value |
|---|---|
| wall clock (container build + full analysis incl. descent) | ~0.5–0.9 s |
| peak memory (real) | ~100–118 MB |
| files parsed+processed (controller + descended `OrderService`) | 2 |
| max descent depth reached | 2 |

Same shape as Spike A: container build dominates; per-file analysis and descent
are cheap and memoized. Confirms the "boot once, walk many" worker model.

---

## Traps (reused Spike A's, plus one new for descent)

1. **Descent files must be primed BEFORE their first parse (new).**
   `defaultAnalysisParser → CachedParser → PathRoutingParser` caches the *first*
   parse of each file. If a callee file (`OrderService.php`) is parsed while it is
   **not** in the analysed set — e.g. via incidental reflection during the
   controller pass — it is cached **body-stripped** (`CleaningParser`), and Layer
   3 descent then silently reads **zero** throw points from it. The spike avoids
   this by priming **all** `app/` files (sorted, on both parser + resolver) up
   front. In production the `RuntimeAdapter` must add each callee file to the
   analysed set on **both** services and (re)prime **before** parsing it, with a
   regression test asserting descended method bodies survive. This is the Spike A
   body-stripping trap, re-encountered one level deeper.

2. **Layer 2 leans on Larastan being loaded.** The explicit types for
   `abort`/`authorize`/`validate` come from Larastan's bundled stubs (via the
   `includes:` in the neon). Without Larastan they would all be implicit and
   Layer 2 would have to *rescue* every one by callee (still works — just more
   rescues, all `likely`). Docuccino always ships with Larastan, so this is fine,
   but the registry must not *assume* the explicit form.

3. **`@L-1` phantom throw points.** Some implicit points (a `new UserResource(...)`
   constructor, and an execution-end point) report `getStartLine() === -1`. Harmless
   (they're dropped noise) but the model's `SourceLocation` must tolerate a missing
   line.

4. **Catch subtraction only visibly helps *explicit* points.** `subtractCatchType`
   does `TypeCombinator::remove(type, catchType)`; removing `ModelNotFoundException`
   from a bare `Throwable` leaves `Throwable`. So `try { User::findOrFail() } catch
   (ModelNotFoundException)` would leave a residual `Throwable` noise point *and*
   fail to record that the 404 was handled. Because we drop implicit points anyway
   the residual never reaches output — but it means catch-narrowing benefits are
   real only for explicitly-typed throws (case 8, both explicit, works perfectly).

5. All Spike A traps still apply unchanged (manual `bootstrapFiles`; dual
   `setAnalysedFiles`; cwd = app root; `FileHelper::normalizePath`).

---

## Bottom line

The 3-layer design holds: **8/8 cases pass, deterministically.** Ship it with the
four corrections — (A) drop on `!isExplicit()`, never `canContainAnyThrowable`;
(B) Layer 2 both enriches explicit points and rescues implicit forwarders;
(C) trust `@throws` by default, offer a strict descent mode; (D) exception
identity includes status — and set exception-flow descent depth to **3**.
Proceed to Phase 2 `ThrowAnalyzer`.
