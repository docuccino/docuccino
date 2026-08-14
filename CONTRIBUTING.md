# Contributing to Docuccino

Thanks for your interest in Docuccino. It's an open-source project, MIT licensed — see
[LICENSE](LICENSE).

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

Pull requests are **squash-merged, and the squash subject is the pull request title** — so the title,
not your individual commits, is the message that lands on `main`, and the only thing the changelog
generator ever reads. A `Conventional PR title` check validates it on every edit:

- A type from `build`, `chore`, `ci`, `docs`, `feat`, `fix`, `perf`, `refactor`, `revert`, `style`,
  `test`.
- A scope from the allow-list, or none at all: `core`, `attributes`, `laravel`,
  `inference-phpstan` are the packages; `repo`, `website` and `ci` map to no package. Anything else
  fails — the scope decides which package's changelog an entry lands in, so it cannot be freeform.
- A breaking change needs **both** halves: `!` before the colon *and* a `BREAKING CHANGE:` footer in
  the body saying what breaks. Either alone fails, because either alone is how a breaking change
  lands as a routine entry.

Fix a red check by editing the title (or body) — it re-runs itself. Check a title before you open
anything with `composer lint:pr-title -- --title='feat(core): …'`.

`feat`, `fix`, `perf` and anything breaking become changelog entries. `php/*/CHANGELOG.md` and the
site's [changelog page](website/src/content/docs/changelog.md) are **generated** by
`tools/changelog.php` — never edit them; fix the commit message.

## Submitting changes

`main` is protected: **every change lands through a pull request**, including a maintainer's own.
Direct pushes to `main` are rejected by a repository ruleset, and there is no admin bypass.

1. Branch from `main` (fork first if you do not have write access).
2. Commit your work — conventional message, signed off (`git commit -s`).
3. Open a pull request against `docuccino/docuccino`, with a **conventional title**: it is the
   message that lands.
4. Wait for the **`CI gate`** and **`Conventional PR title`** checks to pass, then squash-merge.

`CI gate` is a single aggregate check that fails unless *every* CI job succeeded — the quality
matrix across PHP 8.3/8.4/8.5 plus the prefer-lowest and PHPStan-minor legs, the UIR schema drift
guard, the coverage gates, and the fixture suite. It is the only required check by design: the
individual job names carry version strings that change as the matrix moves, and a required check
that stops reporting blocks every pull request. Do not add individual jobs to the required set.

Your branch must be up to date with `main` before merging, so that CI's verdict is about the tree
that actually lands. Rebase if `main` has moved.

Two things CI cannot check for you, so check them yourself before opening a PR:

- The docs site's configuration reference stays in sync with
  `php/laravel/config/docuccino.php` when you add or change an option.
- A golden regeneration is an isolated commit — never bundled with the change that caused it.

### Release tags are immutable

`v*` tags cannot be moved or deleted once pushed: a tag fans out through the subtree split to all
four package repositories and is what Composer resolves, so a mutable tag would mean one version
resolving to different bytes. A mistake in a release is corrected by cutting the next patch
version, never by re-tagging. See [RELEASING.md](RELEASING.md).

## Reporting a security issue

Do not open a public issue. Follow [SECURITY.md](SECURITY.md), which routes reports through
GitHub's private vulnerability reporting.

## Repository layout

This is a monorepo. Each `php/*` directory is subtree-split into a read-only repository of its
own (`docuccino/php-core`, `docuccino/php-attributes`, `docuccino/inference-phpstan`,
`docuccino/laravel`) — that is what Packagist serves, and the split happens on every push to `main`
(see [RELEASING.md](RELEASING.md)). Repository names and Composer package names differ where a bare
name would be language-generic: the `docuccino/php-core` repository ships the `docuccino/core`
package, and the package names below are the ones you `composer require`. `spec/` is split the same way to
`docuccino/spec`, which is not a Composer package: it exists so GitHub Pages has a repository from
which to serve `spec.docuccino.app`. Never commit to a split repository: it is overwritten. All work
happens here.

What lives where:

- `php/core` — `docuccino/core`, framework-agnostic UIR model, canonicalizer, identity,
  validator, emitters, semantic diff, extension contracts.
- `php/attributes` — `docuccino/attributes`, dependency-free attribute classes.
- `php/inference-phpstan` — `docuccino/inference-phpstan`, PHPStan/Larastan inference behind
  core's `TypeEngine`.
- `php/laravel` — `docuccino/laravel`, the Laravel adapter (provider, config, commands, viewer,
  integrations).
- `spec/` — `docuccino/spec`, the versioned UIR JSON Schemas, served at spec.docuccino.app by that
  repository's GitHub Pages site. Its `CNAME`, `.nojekyll`, `index.html` and `README.md` are part of
  the split payload, so they live here — anything added directly to `docuccino/spec` is wiped on the
  next release. This is the **canonical authoring copy** of each schema. `php/core` ships its
  own package-relative copy
  under `php/core/resources/spec/` (so `Validator` resolves the schema from a vendor install,
  not from a monorepo-relative path). Edit the canonical copy under `spec/`, then run
  `composer sync-schema` to refresh the package copy; a byte-equality drift guard (`SchemaShippingTest`)
  fails CI if they diverge.
- `website/` — the Astro + Starlight docs site (a Node project, not a Composer package).

## Local development

```bash
composer install

composer test               # full suite, parallel (the `fixture` group auto-skips without the fixture app)
composer analyse            # PHPStan level max — NO baselines, no blanket ignores
composer lint               # code style (composer fix to apply)
composer validate --strict  # per package
```

Use the composer scripts rather than calling `vendor/bin/*` directly: they carry the flags the gates
need (`--parallel`, and the 2G memory limits PHPStan and type coverage want), and CI runs the same
scripts, so the two cannot drift.

Everything must be **green on all checks, always** — Pest, PHPStan at level max, Pint, and
`composer validate`. `declare(strict_types=1)` is required in every PHP file.

### The fixture app (real-engine tests)

The Laravel adapter's feature tests run against the workbench under `php/laravel/workbench/`.
The inference engine's real-analysis tests (the `fixture` group) run out-of-process against a
provisioned Laravel + Larastan app at `tests/fixture-app/app`, which is **gitignored** — recreate
it per `tests/fixture-app/setup.md` (or let CI's cached provisioning do it). Then:

```bash
composer test:inference-fixture   # real-engine integration tests
```

Set `DOCUCCINO_REQUIRE_FIXTURE=1` to turn a missing/broken fixture app into a hard failure instead
of a silent skip.

## Determinism & the golden discipline

**Determinism is a product feature:** byte-identical output for identical code — no timestamps, no
absolute paths, no randomness in any emitted document. This is a hard, tested guarantee (CI asserts
cold-vs-warm cache, 1-vs-8-worker, and re-run byte-diffs, plus a UIR→OAS round-trip losslessness
test).

Golden files under `php/*/tests/Fixtures/golden/` are **byte-locked**. Never regenerate them
casually:

- A sanctioned regeneration is an **isolated commit** that explains exactly why the bytes changed.
- Regenerate locally with `DOCUCCINO_UPDATE_GOLDEN=1`:
  ```bash
  DOCUCCINO_UPDATE_GOLDEN=1 vendor/bin/pest --parallel --filter=<golden test>
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
composer test:coverage   # line coverage, gated per package
composer test:types      # declared types, 100%
```

Line coverage is gated **per package** by `tools/coverage-floors.php`, which `composer test:coverage`
runs over the clover report. Those floors are an **honest ratchet** — measured-now values, raised as
coverage rises, never lowered without a documented justification (see `docs/testing.md`).

## Writing an integration

Built-in integrations live in `php/laravel/src/Integrations/<Name>/` — self-contained, with one
`<Name>Integration` registrar (a static `extensions()` list, plus a `class_exists`-guarded
`installed()` for conditional ones), importing only the public extension surface. An arch test
(`IntegrationsArchTest`) enforces this — they use the *same public API* a third party would. See the
[extension authoring guide](https://docs.docuccino.app/extending/extension-authoring/) for the full
template, the contracts, `#[ExtensionOrder]`, and the placement rule.

## Docs site

The site under `website/` builds with `npm run build` (from `website/`). Content is sourced from the
actual code and design docs — keep the `<!-- Source of truth: … -->` comments accurate. See
[`website/README.md`](website/README.md).
