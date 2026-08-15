---
title: Changelog
description: Every user-facing change in Docuccino — breaking changes, features, fixes and performance work — for all four packages.
---

**This page is generated** from the commit history by `tools/changelog.php` — do not edit it
by hand, fix the commit message instead.

Every user-facing change across the four packages. The bold prefix is the commit's scope:
**core**, **attributes**, **laravel** and **inference-phpstan** are the packages, and the
rest (**website**, **repo**, **ci**) ship no package. Entries begin after v0.1.2; older history
is in the [repository](https://github.com/docuccino/docuccino) git log.

Each package repository also carries its own `CHANGELOG.md` with just its entries.

## v0.4.0

### Breaking changes

- **core**: name the thing a diagnostic's reader must go and change ([#37](https://github.com/docuccino/docuccino/pull/37))
  - `identity.duplicate-operation` is renamed to `route.duplicate-operation`. Anything matching that code by name must be updated. The other two renames were added after v0.3.0 and have never shipped.
- **core**: mint every component name from what it is, and stop warning about correct code ([#35](https://github.com/docuccino/docuccino/pull/35))
  - two contested definitions in `components.responses` or `components.securitySchemes` now take content-derived names instead of a first-come `_2` suffix, and the `security` requirements naming them are repointed. A host-bound operation's `servers` URL now carries the port and base path of the document server it inherits from.
- **core**: publish a component under the name its schema earns, not the slot it landed in ([#30](https://github.com/docuccino/docuccino/pull/30))
  - a class-derived request body now publishes under a `Request`-suffixed component name. The facet applies whether or not the name is contested — that is what makes it local, since adding a read endpoint can then only ever *add* a name rather than reassign one. A Spatie `ArticleData` used only as a request body publishes as `ArticleDataRequest` where it published as `ArticleData`, so a generated client renames that type once. Pin the old name with `#[SchemaName]` if you need it unchanged.
- **inference-phpstan**: delete the worker pool and the engine result cache ([#21](https://github.com/docuccino/docuccino/pull/21))
  - docuccino/inference-phpstan no longer ships the bin/worker.php binary or depends on symfony/process, and the @internal Orchestration and Cache namespaces are gone along with PhpStanEngineFactory::createOrchestrated() and ::createCaching(). The docuccino/laravel engine.mode values "orchestrated" and "caching" are removed; setting either now degrades to in-process with an engine.mode-unknown warning in place of engine.mode-not-wired.

### Bug fixes

- **laravel**: warn when a published value came from the build machine ([#34](https://github.com/docuccino/docuccino/pull/34))
- **core**: key a fragment on where a fact was written, and give two identities two components ([#33](https://github.com/docuccino/docuccino/pull/33))
- **laravel**: type a bound parameter from the column it names, and stop publishing the catch-all ([#32](https://github.com/docuccino/docuccino/pull/32))
- **laravel**: document a route bound to a host as an operation of its own ([#29](https://github.com/docuccino/docuccino/pull/29))
- **inference-phpstan**: serve a memoised response shape only to a caller that could have earned it ([#27](https://github.com/docuccino/docuccino/pull/27))
- **core**: share an error body by its shape, not by its wording ([#26](https://github.com/docuccino/docuccino/pull/26))
- **laravel**: document rate-limit headers by meaning, not by value ([#24](https://github.com/docuccino/docuccino/pull/24))
- **laravel**: emit real types where the schemas documented nothing ([#23](https://github.com/docuccino/docuccino/pull/23))
- **laravel**: make the fragment cache safe to turn on, and warm builds cheap ([#20](https://github.com/docuccino/docuccino/pull/20))

## v0.3.0

### Breaking changes

- **core**: carry node identities into exported OpenAPI so the diff stays semantic ([#18](https://github.com/docuccino/docuccino/pull/18))
  - `docuccino:export` writes an `x-docuccino-id` member on every node of an OpenAPI artifact. Re-exporting an existing artifact shows that as a one-time diff; pass `--drop-ids` for the previous bytes. Emitting through the library is unaffected — `OpenApi32Emitter::emit()` still drops every Docuccino member by default.

### Features

- **laravel**: recover the Query Builder allow-lists a method or a constructor builds ([#19](https://github.com/docuccino/docuccino/pull/19))

### Bug fixes

- **laravel**: illustrate an error member with the value its schema states ([#16](https://github.com/docuccino/docuccino/pull/16))

## v0.2.1

### Bug fixes

- **laravel**: document an error response under the status its body states ([#14](https://github.com/docuccino/docuccino/pull/14))

## v0.2.0

### Breaking changes

- **laravel**: document spatie's inherited POST 201 default ([#10](https://github.com/docuccino/docuccino/pull/10))
  - a POST action returning a spatie Data class without its own `calculateResponseStatus()` override is now documented `201` instead of `200`. This matches what the application actually returns, but it changes emitted output — and so a `docuccino:diff` result — with no action from the user. An app that genuinely answers 200 on a POST is one that overrides the method, and the override is still read first.
