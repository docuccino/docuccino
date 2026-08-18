# Releasing

All four packages are versioned in lockstep with the monorepo: they require each other at
`self.version`, so a tag means the same thing everywhere.

## Cutting a release

A release is not a checklist you remember — it is a pull request that is already open.
[`.github/workflows/release-pr.yml`](.github/workflows/release-pr.yml) runs on every push to `main`,
regenerates every changelog from the commit messages, and keeps one open **`Release vX.Y.Z`** pull
request current, with the pending entries as its description.

1. Review that pull request and wait for `CI gate`.
2. Bump `DocuccinoServiceProvider::VERSION` in
   [`php/laravel/src/DocuccinoServiceProvider.php`](php/laravel/src/DocuccinoServiceProvider.php) to
   the version that pull request's title names — see [The generator version](#the-generator-version).
3. **Squash-merge the release pull request. That is the release** — `main` now carries the changelog
   for a version that is not yet tagged.
4. Tag the merge commit with the version the pull request named:

```bash
git switch main && git pull
git tag v1.2.3
git push origin v1.2.3
```

Pushing the tag fires [`.github/workflows/split.yml`](.github/workflows/split.yml), which copies each
split target into `docuccino/<name>` and tags it `v1.2.3`. Packagist picks the new tags up from its
GitHub webhook.

Every push to `main` runs the same split without a tag, so the **package** repositories always mirror
the current `main` — which is what makes a `dev-main` install possible.

Two targets deliberately publish on a tag only, because both are read by people rather than by
Composer, and between a merge and a tag `main` describes a version nobody can install:

- `docuccino/spec`, whose Pages site serves `spec.docuccino.app`. Publishing an unreleased schema
  there would state a version of the spec nothing can depend on.
- the docs site ([`deploy.yml`](.github/workflows/deploy.yml)), so the prose matches the release a
  reader is running.

A docs-only fix produces no changelog entry, so it opens no release pull request and has nothing to
merge. Republish it with `gh workflow run "Deploy docs"` rather than waiting for an unrelated feature.

The ordering is the point: the tag lands on a commit that **already contains its own changelog**.
Generating on tag push would put the changelog after the tag, and the split would carry a
pre-changelog tree into every package repository — so `docuccino/core@v1.2.3` on Packagist would ship
a `CHANGELOG.md` missing the release it is.

Tagging stays manual on purpose. A tag pushed with the automatic `GITHUB_TOKEN` triggers no further
workflows, so `split.yml` would never see it.

### The generator version

`DocuccinoServiceProvider::VERSION` (in
[`php/laravel/src/DocuccinoServiceProvider.php`](php/laravel/src/DocuccinoServiceProvider.php)) is the
one string in this repository that has to say which release you are running. Every emitted document
publishes it as `x-docuccino.generator.version` — the field a bug report is read against — and it keys
the fragment cache's tool version, so bumping it is also what stops an upgrade serving fragments an
older generator recorded. A stale constant makes the document name a generator that never produced it.

It rides its own **`chore(laravel): …`** pull request onto `main`, merged before the release pull
request. Two reasons for that shape: `release/next` is a generated branch, reset and force-pushed on
every push to `main`, so a commit placed there is lost; and `chore` produces no changelog entry, so
the pending version the release pull request names does not move under you. Merging the bump re-runs
the release workflow, which rebuilds `release/next` from a `main` that now carries it.

No goldens need regenerating. The golden comparison (`withoutGeneratorVersion()` in `tests/Pest.php`)
replaces that one member on both sides, so the committed bytes keep the version they were recorded
with and a bump changes nothing else — `info.version`, `specVersion` and `contentHash` all stay
byte-locked.

### The changelogs

[`tools/changelog.php`](tools/changelog.php) is the generator — plain PHP, no toolchain, `composer
changelog` to run it by hand. It rewrites all of these from the commit history every time, so they
are **generated artifacts**: fix a wrong entry by fixing the commit message, never by editing the
file.

| File | Contents |
| --- | --- |
| `php/<pkg>/CHANGELOG.md` | that package's entries — rides the split into its own repository and onto Packagist |
| `website/src/content/docs/changelog.md` | every package's entries, as the docs site's changelog page |

What lands in them:

- Only `feat`, `fix` and `perf`, plus anything carrying a `BREAKING CHANGE:` footer whatever its
  type. Breaking changes render first, in their own section.
- Routed by the **scope → package table** in [`tools/conventional-commit.php`](tools/conventional-commit.php),
  never by which directories a commit touched: `repo`, `website` and `ci` map to no package and
  reach the aggregate page only.
- Merge commits and unparseable subjects are skipped (the latter with a warning on stderr).
- Nothing is dated. A pending release's date would change on every push to `main` and churn the
  release pull request for no information; determinism matters more here than a date does.
- History up to and including `v0.1.2` — the `CHANGELOG_BASELINE` constant — predates the title
  gate and is deliberately not parsed. It is in the git log.

Versions come from the pending changes: below `1.0.0` a breaking change moves the minor and
everything else the patch; from `1.0.0` on it is plain semver.

### Repository settings this depends on

Two settings live outside the code, and without them the title gate is decorative — a merge whose
message no gate ever saw would land, and the changelog would silently miss it. In
**Settings → General → Pull Requests**:

- **Allow squash merging** on, and its commit message set to **"Default to pull request title"** —
  that is what makes the gated title the message on `main`.
- **Allow merge commits** and **Allow rebase merging** both off.

Add **`Conventional PR title`** to `main`'s required status checks alongside `CI gate`. Unrequired,
the gate is advisory — a red title check does not stop a merge. It is safe to require: the check name
is stable (no matrix, no version string), the workflow has no path filter so it always reports, and
on the release pull request the job skips itself, which required checks accept as passing. That is
the only addition to the required set; the CI matrix legs stay out of it for the reasons in
[CONTRIBUTING.md](CONTRIBUTING.md).

## RELEASE_TOKEN

The release workflow pushes a branch and opens a pull request. `secrets.GITHUB_TOKEN` cannot do that
usefully — and not for a permissions reason: a branch pushed or a pull request opened with it
triggers **no further workflow runs**, so the release pull request would sit with no CI, its required
`CI gate` check would never report, and it could never be merged. So it uses a fine-grained PAT
stored as the `RELEASE_TOKEN` Actions secret:

- Resource owner: the `docuccino` organization
- Repository access: only `docuccino/docuccino` — the opposite selection from `SPLIT_TOKEN`, which
  needs the five split targets and not this repository
- Permissions: **Contents: Read and write** and **Pull requests: Read and write**, nothing else

**It expires**, with the same consequence as `SPLIT_TOKEN`: an expired token fails only this
workflow. CI stays green and no changelog is regenerated, so watch for the failing Release PR run
rather than trusting a green CI badge.

## The split repositories

There are five split targets — the four Composer packages, plus the UIR spec:

| Source | Split repository | Composer package |
| --- | --- | --- |
| `php/core` | `docuccino/php-core` | `docuccino/core` |
| `php/attributes` | `docuccino/php-attributes` | `docuccino/attributes` |
| `php/inference-phpstan` | `docuccino/inference-phpstan` | `docuccino/inference-phpstan` |
| `php/laravel` | `docuccino/laravel` | `docuccino/laravel` |
| `spec/` | `docuccino/spec` | none — its GitHub Pages site serves `spec.docuccino.app` at the schemas' exact `$id` URLs |

### Repository naming

Repository names and Composer package names are deliberately allowed to differ. Packagist reads the
package name out of `composer.json`, so the two are independent, and each language ecosystem already
namespaces its own registry — `docuccino/core` on Packagist can only ever be PHP. GitHub has no such
separation: the `docuccino` org is one flat namespace shared by every language we may add.

So the rule is: **a repository whose name would be language-generic carries a language prefix; one
that already names its language or framework does not.** `core` and `attributes` are generic, hence
`docuccino/php-core` and `docuccino/php-attributes`. `laravel` and `inference-phpstan` already say
PHP, so they stay unprefixed, as does `spec`, which is language-neutral by definition. A future
Python implementation would be `docuccino/python-core` plus `docuccino/fastapi`.

The Composer package names never carry the prefix — `composer require docuccino/core` is unchanged
by any of this, and renaming a repository does not affect it.

All five are **read-only mirrors**. Never commit to them — the next split overwrites whatever is
there. All work happens in this repository.

A brand-new (empty) target repository can't be split into, because the action tries to push a branch
with no commits. The workflow's "Initialise … if empty" step gives an empty target its first commit,
so adding another target needs nothing but creating the repository.

## SPLIT_TOKEN

The split pushes to *other* repositories, which the automatic `GITHUB_TOKEN` cannot do. It uses a
fine-grained PAT stored as the `SPLIT_TOKEN` Actions secret:

- Resource owner: the `docuccino` organization
- Repository access: only `docuccino/php-core`, `docuccino/php-attributes`,
  `docuccino/inference-phpstan`, `docuccino/laravel`, `docuccino/spec` (this repository does **not**
  need to be included). The selection is stored by repository ID, so renaming a target does not
  invalidate an existing token
- Permissions: **Contents: Read and write**, nothing else

**It expires.** Put the expiry in a calendar. An expired token fails only the split workflow — the
monorepo's own CI stays green, so watch for the failing Split run rather than trusting a green CI
badge. The workflow header has the full creation walkthrough and the exact error messages a
misscoped token produces.
