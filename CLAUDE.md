# Docuccino — Monorepo Guide

Docuccino is an open-source (MIT) API documentation generator for Laravel that compiles
application code into a **UIR** (Universal Intermediate Representation — an OAS-3.2-shaped,
deterministic, identity-carrying JSON document) and emits OpenAPI 3.2/3.1, with semantic
diffing and a bundled Scalar viewer.

> Full references — read these before substantial work:
> - [`docs/design/uir-and-extensions.md`](./docs/design/uir-and-extensions.md) — UIR spec detail, extension API, precedence, **placement rule**
> - [`docs/design/inference-embedding.md`](./docs/design/inference-embedding.md) — PHPStan/Larastan engine design + spike-verified traps
> - [`docs/testing.md`](./docs/testing.md) — coverage standards, how to run coverage, ratchet policy
> - [`website/STYLE.md`](./website/STYLE.md) — binding style bar for the docs site (Laravel docs are the gold standard)
> - [`RELEASING.md`](./RELEASING.md) — tagging, the subtree split, `SPLIT_TOKEN`

## ⚠️ Absolute rules

- **Green on all checks, always**: `composer test` (incl. `composer test:inference-fixture`
  when the fixture app is present), `composer analyse` (PHPStan level max, NO baselines, no
  blanket ignores), `composer lint`, `composer validate --strict` (all packages).
  CI also gates line coverage **per package** (`composer test:coverage` →
  `tools/coverage-floors.php`; honest measured-now floors, ratchet up, never down) and type
  coverage (`composer test:types`, 100%). **Use the composer scripts** — they carry the flags
  the gates need (`--parallel`, and the 2G memory limits). CI runs the same scripts, so they
  cannot drift. The 2G is headroom, not a requirement: type coverage peaks near 120 MB and
  times the same at PHP's default limit as at 4G, so a type-coverage run that appears to hang
  for minutes is stale pcov state — kill it and re-run rather than reaching for more memory.
- **Determinism is a product feature**: byte-identical output for identical code. No
  timestamps, no absolute paths, no randomness in any emitted document. Golden files under
  `php/*/tests/Fixtures/golden/` are byte-locked — never regenerate casually; a
  sanctioned regeneration is an ISOLATED commit explaining exactly why bytes changed.
  (`DOCUCCINO_UPDATE_GOLDEN=1` regenerates locally; CI guards it is unset.)
- **Conventional commits** (`feat(laravel): …`, `fix(core): …`), NO Co-Authored-By trailers.
  Merges are **squash-only and the PR title is the message that lands**, so the title is gated
  (`.github/workflows/pr-title.yml` → `tools/pr-title-lint.php`): a conventional type, an optional
  `!` that must be paired with a `BREAKING CHANGE:` body footer (both halves, or neither), and a
  scope from the allow-list in `tools/conventional-commit.php` — `core`, `attributes`, `laravel`,
  `inference-phpstan` are packages, `repo`, `website`, `ci` map to none. The changelogs are
  **generated** from those messages (`composer changelog`, and automatically on every push to
  `main`): never hand-edit `php/*/CHANGELOG.md` or `website/src/content/docs/changelog.md`, fix the
  commit message instead. Release flow in [`RELEASING.md`](./RELEASING.md).

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
- **Public API boundary**: `@internal` marks non-public core; `CoreBoundaryArchTest` +
  `IntegrationsArchTest` enforce. The extension-author surface freezes at v1.
- **Package direction**: `attributes ← core ← {laravel, inference-phpstan}` — the adapter and the
  engine are SIBLINGS. `docuccino/laravel` must install without an analyser: it names the engine's
  entry class by string (`Engine\EnginePackage`) and degrades to `NullTypeEngine` + one
  `engine.not-installed` warning. `AdapterBoundaryArchTest` / `EngineBoundaryArchTest` enforce both
  directions — note a Pest arch layer can only see PSR-4-autoloaded namespaces, so a phar dependency
  like phpstan/phpstan needs the `importsMatching()` import scan instead.
- **Split repo names ≠ package names**: a split repository whose name would be language-generic
  carries a language prefix (`docuccino/php-core` ships the `docuccino/core` package); one already
  naming its language or framework does not (`docuccino/laravel`). Composer package names never
  carry the prefix. GitHub's org is one flat namespace across every language we may add; Packagist
  is PHP-only. Rule and rationale in [`RELEASING.md`](./RELEASING.md).
- **Precedence**: fallback(5) < inference(10) < integration(20) < docblock(30) <
  attribute(40) < overlay(45) < config(50); field-level patch semantics via PatchGuard.
- **Coverage standards (binding)**: every mapping/lookup table gets a dataset test over
  EVERY entry + unknown-entry degradation; stub-engine tests prove mechanics only — the
  parsing/recovery half needs real-path tests (fixture group). Negative paths are coverage.
- **Fixture honesty (binding)**: real-engine fixtures MUST use idiomatic target-package shapes
  (magic-attribute `@property` models, conditional/closure resource fields, `Rule::*` descriptors);
  a fixture shaped to satisfy the analyzer proves nothing — pin the degraded output + diagnostic
  instead. See `docs/testing.md` §"Fixture honesty".
- **Fragment cache soundness**: anything an extension reads that affects output must flow
  into `RouteContext::dependencies()` (files) or the descriptor cache inputs.
- **Config surface**: `php/laravel/config/docuccino.php` is framework-config style — every
  option present, optional ones commented out, one short comment each. A key the code reads must
  appear there, and the website's configuration reference must stay in sync with it. It must also stay
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
composer test:inference-fixture  # real-engine integration tests
composer analyse                 # PHPStan level max (2G)
composer lint                    # Pint --test  (composer fix to apply)
composer test:coverage           # clover + per-package floors (needs pcov)
composer test:types              # type coverage, 100% (2G)
DOCUCCINO_UPDATE_GOLDEN=1 vendor/bin/pest --parallel --filter=<golden test>   # sanctioned regens only
```

**ALWAYS pass `--parallel` to pest — every invocation, including `--filter` and single-file
runs** (full suite ~16s parallel vs ~65s serial; you will run it many times). The composer
scripts include it. Never define shared helper functions at test-file level — they break under
Paratest process splitting; shared helpers live in `tests/Pest.php`. If a coverage/type-coverage
run hangs (stale pcov state, rare), kill it and re-run fresh.

Laravel adapter feature tests run on orchestra/testbench with the workbench app under
`php/laravel/workbench/`; the engine's real-analysis tests run out-of-process against
`tests/fixture-app/app`. The workbench app and `tests/Fixtures/**` are test INPUT the product
parses — their docblocks and attributes are data, so edit them only to change what a test proves.

## Project status

Feature-complete and green: core, attributes, the inference engine, the Laravel adapter, the
content layer and the docs site. The subtree split publishes the packages and the spec on every
push to `main` ([`RELEASING.md`](./RELEASING.md)).

Release state — repository visibility, which versions are tagged, what Packagist is serving — is
whatever GitHub and Packagist currently say. Do not restate it here; check it, because a stale
claim in this file has already misled the work. Roadmap and decision history live outside this
repo — the binding conventions are the ones in this file and under `docs/`.
