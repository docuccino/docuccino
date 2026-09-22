<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/logo.svg">
    <source media="(prefers-color-scheme: light)" srcset="art/logo-light.svg">
    <img alt="Docuccino" src="art/logo-light.svg" width="420">
  </picture>
</p>

<p align="center">
  <a href="https://github.com/docuccino/docuccino/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/docuccino/docuccino/actions/workflows/ci.yml/badge.svg?branch=main"></a>
  <a href="https://github.com/docuccino/docuccino/actions/workflows/website.yml"><img alt="Docs" src="https://github.com/docuccino/docuccino/actions/workflows/website.yml/badge.svg?branch=main"></a>
  <a href="https://packagist.org/packages/docuccino/laravel"><img alt="Latest version" src="https://img.shields.io/packagist/v/docuccino/laravel"></a>
  <a href="LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue"></a>
  <img alt="PHP 8.3+" src="https://img.shields.io/badge/php-8.3%2B-777bb4">
  <img alt="Laravel 12 and 13" src="https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20">
</p>

# Docuccino

**API documentation for Laravel that documents change and provenance, not just endpoints.**
Docuccino compiles your application into OpenAPI 3.2 — and records how it knew. That record travels
in the document under one reserved member, `x-docuccino`, the **Docuccino extension**: a stable
identity for every operation, schema and parameter, and the `file:line` behind every detail. Because
those identities are computed from meaning and the output is byte-deterministic, the document answers
*what changed* and *why is it documented this way* — not merely *what are the endpoints*. That makes
it a clean input for tooling you build on top: changelogs, mock servers, and agent-facing tool
schemas. Export plain OpenAPI 3.2, 3.1 or 3.0 and the whole extension is dropped on the way out.

## Quickstart

Where do your docs need to be readable? That's what decides how you install.

**Serve docs from your app** — the viewer live at `/docs/api` on a deployed environment:

```bash
composer require docuccino/laravel                   # ships to production
composer require --dev docuccino/inference-phpstan   # powers type inference
php artisan docuccino:install                        # config, route check, → docs/openapi.json
```

**Docs in development only**, or **hosted somewhere else** (ReadMe, Bump.sh, any OpenAPI host) — keep
both packages dev-only, so `composer install --no-dev` ships neither and production `vendor/` holds
nothing of Docuccino at all:

```bash
composer require --dev docuccino/laravel docuccino/inference-phpstan
php artisan docuccino:install                        # then upload the artifact, if it's hosted
```

Either way analysis is a build-time job: the inference engine (PHPStan + Larastan) runs wherever you
generate the document, never on a production host.

Then open the bundled **Scalar** viewer at `/docs/api` (available in `local` by default; gate it to
expose it elsewhere). `docuccino:install` publishes `docuccino.yaml` and
`config/docuccino.php`, tells you how many of your routes the default `api/*` pattern matches, and
writes the first document — the defaults are live with or without the published files, and it never
replaces one you already have. Full walkthrough:
**[Getting started](https://docs.docuccino.app/laravel/getting-started/)**.

## Why Docuccino

- **Inferred exception handlers** — error responses are read from your app's *real* exception
  handling (render callbacks, `render()`, `Responsable::toResponse()`), analysed with the thrown
  type narrowed so `instanceof` branches resolve. Error docs are automatic, zero config.
- **Deep Query Builder chains** — `allowedFilters`/`allowedSorts`/`allowedIncludes` are recovered by
  constant-folding through helper methods several calls deep, and pagination params appear when
  the call graph reaches a paginating terminal. No hand-written `#[QueryParameter]` lists.
- **Semantic diff you can gate on** — `docuccino:diff --enforce` compares two artifacts over stable
  identities and enforces a versioning policy (semver / date / none); a breaking change without the
  right version bump fails the build.
- **Determinism as a feature** — byte-identical output for identical code (canonical ordering, no
  timestamps, no absolute paths), asserted by byte-diff invariants in CI so a regeneration can never
  drift unnoticed.
- **Real static analysis, no bespoke type system** — PHPStan + Larastan embedded behind a `TypeEngine`
  boundary; your own PHPStan extensions improve your docs with zero Docuccino-specific API.
- **An extension you can build on** — one reserved member, held to a JSON Schema published at
  `spec.docuccino.app`, carrying the provenance and identities downstream tooling needs.

**Already using another generator?** [Docuccino vs Scramble](https://docs.docuccino.app/guides/vs-scramble/)
and [Docuccino vs Scribe](https://docs.docuccino.app/guides/vs-scribe/) compare the tools fairly and
map your attributes, config, and extensions across.

## Packages

This is a monorepo, subtree-split into individual packages on release.

| Package | Directory | Role |
| --- | --- | --- |
| `docuccino/laravel` | [`php/laravel`](php/laravel/README.md) | The Laravel adapter: provider, config, commands, viewer, integrations. |
| `docuccino/core` | [`php/core`](php/core/README.md) | Framework-agnostic document model, canonicalizer, identities, emitters, diff, contracts. |
| `docuccino/inference-phpstan` | [`php/inference-phpstan`](php/inference-phpstan/README.md) | PHPStan + Larastan embedded behind core's `TypeEngine`. Install as a **dev** dependency. |
| `docuccino/attributes` | [`php/attributes`](php/attributes/README.md) | Dependency-free PHP attribute classes. |

The versioned JSON Schema lives in [`spec/uir/`](spec/uir/) and is served at its `$id` URLs from
`spec.docuccino.app`.

## Documentation

Full documentation is at **[docs.docuccino.app](https://docs.docuccino.app)**:

- [Getting started](https://docs.docuccino.app/laravel/getting-started/)
- [Configuration reference](https://docs.docuccino.app/laravel/reference/configuration/) ·
  [Commands](https://docs.docuccino.app/laravel/reference/commands/) ·
  [Attributes](https://docs.docuccino.app/laravel/reference/attributes/)
- [Package support](https://docs.docuccino.app/laravel/packages/)
- [Extension authoring](https://docs.docuccino.app/extending/extension-authoring/)
- Comparisons: [vs Scramble](https://docs.docuccino.app/guides/vs-scramble/) ·
  [vs Scribe](https://docs.docuccino.app/guides/vs-scribe/)
- [The Docuccino extension](https://docs.docuccino.app/uir/)

Upgrading between releases: [`UPGRADING.md`](UPGRADING.md).

The docs site source lives in [`website/`](website/README.md) (Astro + Starlight).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Contributions require a DCO sign-off (`git commit -s`) and
follow Conventional Commits; determinism and the golden-file discipline are hard, tested guarantees.
Releases and the subtree split are described in [RELEASING.md](RELEASING.md).

## License

MIT © Docuccino. See [LICENSE](LICENSE).
