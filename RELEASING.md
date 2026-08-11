# Releasing

All four packages are versioned in lockstep with the monorepo: they require each other at
`self.version`, so a tag means the same thing everywhere.

## Cutting a release

```bash
git switch main && git pull
# green on: composer test, composer test:inference-fixture, composer analyse, composer lint
git tag v1.2.3
git push origin v1.2.3
```

That's the whole release. Pushing the tag fires [`.github/workflows/split.yml`](.github/workflows/split.yml),
which copies each split target into `docuccino/<name>` and tags it `v1.2.3`. Packagist picks the new
tags up from its GitHub webhook.

Every push to `main` runs the same split without a tag, so the package repositories always mirror the
current `main`.

## The split repositories

There are five split targets — the four Composer packages, plus the UIR spec:

| Source | Split repository | Composer package |
| --- | --- | --- |
| `packages/core` | `docuccino/php-core` | `docuccino/core` |
| `packages/attributes` | `docuccino/php-attributes` | `docuccino/attributes` |
| `packages/inference-phpstan` | `docuccino/inference-phpstan` | `docuccino/inference-phpstan` |
| `packages/laravel` | `docuccino/laravel` | `docuccino/laravel` |
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
