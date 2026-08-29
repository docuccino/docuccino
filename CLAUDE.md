# Docuccino — Monorepo Guide

Docuccino is an open-source (MIT) API documentation generator for Laravel that compiles
application code into a **UIR** (Universal Intermediate Representation — an OAS-3.2-shaped,
deterministic, identity-carrying JSON document) and emits OpenAPI 3.2/3.1, with semantic
diffing and a bundled Scalar viewer.

> Full references — read these before substantial work:
> - [`docs/design/uir-and-extensions.md`](./docs/design/uir-and-extensions.md) — UIR spec detail, extension API, precedence, **placement rule**
> - [`docs/design/inference-embedding.md`](./docs/design/inference-embedding.md) — PHPStan/Larastan engine design + spike-verified traps
> - [`docs/design/defect-classes.md`](./docs/design/defect-classes.md) — patterns hit more than once, and the test that recognises each
> - [`docs/testing.md`](./docs/testing.md) — coverage standards, how to run coverage, ratchet policy
> - [`website/STYLE.md`](./website/STYLE.md) — binding style bar for the docs site (Laravel docs are the gold standard)
> - [`RELEASING.md`](./RELEASING.md) — tagging, the subtree split, `SPLIT_TOKEN`

## What we optimise for

**Best out of the box.** A correct document with no configuration is the product. An option is an
admission we could not work it out — before adding one, ask what would have to be true for the
default to be right, and do that instead where it is sound. A knob most users must turn is a bug
with a workaround attached.

**Two audiences, both first-class.** The DX of the package's USER — the developer running the
generator — and the DX of the CONSUMER of its output — whoever reads the document, or catches an
error in a client generated from it — are separate, and they pull apart. The author wants a default
they never touch, a name they *can* override, and a diagnostic naming where to go and what to change.
The consumer cannot see the codebase: they want a type they can name in a `catch`, and a contract
that does not lie. Most of the rules below are one of those two audiences made specific; when a
change serves one at the other's expense, say so out loud rather than letting it pass as a tidy-up.

**One application's report, every application's fix.** A report is evidence, not a specification —
it is one application noticing a defect the product has for everyone. So the fix is written in the
product's own vocabulary and sized to the defect, never to the reporter: state it without naming the
application that asked, and a second application should recognise its own problem in that sentence.
If the description needs "because their app does X", the shape is wrong, and the honest move is to
find the general fact behind X and fix that.

The other half is the same rule looking outward: **one instance means a class, so sweep for the
rest.** Fixing what was reported and stopping there leaves siblings that will each arrive as their
own report. Every large finding in this codebase has been a class rather than an instance — an empty
object mistaken for an empty array in six separate readers, a key name used as a position in four
members plus the test that guarded them, a diagnostic hardened in one field with its sibling left
raw in four places. When a fix lands, say what the class is and what else you checked; a fix with no
sweep beside it is a report closed, not a defect removed.

And size a mechanism to what has been measured, not to what could be true. An argument from a
standard or a limit is a reason to *look*, and the count is the reason to build: a guard with zero
observed firings, sized from a bound nothing in the corpus approaches, is speculation with a
maintenance cost — and worse, its own failure mode is usually newer and less understood than the one
it prevents.

## ⚠️ Absolute rules

- **Green on all checks, always**: `composer test` (incl. `composer test:inference-fixture`
  when the fixture app is present), `composer analyse` (PHPStan level max, NO baselines, no
  blanket ignores), `composer lint`, `composer validate --strict` (all packages).
  CI also gates line coverage **per package** (`composer test:coverage` →
  `tools/coverage-floors.php`; honest measured-now floors, ratchet up, never down) and type
  coverage (`composer test:types`, 100%). **Use the composer scripts** — they carry the flags
  the gates need (`--parallel`, the two 2G limits, and the grpc fork guard below). Every check step
  in CI is one of these scripts — no raw `vendor/bin/…` lines — so the two cannot drift; a step that
  spells the flags out again IS the drift. Both 2G are real: `analyse` runs PHPStan in a process of
  its own, and `--memory-limit` on `pest --type-coverage` is the type-coverage plugin's own flag,
  which it applies with `ini_set` before analysing — `phpunit.xml`'s `<ini name="memory_limit">` governs the
  phpunit run and never reaches that path. A COLD type-coverage run dies inside PHPStan at PHP's
  128M default and passes at 256M, so the 2G is headroom rather than decoration. Memory is still
  not the lever a hang looks like: a run that appears to hang is never short of it.
  **Leave the type-coverage cache alone**, and do not blame pcov. A cold run — an empty
  `vendor/pestphp/pest-plugin-type-coverage/.temp` — forks worker processes, and `fork()` is
  unsafe under an extension that runs background threads: with `grpc` loaded the children
  deadlock in module shutdown and the parent waits on them forever, at 0% CPU, with no timeout.
  `composer test:types` therefore runs pest under `-d grpc.enable_fork_support=1`, which is inert
  where grpc is absent and turns "never finishes" into ~5s (mechanism and repro in
  [`docs/testing.md`](./docs/testing.md)). Clear the cache for one reason only — a `ParseError`
  inside that `.temp/`, which the plugin causes by writing the file non-atomically and is never
  your code — then re-run. CI does the same: run, and only on failure clear and retry once.
  **Local green is not CI green**: `composer analyse` runs the PHPStan the lockfile resolves, and
  the quality matrix also runs a leg pinned to an older PHPStan minor plus a `--prefer-lowest` leg
  (both named in `.github/workflows/ci.yml`), which are stricter in places. The one that bites is an
  **unsealed array shape** — `@param array{a: string, b: ?int, ...}` — accepted by the resolved
  PHPStan and rejected by the pinned minor as `missingType.iterableValue`. Name every key, and
  where one shape is described in more than one place declare it ONCE as a `@phpstan-type` alias on
  the class and refer to that (`DocumentDiffer`, `ComponentNames`, `ModelSchema`). Reproduce recipe,
  lockfile restore included, in [`CONTRIBUTING.md`](./CONTRIBUTING.md).
- **Determinism is a product feature**: byte-identical output for identical code. No
  timestamps, no absolute paths, no randomness in any emitted document. Determinism is
  necessary but not sufficient — output must also be **local**: adding, removing, renaming or
  reordering one part of an application must never change the emitted representation of an
  unrelated part, and a **warm build must equal a cold one**, bytes and diagnostics both. A
  name, an id or a `$ref` that depends on encounter order satisfies determinism and still
  silently changes what a generated client means. Golden files under
  `php/*/tests/Fixtures/golden/` are byte-locked — never regenerate casually; a
  sanctioned regeneration is an ISOLATED commit explaining exactly why bytes changed.
  (`DOCUCCINO_UPDATE_GOLDEN=1` regenerates locally; CI guards it is unset.)
- **A degraded answer must still be true**: prefer a valid vague schema over a precise false
  one. An unconstrained-but-honest shape costs a client some type safety; a confidently wrong
  one costs them a runtime failure. When recovery is partial, widen rather than guess — and say
  so with a diagnostic rather than degrading quietly.
- **A closed set owes the document an enum**: any value domain the application enforces at runtime
  is a closed set — an allow-list (Query Builder's filters, sorts, includes and fields), an enum
  cast, a validation `in:` rule, an implicit enum route binding, a `whereIn` — and the document says
  so or it is under-describing what the server will accept. Publishing `type: string` where the set
  is known costs the consumer type safety and leaves their generated client unable to enumerate the
  options; publishing an enum NARROWER than what the server accepts is worse, because it marks a
  working request invalid. So a published enum must equal the validation surface, and where recovery
  of that surface is partial — one entry of a rule whose values could not be read, one allow-list
  member behind an expression that would not fold — widen the whole parameter to a string with a
  diagnostic rather than publish the short enum.
- **Conventional commits** (`feat(laravel): …`, `fix(core): …`), NO Co-Authored-By trailers.
  Merges are **squash-only and the PR title is the message that lands**, so the title is gated
  (`.github/workflows/pr-title.yml` → `tools/pr-title-lint.php`): a conventional type, an optional
  `!` that must be paired with a `BREAKING CHANGE:` body footer (both halves, or neither), and a
  scope from the allow-list in `tools/conventional-commit.php` — `core`, `attributes`, `laravel`,
  `inference-phpstan` are packages, `repo`, `website`, `ci` map to none. The changelogs are
  **generated** from those messages (`composer changelog`, and automatically on every push to
  `main`): never hand-edit `php/*/CHANGELOG.md` or `website/src/content/docs/changelog.md`, fix the
  commit message instead. The one exception is a version already RELEASED whose commit record was
  destroyed and so cannot be fixed — `tools/changelog-supplements.php`, refused for the pending
  range and guarded against going stale ([`RELEASING.md`](./RELEASING.md)). Release flow in [`RELEASING.md`](./RELEASING.md).

## Monorepo layout

```
php/core/               docuccino/core        — UIR model, canonicalizer, identities,
                                                     drafts+PatchGuard, emitters, diff+policies,
                                                     overlays, Lint, TypeGrammar (phpdoc/type
                                                     string readers), extension contracts.
                                                     Framework-agnostic (arch-test enforced: no
                                                     Illuminate/engine imports, and no PHPStan
                                                     but the standalone PhpDocParser).
php/attributes/         docuccino/attributes  — dep-free PHP attributes only.
php/inference-phpstan/  docuccino/inference-phpstan — PHPStan+Larastan engine behind
                                                     core's TypeEngine/TypeEngineBuilder contracts.
                                                     In-process, one container per build.
                                                     DEV-ONLY install.
php/laravel/            docuccino/laravel     — adapter: provider, late-bound registry,
                                                     pipeline, commands, viewer, Integrations/.
spec/uir/1.0/schema.json     the UIR JSON Schema (the long-term product).
tests/fixture-app/           the real-engine fixture app: tracked overlay sources in src/,
                             recreate recipe in setup.md, and the provisioned Laravel +
                             Larastan install in app/ (gitignored — recreate per
                             tests/fixture-app/setup.md).
```

## Key conventions (details in the design docs)

- **Placement rule**: input = UIR document → core; input = Laravel code → adapter.
  Framework-neutral machinery in core, framework vocabulary + recovery in the adapter.
- **Integrations**: `php/laravel/src/Integrations/<Name>/` — self-contained, ONE
  `<Name>Integration` registrar via the public Registrar API, `class_exists` conditional
  registration, imports only the public surface (`IntegrationsArchTest` is the definition;
  extend its allow-list only with justification — never duplicate a core utility to dodge it).
- **A vendor package's grammar is a function of the version the app resolved**, not of the version
  the integration was written against. Recover against what `composer.lock` actually installed
  (`Composer\InstalledVersions`), and where the installed major predates the grammar the integration
  implements, degrade to the vague-but-true shape with a diagnostic rather than publish a confident
  claim in another version's dialect. The anti-pattern is an integration that declares no constraint
  on the package it activates on — `class_exists` is a presence check, not a version check — and
  then emits its current major's grammar unconditionally.
- **Public API boundary**: `@internal` marks non-public core, enforced in two halves.
  `IntegrationsArchTest` reads IMPORTS — a built-in integration may consume only the allow-listed
  public surface. `CoreBoundaryArchTest` reads REFLECTION — no public method or property of the
  extension-author surface may take or return an `@internal` type, which an import scan cannot see
  (`$context->converter()->…` imports nothing); its remaining rules are package-direction. The
  extension-author surface freezes at v1.
- **Package direction**: `attributes ← core ← {laravel, inference-phpstan}` — the adapter and the
  engine are SIBLINGS. `docuccino/laravel` must install without an analyser: it names the engine's
  entry class by string (`Engine\EnginePackage`) and degrades to `NullTypeEngine` + one
  `engine.not-installed` warning. `AdapterBoundaryArchTest` / `EngineBoundaryArchTest` enforce both
  directions — note a Pest arch layer can only see PSR-4-autoloaded namespaces, so a phar dependency
  like phpstan/phpstan needs the `importsMatching()` source scan instead. That scan tokenises rather
  than greps `use` lines, because `\PHPStan\Foo::class` names the analyser exactly as an import does;
  a name in a STRING is the one sanctioned exception, and tokenising exempts it for free.
- **Split repo names ≠ package names**: a split repository whose name would be language-generic
  carries a language prefix (`docuccino/php-core` ships the `docuccino/core` package); one already
  naming its language or framework does not (`docuccino/laravel`). Composer package names never
  carry the prefix. GitHub's org is one flat namespace across every language we may add; Packagist
  is PHP-only. Rule and rationale in [`RELEASING.md`](./RELEASING.md).
- **Precedence**: fallback(5) < inference(10) < integration(20) < docblock(30) <
  attribute(40) < overlay(45) < config(50); field-level patch semantics via PatchGuard.
- **A minted name is a function of the thing, never of the order it was met**: any name, id or
  `$ref` the document publishes must be derivable from the set of things contesting it — their
  identities, their content — and never from registration, discovery or route order. A
  first-come counter (`Foo_2`) is the anti-pattern: deterministic per build, and it still
  reassigns meaning when an unrelated route is added. `ComponentNames` owns the invariant;
  every path that mints a name owes it. A published name is read by people AND by code
  generators — it becomes a type name in a generated client — so an opaque discriminator has a
  real cost, and a changed name reads as a changed contract even when only an example moved.
  Where a component is deduped by one key and named from another, the two must agree: dedupe by
  content while naming for a cause, and the name silently becomes a function of which causes
  happened to collide on a body.
- **A diagnostic earns its place by where it fires, not by being right**: before shipping one,
  measure its firing population against a real application and count the hits where the reader
  can act and the hits where they cannot. One that fires mostly where nothing can be done — no
  property to annotate, a shape that was already right — trains people to ignore the channel,
  and takes the useful diagnostics with it. "It would be technically correct" is not the bar;
  state the two counts.
- **A guard must read the same grammar as the thing it guards**: when one reader folds an
  expression and another decides whether folding is safe, the safe-decider must recognise every
  form the folder does. A guard that unwraps fewer expression shapes than the fold it protects
  is a hole, not a conservative default.
- **Validate a report's premise before fixing it**: a report names a symptom and guesses a
  cause, and the guess is often wrong in a way that would make the fix wrong too — a property
  reported as unannotated may carry a constructor `@param`, a stated "expected" output may
  itself be invalid. Reproduce the symptom in this repo's own tests before changing anything;
  if the premise does not hold, say so and fix what is actually broken. When a comparison claims
  another tool does better, check whether the application hand-wrote extensions to make it do
  so — bespoke code on one side is not a default on the other.
- **The emitted document is read by API consumers, not by the app's authors**: descriptions,
  summaries and examples address someone who cannot see the codebase, so they never carry
  tooling advice, attribute names or "pin this with…" guidance. Guidance for the author is a
  diagnostic, which is where they will actually see it.
- **The document is COMPILED, not only read**: an SDK generator and a viewer take it as input, so a
  fact the build proved is owed in machine-readable form wherever prose states it — an ability named
  in a description and carried in `x-permissions` is the shape; a value list spelled out in a
  description with no `enum` beside it, or a pagination envelope described but never componentized,
  is prose no generator can read. And anything minted into the document becomes an identifier in
  somebody's generated client, so it is either identifier-safe or it carries the names that make it
  so (`x-enum-varnames`/`x-enumNames` beside a value like `-total`). A decoration standard adopted
  anywhere is owed everywhere the same kind of fact appears — ONE enum-decoration policy covering
  component enums and parameter enums both, never one per producer.
- **Coverage standards (binding)**: every mapping/lookup table gets a dataset test over
  EVERY entry + unknown-entry degradation; stub-engine tests prove mechanics only — the
  parsing/recovery half needs real-path tests (fixture group). Negative paths are coverage.
  A dataset only proves the rows it LISTS, so a hand-maintained "full set" — a catalogue, a
  reference page, an allow-list — owes a SEPARATE guard that reads the source of truth and fails
  when the list is short; an attribute has shipped uncatalogued with the whole suite green. And a
  scan that matches NOTHING must fail rather than pass: assert a plausible minimum beside the real
  assertion so a scanner that stopped seeing its shapes fails loudly instead of passing forever.
  A guard derived from a SUBSET is silent outside it, and two of them side by side cover their two
  subsets and nothing between — so where guards divide a domain, assert their UNION against the
  domain itself, and let a member owing no answer carry a ROW saying so rather than falling in the
  gap. **Covering is not agreeing**: a guard over what several tables READ proves nothing about
  whether they answer alike, which is how a tightened `maxItems` failed a release gate on the same
  day a tightened `maxContains` passed it. Where one fact is computed at more than one site, the
  sites owe a guard that they agree — and it must state the rule independently, because a guard that
  asks the code for its own rule agrees with whatever the code does.
  An invariant stated in prose owes a test that fails when it is broken: reversing a `sort()` whose
  docblock said the answer must never depend on producer order passed all 8327 tests, and swapping a
  merge order documented as append-only passed every test of the file that documents it. And a claimed
  GUARD must be executed, never asserted — write the code the guard should refuse and confirm it does.
  A docblock promising PHPStan would refuse a careless call shipped while the call analysed clean; a
  claim that overstates its own guard is how the next reader skips writing one.
  Full statement in [`docs/testing.md`](./docs/testing.md) §Standards.
- **Fixture honesty (binding)**: real-engine fixtures MUST use idiomatic target-package shapes
  (magic-attribute `@property` models, conditional/closure resource fields, `Rule::*` descriptors);
  a fixture shaped to satisfy the analyzer proves nothing — pin the degraded output + diagnostic
  instead. See `docs/testing.md` §"Fixture honesty".
- **Fragment cache soundness**: anything an extension reads that affects output must flow
  into `RouteContext::dependencies()` (files) or the descriptor cache inputs. A warm build must
  also **report** what a cold one reports — a diagnostic raised while building is lost on a warm
  hit unless it travels on the operation fragment, and fewer diagnostics on a warm build is a
  silent degradation, not a saving. The file to record is where a fact was **written**, not where it
  was asked for: inheritance and traits answer most of what the build recovers, so a class's own file
  is only the start (`DeclarationFiles`), and an enum whose cases were copied is a file of its own.
  Under-keying is a correctness bug and over-keying only a cost — key more when in doubt.
- **Config surface**: `php/laravel/config/docuccino.php` is framework-config style — every
  option present, optional ones commented out, one short comment each. Commenting out is not
  cosmetic: a key shipped PRESENT, even as `null`, is part of the resolved config and so changes
  every document's `configHash`, churning every golden for a feature nobody turned on. A key the
  code reads must appear there, and the website's configuration reference must document it — key
  for key, commented options included, which `ConfigReferenceSyncTest` checks in both directions
  (a new section of that page needs a line in `CONFIG_REFERENCE_SECTIONS`). It must also stay
  **pure data** — no imports, no class references, `env()` the only call — so a dev-only install
  survives a `--no-dev` production boot, which loads every `config/` file (`ShippedConfigTest`).
- **Comment style**: comments are small and informal. Class docblocks are 1–3 short sentences
  (what it is + the one non-obvious invariant); method docblocks are annotations plus at most a
  line of prose; inline comments only where the code isn't obvious. State a cross-cutting
  invariant in full ONCE, in the class that owns it, and point at it from elsewhere. Long-form
  design detail belongs in `docs/design/*`, not in a docblock. No project history in comments
  (no phases, waves, item numbers, review or decision-log references).

## Dev commands (from the repo root)

```bash
composer install
composer test                    # full suite, parallel (fixture group auto-skips w/o fixture app)
composer test:unit               # everything but the fixture group — what CI's quality job runs
composer test:inference-fixture  # real-engine integration tests
composer analyse                 # PHPStan level max (2G)
composer lint                    # Pint --test  (composer fix to apply)
composer test:coverage           # clover + per-package floors (needs pcov)
composer test:types              # type coverage, 100% (forks — carries the grpc fork guard)
DOCUCCINO_UPDATE_GOLDEN=1 vendor/bin/pest --parallel --filter=<golden test>   # sanctioned regens only
```

**ALWAYS pass `--parallel` to pest — every invocation, including `--filter` and single-file
runs** (full suite ~16s parallel vs ~65s serial; you will run it many times). The composer
scripts include it. Never define shared helper functions at test-file level — they break under
Paratest process splitting; shared helpers live in `tests/Pest.php`.

Laravel adapter feature tests run on orchestra/testbench with the workbench app under
`php/laravel/workbench/`; the engine's real-analysis tests run out-of-process against
`tests/fixture-app/app`. The workbench app and `tests/Fixtures/**` are test INPUT the product
parses — their docblocks and attributes are data, so edit them only to change what a test proves.

**One working tree, one branch, one writer.** A working tree is single-threaded: `git checkout`
rewrites every file in it, so two agents editing one tree switch branches out from under each
other. Parallel work gets a worktree each (`git worktree add`), and the writer of a branch is the
only process allowed to check it out. This is not a tidiness rule — every one of these has already
happened here: a commit landing on another branch **under another agent's message**, so the change
was correct and the history was a lie; a suite reporting green for a tree it had stopped being;
and a warm-build assertion failing because the fragment cache honestly re-hashed files a concurrent
checkout had rewritten, which reads exactly like a flake and is not one. **A gate result is only
worth what the tree was during the run** — if anything else could have written to it, the number
means nothing and the run has to be repeated in a quiet tree. Never `git checkout <file>` to undo an
experiment either; it silently discards a neighbour's uncommitted work, so copy the file aside
instead.

A worktree isolates FILES and nothing else. Sibling worktrees share one `TMPDIR` and one scratchpad,
and two suites running at once delete each other's fragment cache mid-test — a reviewer reported 385
spurious failures, an agent lost a failure it could never reproduce, one had its scratchpad file
overwritten, and benchmarks read up to 10x their quiet-tree time. Give each run a private `TMPDIR`, or
run the gates one at a time, and say which — "anything else could have written to it" includes the
worktree next door.

**Never branch from a branch you are about to dispatch work onto.** A stack based on another open
branch inherits whatever that branch was when you cut it, and the ancestry check that would catch it
reads the OLD hashes, so it reports nothing wrong. Re-chain and verify by CONTENT, not by SHA. Merging
such a stack bottom-up is worse: squashing the base collapses every message above it, which is how one
release shipped with eight of its thirteen entries missing from the changelog
([`RELEASING.md`](./RELEASING.md)).

The one honest pull toward sharing the main checkout is `tests/fixture-app/app`, which is gitignored
and so absent from a fresh worktree. That does not license sharing: a task needing the fixture group
takes the main checkout **alone**, and everything else waits or runs in a worktree with the group
skipping. Say which happened — "233 skipped" is not a full pass, and reporting it as one is the
failure the fixture-honesty rule exists to prevent.

**zsh does not word-split an unquoted variable.** `for b in $BRANCHES` and `-- $FILES` pass one
giant string, so a loop runs once on nonsense and a pathspec matches nothing — silently, with exit 0.
Write the list literally, or `read` it line by line.

## Project status

Feature-complete and green: core, attributes, the inference engine, the Laravel adapter, the
content layer and the docs site. The subtree split publishes the packages and the spec on every
push to `main` ([`RELEASING.md`](./RELEASING.md)).

Release state — repository visibility, which versions are tagged, what Packagist is serving — is
whatever GitHub and Packagist currently say. Do not restate it here; check it, because a stale
claim in this file has already misled the work. Roadmap and decision history live outside this
repo — the binding conventions are the ones in this file and under `docs/`.
