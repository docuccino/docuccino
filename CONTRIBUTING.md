# Contributing to Docuccino

Thanks for your interest in Docuccino. This is an open-core project (MIT); a paid SaaS later
consumes the UIR artifacts the open packages produce.

## Developer Certificate of Origin (DCO)

All contributions require a DCO sign-off. By signing off you certify the
[Developer Certificate of Origin 1.1](https://developercertificate.org/).

Add a `Signed-off-by` trailer to every commit — `git commit -s` does this for you:

```
Signed-off-by: Your Name <you@example.com>
```

Pull requests whose commits are not signed off will not be merged.

## Conventional Commits

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/), scoped to a
package or area: `feat(laravel): …`, `fix(core): …`, `refactor(inference-phpstan): …`,
`docs(website): …`, `chore(repo): …`. Do **not** add `Co-Authored-By` trailers.

## Repository layout

This is a monorepo, subtree-split into individual packages on release:

- `packages/core` — `docuccino/core`, framework-agnostic UIR model, canonicalizer, identity,
  validator, emitters, semantic diff, extension contracts.
- `packages/attributes` — `docuccino/attributes`, dependency-free attribute classes.
- `packages/inference-phpstan` — `docuccino/inference-phpstan`, PHPStan/Larastan inference behind
  core's `TypeEngine`.
- `packages/laravel` — `docuccino/laravel`, the Laravel adapter (provider, config, commands, viewer,
  integrations).
- `spec/` — the versioned UIR JSON Schemas (published to spec.docuccino.app). This is the
  **canonical authoring copy** of each schema. `packages/core` ships its own package-relative copy
  under `packages/core/resources/spec/` (so `Validator` resolves the schema from a vendor install,
  not from a monorepo-relative path). Edit the canonical copy under `spec/`, then run
  `composer sync-schema` to refresh the package copy; a byte-equality drift guard (`SchemaShippingTest`)
  fails CI if they diverge.
- `website/` — the Astro + Starlight docs site (a Node project, not a Composer package).

## Local development

```bash
composer install

vendor/bin/pest --parallel              # full suite (the `fixture` group auto-skips without the fixture app)
vendor/bin/phpstan analyse --no-progress  # level max — NO baselines, no blanket ignores
vendor/bin/pint --test                  # code style (drop --test to fix)
composer validate --strict              # per package
```

Everything must be **green on all checks, always** — Pest, PHPStan at level max, Pint, and
`composer validate`. `declare(strict_types=1)` is required in every PHP file.

### The fixture app (real-engine tests)

The Laravel adapter's feature tests run against the workbench under `packages/laravel/workbench/`.
The inference engine's real-analysis tests (the `fixture` group) run out-of-process against a
provisioned Laravel + Larastan app at `tests/fixture-app/app`, which is **gitignored** — recreate
it per `tests/fixture-app/setup.md` (or let CI's cached provisioning do it). Then:

```bash
vendor/bin/pest --parallel --group=fixture   # real-engine integration tests
```

Set `DOCUCCINO_REQUIRE_FIXTURE=1` to turn a missing/broken fixture app into a hard failure instead
of a silent skip.

## Determinism & the golden discipline

**Determinism is a product feature:** byte-identical output for identical code — no timestamps, no
absolute paths, no randomness in any emitted document. This is a hard, tested guarantee (CI asserts
cold-vs-warm cache, 1-vs-8-worker, and re-run byte-diffs, plus a UIR→OAS round-trip losslessness
test).

Golden files under `packages/*/tests/Fixtures/golden/` are **byte-locked**. Never regenerate them
casually:

- A sanctioned regeneration is an **isolated commit** that explains exactly why the bytes changed.
- Regenerate locally with `DOCUCCINO_UPDATE_GOLDEN=1`:
  ```bash
  DOCUCCINO_UPDATE_GOLDEN=1 vendor/bin/pest --filter=<golden test>
  ```
- **CI guards that `DOCUCCINO_UPDATE_GOLDEN` is unset**, so a drifting document can never masquerade
  as green.

## Coverage standards

Coverage protects the paths goldens never traverse. Summarised (full detail in
[`docs/testing.md`](docs/testing.md)):

- **Mapping / lookup tables** (rule maps, attribute maps, cast→format, `KnownThrowers`, enum naming)
  are tested **dataset-driven over every entry**, plus the unknown-entry degradation contract. One
  tested entry is not coverage. An unreachable entry is deleted, not tested.
- **Stub / real splits.** `StubTypeEngine` tests prove pipeline mechanics; every integration's
  recovery/parsing half also needs a real-path test (real reflection, or the real engine via the
  `fixture` group). Ask of every test: which half does this prove, and where is the other half?
- **Negative paths, exit codes, and degradation branches are coverage**, not extras.

Run coverage locally (needs pcov):

```bash
vendor/bin/pest --coverage --exclude-group=fixture --min=<floor>   # line coverage
vendor/bin/pest --type-coverage --exclude-group=fixture --min=100 --memory-limit=2G  # declared types
```

The enforced floors live in `.github/workflows/ci.yml` (the `coverage` job). The line floor is an
**honest ratchet** — raised as coverage rises, never lowered without a note in `docs/plan.md`.

## Writing an integration

Built-in integrations live in `packages/laravel/src/Integrations/<Name>/` — self-contained, with one
`<Name>Integration` registrar (a static `extensions()` list, plus a `class_exists`-guarded
`installed()` for conditional ones), importing only the public extension surface. An arch test
(`IntegrationsArchTest`) enforces this — they use the *same public API* a third party would. See the
[extension authoring guide](https://docs.docuccino.app/guides/extension-authoring/) for the full
template, the contracts, `#[ExtensionOrder]`, and the placement rule.

## Docs site

The site under `website/` builds with `npm run build` (from `website/`). Content is sourced from the
actual code and design docs — keep the `<!-- Source of truth: … -->` comments accurate. See
[`website/README.md`](website/README.md).
