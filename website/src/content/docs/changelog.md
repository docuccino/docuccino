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

## v0.3.0

### Breaking changes

- **core**: carry node identities into exported OpenAPI so the diff stays semantic ([#18](https://github.com/docuccino/docuccino/pull/18))
  - `docuccino:export` writes an `x-docuccino-id` member on every node of an OpenAPI artifact. Re-exporting an existing artifact shows that as a one-time diff; pass `--drop-ids` for the previous bytes. Emitting through the library is unaffected — `OpenApi32Emitter::emit()` still drops every Docuccino member by default.

### Bug fixes

- **laravel**: illustrate an error member with the value its schema states ([#16](https://github.com/docuccino/docuccino/pull/16))

## v0.2.1

### Bug fixes

- **laravel**: document an error response under the status its body states ([#14](https://github.com/docuccino/docuccino/pull/14))

## v0.2.0

### Breaking changes

- **laravel**: document spatie's inherited POST 201 default ([#10](https://github.com/docuccino/docuccino/pull/10))
  - a POST action returning a spatie Data class without its own `calculateResponseStatus()` override is now documented `201` instead of `200`. This matches what the application actually returns, but it changes emitted output — and so a `docuccino:diff` result — with no action from the user. An app that genuinely answers 200 on a POST is one that overrides the method, and the override is still read first.
