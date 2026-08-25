# Testing & coverage standards

This document is the authority on the project's test-coverage standards, plus how to run
coverage locally and the ratchet policy for the CI gate.

## Standards

- **Mapping / lookup tables** (rule maps, attribute maps, cast → format tables,
  `KnownThrowers`, enum naming strategies, the phpdoc type-grammar table) are tested
  **dataset-driven over EVERY entry, plus the unknown-entry degradation contract**. One
  tested entry in a table is not coverage. When an entry turns out to be unreachable
  (e.g. a `match` label that can never be selected), it is deleted, not tested.
- **A dataset only proves the rows it LISTS.** Which makes a hand-maintained "full set" — an
  attribute catalogue, a reference page, an allow-list — a second thing to prove, not a proof in
  itself: it needs a guard that reads the **source of truth** (the directory, the config file, the
  code that emits) and fails when the list is short. An attribute shipped with no catalogue entry
  and the whole suite stayed green, because nothing was asking what the package ships.
  `AttributesTest`'s catalogue guard, `ConfigReferenceSyncTest` and `DiagnosticsReferenceTest` are
  the shape: derive one side, compare, and name what appears on only one.
- **Stub / real splits.** `StubTypeEngine` tests prove *pipeline mechanics* only; every
  integration's recovery/parsing half also needs a **real-path** test (real reflection,
  or the real engine via the `fixture` group). Ask of every test: which half does this
  prove, and where is the other half proven?
- **An assertion that cannot fail proves nothing.** `expect($response['content']['schema'] ?? [])`
  passes whether or not the key is there, and a test that hand-builds a value the real recovery
  path cannot produce pins a shape the product cannot reach. Ask of every pin: if the feature
  were absent entirely, would this still be green? Assert that the key is present, not only what
  is inside it, and build fixtures through the real path rather than constructing them.
- **A scan that finds nothing must FAIL.** A source-scanning test — an arch rule, a reference
  guard, a catalogue check — that silently matches zero things passes forever and proves nothing,
  and it goes quiet at exactly the moment the scanner breaks. Assert a plausible minimum beside the
  real assertion: well under what the tree holds today, so ordinary work never trips it, and far
  enough above zero that a scanner which stopped recognising one of its shapes fails loudly. The
  diagnostics and config-reference guards, the boundary arch tests, and the attribute,
  extension-contract and lint catalogues all carry one.
- **A driver's contract includes what its pinned version renders.** A viewer or emitter driver ships
  a bundle, and what that bundle actually renders or accepts is half of what the driver promises — so
  a test that asserts the markup the driver wrote, or the bytes it handed over, is not a test of the
  contract. A driver shipped serving a document version its own pinned bundle only half-supported,
  and the markup-only test stayed green throughout. Pin what the pinned version does with the
  document it is given, not only what the driver wrapped around it.
- **An emitted artifact answers to an external authority, in EVERY serialisation it ships.** A golden
  pins bytes against a committed copy of themselves and a round trip compares a document to itself:
  neither knows what the format's own spec says, so both stay green on output no consumer can read.
  Every format therefore validates against its **vendored publisher meta-schema** — byte-exact as the
  publisher serves it, at a dated (immutable) URI, offline, with the file's own declared id pinned in
  a test so a rename cannot quietly swap it — and the guard covers each serialisation separately.
  `--yaml` shipped `paths: []` for a `paths` that is an empty MAP, through goldens, round trips and a
  `Validator` run, because the ONE assertion nobody had written was "does the YAML answer to the
  OpenAPI schema". `OpenApiMetaSchema` is the shape; `Formats` is the source of truth the vendored set
  is checked against, so a new emitted version cannot land without one. This is a standard about the
  **packages' emitted artifacts** — an OpenAPI document, a Postman collection — and not about every
  file the repo happens to write: the website's `sitemap.xml` and `robots.txt` fall under its letter
  with no oracle intended.
- **Know what a disabled validator option really disabled.** An option turned off to dodge one defect
  takes everything else it governed with it, and the docblock tends to record only the half that
  prompted it. `allowUnevaluated => false` reads as "stops asserting no unrecognised member"; it also
  disables every `patternProperties` **key gate** in 3.1 and 3.2, because those key sets are enforced
  only through `unevaluatedProperties: false` — 28 sites in each. So a `paths` key not starting with
  `/`, a response keyed `twohundred` and a misspelled `info.versionn` all validated clean, while 3.0
  (which has no such keyword) rejected every one. Measure the real scope, record it at the call site,
  and recover what can be recovered rather than accepting the whole loss: the three key gates are now
  walked back on directly from patterns READ OUT of the vendored file
  (`OpenApiMetaSchema::keyGateFindings()`), which needs no validator at all.
  `OpenApiUnevaluatedScopeTest` is that scope as a **measured matrix** — every row derived by mutating
  a document all three versions accept, including the rows that still pass, because pinned blindness
  stops being a surprise and a future weakening shows up as a row flipping.
- **A rule the schema cannot express still needs asserting.** JSON Schema cannot state uniqueness
  across positions, so a duplicate `operationId` — which the spec requires unique across the whole API
  — validates clean at every version while a generated client silently loses a method to the
  collision. Where the authority structurally cannot carry a rule, the suite carries it
  (`OpenApiMetaSchema::operationIdFindings()`), with a floor proving the documents it walks actually
  carry the thing being checked.
- **A round trip through a lossy reader proves nothing about what it cannot see.** `Yaml::parse()`
  answers a PHP array for a mapping AND for a sequence, so every YAML assertion in the suite was blind
  to map-vs-sequence — the exact axis the bug was on. When a reader collapses a distinction the format
  carries, either read with kind preserved (`PARSE_OBJECT_FOR_MAP`) or compare against the
  serialisation that does; `EmittedDocument` does both. Be precise about what that comparison is worth:
  it catches **YAML/JSON divergence** at positions the meta-schemas leave unconstrained (a Schema
  Object's members, in 3.1 and 3.2) — and only divergence. A wrong scalar, a misspelled key, a missing
  required member and an unknown keyword are identical on both sides, so they pass it; the meta-schema
  is what judges those, and where it declines to (inside a Schema Object) nothing does.
- **Absence and a null value are not the same reading.** A comparison that reaches for a member with
  `?? null` cannot tell "this side dropped it" from "this side wrote null", and documents really do
  emit null — `postman-surface` does, at three `closedAt` positions. Deleting a member there read as
  agreement and the oracle reported no differences, at exactly the unconstrained positions it exists to
  cover. Ask presence with `property_exists()`/`array_key_exists()` and report the two cases apart.
- **A difference that exists only in the BYTES survives every oracle that parses first.** Both guards
  above — the meta-schema validation and the kind comparison — read a parsed graph, so anything the
  parse normalises away is invisible to them by construction. The known instance is **mapping-key
  quoting**: every `responses` map is keyed by status code, and `json_decode`, `Yaml::parse` and
  `get_object_vars` all answer `int(200)` for that key, quoted or not. So YAML emitted `200:` where
  JSON emitted `"200":` in every document the product had ever written, and the two oracles compared
  int to int and agreed. The parse boundary is where this class hides; anyone adding an oracle should
  assume a graph comparison cannot see it, and pin such a fix with an assertion over the raw bytes
  rather than letting a golden diff stand as the only record.
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

# Type coverage (declared types over the src set)
composer test:types
```

`tools/coverage-floors.php` is the gate: it sums `coveredstatements`/`statements` per
`php/<pkg>/src/` out of the clover report and fails any package under its floor.

### The cold type-coverage hang, and why the script passes a grpc flag

A COLD type-coverage run — one with an empty `vendor/pestphp/pest-plugin-type-coverage/.temp` —
used to hang indefinitely on some machines with every process pinned at 0% CPU. It is not memory
and it is not pcov, so raising limits and clearing caches both do nothing. The mechanism:

- the plugin only forks on the cold path. Warm, every file is a cache hit, `analyseChunks()` is
  never reached, and nothing forks — which is why the hang looked like a cache problem.
- cold, it fans the files out over `pcntl_fork()` children (via `nunomaduro/pokio`).
- `fork()` copies one thread. An extension that runs background threads therefore wakes up in
  the child believing in threads that no longer exist. `grpc` is one: its module shutdown waits
  on a condition variable those threads would have signalled, so the child never finishes
  exiting, and the parent blocks forever in `pcntl_waitpid()`.

Reproducible with no Pest involved at all — a bare `fork()` plus `exit(0)` child never gets
reaped when the `grpc` extension is loaded with its default `grpc.enable_fork_support=0`:

```bash
php -r '$p=pcntl_fork(); if($p===0){exit(0);} pcntl_waitpid($p,$s); echo "reaped\n";'
```

So `composer test:types` runs pest under `-d grpc.enable_fork_support=1`, which is grpc's own
supported answer for processes that fork. It costs nothing where grpc is absent (PHP ignores an
unknown `-d` directive, and CI has no grpc), and it takes the cold run from "never finishes" to
about five seconds. Any other command that forks under an extension like this owes the same flag.

`config.process-timeout` is raised to 1800 in the root `composer.json` as headroom for slow first
runs after a clean checkout; it was never what rescued a hang, since the hang had no timeout.

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

## Measured coverage (2026-08-19)

Line coverage (statements) over the suite excluding the `fixture` group. These are the numbers the
floors are set from — measure, then set the floor to the measured integer.

| Package             | Measured   | Floor | Why                                              |
|---------------------|------------|-------|--------------------------------------------------|
| `core`              | **96.37%** | 95    | fully in-process-measurable                      |
| `laravel`           | **94.70%** | 94    | fully in-process-measurable                      |
| `inference-phpstan` | **43.77%** | 40    | real path is subprocess-only → `fixture`-proven  |
| `attributes`        | —          | —     | dep-free attribute classes, not in `<source>`    |
| Overall             | 90.48%     | —     | informational only; no longer a gate             |

Every ratchet UP follows one of two shapes, and both are worth aiming for deliberately rather than
waiting for. Either **the work landed in the measurable half** — a rule driven by native reflection,
docblock parsing or php-parser positions, which the in-process suites reach — or **the measurable part
was moved out** of a subprocess-only class into one of its own, which is the preferred answer whenever
the engine gains work (`DescentBudget`, `ContentTypeLabel`, `SourceOrder`, core's `ArrayKey` all came
out that way). The per-ratchet arithmetic lives in git history; what has to be recorded here is the
other direction.

**A floor drop is only ever a documented denominator change**, and there have been two.

`inference-phpstan` dropped 41 → 37 in the change set that moved the phpdoc type grammar into core.
Those four classes are fully unit-tested in-process (141/161 statements, 87.6%) and sat well above the
engine's average, so taking them out of the numerator AND denominator lowered the ratio without losing
a single test: 41.64% over the pre-move file set is the old 41.83% figure. Core absorbed them at a
slightly lower rate than its own average (92.39% → 92.21%), which is why its floor held at 92 rather
than ratcheting.

`inference-phpstan` dropped 43 → 34 when the worker pool and the engine result cache were deleted. Both
were in the measurable half, unit-tested in process at near-full coverage, so removing them took ~300
well-covered statements out of the numerator and left a remainder proven out-of-process: 889/2033
(43.73%) became 538/1557 (34.55%) without losing a proof. Core rose in the same change, the result
model's serialization contract going from an incidental proof to a direct one.

Two moves are worth keeping as precedent because the denominator went the *other* way and the answer
was NOT to lower a floor. When the response refiner gained a 61-statement, Scope-driven construction-site
descent that pcov cannot observe, the package fell to 36.68% — and the fix was closing a real gap the
standards already required, giving `NativeTypeMapper` (a lookup table with zero in-process coverage) its
dataset test. When core's `ArrayKey` absorbed the list-vs-map key rule from the engine's two byte-identical
private copies, a well-covered 7/8 block left the engine and took it below its floor — and the answer was
`EngineConfig`/`RuntimeConfig`, pure parent-process value objects at 1/9 between them, going to 9/9.

`inference-phpstan`'s figure is **not** comparable to the others and must not be read as
"untested": its real analysis runs out-of-process where pcov cannot see it (see above), and the
`fixture` group is its behavioural proof. Raising that number means adding **in-process** unit tests
for its pure/parent-process classes, never more subprocess fixture tests.

## The CI coverage gate & ratchet policy

- The gate lives in `.github/workflows/ci.yml` as a dedicated **coverage** job: a clover report
  under **pcov** (via `setup-php`) plus `php tools/coverage-floors.php`, which enforces a floor
  **per package**. `composer test:coverage` runs the same two steps locally.
- Each floor is an **honest floor** — the measured-now percentage rounded DOWN to an integer, never
  an aspiration. Current floors: `core` **95**, `laravel` **94**, `inference-phpstan` **40**.
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
