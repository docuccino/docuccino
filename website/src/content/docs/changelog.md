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

## v0.13.1

### Features

- **laravel**: scope a scaffolded change only where the application forked the shape ([#346](https://github.com/docuccino/docuccino/pull/346))
- **laravel**: write a version change beside the module that owns the shape ([#345](https://github.com/docuccino/docuccino/pull/345))
- **laravel**: scaffold version-change classes from the diff the product can already compute ([#342](https://github.com/docuccino/docuccino/pull/342))
- **laravel**: read version changes from every directory a modular app keeps them in ([#341](https://github.com/docuccino/docuccino/pull/341))
- **laravel**: put a response field a version removed back into the older document ([#336](https://github.com/docuccino/docuccino/pull/336))
- **laravel**: declare when a field became required, and in which direction ([#334](https://github.com/docuccino/docuccino/pull/334))
- **laravel**: scope a version change to chosen operations ([#321](https://github.com/docuccino/docuccino/pull/321))
- **laravel**: derive a document per API version from declared changes ([#320](https://github.com/docuccino/docuccino/pull/320))
- **attributes**: declare the API version-change vocabulary ([#318](https://github.com/docuccino/docuccino/pull/318))

### Bug fixes

- **ci**: retry the type-coverage gate serially, where its cache cannot race itself ([#353](https://github.com/docuccino/docuccino/pull/353))
- **laravel**: publish one error contract for an HTTP error whose status could not be read ([#351](https://github.com/docuccino/docuccino/pull/351))
- **inference-phpstan**: read a construction's status by one rule, in the scope it is written ([#350](https://github.com/docuccino/docuccino/pull/350))
- **laravel**: document the one status a route-name conditional takes on that route ([#349](https://github.com/docuccino/docuccino/pull/349))
- **inference-phpstan**: read the status from the factory a throw names ([#348](https://github.com/docuccino/docuccino/pull/348))
- **inference-phpstan**: read the status an HttpException subclass sets on itself ([#347](https://github.com/docuccino/docuccino/pull/347))
- **core**: mint a class schema identity in one place, facet and all ([#333](https://github.com/docuccino/docuccino/pull/333))
- **inference-phpstan**: stabilise a walk scope through the interface that promises the call ([#339](https://github.com/docuccino/docuccino/pull/339))
- **laravel**: rewrite an example with the schema a version renames ([#328](https://github.com/docuccino/docuccino/pull/328))
- **core**: key a cached fragment on the document whose identities it carries ([#326](https://github.com/docuccino/docuccino/pull/326))
- **laravel**: refuse to widen a version-change scope, and read a version set as versions ([#323](https://github.com/docuccino/docuccino/pull/323))

### Performance

- **laravel**: declare the API version header once and point every operation at it ([#327](https://github.com/docuccino/docuccino/pull/327))

## v0.13.0

### Breaking changes

- **core**: give one schema direction one verdict, wherever it was computed ([#312](https://github.com/docuccino/docuccino/pull/312))
  - `docuccino:diff --enforce` now rejects changes it accepted before, all of them a widening on a RESPONSE: a type set that grew (`schema.type-widened`) or a `type` constraint that left (`schema.type-removed`); an `allOf` branch or the whole `allOf` removed; a `not` removed; a `contains` that was asserting something removed; a `minContains` lowered and a `maxContains` raised. An `$id` arriving on a schema is also breaking now, where before only a changed or removed one was. Requests are unaffected. Migrating a schema between `nullable: true` and `type: [T, null]` now reports nothing at all, where it previously reported a non-breaking `schema.type-widened`.
- **core**: report a body declaration no operation could read ([#309](https://github.com/docuccino/docuccino/pull/309))
  - `attribute.schema-class-unusable` is a new warning, so a pipeline running `--fail-on=warning` can newly fail on an application that binds a request type carrying `#[BodyParameter]` to read routes only. Delete the declaration, describe the parameters with `#[QueryParameter]` on the action, or accept the code via `diagnostics.accept`.
- **core**: decide every keyword the draft model knows, so no repointed discriminator passes --enforce as safe ([#305](https://github.com/docuccino/docuccino/pull/305))
  - `docuccino:diff --enforce` now rejects changes it previously accepted. A discriminator arriving, its tag property renamed, or a mapping entry removed or repointed is breaking on both sides; a mapping entry added, and the discriminator leaving, are breaking on a response, the same argument a value joining a response `enum` makes; a null withdrawn is breaking on both sides and one admitted is breaking on a response; and an `$id`, `$anchor` or `$schema` changed is breaking, because a pointer this diff does not resolve may name the old one and a changed dialect makes every comparison beside it a comparison of two languages. A pipeline gating on the diff may need a version bump it did not need before.
- **core**: give every refinement keyword a direction, so no tightened bound passes --enforce as safe ([#304](https://github.com/docuccino/docuccino/pull/304))
  - `docuccino:diff --enforce` now rejects changes it previously accepted. A refinement tightened (`schema.refinement-narrowed`) is breaking on both sides; one relaxed (`schema.refinement-widened`) is breaking on a response, the same argument a value joining a response `enum` makes; and one whose direction cannot be computed (`schema.refinement-changed`) is breaking, because a false alarm costs the author one look and a false "safe" costs the consumer a broken client. A pipeline gating on the diff may need a version bump it did not need before.
- **core**: let a request type carry the declarations that are true of it ([#295](https://github.com/docuccino/docuccino/pull/295))
  - a `#[BodyParameter]` on a request class used to document nothing and now patches that class's component, so an application that had written one emits a different request body. A class-target attribute a request type is not read for now raises `attribute.schema-class-unread` rather than being ignored, which a build run with warnings as failures will notice. And `RuleSetNormalizer::report()` no longer defaults its source-class argument, so a caller that omits it is refused by PHP rather than silently weighing one declaration site of the two — a signature break for anything outside this repository that calls it.
- **core**: give every subschema position a polarity, so no composition narrowing passes --enforce as safe ([#294](https://github.com/docuccino/docuccino/pull/294))
  - `docuccino:diff --enforce` now rejects changes it previously accepted — a narrowing under any composition or conditional keyword, and any change under `not`, `if` or a definition store. A pipeline that was green on such an edit will now fail, which is the gate telling the truth for the first time; the change is either honest under the document's `versioning` policy or it is not.
- **core**: answer the values OpenAPI 3.2 added to a member's domain when downleveling ([#293](https://github.com/docuccino/docuccino/pull/293))
  - A downleveled 3.1 or 3.0 document no longer carries a parameter whose location OpenAPI 3.2 alone defines, nor a `style` value it alone defines, and each loss raises a `downlevel.value-not-in-3.1` warning. The bytes those documents used to carry were invalid against the version they claimed, so a consumer's validator already refused them; a build gating on warnings will now see one where it previously saw none.

### Bug fixes

- **core**: key a security requirement the way every other diff identity is keyed ([#311](https://github.com/docuccino/docuccino/pull/311))
- **core**: publish no path the scrubber could not account for ([#310](https://github.com/docuccino/docuccino/pull/310))
- **core**: ask a recognised root how deep it is before attributing a run to it ([#308](https://github.com/docuccino/docuccino/pull/308))
- **core**: reduce a bare path a brace or a backslash had refused, where a root already accounts for it ([#307](https://github.com/docuccino/docuccino/pull/307))
- **core**: stop taking a compression wrapper as proof of a local file ([#306](https://github.com/docuccino/docuccino/pull/306))
- **core**: read the two keywords that say what an example may not be ([#292](https://github.com/docuccino/docuccino/pull/292))
- **core**: decide every registered stream wrapper, and guard the table against going short ([#291](https://github.com/docuccino/docuccino/pull/291))

## v0.12.0

### Breaking changes

- **laravel**: let a response-header declaration say nothing about what it did not write ([#287](https://github.com/docuccino/docuccino/pull/287))
  - `Exchange::$headers` is `array<string, list<string>>`; `Exchange::header()` returns a list. Callers reading a single string must take the first element.
- **laravel**: publish only the model keys a response actually carries ([#285](https://github.com/docuccino/docuccino/pull/285))
  - an Eloquent model schema stops publishing keys the server does not return — an append or a `$with` relation named in `$hidden` or absent from a `$visible` allow-list, a name written in both lists, and the framework's own `exists`, `timestamps`, `incrementing`, `preventsLazyLoading`, `wasRecentlyCreated` and `usesUniqueIds` properties. A client generated from the new document loses fields it was never receiving, and a diff against a previously published document reports those keys as removals.
- **attributes**: let a parameter declaration say nothing about required ([#284](https://github.com/docuccino/docuccino/pull/284))
  - `$required` on `#[QueryParameter]`, `#[HeaderParameter]`, `#[CookieParameter]` and `#[ResponseHeader]` is now `?bool` defaulting to `null`. A declaration that relied on the old `false` default to mark a parameter optional must now say `required: false`.
- **core**: tell an absent answer from an answer nobody can read ([#281](https://github.com/docuccino/docuccino/pull/281))
  - `ContractParameter::schema()` returns a `ParameterSchema` rather than `?array`. Call `->read()` for the node and `->kind` for why there isn't one.
- **attributes**: let a body declaration say a field is optional ([#280](https://github.com/docuccino/docuccino/pull/280))
  - `BodyParameter::$required` is now `?bool` defaulting to `null`. Code constructing the attribute positionally, or reading `$required` as a `bool`, must handle the third state.
- **core**: drop ValidationField::type(), which answers a union with null ([#279](https://github.com/docuccino/docuccino/pull/279))
  - `ValidationField::type()` is removed from the rule-transformer surface a third-party `RuleTransformer` is handed. Use `types(): list<string>`, which answers every type word the field carries: one for a scalar type, several for a union, none where nothing has typed it yet. Null is never among the words — nullability is a flag the schema applies as it assembles, so a rule running after `nullable` still reads what the field is. `count($types) === 1 ? $types[0] : null` restores the old answer exactly, and restores the defect with it: branch on the words instead — `$types === []` is "nothing has typed this", and a field stating several is a case to handle, not one to fall through.

### Bug fixes

- **laravel**: report an author-supplied name that matched nothing instead of dropping it silently ([#283](https://github.com/docuccino/docuccino/pull/283))
- **repo**: restore the entries a squashed stack took out of v0.11.0's changelog ([#282](https://github.com/docuccino/docuccino/pull/282))
- **laravel**: credit a webhook delivery for what the check proved ([#277](https://github.com/docuccino/docuccino/pull/277))
- **laravel**: let a nested body declaration reach the key it names ([#276](https://github.com/docuccino/docuccino/pull/276))

## v0.11.0

### Breaking changes

- **core**: gate contract coverage on documented responses, not operations ([#260](https://github.com/docuccino/docuccino/pull/260))
  - `docuccino:coverage --min` is measured against documented responses rather than operations, so an existing floor will read lower. `CoverageReport::total()` and `exercisedCount()` are removed rather than silently re-meaning; `missing()` changes from "operations never exercised" to "operations with any unexercised response". `documentedStatuses()` renders in family order, so `{500, 5XX, 1XX}` reads `500, 1XX, 5XX` where it read `1XX, 500, 5XX`. `assertValidRequest()` no longer credits a response.
- **laravel**: record an example only where an assertion names it ([#271](https://github.com/docuccino/docuccino/pull/271))
  - `ApiContract::record()` no longer publishes an example for every checked response. An exchange is recorded only where the assertion names the scenario — `assertValidExchange(recordAs: 'with-tags')` — so a suite that records today records nothing tomorrow until its call sites name what is worth publishing. Committed recordings already on disk are still read and still publish; each build reports them once as `examples.recording-unnamed`, since no run will refresh them. An explicit `recordAs: ''` now raises rather than being ignored.

### Features

- **core**: hold the payload a webhook dispatches to its contract ([#262](https://github.com/docuccino/docuccino/pull/262))
- **core**: check the response headers the document publishes ([#261](https://github.com/docuccino/docuccino/pull/261))
- **laravel**: report a nested data collection that will be wrapped ([#265](https://github.com/docuccino/docuccino/pull/265))
- **core**: hold a security requirement to the schemes the document publishes ([#272](https://github.com/docuccino/docuccino/pull/272))

### Bug fixes

- **laravel**: mark the response headers the framework always sends as required ([#263](https://github.com/docuccino/docuccino/pull/263))
- **core**: read a parameter's type in the grammar the validator reads ([#266](https://github.com/docuccino/docuccino/pull/266))
- **core**: read an ambiguous empty body as the container the contract accepts ([#267](https://github.com/docuccino/docuccino/pull/267))
- **core**: follow a $ref wherever the grammar permits one ([#268](https://github.com/docuccino/docuccino/pull/268))
- **laravel**: read a #[BodyParameter] name as a field path, not a map key ([#269](https://github.com/docuccino/docuccino/pull/269))
- **laravel**: read a bare array rule as either container, not as a list ([#270](https://github.com/docuccino/docuccino/pull/270))
- **core**: resolve a Reference Object before the diff decides what changed ([#273](https://github.com/docuccino/docuccino/pull/273))

## v0.10.5

### Features

- **core**: report an annotation-only schema change without gating on it ([#256](https://github.com/docuccino/docuccino/pull/256))

### Bug fixes

- **core**: read a generated key as its kind, not its identity ([#257](https://github.com/docuccino/docuccino/pull/257))

## v0.10.4

### Features

- **core**: describe a request body per operation ([#253](https://github.com/docuccino/docuccino/pull/253))

### Bug fixes

- **laravel**: keep the declared container through the rule vocabulary ([#252](https://github.com/docuccino/docuccino/pull/252))
- **laravel**: a date field's format comes from its most specific source ([#251](https://github.com/docuccino/docuccino/pull/251))
- **core**: read a lint safelist pointer in the spelling every message prints ([#250](https://github.com/docuccino/docuccino/pull/250))

## v0.10.3

### Features

- **core**: let a class describe the schema it publishes ([#247](https://github.com/docuccino/docuccino/pull/247))

### Bug fixes

- **laravel**: follow a remapped property to the key the request accepts ([#246](https://github.com/docuccino/docuccino/pull/246))
- **core**: publish an input DTO's own prose on the request side ([#245](https://github.com/docuccino/docuccino/pull/245))

## v0.10.2

### Bug fixes

- **laravel**: wrap every arm of a composite at the response root ([#243](https://github.com/docuccino/docuccino/pull/243))

## v0.10.1

### Bug fixes

- **core**: read a quoted JSON string example as the string it quotes ([#239](https://github.com/docuccino/docuccino/pull/239))
- **laravel**: a union keeps every member a producer contributes one shape to ([#238](https://github.com/docuccino/docuccino/pull/238))

### Performance

- **ci**: make the coverage gate fast and separately re-runnable ([#241](https://github.com/docuccino/docuccino/pull/241))

## v0.10.0

### Breaking changes

- **laravel**: say what became of a configured path instead of failing in silence ([#228](https://github.com/docuccino/docuccino/pull/228))
  - a document whose `info.description.file` could not be read published `description: ""` and now publishes no `description` member, with the `configHash` moving to match. An empty string claims this API's description *is* the empty string; absent is the true answer, and it now arrives with an error or a warning naming the key. Separately, a document naming `coverage.log` by absolute path re-fingerprints once, because that path is no longer folded into `configHash` — it was machine-dependent there.
- **core**: scrub a machine out of a message without rewriting what the author wrote ([#218](https://github.com/docuccino/docuccino/pull/218))
  - the drop half of `downlevel.path-item-ref` is now `downlevel.path-item-unresolved`. A pipeline at `--fail-on=warning` that accepted the old code to silence the inlining notice can now fail on the drop, which is the point — the two were never the same finding.
- **attributes**: stop declaring a target nothing can honour ([#216](https://github.com/docuccino/docuccino/pull/216))
  - `#[Summary]` no longer declares `TARGET_PROPERTY` and `#[Example]` no longer declares `TARGET_PARAMETER`. Code reflecting either at those targets now throws where it did not, and a `#[Summary]` written on a property is a PHP error rather than a build diagnostic.
- **laravel**: consult #[IgnoreResponse] before a producer converts a body ([#206](https://github.com/docuccino/docuccino/pull/206))
  - a document built from unchanged code can now lose responses it used to publish. A consumer cannot tell a repaired defect from a withdrawn response — a generated client loses a case from its error union, and `docuccino:diff` reports a removed response — so this is treated as breaking from the outside even though the old behaviour was the bug.
- **core**: derive a schema keyword's shape from its own contract ([#203](https://github.com/docuccino/docuccino/pull/203))
  - `unevaluatedItems`, `unevaluatedProperties` and `additionalItems` now publish as objects rather than empty arrays and take their place in the normative member order. A boolean at `items`, `contains`, `not`, `if`, `then`, `else` or `propertyNames` is published as written, which reverses what `not: false` means to a generated client.
- **core**: downlevel a response whose key is spelled like a keyword ([#202](https://github.com/docuccino/docuccino/pull/202))
  - a 3.0 document's `responses.default` and any component or header named after a schema keyword are now converted rather than passed through, so 2020-12 constructs at those positions become their 3.0 equivalents. A `components.pathItems` entry referenced from `paths` is inlined at each use site instead of leaving a `$ref` to a bucket 3.0 does not have.
- **core**: stop a numeric or multi-shape component name colliding in silence ([#199](https://github.com/docuccino/docuccino/pull/199))
  - a shared error response offering two or more representations that each reference a distinct component is now published as `AuthenticationChallenge_ProblemDetailsData` rather than `AuthenticationChallengeProblemDetailsData`, renaming that type in generated clients. `--yaml` output quotes numeric mapping keys, so every `responses` key moves from `200:` to `'200':`.
- **core**: give every server variable the default the spec requires ([#196](https://github.com/docuccino/docuccino/pull/196))
  - `postman.server-variable-no-default` is retired in favour of `server.variable-no-default`, which every emitter raises; a `diagnostics.accept` entry or `--fail-on` filter naming the old code stops matching and will surface as `config.accept-unused`. A server variable declaring an `enum` and no `default` now publishes the enum's first value as its `default`; one declaring neither is no longer emitted.
- **core**: name a contested error response for the body it actually carries ([#192](https://github.com/docuccino/docuccino/pull/192))
  - an error response carrying a representation its claimed name does not describe is now published under a name derived from the components it references, instead of that claim plus a content hash. A document that published two hash-suffixed variants of one name now publishes the claimed name and a derived one, so a generated client's type name changes for the contested body.

### Features

- **core**: read #[Example] and #[Description] on the properties they declare ([#204](https://github.com/docuccino/docuccino/pull/204))
- **attributes**: let a #[Response] name the error component it declares ([#193](https://github.com/docuccino/docuccino/pull/193))

### Bug fixes

- **core**: say a recordings directory was refused instead of publishing nothing ([#236](https://github.com/docuccino/docuccino/pull/236))
- **core**: reduce a path a colon interrupts, and redact a shallow machine prefix ([#229](https://github.com/docuccino/docuccino/pull/229))
- **core**: name a class the same way wherever a build prints one ([#227](https://github.com/docuccino/docuccino/pull/227))
- **core**: read a boolean subschema as the schema it is when building an example ([#226](https://github.com/docuccino/docuccino/pull/226))
- **core**: drop every member OpenAPI 3.2 added when downleveling ([#224](https://github.com/docuccino/docuccino/pull/224))
- **core**: read a boolean at a top-level Schema slot instead of dropping the member ([#221](https://github.com/docuccino/docuccino/pull/221))
- **laravel**: describe the filter kinds the installed query builder ships ([#211](https://github.com/docuccino/docuccino/pull/211))
- **laravel**: recognise every name mapper the installed spatie ships ([#209](https://github.com/docuccino/docuccino/pull/209))
- **core**: close the loose ends a diagnostics sweep left ([#208](https://github.com/docuccino/docuccino/pull/208))
- **inference-phpstan**: follow a fluent call to the status it sets ([#207](https://github.com/docuccino/docuccino/pull/207))
- **laravel**: read an authored @example wherever it is written ([#205](https://github.com/docuccino/docuccino/pull/205))
- **core**: pull JSON into a document through one reader ([#200](https://github.com/docuccino/docuccino/pull/200))
- **core**: write empty maps as maps in YAML output ([#197](https://github.com/docuccino/docuccino/pull/197))
- **core**: give a 3.0 operation the responses the spec requires of it ([#195](https://github.com/docuccino/docuccino/pull/195))
- **core**: keep an authored empty-object example an object from docblock to bytes ([#191](https://github.com/docuccino/docuccino/pull/191))

## v0.9.1

### Bug fixes

- **core**: audit examples against the document that ships, and never let a lint kill the export ([#189](https://github.com/docuccino/docuccino/pull/189))

## v0.9.0

### Breaking changes

- **core**: retract the keywords a declared schema shape supersedes ([#185](https://github.com/docuccino/docuccino/pull/185))
  - a schema whose shape is declared by an attribute, docblock or overlay no longer publishes the inference keywords that declaration supersedes. Documents gain a closed shape where they previously advertised extra keys, and lose `type`/`items` beside a declared `$ref`.
- **laravel**: patch a recovered request body instead of replacing it ([#184](https://github.com/docuccino/docuccino/pull/184))
  - an operation with both a recovered request body and a `#[BodyParameter]` now publishes the recovered properties with the declared one patched in, rather than only the declared one, and gains the `422` it was previously missing. Documents affected by the old behaviour will change shape — in the direction of describing what the endpoint actually accepts.
- **core**: name a shared error component for what it is, not for what surrounded it ([#183](https://github.com/docuccino/docuccino/pull/183))
  - shared error components are named and grouped differently. Two operations whose error bodies differ only in wording now share one `components.responses` entry — most often gaining a named type where each previously kept an inline schema, since the wording had been keeping them below the sharing threshold — and a hoisted shape under a multi-representation response is named `Error<status>` rather than inheriting the response's claimed name. Non-plurality arms gain `summary`/`description` beside their `$ref` in 3.1/3.2 output; a 3.0 export drops that wording with a `downlevel.ref-siblings` note. Examples now merge across wordings, so an operation may advertise an example another operation recorded. A generated client written against the previous names will need regenerating.
- **core**: let a declared response retract the placeholders it supersedes ([#181](https://github.com/docuccino/docuccino/pull/181))
  - the `inferred-response.unpinned-redirect` diagnostic is now `lint.unpinned-redirect`. Anything filtering on the old code, or safelisting it under `diagnostics.accept`, needs the new name.

### Bug fixes

- **laravel**: stop three help strings prescribing a remedy that changes nothing ([#187](https://github.com/docuccino/docuccino/pull/187))
- **laravel**: remove an ignored parameter late enough that it stays removed ([#186](https://github.com/docuccino/docuccino/pull/186))
- **laravel**: read a docblock example as the type its schema declares ([#180](https://github.com/docuccino/docuccino/pull/180))
- **core**: honour a document's configured format samples in the Postman collection ([#179](https://github.com/docuccino/docuccino/pull/179))

## v0.8.5

### Features

- **laravel**: share the pagination envelope links and meta across page components ([#177](https://github.com/docuccino/docuccino/pull/177))
- **laravel**: let a document override filter descriptions and format samples ([#176](https://github.com/docuccino/docuccino/pull/176))
- **core**: synthesize a property example from the validation rules a request recovers ([#171](https://github.com/docuccino/docuccino/pull/171))
- **laravel**: share one page component per item type and paginator kind ([#169](https://github.com/docuccino/docuccino/pull/169))

### Bug fixes

- **laravel**: stop the diagnostics channel drowning in noise nobody can act on ([#175](https://github.com/docuccino/docuccino/pull/175))
- **laravel**: stamp coverage-span tests from one base and let a reason replace the generic note ([#174](https://github.com/docuccino/docuccino/pull/174))
- **laravel**: let a QB entry's own comment and default outrank its filter class attribute ([#173](https://github.com/docuccino/docuccino/pull/173))
- **laravel**: describe each Query Builder filter by the match it performs ([#170](https://github.com/docuccino/docuccino/pull/170))
- **laravel**: declare the packages the integrations target, and name the real fields separator ([#168](https://github.com/docuccino/docuccino/pull/168))
- **core**: publish the reason a deprecated operation carries ([#167](https://github.com/docuccino/docuccino/pull/167))

## v0.8.4

### Features

- **core**: project tag groups and pass viewer configuration through ([#158](https://github.com/docuccino/docuccino/pull/158))
- **laravel**: document sparse fieldsets as enums of the allow-list ([#154](https://github.com/docuccino/docuccino/pull/154))
- **laravel**: describe and name the include and sort enum values ([#153](https://github.com/docuccino/docuccino/pull/153))
- **laravel**: document include and sort as enums of the allow-list ([#148](https://github.com/docuccino/docuccino/pull/148))

### Bug fixes

- **laravel**: serve each viewer the OpenAPI version it implements ([#157](https://github.com/docuccino/docuccino/pull/157))
- **laravel**: report unreadable attributes, inherit class-level ones, read @deprecated ([#161](https://github.com/docuccino/docuccino/pull/161))
- **laravel**: type string-backed enum route bindings from the enum ([#160](https://github.com/docuccino/docuccino/pull/160))
- **core**: flag unions whose empty branch erases the typed contract ([#162](https://github.com/docuccino/docuccino/pull/162))
- **laravel**: degrade include and sort typing on spatie/laravel-query-builder below v7 ([#152](https://github.com/docuccino/docuccino/pull/152))
- **core**: emit an explicit empty schema for untyped parameters ([#147](https://github.com/docuccino/docuccino/pull/147))

## v0.8.3

### Features

- **laravel**: type QB foreign-key filters off the related model's key ([#142](https://github.com/docuccino/docuccino/pull/142))
- **laravel**: type QB filters off the subject model's primary key ([#141](https://github.com/docuccino/docuccino/pull/141))

### Bug fixes

- **laravel**: let a shared filter class declare its schema via a class-level attribute ([#143](https://github.com/docuccino/docuccino/pull/143))

## v0.8.2

### Features

- **website**: add robots.txt pointing crawlers at the sitemap ([#139](https://github.com/docuccino/docuccino/pull/139))

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
