# Docuccino — Monorepo Guide

Docuccino is an open-source (MIT) API documentation generator for Laravel that compiles
application code into a **UIR** (Universal Intermediate Representation — an OAS-3.2-shaped,
deterministic, identity-carrying JSON document) and emits OpenAPI 3.2/3.1, with semantic
diffing and a bundled Scalar viewer. Private until v1 launch.

> Full references — read these before substantial work:
> - [`docs/plan.md`](./docs/plan.md) — approved plan, locked decisions, phase roadmap, coverage standards
> - [`docs/design/uir-and-extensions.md`](./docs/design/uir-and-extensions.md) — UIR spec detail, extension API, precedence, **placement rule**
> - [`docs/design/inference-embedding.md`](./docs/design/inference-embedding.md) — PHPStan/Larastan engine design + spike-verified traps
> - [`docs/testing.md`](./docs/testing.md) — coverage standards, how to run coverage, ratchet policy

## ⚠️ Absolute rules

- **Green on all checks, always**: `vendor/bin/pest` (incl. `--group=fixture` when the
  fixture app is present), `vendor/bin/phpstan` (level max, NO baselines, no blanket
  ignores), `vendor/bin/pint --test`, `composer validate --strict` (all packages).
  CI also gates line coverage (`--min` in `.github/workflows/ci.yml`) and type coverage (100).
- **Determinism is a product feature**: byte-identical output for identical code. No
  timestamps, no absolute paths, no randomness in any emitted document. Golden files under
  `packages/*/tests/Fixtures/golden/` are byte-locked — never regenerate casually; a
  sanctioned regeneration is an ISOLATED commit explaining exactly why bytes changed.
  (`DOCUCCINO_UPDATE_GOLDEN=1` regenerates locally; CI guards it is unset.)
- **Conventional commits** (`feat(laravel): …`, `fix(core): …`), NO Co-Authored-By trailers.

## Monorepo layout

```
packages/core/               docuccino/core        — UIR model, canonicalizer, identities,
                                                     drafts+PatchGuard, emitters, diff+policies,
                                                     overlays, Lint, extension contracts.
                                                     Framework-agnostic (arch-test enforced:
                                                     no Illuminate/PHPStan imports).
packages/attributes/         docuccino/attributes  — dep-free PHP attributes only.
packages/inference-phpstan/  docuccino/inference-phpstan — PHPStan+Larastan engine behind
                                                     core's TypeEngine contract (workers, cache).
packages/laravel/            docuccino/laravel     — adapter: provider, late-bound registry,
                                                     pipeline, commands, viewer, Integrations/.
spec/uir/1.0/schema.json     the UIR JSON Schema (the long-term product).
spikes/                      Phase-0 proof spikes (reference implementations; fixture app
                             at spikes/fixture-app is gitignored — recreate per
                             spikes/fixture-app-setup.md).
```

## Key conventions (details in the design docs)

- **Placement rule**: input = UIR document → core; input = Laravel code → adapter.
  Framework-neutral machinery in core, framework vocabulary + recovery in the adapter.
- **Integrations**: `packages/laravel/src/Integrations/<Name>/` — self-contained, ONE
  `<Name>Integration` registrar via the public Registrar API, `class_exists` conditional
  registration, imports only the public surface (`IntegrationsArchTest` is the definition;
  extend its allow-list only with justification — never duplicate a core utility to dodge it).
- **Public API boundary**: `@internal` marks non-public core; `CoreBoundaryArchTest` +
  `IntegrationsArchTest` enforce. The extension-author surface freezes at v1.
- **Precedence**: fallback(5) < inference(10) < integration(20) < docblock(30) <
  attribute(40) < overlay(45) < config(50); field-level patch semantics via PatchGuard.
- **Coverage standards (binding)**: every mapping/lookup table gets a dataset test over
  EVERY entry + unknown-entry degradation; stub-engine tests prove mechanics only — the
  parsing/recovery half needs real-path tests (fixture group). Negative paths are coverage.
- **Fragment cache soundness**: anything an extension reads that affects output must flow
  into `RouteContext::dependencies()` (files) or the descriptor cache inputs.

## Dev commands (from the repo root)

```bash
composer install
vendor/bin/pest                         # full suite (fixture group auto-skips w/o fixture app)
vendor/bin/pest --group=fixture         # real-engine integration tests
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test                  # (drop --test to fix)
vendor/bin/pest --coverage --exclude-group=fixture --min=78
DOCUCCINO_UPDATE_GOLDEN=1 vendor/bin/pest --filter=<golden test>   # sanctioned regens only
```

Laravel adapter feature tests run on orchestra/testbench with the workbench app under
`packages/laravel/workbench/`; the engine's real-analysis tests run out-of-process against
`spikes/fixture-app`.

## Project status

Phases 0–4a complete; Phase 4b in progress; Phase 5 (content layer, docs site, Scramble
migration guide) next; Phase 6 (Eos dogfooding) is gated on human oversight — do not start
it autonomously. Current decisions log lives in `docs/plan.md` (dated inline notes).
