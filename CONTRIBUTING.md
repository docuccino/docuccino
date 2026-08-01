# Contributing to Docuccino

Thanks for your interest in Docuccino. This is an open-core project (MIT); a paid
SaaS later consumes the UIR artifacts the open packages produce.

## Developer Certificate of Origin (DCO)

All contributions require a DCO sign-off. By signing off you certify the
[Developer Certificate of Origin 1.1](https://developercertificate.org/).

Add a `Signed-off-by` trailer to every commit — `git commit -s` does this for you:

```
Signed-off-by: Your Name <you@example.com>
```

Pull requests whose commits are not signed off will not be merged.

## Repository layout

This is a monorepo, subtree-split into individual packages on release:

- `packages/core` — `docuccino/core`, framework-agnostic UIR model, canonicalizer,
  identity, validator, emitters.
- `packages/attributes` — `docuccino/attributes`, dependency-free attribute classes.
- `packages/laravel` — `docuccino/laravel`, Laravel adapter (Phase 3).
- `packages/inference-phpstan` — `docuccino/inference-phpstan`, PHPStan inference (Phase 2).
- `spec/` — the versioned UIR JSON Schemas (published to spec.docuccino.app).

## Local development

```bash
composer install
composer test      # Pest
composer analyse   # PHPStan (level max)
composer lint      # Pint (dry-run)
```

Everything must be green — tests, PHPStan at level max, and Pint — before a change
is merged. `declare(strict_types=1)` is required in every PHP file.

## Conventions

- Conventional Commits (`feat(core): …`, `fix(core): …`, `chore(repo): …`).
- No timestamps or other non-deterministic values in emitted artifacts — determinism
  is a hard, tested guarantee.
