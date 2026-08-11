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
  contributor. Do not read `inference-phpstan`'s ~37% line figure as "untested" — read it
  as "mostly proven out-of-process".
- The CI **coverage** job therefore runs `--exclude-group=fixture` (fast, no app to
  provision) and the separate **fixture** job keeps proving the engine behaviourally.
- Improving `inference-phpstan`'s *measurable* line coverage means adding **in-process**
  unit tests for its pure/parent-process classes (translators, registries, protocol,
  orchestration bookkeeping) — not more subprocess fixture tests.

## Measured coverage (2026-08-09)

Line coverage (statements) over the suite excluding the `fixture` group. These are the numbers the
floors are set from — measure, then set the floor to the measured integer.

| Package             | Measured   | Floor | Why                                              |
|---------------------|------------|-------|--------------------------------------------------|
| `core`              | **92.21%** | 92    | fully in-process-measurable                      |
| `laravel`           | **91.90%** | 91    | fully in-process-measurable                      |
| `inference-phpstan` | **37.35%** | 37    | real path is subprocess-only → `fixture`-proven  |
| `attributes`        | —          | —     | dep-free attribute classes, not in `<source>`    |
| Overall             | 82.29%     | —     | informational only; no longer a gate             |

`inference-phpstan`'s floor dropped from 41 to 37 in the same change set that moved the phpdoc type
grammar into core. Those four classes are fully unit-tested in-process (141/161 statements, 87.6%) and
sat well above the engine's average, so taking them out of the numerator AND denominator lowered the
ratio without losing a single test: 41.64% over the pre-move file set is the old 41.83% figure. Core
absorbed them at a slightly lower rate than its own average (92.39% → 92.21%), which is why its floor
holds at 92 rather than ratcheting. A floor drop is only ever this: a documented denominator change.

`inference-phpstan`'s figure is **not** comparable to the others and must not be read as
"untested": its real analysis runs out-of-process where pcov cannot see it (see above), and the
`fixture` group is its behavioural proof. Raising that number means adding **in-process** unit tests
for its pure/parent-process classes, never more subprocess fixture tests.

## The CI coverage gate & ratchet policy

- The gate lives in `.github/workflows/ci.yml` as a dedicated **coverage** job: a clover report
  under **pcov** (via `setup-php`) plus `php tools/coverage-floors.php`, which enforces a floor
  **per package**. `composer test:coverage` runs the same two steps locally.
- Each floor is an **honest floor** — the measured-now percentage rounded DOWN to an integer, never
  an aspiration. Current floors: `core` **92**, `laravel` **91**, `inference-phpstan` **37**.
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
