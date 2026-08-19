---
title: Diagnostics reference
description: Every diagnostic code Docuccino emits — what it means, how loud it is, and what to do about it.
---


Diagnostics are how Docuccino tells you it knows less than your code does, or that something you
configured didn't take. They're printed by `docuccino:export`, `docuccino:validate`, `docuccino:cache`
and `docuccino:watch`, grouped by route and in a stable order, and they can be embedded in a UIR
document with `--embed-diagnostics`.

Every one carries a **code** — the stable identifier in the first column below — plus a message
naming the exact class, route or file it's about, and usually a line of help. This page is the
exhaustive list. If you're working back from a symptom rather than from a code, start at
[Troubleshooting](/laravel/guides/troubleshooting/).

Every code a build reports prints a link to this page beneath it, the first time that code
appears in a run — so a code you met in your terminal is one click from what it means.

## Severities

A diagnostic's severity is what `--fail-on` gates on: pass it a floor and anything that loud or
louder exits non-zero.

| Severity | What it means |
|---|---|
| **Error** | Something is missing from the document, or the document is invalid. Always worth fixing. |
| **Warning** | Something you wrote didn't take effect, or the output is less useful than you asked for. |
| **Info** | Docuccino recovered less than your code says and widened to stay truthful. Normal in small numbers; the same code on every action is a signal. |
| **Hint** | Noise Docuccino dropped on purpose. Nothing to do. |

```bash
# Fail CI on anything that isn't a recovery note.
php artisan docuccino:export --fail-on=warning

# Tighter: gate on inference certainty too, once the info list is short.
php artisan docuccino:export --fail-on=info
```

On an existing codebase, start at `warning` and tighten to `info` later. A gate that fires on day one
is a gate the team switches off.

## Accepting a code

The other way to tighten a gate is to accept the codes you can't act on. List them under
[`diagnostics.accept`](/laravel/reference/configuration/#diagnostics) and they keep printing — marked
`accepted`, with a hit count at the end of the block — while `--fail-on` stops counting them:

```php
'diagnostics' => [
    'accept' => ['eloquent.no-columns', 'validation.rule-unrecoverable'],
],
```

```
    [info, accepted] eloquent.no-columns: Model App\Vendor\Ledger exposes no documentable columns.
  Accepted, so --fail-on ignores them: eloquent.no-columns (12)
```

Acceptance changes the exit code and nothing else: the document is byte-identical either way, and the
report is still in the log the day it starts firing somewhere new. **An `error` is never accepted** —
it says the document is wrong or the build lost a whole tier of facts, and an entry that reaches one
is reported as `config.accept-refused` while the run fails as it always would. An entry nothing
reports is reported as `config.accept-unused`, so the list can't outlive what it was for.

## The engine and inference

Docuccino reads your types with an embedded static analyzer. These codes are about the analyzer
itself — whether it ran, and where reading your code hit a bound.

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `engine.not-installed` | warning | No analyzer is present, so the document came from docblocks and attributes only | [Install the engine](/laravel/guides/troubleshooting/#the-document-is-thin): `composer require --dev docuccino/inference-phpstan`. Set `DOCUCCINO_ENGINE=null` if you meant to document without inference |
| `engine.boot-failed` | error | The analyzer is installed and couldn't start, so the document came from docblocks and attributes only | Generate from the project root, in an environment your app boots in — see [the analyzer won't start](/laravel/guides/troubleshooting/#the-analyzer-wont-start). The message quotes what it threw |
| `engine.mode-unknown` | warning | `engine.mode` isn't a mode Docuccino has; it ran in-process anyway | Set `DOCUCCINO_ENGINE` to `in-process` or `null` — see [Engine](/laravel/reference/configuration/#engine) |
| `inference.action-failed` | warning | Analyzing one action threw | One or two: annotate those actions. All of them: you probably have a [version mismatch](/laravel/guides/troubleshooting/#responses-are-missing-everywhere) |
| `inference.method-not-found` | warning | An action's method has no body the analyzer can read — it's abstract, or resolved to something that isn't a method | Point the route at a concrete method, or state the response with [`#[Response]`](/laravel/reference/attributes/#response) |
| `inference.callable-failed` | warning | Analyzing a callable the trace followed — a render callback, a query-object method — threw | The message names it. Simplify it, or state the shape it produces with an attribute |
| `inference.callable-not-found` | info | A callable the trace wanted to follow has no readable body | Usually vendor code, and usually fine. If it's yours, express it as a plain method the analyzer can reach |
| `inference.response-shape-truncated` | info | Recovering a response shape ran out of descent depth or file budget, so the response is documented as its bare declared type — true, but poorer than your code is | Shorten the helper chain between the action and the value it returns, or state the response with [`#[Response]`](/laravel/reference/attributes/#response) |
| `inference.ambiguous-narrowing` | info | More than one return site is reachable for the narrowed type, so the first in source order was used and the recovered shape may be ambiguous | Give the action one return site per status, or pin the ones you care about with [`#[Response]`](/laravel/reference/attributes/#response) |
| `inference.throw-noise-dropped` | hint | Implicit "this could throw anything" points were dropped so they don't become error responses | Nothing. This is the analyzer being quiet on your behalf |

## Routes, operations and names

The document has one operation per path and method, and one component per name. These codes fire
where two things want the same slot, or where a name reached the document that a client can't use.

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `route.build-failed` | error | Documenting one route threw, so it was omitted or replaced by a skeleton operation | The message quotes the failure and names the route. Fix it, or exclude the route from the document |
| `route.duplicate-operation` | error | Two routes resolve to the same operation identity — their paths differ only by a parameter's name, which isn't part of an identity — so a semantic diff pairs them as one operation | Give one of them a path that differs by more than a parameter name |
| `route.operation-collision` | error | Two routes claim one path and method, usually the same URI on two hosts, and OpenAPI has room for one | Remove the duplicate registration, or give each host its own [document](/laravel/guides/multiple-documents/) — see [routes bound to a host](/laravel/guides/production/#routes-bound-to-a-host) |
| `route.duplicate-operation-id` | warning | Two routes publish one `operationId`, so a generated client names one function for the pair | Give one its own id with [`#[OperationId]`](/laravel/reference/attributes/#operationid), or name the routes distinctly |
| `route.fallback-omitted` | info | A `Route::fallback()` catch-all was left out: its path stands for every unmatched request, not an endpoint | Nothing, usually. Document the not-found body as a `404` on the operations that produce one — see [Fallback routes](/laravel/documenting/requests/#fallback-routes) |
| `route-binding.column-untyped` | info | A `{invoice:reference}` binding names a column nothing types, so the parameter is a plain `string` rather than the column's type | Add a `@property` tag (or a `$casts` entry) for that column on the model — see [Path parameters](/laravel/documenting/requests/#path-parameters) |
| `tags.name-collision` | info | Two controllers of the same short name derived one default tag, so their operations group together | Nothing, if that grouping is what you meant. Otherwise split them with [`#[Group]`](/laravel/reference/attributes/#group) or `tags.map` |
| `components.name-collision` | warning | Two schemas asked for one component name, so each was published under a name of its own — derived from its namespace, or from a hash of its content where there was no namespace to walk | Nothing, if those names read well. A hashed one rarely does: name them yourself with [`#[SchemaName]`](/laravel/reference/attributes/#schemaname) or [`#[ErrorComponent]`](/laravel/documenting/errors/#name-your-own-errors-with-errorcomponent). The message lists every claimant and the name it got |
| `components.example-name-conflict` | warning | Two operations sharing one error body give the same example name to different examples, so the body stayed inline rather than publishing a shared component with a name that means two things | Rename one of them, or make them agree. The message names the key and the component it would have published under |
| `components.name-invalid` | warning | A shared error-response name an OpenAPI component key can't carry reached the document — from an overlay, since a producer's own name is refused where it's written — so the body was published under its status instead | Fix the name: a component key is letters, digits, `.`, `_` and `-` only. The message names whatever the document says wrote it |
| `document.schema-invalid` | error | The assembled document failed validation against the UIR schema | Report it — this is a bug in Docuccino, not in your app. The message gives the JSON pointer and the rule that failed |

## Responses recovered from your code

Docuccino widens rather than guesses. Each of these says which response got vaguer and why.

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `inferred-response.payload-unrecoverable` | info | An action returns a bare framework response whose body the document still can't describe — a JSON body of unrecovered shape, or a stream whose media type nothing states | Build the payload where the analyzer can see it, or name it with `#[Response]` — see [framework response objects](/laravel/documenting/responses/#framework-response-objects) and [file downloads and streams](/laravel/documenting/responses/#file-downloads-and-streams) |
| `inferred-response.unpinned-redirect` | info | A redirect's exact 3xx isn't stated at the return site, so it's documented as the `3XX` range | Pin it with [`#[Response(302)]`](/laravel/reference/attributes/#response) when the endpoint isn't conditional |
| `inferred-handler.too-dynamic` | info | Your exception handler couldn't be folded to a fixed response shape for some exception types, so those responses fall through to the next error tier | Return a JSON response with a constant status from the handler, or document those responses explicitly — see [Error responses](/laravel/documenting/errors/) |
| `inferred-handler.render-callback-skipped` | info | A `render` callback couldn't be analyzed and was skipped, so its error responses fall through to the next tier | Register the renderer as an invokable object, an `[$object, 'method']` pair, or a closure typed to the exception it handles |
| `validation.rule-unrecoverable` | info | A validation field's rules are a closure, a custom rule object with no `#[RuleSchema]`, or a conditional descriptor, so the field is documented from its type alone — or omitted where it has no type either | Express the field with recoverable rules, or annotate the rule class with [`#[RuleSchema]`](/laravel/documenting/requests/#documenting-a-custom-rule) |
| `validation.rule-values-unread` | info | A rule states a value that isn't written at the rule — it comes from a call, a variable or a spread — so the constraint is left off and the field keeps the rules that did recover | Write every value where the rule is (`Rule::in('draft', 'live')`), or state them in an [overlay](/laravel/guides/customizing-output/#openapi-overlays-in-practice) — a partial list would make a generated client reject a value the API accepts |
| `validation.rule-unhandled` | info | No transformer handled a validation rule, so the property stays permissive | Nothing, for rules with no schema meaning. For one that should constrain the schema, register a rule transformer — see [Documenting a custom rule](/laravel/documenting/requests/#documenting-a-custom-rule) |

## Attributes

An attribute that couldn't be applied never fails the build — it says so here and the document keeps
what it would have had.

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `attribute.body-on-bodyless-status` | warning | A `#[Response]` names a body under a status HTTP forbids one on, so the body wasn't documented | Document it under a status that may carry one, or drop `type:` — 1xx, `204`, `205` and `304` never carry content |
| `attribute.error-component-invalid` | warning | An `#[ErrorComponent]` name an OpenAPI component key can't carry, so the attribute named nothing and the response kept its default name | Fix the name: letters, digits, `.`, `_` and `-` only. A reason phrase as one word — `NotFound`, `TooManyRequests` — reads best as a generated client's type. See [Name your own errors](/laravel/documenting/errors/#name-your-own-errors-with-errorcomponent) |
| `attribute.error-component-contested` | warning | Two exceptions one action signals name the same status's response differently, and a response carries one name, so the default stands | Keep the attribute on the exception that response really is, give the errors statuses of their own, or name the body on the render method that builds it |
| `attribute.mock-invalid` | warning | A `#[Mock]` that can publish nothing — no faker expression and no seed group, or a class-level one that doesn't name its property | Fill it in or delete it. The message names the exact class or property — see [Mock data hints](/laravel/documenting/schemas/#mock-data-hints) |
| `attribute.mock-unknown-property` | warning | A class-level `#[Mock(property: '…')]` names a member the schema doesn't publish, so the hint was dropped | Fix the name, or unhide the property. Columns, `toArray()` keys and validated fields are all named as they appear on the wire |
| `attribute.example-unusable` | warning | An `#[Example]` that doesn't describe one example — no value, or more than one, or more than one target, or a nameless one sharing a node with named ones | Give it exactly one value and at most one target; the message names which half is wrong. See [`#[Example]`](/laravel/reference/attributes/#example) |
| `attribute.example-target-missing` | warning | An `#[Example]` points at something the operation doesn't document — a status, a media type, a parameter, a request body | Document the target first with [`#[Response]`](/laravel/reference/attributes/#response), [`#[BodyParameter]`](/laravel/reference/attributes/#bodyparameter) or a parameter attribute, or point the example somewhere it is |
| `attribute.example-duplicate-name` | warning | Two `#[Example]` declarations on one node share a name, and a name is a map key, so the second was dropped | Give each its own name — the message says which name and which node |
| `example-file.missing` | warning | An `#[Example(file: …)]` names a file that isn't there | Create it or fix the path, which is read relative to your application root. Docuccino watches it either way, so the example appears the moment the file does |
| `example-file.invalid` | warning | An `#[Example(file: …)]` file didn't decode — not `.json`/`.yaml`/`.yml`, unparseable, or empty | Fix the file; the message quotes the parser |
| `example-file.escapes-base-path` | error | An `#[Example(file: …)]` path resolves outside your application root, so nothing was read | Write the path relative to the application root; a path that leaves it is refused by design |
| `attribute.description-unusable` | warning | A `#[Description]` carries both `text:` and `file:`, or neither, so it says nothing certain and nothing was documented | Give it exactly one of the two; the message says which half is wrong. See [`#[Description]`](/laravel/reference/attributes/#description) |
| `description-file.missing` | warning | A `#[Description(file: …)]` names a file that isn't there | Create it or fix the path, which is read relative to your application root. Docuccino watches it either way, so the description appears the moment the file does |
| `description-file.escapes-base-path` | error | A `#[Description(file: …)]` path resolves outside your application root, so nothing was read | Write the path relative to the application root — see [symbol-anchored prose](/laravel/guides/narrative-content/#symbol-anchored-prose) |

## Configuration

Everything Docuccino read out of `config/docuccino.php` and couldn't use. Every code here names the
exact key.

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `config.extension-missing` | warning | An entry in `extensions` names no autoloadable class, or isn't a class-string at all, so that extension contributed nothing | Fix the class name and namespace, and check it's autoloadable. `InvoiceExtension::class` still evaluates to a string when the class is missing, so a typo is otherwise silent |
| `config.engine-neon-missing` | warning | `engine.neon` names a PHPStan config file that isn't there, so the analyzer ran without whatever it registers | Fix the path — it's read relative to your application root — or drop the key. See [Engine](/laravel/reference/configuration/#engine) |
| `config.unknown-integration` | info | An `integrations.<key>` bag names no integration, so nothing reads it | Fix the key. The message suggests the one you probably meant, and [Integrations](/laravel/reference/configuration/#integrations) lists them all |
| `config.enabled-ignored` | info | You set `enabled` on an integration that's always on and has no toggle | Delete the key |
| `config.machine-dependent-value` | warning | A value your clients act on — an OAuth flow URL, a session cookie name — came from the build machine rather than from anything you pinned, and was published as-is | Pin it in `config/docuccino.php` — see [Pin the values your clients act on](/laravel/documenting/authentication/#pin-the-values-your-clients-act-on) |
| `config.machine-dependent-path` | info | A configured path points outside your application, so it's folded into the document's hash verbatim and the output stops being portable between machines | Move the target inside the application; in-app paths are stored relative to the base path |
| `config.unknown-tag-strategy` | info | `tags.default_strategy` isn't a strategy Docuccino has, so it fell back to `controller` | Set it to `controller` or `none` — see [Tags](/laravel/reference/configuration/#tags) |
| `config.unknown-tag-parent` | info | A tag in `tags.definitions` is parented to a tag no definition declares, so the link was dropped | Declare the parent, or remove the `parent` key. OpenAPI requires a parent tag to exist |
| `config.tag-parent-cycle` | info | A `tags.definitions` parent link closes a cycle, so it was dropped to keep the hierarchy a tree | Re-parent one of the tags in the cycle the message names |
| `config.export-no-targets` | error | `export.targets` is set but holds no entries, so the document has nowhere to go | Remove the key to fall back to `export.path`, or list at least one `{format, path}` entry |
| `config.export-target-shape` | error | An `export.targets` entry isn't a `{format, path}` pair with both members set to a non-empty string | Fix the entry — see [Export targets](/laravel/reference/configuration/#export) |
| `config.export-unknown-format` | error | An export target names a format Docuccino doesn't emit | Use one of the formats the message lists |
| `config.export-duplicate-format` | error | Two export targets name the same format, and `--format` and the viewer's artifact both have to resolve to one file | Keep one target per format |
| `config.export-duplicate-path` | error | Two export targets in one document write the same file, so the later would clobber the earlier | Give each target its own path |
| `config.export-yaml-unsupported` | error | An export target asks for a `.yaml` file in a format that has no YAML serialization | Give it a `.json` path rather than a `.yaml` file holding JSON |
| `config.export-path-collision` | error | Two documents write the same file, so one would clobber the other | Give each document its own export path — see [Multiple documents](/laravel/guides/multiple-documents/) |
| `config.accept-refused` | warning | A code in `diagnostics.accept` was reported as an error, and acceptance never covers an error, so it still failed the run | Fix what the error reports, then delete the entry — see [Diagnostics](/laravel/reference/configuration/#diagnostics) |
| `config.accept-unused` | warning | A code in `diagnostics.accept` that nothing reported: the cause is fixed, or the code is misspelled | Delete the entry. Checked only once a run has built every document, so a single-document run never raises it |
| `config.export-path-ignored` | info | A document sets both `export.targets` and `export.path`, and targets win, so the path is never written | Delete the `export.path` key |
| `integration.disabled` | info | An integration's package is installed, but the integration is off — either opt-in and never switched on, or explicitly disabled | Set `integrations.<key>.enabled = true` if you want its contributions. The message names the package and the key |

## Package integrations

What each package integration couldn't recover from your code. Every one of these degrades to a
correct-but-vaguer document rather than dropping the endpoint.

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `eloquent.no-columns` | info | A model exposes no documentable columns, so its response is a bare object | Add `@property` (or `@property-read`) tags for the model's attributes — e.g. `@property int $id` — so its columns and their types are recovered |
| `eloquent.custom-date-serialization` | info | A model overrides `serializeDate()`, so its date attributes' wire format isn't statically known and they're documented as plain strings | Nothing, unless clients need an exact format — then pin one with an annotation |
| `eloquent.unresolved-eager-load` | info | A relation named in `$with` couldn't be resolved to a related model, so it's omitted from the schema | Give the relation method a generic return type — e.g. `HasMany<LineItem, $this>` |
| `eloquent.unmapped-morph` | info | A morph variant has no `Relation::morphMap()` alias, so the union is emitted without a discriminator | Register an alias in `Relation::enforceMorphMap([...])` for every variant, so a stable discriminator can be emitted |
| `query-builder.unresolved-entry` | warning | A Query Builder allow-list entry couldn't be resolved statically, so it's omitted from the docs | Use a literal value or a factory call — `AllowedFilter::exact('status')` — so it can be recovered |
| `query-builder.no-allowlists-recovered` | info | A paginating Query Builder terminal was reached, but no allowed filters, sorts or includes were recovered | Expected when the endpoint offers none. Otherwise declare them somewhere the trace reaches — see [query objects](/laravel/packages/query-builder/#query-objects-allow-lists-in-a-separate-class) |
| `query-builder.partial-on-enum` | info | A partial-match filter over an enum-cast column can't have its values enumerated | Use `AllowedFilter::exact()` so the enum's values are documented — see [partial filters over an enum column](/laravel/packages/query-builder/#partial-filters-over-an-enum-column) |
| `query-builder.default-config` | info | The package's config wasn't readable, so documented parameter names use its defaults (`filter`/`sort`/`include`/`fields`) | Publish it — `php artisan vendor:publish --tag=query-builder-config` — so custom names reach the docs |
| `spatie-data.response-status-unresolved` | info | A Data class's `calculateResponseStatus()` doesn't fold to constant statuses, so the success response is documented as `200` | Return constant ints, or a ternary whose arms are both constant — see [Success statuses](/laravel/packages/spatie-data/#success-statuses) |
| `spatie-data.unknown-mapper` | info | A Data class uses a name mapper Docuccino doesn't recognize, so its property names are documented unmapped | Use one of the package's own mappers, or rename the properties |
| `json-api-paginate.default-config` | info | The package's config wasn't readable, so documented pagination parameters use its defaults (`page[number]`/`page[size]`) | Publish it — `php artisan vendor:publish --tag=json-api-paginate` — so custom names reach the docs |
| `rate-limit.unregistered-limiter` | info | A route throttles on a named limiter nothing registers with `RateLimiter::for()`, so the allowance can't be documented | Register it in a service provider, or state the allowance inline as `throttle:60,1` — see [Named limiters](/laravel/documenting/rate-limiting/#named-limiters) |
| `rate-limit.multiple-throttles` | info | A route carries more than one throttle middleware; one `429` is documented from the first | Nothing. The others are still enforced — they just aren't separately representable in OpenAPI |

## Webhooks

Webhooks are collected from `#[Webhook]` classes under the directory you configure. See
[Webhooks](/laravel/documenting/webhooks/).

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `webhook.dir-missing` | warning | The configured webhook directory doesn't exist, so no webhooks were collected | Create it, or unset `webhooks.dir` — see [pointing the document at your webhook classes](/laravel/documenting/webhooks/#point-the-document-at-your-webhook-classes) |
| `webhook.dir-escapes-base` | warning | The webhook directory resolves outside your application root and was ignored | Write it relative to the application root |
| `webhook.name-invalid` | warning | A class carries a `#[Webhook]` with no name, so it isn't in the document — a webhook is published under its name | Give the attribute the name the receiving endpoint subscribes to, e.g. `#[Webhook('invoice.paid')]` |
| `webhook.name-collision` | error | Two classes claim one webhook name and method, so one of them isn't in the document | Give one a name of its own — a webhook name is the contract a consumer subscribes to |
| `webhook.operation-collision` | error | A webhook already documents that method from another class, so this one isn't in the document | Give one of them its own name, or a method the other doesn't use |
| `webhook.method-unknown` | warning | A `#[Webhook]` asks for an HTTP method OpenAPI has no path-item member for, so it's documented as `POST` | Use one of the methods the message lists |
| `webhook.payload-unresolved` | warning | A webhook's payload type resolves to no shape, so its body is an unconstrained object | Name a class or array shape the payload is built from, or drop the payload argument to document the annotated class itself — see [Annotate the payload](/laravel/documenting/webhooks/#annotate-the-payload) |
| `webhook.build-failed` | error | Documenting one webhook threw, so it isn't in the document | The message quotes the failure and names the webhook |

## Narrative content

Codes from the Markdown pages you fold into the document. See
[Adding your own pages](/laravel/guides/narrative-content/).

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `content.dir-missing` | warning | The configured content directory doesn't exist, so no pages were compiled | Create it, or unset `content.dir` |
| `content.dir-escapes-base` | warning | The content directory resolves outside your application root and was ignored | Write it relative to the application root |
| `content.duplicate-slug` | error | Two content pages share a slug, so the later one is ignored | Rename one of them; a slug is a page's address |
| `content.duplicate-operation-id` | warning | Two operations share an `operationId`, so an `::operation` directive naming it resolves to the last one in path order | Give one its own id with [`#[OperationId]`](/laravel/reference/attributes/#operationid) |
| `content.unresolved-directive` | error | An `::operation` or `::schema` directive is missing its attribute, or points at something the document doesn't have | Point it at a documented operation id, `METHOD /path`, or component schema name — see [Linking to your API](/laravel/guides/narrative-content/#linking-to-your-api) |
| `content.unknown-directive` | warning | A directive Docuccino doesn't resolve was left in the page untouched | Nothing, if your renderer handles it. Docuccino resolves `::operation` and `::schema` |
| `content.unresolved-nav-ref` | error | A page's nav frontmatter references something that resolves to nothing | Fix the reference — see the [frontmatter reference](/laravel/guides/narrative-content/#frontmatter-reference) |

## Overlays

Codes from OpenAPI Overlays. See [Customizing the output](/laravel/guides/customizing-output/).

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `overlay.invalid` | warning | An overlay file couldn't be read as an overlay, so it was skipped entirely | The message quotes the problem. Check the file parses and carries the overlay members |
| `overlay.conflicting-operation` | error | An overlay action declares both `update` and `remove`, and an action carries exactly one operation | Split it into two actions |
| `overlay.unsupported-selector` | error | An overlay target uses a selector Docuccino doesn't resolve | Rewrite it with the supported subset: object members, array indexes, and `[?(@.field=='value')]` equality filters — see [the target selector](/laravel/guides/customizing-output/#the-target-selector) |
| `overlay.target-missing` | warning | An overlay target matched no node, so the action did nothing | Check the target against the document you're overlaying — overlays edit what already exists |

## Recorded examples

Codes from examples your test suite recorded. See [Examples your tests
recorded](/laravel/documenting/examples/#examples-your-tests-recorded).

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `examples.recordings-empty` | info | The recordings directory holds no recordings, so the document publishes none | Run your suite with the recorder registered, or drop `examples.recordings` |
| `examples.recording-unreadable` | warning | A file in the recordings directory isn't a recording Docuccino can read, or records an operation its filename doesn't name | Re-record it, or delete it. The message names the file |
| `examples.recording-orphaned` | warning | A recording is for an operation this document no longer has — the route was renamed, moved or removed | Delete the file, then re-record whatever replaced it. The message names the endpoint it came from |
| `examples.recording-unsafe` | warning | A committed recording still holds what looks like a credential, so it wasn't published | Re-record it; credentials are replaced on the way out. If the value really is public, list the pointer the message names under `lint.leakage.allow` — a bare property name silences the lint but never the redaction. The message names the pointer, never the value |
| `examples.recording-name-unpublished` | warning | A recording named a scenario on an error response, and a named example there would take that response out of the component other routes share | The body publishes without the name. Record it unnamed, or set `representation.errors.components` to `false` if the names matter more |

## Lint rules

Document-quality rules. `lint.data-leakage` is on by default; the rest are opt-in — see
[Lint](/laravel/reference/configuration/#lint).

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `lint.data-leakage` | warning | A schema property, example or default value looks like a credential | Hide it (`#[Hidden]`, or drop it from the resource), or safelist it under `lint.leakage.allow` — see [Data leakage](/laravel/reference/configuration/#data-leakage) |
| `lint.missing-description` | warning | An operation publishes neither a summary nor a description, so the document never says what it does | Give the action a docblock — its first line becomes the summary — or write one in an overlay. See [Descriptions](/laravel/reference/configuration/#descriptions) |
| `lint.operation-id-style` | warning | An `operationId` a generated client can't name a method after: empty, leading with a digit, or outside letters, digits and `.` `-` `_` `@` | Give it an id with [`#[OperationId]`](/laravel/reference/attributes/#operationid), or rename the route. See [Operation ids](/laravel/reference/configuration/#operation-ids) |
| `lint.undocumented-tag` | warning | Operations carry a tag `tags.definitions` never declares, so it publishes without the summary, description and parent the declared ones have | Add an entry for it, or map it onto a declared tag with `tags.map` or [`#[Group]`](/laravel/reference/attributes/#group). See [Undocumented tags](/laravel/reference/configuration/#undocumented-tags) |

## Emitting OpenAPI 3.1 and 3.0

Docuccino's document is OpenAPI 3.2-shaped. Exporting `openapi-3.1` or `openapi-3.0` rewrites what
those versions spell differently and drops what they can't express — each one saying which member, at
which JSON pointer. None of them mean you did anything wrong: they're the price of the older target,
and keeping the 3.2 artifact alongside costs you nothing.

Where a code is `info or warning`, the quieter one means Docuccino rewrote the construct into
something equivalent and the louder one means it had to drop it.

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `downlevel.query-method` | warning | The 3.2 `query` HTTP method has no 3.1 spelling, so the operation isn't in the emitted document | Keep the 3.2 artifact for consumers that need it |
| `downlevel.additional-operations` | warning | The 3.2 `additionalOperations` member has no 3.1 spelling and was dropped | Model custom methods with a standard method for 3.1 toolchains |
| `downlevel.tag-summary` | warning | A tag's 3.2 `summary` was dropped; 3.1 tags fall back to their `name` for display | Nothing, or lead the tag's `description` with the same sentence |
| `downlevel.tag-parent` | warning | A tag's 3.2 `parent` was dropped, so the tag hierarchy flattens | Nest the naming instead — "Billing / Invoices" |
| `downlevel.tag-kind` | warning | A tag's 3.2 `kind` was dropped, so 3.1 consumers treat every tag the same | Nothing |
| `downlevel.webhooks` | warning | `webhooks` was dropped; OpenAPI 3.0 doesn't define it | Keep the 3.1 or 3.2 artifact for consumers that need the webhook contract |
| `downlevel.component-path-items` | warning | `components.pathItems` was dropped; OpenAPI 3.0 doesn't define it | Inline the path item at each use site if 3.0 consumers need it |
| `downlevel.info-summary` | warning | `info.summary` was dropped; OpenAPI 3.0 doesn't define it | Lead `info.description` with the same sentence |
| `downlevel.license-identifier` | info or warning | The SPDX `info.license.identifier` became `info.license.url`, or was dropped where a `url` was already set | Nothing |
| `downlevel.mutual-tls` | warning | A `mutualTLS` security scheme was dropped, along with every requirement naming it | Document mutual TLS in prose for 3.0 consumers, or keep the 3.1 artifact |
| `downlevel.multi-type` | info or warning | A multi-type `type` became an `anyOf` of single-type branches, or was dropped where the schema already composes | Nothing |
| `downlevel.null-type` | warning | A null-only type became an untyped `nullable: true`; OpenAPI 3.0 has no `null` type | Nothing, though 3.0 consumers see a looser schema |
| `downlevel.nullable-composition` | info | A `{type: null}` branch moved onto its parent as `nullable: true` | Nothing |
| `downlevel.ref-siblings` | info | Members beside a `$ref` were hoisted into an `allOf` wrapper, because OpenAPI 3.0 ignores a `$ref` sibling | Nothing |
| `downlevel.const` | info | A `const` became a single-value `enum`, which is how OpenAPI 3.0 pins a value | Nothing |
| `downlevel.content-encoding` | info or warning | `contentEncoding: base64` became `format: byte`, or was dropped where a `format` was already set | Nothing |
| `downlevel.exclusive-bound` | warning | A numeric `exclusiveMinimum`/`exclusiveMaximum` was dropped, because 3.0 spells it as a boolean on a bound that's already taken | Nothing, though 3.0 consumers see an unbounded number |
| `downlevel.schema-examples` | info or warning | The first of a schema's `examples` was kept as `example`, or they were dropped where none could be | Nothing |
| `downlevel.unsupported-keyword` | warning | A JSON Schema keyword OpenAPI 3.0 doesn't define was dropped from a schema | Keep the 3.1 or 3.2 artifact for consumers that validate against the full constraint |

## Postman collections

A Postman collection describes requests a person sends, so it carries less than an OpenAPI document
does. See [Postman collections](/laravel/reference/commands/#postman-collections).

| Code | Severity | What it means | What to do |
|---|---|---|---|
| `postman.no-server` | warning | The document declares no servers, so the collection's `baseUrl` is empty | Declare a server, or fill the variable in once after importing |
| `postman.server-variable-no-default` | warning | A server variable declares no default, so the collection can't suggest a value | Give the variable a default in your server definition |
| `postman.variable-name-collision` | warning | A server variable is named `baseUrl`, which the collection already uses, so it isn't published as a variable of its own | Rename the server variable |
| `postman.path-template-partial` | warning | A path segment templates only part of itself, and a Postman path variable stands for a whole segment, so the segment was left literal | Edit the URL after importing, or template the whole segment |
| `postman.auth-unsupported` | warning | A security scheme has no Postman equivalent, so requests are sent unauthenticated | Add the credential by hand in Postman |
| `postman.auth-multi-scheme` | warning | An operation requires more than one credential together, and a Postman request carries one | Supply the others by hand — the message names which one the collection sends |
| `postman.body-not-object` | warning | A form body isn't an object, and a form body is a list of fields, so it's sent empty | Fill the body in after importing |
| `postman.body-media-type` | warning | No example body could be built for a media type, so requests using it are sent empty | Add an example for that media type — see [Example payloads](/laravel/documenting/examples/) |
| `postman.examples-truncated` | warning | An operation documents more saved responses than the collection keeps, so the first few by status were saved to stay navigable | Nothing. The full set is still in the OpenAPI document |
| `postman.callbacks-dropped` | warning | The callbacks an operation declares have no Postman equivalent | Nothing |
| `postman.webhooks-dropped` | warning | A collection describes requests you send, so it can't carry the webhooks your API delivers | Nothing. Export an OpenAPI format alongside for the webhook contract |
| `postman.yaml-ignored` | warning | A Postman collection has no YAML form, so JSON was written | Give the target a `.json` path |
