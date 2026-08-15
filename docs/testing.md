# Testing & coverage standards

This document is the authority on the project's test-coverage standards, plus how to run
coverage locally and the ratchet policy for the CI gate.

## Standards

- **Mapping / lookup tables** (rule maps, attribute maps, cast → format tables,
  `KnownThrowers`, enum naming strategies, the phpdoc type-grammar table) are tested
  **dataset-driven over EVERY entry, plus the unknown-entry degradation contract**. One
  tested entry in a table is not coverage. When an entry turns out to be unreachable
  (e.g. a `match` label that can never be selected), it is deleted, not tested.
- **Stub / real splits.** `StubTypeEngine` tests prove *pipeline mechanics* only; every
  integration's recovery/parsing half also needs a **real-path** test (real reflection,
  or the real engine via the `fixture` group). Ask of every test: which half does this
  prove, and where is the other half proven?
- **An assertion that cannot fail proves nothing.** `expect($response['content']['schema'] ?? [])`
  passes whether or not the key is there, and a test that hand-builds a value the real recovery
  path cannot produce pins a shape the product cannot reach. Ask of every pin: if the feature
  were absent entirely, would this still be green? Assert that the key is present, not only what
  is inside it, and build fixtures through the real path rather than constructing them.
- **Negative paths, exit codes, and degradation branches are coverage**, not extras.
- **Coverage gates protect the goldens' blind spots** — code paths the golden-file suite
  never traverses (emit branches, patch/precedence, cache read/validate, error/skeleton
  branches). A green golden diff does not imply those paths ran.
- **Fixture honesty (binding).** A **real-engine fixture** (anything the `fixture` group analyses,
  or a fixture whose recovery half is proven against the real engine) MUST use the **idiomatic
  target-package shape**: a magic-attribute Eloquent model documented with `@property` docblocks —
  never typed public column properties; a resource with closure/conditional (`when*`) fields; a
  FormRequest/action with `Rule::*` descriptors; a mapper/attribute used the way the package's own
  docs show. **A fixture shaped to satisfy the analyzer proves nothing** — it hides the gap it was
  meant to exercise: a model without `@property` docblocks, a resource with no conditional fields,
  a request with no `Rule::*` descriptor all quietly hide the analyzer paths that matter most.
  When a test needs a shape the analyzer cannot yet handle, keep the fixture
  idiomatic and **pin the degraded output + its diagnostic** (the honest current behaviour), rather
  than reshaping the fixture until the analyzer looks like it succeeds. Stub-engine fixtures are for
  mapper *mechanics* only and never substitute for a real-engine recovery proof.

## Running coverage locally

Line coverage needs a coverage driver. The project uses **pcov** (fast, statement-level):

```bash
# One-time: install pcov (macOS/Homebrew PHP needs the pcre2 headers on the include path)
CPPFLAGS="-I$(brew --prefix pcre2)/include" pecl install pcov

# The ENFORCED gate: clover report + the per-package floors (this is what CI runs)
composer test:coverage

# …or its two steps by hand
vendor/bin/pest --coverage-clover=build/clover.xml --exclude-group=fixture
php tools/coverage-floors.php build/clover.xml

# Overall line coverage, for a quick single number (not the gate)
vendor/bin/pest --coverage --exclude-group=fixture

# Full text report (per-class line %), written for inspection
vendor/bin/pest --coverage-text=build/coverage.txt --exclude-group=fixture

# Type coverage (declared types over the src set) — 2G, it thrashes for minutes at 1G
composer test:types
```

`tools/coverage-floors.php` is the gate: it sums `coveredstatements`/`statements` per
`php/<pkg>/src/` out of the clover report and fails any package under its floor.

### Why the coverage job excludes the `fixture` group

The inference engine's real analysis (`PhpStanTypeEngine`, `ThrowAnalyzer`, the
`Runtime/V2_2` adapter, the `Trace` tracer) runs **inside a separate PHP subprocess**
(`php/inference-phpstan/tests/Support/FixtureRunner` → `bin/engine-runner.php`),
against the provisioned Laravel + Larastan fixture app. pcov instruments only the parent
Pest process, so **that subprocess execution is invisible to line coverage regardless of
whether the fixture group runs**. Confirmed empirically: including vs excluding the
`fixture` group moves overall coverage by <0.1 pp (79.53% → 79.45%).

Consequences:

- The `fixture` group is the **behavioural proof** for the inference engine's real path
  (return types, throw analysis, QB trace, determinism). It is *not* a line-coverage
  contributor. Do not read `inference-phpstan`'s ~35% line figure as "untested" — read it
  as "mostly proven out-of-process".
- The CI **coverage** job therefore runs `--exclude-group=fixture` (fast, no app to
  provision) and the separate **fixture** job keeps proving the engine behaviourally.
- Improving `inference-phpstan`'s *measurable* line coverage means adding **in-process**
  unit tests for its pure classes (translators, registries, config objects) — not more
  subprocess fixture tests.

## Measured coverage (2026-08-15)

Line coverage (statements) over the suite excluding the `fixture` group. These are the numbers the
floors are set from — measure, then set the floor to the measured integer.

| Package             | Measured   | Floor | Why                                              |
|---------------------|------------|-------|--------------------------------------------------|
| `core`              | **94.81%** | 94    | fully in-process-measurable                      |
| `laravel`           | **92.70%** | 92    | fully in-process-measurable                      |
| `inference-phpstan` | **37.90%** | 37    | real path is subprocess-only → `fixture`-proven  |
| `attributes`        | —          | —     | dep-free attribute classes, not in `<source>`    |
| Overall             | 86.01%     | —     | informational only; no longer a gate             |

`inference-phpstan`'s floor dropped from 41 to 37 in the same change set that moved the phpdoc type
grammar into core. Those four classes are fully unit-tested in-process (141/161 statements, 87.6%) and
sat well above the engine's average, so taking them out of the numerator AND denominator lowered the
ratio without losing a single test: 41.64% over the pre-move file set is the old 41.83% figure. Core
absorbed them at a slightly lower rate than its own average (92.39% → 92.21%), which is why its floor
holds at 92 rather than ratcheting. A floor drop is only ever this: a documented denominator change.

`inference-phpstan` then ratcheted 37 → 38, and the arithmetic is worth recording because both halves
moved at once. The response refiner gained a 61-statement construction-site descent (reading which
constructor arguments a payload object was built with) that is Scope-, reflection- and
file-analysis-driven, so pcov cannot observe a line of it — on its own that took the package to
36.68% (690/1881), below the floor. The fix was **not** to lower the floor but to close a real gap the
standards already required: `NativeTypeMapper`, the reflection type table behind `classMetadata()`'s
property types, had **zero** in-process coverage despite being a lookup table. Its dataset test lands
+27 covered, taking the package to 38.12% (717/1881) — genuinely higher than before the refiner work.
That test also pinned a surprise worth keeping: PHP resolves `self`/`parent` to real FQCNs before
reflection reports them, so only `static` ever reaches the mapper as a relative name.

It then ratcheted 38 → 41 on the same move again: `ClassMetadataFactory` learned to type a promoted
constructor property from the constructor's `@param` tag, which grew the class by 24 statements and — with
a real-reflection dataset test over an autoloaded spatie-shaped probe — took it from **zero** in-process
coverage to 66/66. So 717/1881 (38.12%) became 783/1905 (41.10%). `core` ratcheted 92 → 93 in the same
change: the shared-error transformer shed its example-reconciliation methods and picked up its
malformed-document negative paths (97/97), and the docblock reader's new `@param`/`@var` readers landed
fully covered (64/64).

It then ratcheted 41 → 42, and this one is the pattern to copy when the engine gains work: instead of
funding new subprocess-only statements from somewhere else, *move the measurable part out*. The response
refiner's `Content-Type` re-label read — match the returned variable, find its last assignment, accept
only header writes between that and the return — needs php-parser and file positions but no PHPStan
`Scope`, so it left the refiner for `ContentTypeLabel` and is now driven in-process by parsing snippets
(the position window is the mechanism, so hand-built nodes would prove nothing). That took ~20 statements
out of the invisible half and put ~25 covered ones back, and the fully-covered `SensitiveConstant` name
predicate added more: 783/1905 (41.10%) → 824/1929 (42.72%).

`core` then ratcheted 93 → 94. Most of that headroom arrived with the worker-pool/result-cache deletion,
which moved the result model's serialization contract from an incidental proof to a direct one; the
type-grammar fix (docblock enums answering `EnumT`, the `array<K, V>` key rule, the analyser-prefixed
`@var`/`@param`/`@property` tags) then added 15 statements and covered all 15, taking 4512/4786 (94.27%) to
4527/4801 (94.29%). A dataset over every key identifier the grammar can produce, plus the unresolvable-name
and unaccepted-tag degradations, is what makes that a real 15 rather than a lucky one.

`inference-phpstan` then ratcheted 34 → 36, and it is the same preferred move: the work landed in the
package's measurable half. `ClassMetadataFactory` learned to fall back to a promoted property's own `@var`
and to parameterise a generic-blind class type from a docblock, and both rules are native-reflection plus
docblock parsing — no `Scope`, so the real-reflection probe drives every branch in process, the three
refuse-to-refine ones included. 538/1557 (34.55%) became 575/1594 (36.07%) — a net 37 statements, all of
them covered, with the duplicated `EnumCases` helper (now core's `EnumReflection::names()`) leaving the
covered half in the same move.

`laravel` then ratcheted 91 → 92. The request side stopped throwing away a recovered container type at the
validation-rule boundary: a `list<V>` synthesises the `key.*` item field, an `array{…}` shape a
`key.<member>` field per key, and an `array<string, V>` — which Laravel's vocabulary has no rule for —
carries its value schema on an `additional_properties` rule. That is exactly the shape the standards ask
for: a dataset over every container kind and every nesting combination, plus the degradations (an
unusable element type, a positional shape, the depth stop, and no converter at all), and a second dataset
over the rule-set normaliser's two cross-field passes. 5312/5769 (92.08%) became 5422/5879 (92.23%).

The list-vs-map key rule then moved out of `inference-phpstan` entirely — the translator and the docblock
grammar carried byte-identical private copies, and core's `ArrayKey` is now the single implementation both
call. That took a well-covered 7/8 block out of the engine (575/1594, 36.07% → 568/1586, 35.81%) and
straight below its floor, which is the shape of a denominator change and NOT a reason to lower one. The
answer was the usual one: `EngineConfig` and `RuntimeConfig` — pure, parent-process value objects whose
only callers are subprocess-only, so nothing else in the suite could reach them — had 1/9 between them
and now have 9/9. 576/1586 (36.32%), genuinely higher than before the move. `core` absorbed the rule at
its own rate or better (94.29% → 94.49%); no floor changed.

The shared-error hoist then became two independent passes — the body SHAPE into `components.schemas`, so
a presentational difference cannot split it, and the whole RESPONSE into `components.responses` where
operations state it identically — and both measurable packages rose without a floor moving: `core`
4527/4801 (94.29%) → 4753/5021 (94.66%), `laravel` 5422/5879 (92.23%) → 5414/5858 (92.42%). The
transformer roughly doubled in size and every branch of it is driven from the unit suite, negative paths
included: the malformed media type, the non-numeric status, the response stating both a `$ref` and a
body, a body already pointing elsewhere, the numeric fallback when something already holds a
discriminated name, and the two shapes one *inline*-schema identity would have collapsed. Both measured
figures still round DOWN to the floors already in place, so neither ratchets: a floor is the measured
integer, and 94.66 and 92.42 are still 94 and 92.

`inference-phpstan` then ratcheted 36 → 37, and it is the "move the measurable part out" pattern again. Fixing the
response refiner's memo — an entry may only be served to a caller with the depth and file budget to have computed it
itself, or a route's body depends on which unrelated route ran first — grew the refiner by bound arithmetic that
PHPStan's `Scope` never touches. So that arithmetic left the refiner for `DescentBudget`: what the analysis in
flight has spent, what each memoised shape cost, and whether the caller can afford it. It is pure, so a unit suite
drives every branch in process — the depth bound, the free revisit, the drain contracts, a memoised "nothing
recoverable" told apart from a miss, both refusal paths, and a nested descent costed to its parent with replayed
levels included. 576/1586 (36.32%) became 622/1641 (37.90%): 55 new statements in the package, 46 of them covered.

Route-feature recovery — the column a `{post:slug}` binding names, and the catch-all route that is
reported rather than published — then took `core` 4753/5021 (94.66%) → 4805/5068 (94.81%) and `laravel`
5414/5858 (92.42%) → 5548/5985 (92.70%). Both are almost entirely degradation paths, which is the point:
the column typer's dataset covers every scalar it accepts AND every shape it refuses (a class, an enum, an
`array` cast, a two-scalar union, a column no source mentions, a class that is not a model, a class that
does not exist), and the chain that drives it is unit-tested for a resolver that cannot answer the column
question at all. Neither figure ratchets a floor — 94.81 and 92.70 are still 94 and 92.

`inference-phpstan`'s figure is **not** comparable to the others and must not be read as
"untested": its real analysis runs out-of-process where pcov cannot see it (see above), and the
`fixture` group is its behavioural proof. Raising that number means adding **in-process** unit tests
for its pure/parent-process classes, never more subprocess fixture tests — the ratchet above is
exactly that move, and it is the preferred answer whenever a subprocess-only subsystem lands.

## The CI coverage gate & ratchet policy

- The gate lives in `.github/workflows/ci.yml` as a dedicated **coverage** job: a clover report
  under **pcov** (via `setup-php`) plus `php tools/coverage-floors.php`, which enforces a floor
  **per package**. `composer test:coverage` runs the same two steps locally.
- Each floor is an **honest floor** — the measured-now percentage rounded DOWN to an integer, never
  an aspiration. Current floors: `core` **94**, `laravel` **92**, `inference-phpstan` **37**.
- **Why per-package rather than one global `--min`:** the engine package's real path is
  subprocess-only and invisible to pcov, so every engine feature it gained *diluted the global
  ratio even while genuine in-process coverage rose*. A single global gate therefore sat one engine
  refactor away from red for reasons unrelated to test quality — one engine arc added ~700 such
  lines and pushed measured 83.2% against a floor of 83. Per-package floors localise that: the
  engine carries its own low, honest floor with the fixture-group note, and the fully-measurable
  packages carry high ones that a real regression genuinely trips.
- **Type coverage** is enforced separately at 100%: `composer test:types` (the src set is
  PHPStan level-max, which already implies near-total declared types).
- **Ratchet policy:**
  - When a package's coverage rises, **raise that package's floor** in
    `tools/coverage-floors.php` to the new measured integer in the same PR. Each floor is a
    monotonic ratchet.
  - **Never lower a floor** without a written justification in the pull request that lowers it (e.g.
    a large subprocess-only subsystem landed in that package, or well-covered classes MOVED to another
    package and took the numerator with them). A drop is a reviewed decision, not a quiet CI edit —
    record the arithmetic, as the 41 → 37 entry above does.
  - Ratchet the floors upward in the same change set as the code and tests that raised them.
