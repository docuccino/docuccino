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

## v0.8.1

### Bug fixes

- **laravel**: type QB filters off their declared column binding ([#137](https://github.com/docuccino/docuccino/pull/137))

## v0.8.0

### Breaking changes

- **attributes**: add format to the parameter attributes ([#135](https://github.com/docuccino/docuccino/pull/135))
  - `$format` sits before `$required` in the constructors of `QueryParameter`, `HeaderParameter`, `CookieParameter` and `BodyParameter`, so positional arguments past `$description` shift by one; named arguments are unaffected.

## v0.7.0

### Breaking changes

- **core**: classify response-side enum changes as breaking ([#131](https://github.com/docuccino/docuccino/pull/131))
  - docuccino:diff --enforce now fails changesets that add an enum value to, or drop an enum constraint from, a response schema or a referenced component schema; these previously passed as non-breaking.

### Features

- **core**: track component schema direction to refine enum classification ([#132](https://github.com/docuccino/docuccino/pull/132))

## v0.6.1

### Performance

- **inference-phpstan**: replay a recorded file walk instead of re-running the resolver ([#128](https://github.com/docuccino/docuccino/pull/128))
- **inference-phpstan**: harvest a file's methods, closures and assignments in one pass ([#127](https://github.com/docuccino/docuccino/pull/127))

## v0.6.0

### Breaking changes

- **attributes**: address the API consumer with #[Summary] and #[Description] ([#118](https://github.com/docuccino/docuccino/pull/118))
  - `#[DescriptionFromFile('docs/x.md')]` is removed. Use `#[Description(file: 'docs/x.md')]`, which does the same thing and also takes inline prose as `text:`. A second attribute that does almost the same job is a worse API than one that does both — the same reasoning that gave `#[Example]` its `file:` argument rather than a sibling attribute.

### Features

- **laravel**: make contract coverage a post-run command ([#124](https://github.com/docuccino/docuccino/pull/124))
- **laravel**: walk a new install to its first document ([#120](https://github.com/docuccino/docuccino/pull/120))
- **laravel**: point a diagnostic at the page that documents it ([#119](https://github.com/docuccino/docuccino/pull/119))
- **laravel**: read the provenance trail back with docuccino:explain ([#116](https://github.com/docuccino/docuccino/pull/116))
- **laravel**: accept the diagnostic codes you have already read ([#114](https://github.com/docuccino/docuccino/pull/114))
- **laravel**: record your test suite's responses as documented examples ([#109](https://github.com/docuccino/docuccino/pull/109))
- **core**: show several named examples, and read one from a file ([#108](https://github.com/docuccino/docuccino/pull/108))
- **laravel**: assert your test suite against the generated contract ([#107](https://github.com/docuccino/docuccino/pull/107))
- **laravel**: rebuild and refresh the viewer as your code changes ([#106](https://github.com/docuccino/docuccino/pull/106))
- **laravel**: resolve the viewer through its contract and ship a Redoc driver ([#105](https://github.com/docuccino/docuccino/pull/105))
- **core**: lint the document for missing prose, unusable ids and undeclared tags ([#104](https://github.com/docuccino/docuccino/pull/104))
- **laravel**: let --fail-on gate on the info and hint rungs ([#103](https://github.com/docuccino/docuccino/pull/103))
- **laravel**: hint how a mock server should fake a property ([#102](https://github.com/docuccino/docuccino/pull/102))
- **laravel**: document the webhooks an API delivers ([#101](https://github.com/docuccino/docuccino/pull/101))
- **laravel**: document binary, file, streamed and SSE responses ([#99](https://github.com/docuccino/docuccino/pull/99))
- **laravel**: let projects extend the engine's PHPStan configuration ([#90](https://github.com/docuccino/docuccino/pull/90))
- **core**: promote the schema conversion surface used by integrations to a public contract ([#85](https://github.com/docuccino/docuccino/pull/85))
- **core**: emit a Postman collection ([#81](https://github.com/docuccino/docuccino/pull/81))
- **laravel**: write every configured export target in one run ([#80](https://github.com/docuccino/docuccino/pull/80))

### Bug fixes

- **laravel**: widen a rule's values for every entry that names none ([#125](https://github.com/docuccino/docuccino/pull/125))
- **core**: report a credential a recording cannot redact ([#126](https://github.com/docuccino/docuccino/pull/126))
- **core**: place a call's arguments where its readers index them ([#123](https://github.com/docuccino/docuccino/pull/123))
- **core**: agree the example report's verb with how many examples lied ([#121](https://github.com/docuccino/docuccino/pull/121))
- **laravel**: say why a driver's own response never live-reloads ([#115](https://github.com/docuccino/docuccino/pull/115))
- **repo**: stage a cache-invalidation test's disagreement in its own directory ([#110](https://github.com/docuccino/docuccino/pull/110))
- **laravel**: document a rendered view as text/html instead of reflecting it ([#98](https://github.com/docuccino/docuccino/pull/98))
- **inference-phpstan**: gate the throw-registry rescue on the resolved callee ([#89](https://github.com/docuccino/docuccino/pull/89))
- **laravel**: reject unknown option values and diagnose misconfigured keys ([#88](https://github.com/docuccino/docuccino/pull/88))
- **laravel**: publish the real generator version ([#87](https://github.com/docuccino/docuccino/pull/87))
- **laravel**: print diagnostic help text in command output ([#86](https://github.com/docuccino/docuccino/pull/86))
- **core**: surface engine boot failures and stop caching their degraded fragments ([#84](https://github.com/docuccino/docuccino/pull/84))
- **core**: keep the export destination out of the document config hash ([#79](https://github.com/docuccino/docuccino/pull/79))

## v0.5.1

### Features

- **core**: let a finding the whole document reports travel on the route that found it ([#70](https://github.com/docuccino/docuccino/pull/70))

### Bug fixes

- **laravel**: document a page-size key only where the key's value IS the size ([#75](https://github.com/docuccino/docuccino/pull/75))
- **inference-phpstan**: serve a local's value only to the method that wrote it, and only until something rewrites it ([#74](https://github.com/docuccino/docuccino/pull/74))
- **inference-phpstan**: report the file a node was written in, which is not always the file being analysed ([#73](https://github.com/docuccino/docuccino/pull/73))
- **core**: read every form that writes a local, not only the plain assignment ([#72](https://github.com/docuccino/docuccino/pull/72))
- **laravel**: report the handler deferrals a warm build had been coming back without ([#71](https://github.com/docuccino/docuccino/pull/71))
- **laravel**: let a diagnostic name the closure it means without naming the machine ([#69](https://github.com/docuccino/docuccino/pull/69))
- **core**: stop a collision blaming an author for a name nothing claimed, and name the remedy ([#66](https://github.com/docuccino/docuccino/pull/66))
- **laravel**: let the tier that cannot read an error body stand aside for one that can ([#65](https://github.com/docuccino/docuccino/pull/65))
- **inference-phpstan**: read a response named in a local as the response it was named from ([#64](https://github.com/docuccino/docuccino/pull/64))
- **laravel**: document the page-size key a list endpoint really reads ([#63](https://github.com/docuccino/docuccino/pull/63))

## v0.5.0

### Breaking changes

- **laravel**: name only the paging keys an endpoint really reads ([#58](https://github.com/docuccino/docuccino/pull/58))
  - a Spatie Query Builder list endpoint no longer documents a `per_page` query parameter, and documents its page key under the name the paginating call site gave it. An application that really reads a page-size key, or one whose helper renames the page key out of sight, declares it with `#[QueryParameter]`.
- **core**: share one error response between arms that only illustrate it differently ([#51](https://github.com/docuccino/docuccino/pull/51))
  - an application whose operations state one error response with differing examples now publishes one `components.responses` entry with an `examples` map, where it previously published either an inline response per operation or one hash-discriminated component per illustration. The `$ref`s those operations emit, and the type names a client generated from them carries, change accordingly.
- **core**: diff what a client must satisfy, not only what it asks for ([#55](https://github.com/docuccino/docuccino/pull/55))
  - `Changeset::$unreferencedSchemas` is now `$unreferencedComponents` (`unreferencedComponents` in the JSON payload), since a security scheme nothing requires is stood down into the same list. The `ChangeTarget` enum gains a `securityScheme` case.
- **core**: count every parameter an old artifact declares, and print none of its text as written ([#46](https://github.com/docuccino/docuccino/pull/46))
  - a diff whose old side declares parameters on a path item, or reuses one `x-docuccino.id` across two nodes, now reports removals, additions and edits it previously passed over in silence, so `docuccino:diff --enforce` can fail a comparison it used to allow. The finding is real either way — the gate was reading a document it could not see all of.
- **core**: read a diff's $ref'd parameters as the parameters they name ([#43](https://github.com/docuccino/docuccino/pull/43))
  - a diff of a document whose operations share parameters through `components.parameters` now reports parameter removals, additions and edits it previously passed over in silence, so `docuccino:diff --enforce` can fail a comparison it used to allow. The finding is real either way — the gate was reading a document it could not see all of.
- **core**: let the producer of an error response name the component it publishes under ([#40](https://github.com/docuccino/docuccino/pull/40))
  - shared error components are published under the name of the error rather than its status. Laravel's own errors become `BadRequest`, `Unauthorized`, `Forbidden`, `NotFound`, `UnprocessableEntity` and `TooManyRequests` in `components.schemas` and `components.responses`, in place of `Error400`, `Error401`, `Error403`, `Error404`, `Error422` and `Error429`; their `x-docuccino` component ids change with them. Regenerating a client renames its error types to match — the statuses and bodies are unchanged, so only a hand-written `catch` on the old type name needs updating. A status nothing claims a name for is still `Error<status>`. To choose your own names, register an `ExceptionToResponse` or claim over a built-in from an `OperationExtension`; see the docs at /extending/extension-authoring/#naming-the-component-an-error-publishes-under.
- **core**: pair an exported artifact's parameters and schemas by the id it carries ([#38](https://github.com/docuccino/docuccino/pull/38))
  - emitted documents change for existing projects. Response component names are content-derived, so dropping an unbindable member's placeholder from an example re-mints the hash suffix of any component whose example carried one, and those names flow into generated client type names. A request property recovered as `array<string, V>` whose class overrides `rules()` now emits `{"type":"object","additionalProperties":{}}` where it emitted `{"type":"array"}`. Regenerate and re-commit the artifact you diff against.

### Features

- **laravel**: tell a consumer how to ask for the next page, not just which one they are on ([#52](https://github.com/docuccino/docuccino/pull/52))
- **laravel**: let the render method that built an error body name it ([#54](https://github.com/docuccino/docuccino/pull/54))
- **laravel**: let an exception name the error component it publishes under ([#45](https://github.com/docuccino/docuccino/pull/45))

### Bug fixes

- **core**: tell the truth about two changes the diff went quiet on ([#61](https://github.com/docuccino/docuccino/pull/61))
- **laravel**: let a thrown message name a file without naming the machine ([#60](https://github.com/docuccino/docuccino/pull/60))
- **inference-phpstan**: read a match arm's several conditions as alternatives, not requirements ([#57](https://github.com/docuccino/docuccino/pull/57))
- **core**: make every byte a diff prints, and every node it pairs, survive the artifact ([#56](https://github.com/docuccino/docuccino/pull/56))
- **repo**: finish escaping at the render boundary, and guard the fixture app against drift ([#53](https://github.com/docuccino/docuccino/pull/53))
- **core**: stop a schema no operation reaches failing the diff gate as breaking ([#50](https://github.com/docuccino/docuccino/pull/50))
- **repo**: stop the cold type-coverage run deadlocking in a forked child ([#49](https://github.com/docuccino/docuccino/pull/49))
- **laravel**: escape a diagnostic where it is printed, not where it is written ([#48](https://github.com/docuccino/docuccino/pull/48))
- **inference-phpstan**: stop promising a body member the response sometimes omits ([#44](https://github.com/docuccino/docuccino/pull/44))

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
