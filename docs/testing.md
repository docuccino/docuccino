# Testing & coverage standards

This document captures the test-coverage standards enforced from Phase 4 onward
(mirroring the "Test-coverage standards" section of `docs/plan.md`), plus how to run
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

## Running coverage locally

Line coverage needs a coverage driver. The project uses **pcov** (fast, statement-level):

```bash
# One-time: install pcov (macOS/Homebrew PHP needs the pcre2 headers on the include path)
CPPFLAGS="-I$(brew --prefix pcre2)/include" pecl install pcov

# Overall line coverage + the enforced minimum (this is what CI runs)
vendor/bin/pest --coverage --exclude-group=fixture --min=78

# Full text report (per-class line %), written for inspection
vendor/bin/pest --coverage-text=build/coverage.txt --exclude-group=fixture

# Type coverage (declared types over the src set)
vendor/bin/pest --type-coverage --exclude-group=fixture --min=100
```

Per-package numbers are computed from a clover report:

```bash
vendor/bin/pest --coverage-clover=build/clover.xml --exclude-group=fixture
# then sum coveredstatements/statements per packages/<pkg>/src/ path
```

### Why the coverage job excludes the `fixture` group

The inference engine's real analysis (`PhpStanTypeEngine`, `ThrowAnalyzer`, the
`Runtime/V2_2` adapter, the `Trace` tracer) runs **inside a separate PHP subprocess**
(`packages/inference-phpstan/tests/Support/FixtureRunner` → `bin/engine-runner.php`),
against the provisioned Laravel + Larastan fixture app. pcov instruments only the parent
Pest process, so **that subprocess execution is invisible to line coverage regardless of
whether the fixture group runs**. Confirmed empirically: including vs excluding the
`fixture` group moves overall coverage by <0.1 pp (79.53% → 79.45%).

Consequences:

- The `fixture` group is the **behavioural proof** for the inference engine's real path
  (return types, throw analysis, QB trace, determinism). It is *not* a line-coverage
  contributor. Do not read `inference-phpstan`'s ~41% line figure as "untested" — read it
  as "mostly proven out-of-process".
- The CI **coverage** job therefore runs `--exclude-group=fixture` (fast, no app to
  provision) and the separate **fixture** job keeps proving the engine behaviourally.
- Improving `inference-phpstan`'s *measurable* line coverage means adding **in-process**
  unit tests for its pure/parent-process classes (translators, registries, protocol,
  orchestration bookkeeping) — not more subprocess fixture tests.

## Measured coverage (baseline, 2026-08-02, this hardening task)

Line coverage, per package (statements), suite excluding the `fixture` group:

| Package             | Line coverage      |
|---------------------|--------------------|
| `core`              | ~91%               |
| `laravel`           | ~84%               |
| `inference-phpstan` | ~41% (subprocess — see above) |
| **Overall**         | **79.45%**         |

`attributes` is dep-free attribute classes with no branching logic and is not in the
coverage `<source>` set.

## The CI coverage gate & ratchet policy

- The gate lives in `.github/workflows/ci.yml` as a dedicated **coverage** job:
  `pest --coverage --exclude-group=fixture --min=<N>` with **pcov** via `setup-php`.
- `<N>` is an **honest floor** — the measured overall minus a small buffer, never an
  aspiration. Current floor: **`--min=78`** (measured 79.45%).
- **Type coverage** is enforced separately: `pest --type-coverage --min=100` (the src set
  is PHPStan level-max, which already implies near-total declared types).
- **Ratchet policy:**
  - When overall coverage rises (e.g. a phase adds in-process tests), **raise `--min`** to
    just under the new measured number in the same PR. Coverage is a monotonic floor.
  - **Never lower `--min`** without a note in `docs/plan.md` explaining why (e.g. a large
    subprocess-only subsystem was added). A drop is a plan-level decision, not a quiet CI
    edit.
  - Each build phase should ratchet the floor upward as its code lands with tests.
