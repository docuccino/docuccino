---
title: Configuration reference
description: Every live key in config/docuccino.php and what it does.
---


The published `config/docuccino.php` drives everything. Every key is listed in the file itself —
required keys active, optional ones commented out — so you can discover the whole surface by
scrolling through it. This page is the long-form version: what each key does, what it defaults to,
and where its behavior is explained in full. The file is plain data — no imports, no class
references — so it stays safe to load even where Docuccino itself isn't installed.

The **Default** column is the value the published file ships with. For most keys that is also the
built-in fallback you get by deleting the key, but not for all of them: `error_responses` ships as
`'default'` and falls back to `'none'` when a document omits it, which is why a second document
[inherits nothing](/laravel/guides/multiple-documents/) from the first.

## Top level

```php
return [
    'enabled' => env('DOCUCCINO_ENABLED', true),
    'documents' => [ /* … */ ],
    'extensions' => [],
    'lint' => [ /* … */ ],
    'diagnostics' => [ /* … */ ],
    'engine' => [ /* … */ ],
    'on_route_error' => 'skeleton',
    'cache' => [ /* … */ ],
];
```

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `env('DOCUCCINO_ENABLED', true)` | Master switch. When `false`, every command except `docuccino:clear` aborts with a notice and exits non-zero, **and** the runtime viewer endpoints (`/docs/*`) are not registered at all. Lets you disable generation and serving in an environment without removing config. |
| `on_route_error` | `'skeleton'` | Per-route failure behavior. `skeleton` emits a stub operation plus an error diagnostic (never a dead build); `omit` drops the route entirely. |

## Documents

`documents` is a map of independent pipeline runs. Each entry has its own route filters, info,
servers, security, content, and export target, and shares route contexts + the TypeEngine
in-process. The published config ships one document, `default`.

:::note[Paths are stored relative to your application]
Every path in a document — `info.description.file`, `content.dir`, `webhooks.dir`, `overlays`,
`examples.recordings`, `api_version.changes`, `export.path` — may be
written relative to your application root or as an absolute path; both resolve to the same file. Write
them however you like: a path inside your application is **stored relative to the application root**,
so the generated document is identical on your laptop, in CI, and in a container, wherever the app is
checked out.

A path pointing *outside* your application is kept exactly as you wrote it — it has to be, or the file
could not be read — which makes the generated document specific to machines that have that path. Builds
say so, once per key, with a `config.machine-dependent-path` info diagnostic.
:::

### `api_version`

```php
// 'api_version' => [
//     'changes' => ['app/Api/Versions'],
//     'header' => 'X-Api-Version',
// ],
```

| Key | Values / default | Effect |
| --- | --- | --- |
| `changes` | list of directories / `[]` | Where the `#[ApiVersionChange]` classes live. Each entry may be a glob, so a modular application writes `'modules/*/Api/Versions'` once instead of listing its modules — and an entry matching a path outside the application is refused, wildcard or not. Every directory is read, and the changes are ordered by version then class name however they were spelled, so which entry a class came out of never decides when it applies. |
| `header` | header name / `X-Api-Version` | The request header the version enum is published on. |

Declaring `api_version` makes the document an API **version** rather than a document that merely has
one. Three things follow.

The version is [`info.version`](#info) — the one OAS already models. There is no second key naming it,
because a second key could only ever disagree with the first. Write no `info.version` at all and there
is nothing to derive a version from; the build says so with a `versioning.version-unstated` warning
rather than inventing a version you do not serve. Any value you do write is taken at its word, `1.0.0`
included — declaring `api_version` is the statement that this document is a version.

The order two versions are in is the one [`versioning`](#versioning) names — `date` or `semver`. Say
nothing and Docuccino reads the order off the versions themselves, so an application writing plain
dates or plain semver never has to write it down twice. Versions that are neither, or a mixture, cannot
be ordered and the changes are not applied (`versioning.unordered`).

Every operation gains the header a client pins a version with — `in: header`, optional, defaulting to
this document's version and enumerating every version your `documents` map declares. Consumers read
the set of supported versions out of the document itself, and a generated client gets a named constant
for each one.

That enum is every configured version, not every *public* one, and a published document is a committed
artifact — so an internal, staging or not-yet-announced version configured as a document is named in
the public one. It has to be: an enum narrower than what the server accepts marks a working request
invalid. Keep a version out of the enum by keeping it out of the `documents` map.

Every change declared under `changes.dir` that shipped **after** this version is applied in reverse, so
an older version's document describes the shape that version published. Your code is always the newest
version; a change class says what the API did before it:

```php
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;

#[ApiVersionChange(since: '2026-09-01', description: 'An invoice publishes `total` where it published `amount`.')]
#[RenamedResponseField(schema: InvoiceResource::class, from: 'amount', to: 'total')]
final class InvoiceTotalReplacesAmount {}
```

Nothing in that class is executed, and its body is never read — Docuccino compiles the declaration and
stops there. Serving an older shape at runtime is your application's job, and a
[per-version contract test](/laravel/guides/contract-testing/) is what holds the two halves together:
replay your suite with a version pinned, and every response must validate against that version's
document.

Migrating *stored records* to a new shape is a different problem, and API versioning does not solve it.

The whole loop — declare a change, get a document per version, hold both to your test suite — is in
the [API versioning guide](/laravel/guides/api-versioning/).

### `info`

```php
'info' => [
    'title' => 'API Documentation',
    'version' => '1.0.0',
    // 'description' => ['file' => 'resources/docs/api/description.md'],
],
```

Maps to OAS `info`, and any other OAS `info` field you add (`contact`, `license`,
`termsOfService`, …) is emitted as written. `description` may be a Markdown string or
`['file' => '…md']` to load your API's introduction from a file. `version` is the value the
[versioning policy](#versioning) evaluates during `docuccino:diff --enforce`.

### `servers`

```php
'servers' => [
    ['url' => 'https://api.example.com'],
    // Server variables with defaults/descriptions:
    // ['url' => 'https://{tenant}.example.com', 'variables' => [
    //     'tenant' => ['default' => 'acme', 'description' => 'Tenant slug'],
    // ]],
    // A variable whose legal values are a closed set:
    // ['url' => 'https://api.example.com/{version}', 'variables' => [
    //     'version' => ['default' => 'v2', 'enum' => ['v1', 'v2']],
    // ]],
],
```

Emitted as OAS `servers`, including server variables.

**Every OpenAPI version requires a `default` on a variable**, so give each one a value you serve. A
variable without one raises [`server.variable-no-default`](/laravel/reference/diagnostics/#servers)
against every format the build emits, and what happens next depends on whether the variable declares an
`enum`. With one — as `version` does above — its first value stands in, which resolves the URL to
something you demonstrably serve. With neither, an OpenAPI document leaves the variable out rather than
invent a value, and a Postman collection publishes it blank for a person to fill in.

**An empty string is not a default.** `'default' => ''` reads as *no* default and takes the path above,
because substituting it resolves `https://{tenant}.example.com` to `https://.example.com` — a URL nobody
serves, which leaves a reader exactly where declaring nothing does.

**A dropped variable leaves its placeholder behind.** Only the `variables` entry is removed; the `url` is
published as written, so a `{tenant}` with no default and no enum stays literally `{tenant}` in the
emitted URL. That is deliberate — a template a consumer must fill in is honest, and a guessed hostname is
not — but it means the warning is worth acting on rather than accepting.

For a worked multitenant subdomain example (`{tenant}.example.com`), see
[Deploying to production](/laravel/guides/production/#multitenant-base-urls).

### `routes`

```php
'routes' => [
    'include' => ['api/*'],
    'exclude' => [],
    'closure' => null, // fn (RouteDescriptor $route): bool => ...
    'include_vendor' => false,
],
```

Route selection. `include`/`exclude` are URI globs; `closure` is an optional predicate that runs
after the globs for arbitrary logic. A route must pass includes, fail excludes, and satisfy the
closure to be documented.

Routes whose resolved controller class file lives under the application's `vendor/` directory are
**excluded by default** — the same as `php artisan route:list --except-vendor` — so an installed
package's own routes don't leak into your API reference. Closures and your own app controllers are
never affected, and the `include`/`exclude`/`closure` filters are unchanged. Set `include_vendor` to
`true` to document installed packages' routes.

### `security`

```php
'security' => [
    'auto_detect_middleware' => 'auth*',
    // 'schemes' => [
    //     'bearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
    //     'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
    //     'oauth2' => ['type' => 'oauth2', 'flows' => [...]],
    //     'oidc'   => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://…'],
    // ],
    // 'default'  => [['bearer' => []]], // per-op requirement for auth-detected routes
    // 'document' => [['bearer' => []]], // document-wide security requirement
],
```

| Key | Default | Effect |
| --- | --- | --- |
| `auto_detect_middleware` | `'auth*'` | Wildcard matched against each route's middleware; a match applies the `default` requirement. |
| `schemes` | none | `components.securitySchemes` — full breadth: http bearer/basic, apiKey (header/query/cookie), oauth2 flow builders, OpenID Connect. |
| `default` | none | The per-operation `security` requirement applied to auth-detected routes. |
| `document` | none | A document-wide `security` requirement. |

Declaring any `schemes` here **defers** the auto-config security integrations (Sanctum, Passport)
— explicit config wins. `#[Unauthenticated]` clears a route's requirement regardless.

### `error_responses`

```php
'error_responses' => 'default', // 'default' | 'none'
```

Selects what is published for the exceptions your application does *not* render itself. `default`
documents Laravel's stock JSON error shapes; `none` emits no error responses. Either way, an
[inferred exception handler](/laravel/documenting/errors/) that recovers your app's real error shape —
its body, its status and the media type it is sent as — wins ahead of this fallback. The strategy also
governs the [implicit 401/422/404/403 responses](/laravel/documenting/errors/#implicit-responses-middleware-bindings--validation)
(none of them are emitted under `none`).

Deleting the key is how you ask for `none` — that is the fallback a document that doesn't name the key
gets, and it is why a second document [inherits nothing](/laravel/guides/multiple-documents/) from the
first. Keeping the key and giving it anything else — a misspelling, or an `env()` whose variable isn't
set — is reported as `config.unknown-error-responses` and read as `default`, so a value that can't be
read never quietly empties the document of its errors.

### `tags`

```php
'tags' => [
    'default_strategy' => 'controller', // 'controller' | 'none'
    'map' => [],
    // 'mapper' => Custom::class,   // container-resolved TagMapper; default PrefixTagMapper over `map`.
    // 'definitions' => [           // OAS top-level `tags`, sorted by weight then name:
    //     ['name' => 'Billing', 'summary' => 'Billing', 'kind' => 'nav', 'weight' => 0],
    //     ['name' => 'Forms', 'description' => '…', 'parent' => 'Billing'],
    // ],
],
```

`default_strategy` tags an operation that has no `#[Group]`: `controller` (the default — the
controller's short name with a trailing `Controller` stripped, e.g. `FormController` → `Form`, then
run through `map`) or `none` (leave it untagged). Closure routes are never auto-tagged.
`map` is a raw-tag → display-tag table (exact match wins, else the first matching prefix).
`mapper` swaps in a custom `TagMapper`. `definitions` supplies OAS top-level tag objects.

A definition carries the full OAS 3.2 Tag Object: `name` (required), plus optional `summary`,
`description`, `parent` and `kind`. `weight` is Docuccino's own — it orders the emitted array
(ascending weight, then name) and is never emitted.

| Field | Purpose |
| --- | --- |
| `summary` | A short display label, where `description` is the prose. |
| `parent` | The `name` of the tag this one nests under, for a grouped sidebar. |
| `kind` | A machine-readable category — `nav`, `badge`, `audience` are the common ones; any string is legal. |

`parent` must name another definition, and the links must not form a cycle. A parent naming an
undefined tag emits a `config.unknown-tag-parent` info diagnostic; a link that closes a cycle emits
`config.tag-parent-cycle`. Either way the offending link alone is dropped and the build carries on,
so the emitted hierarchy is always a tree. Because the array is sorted before the parents are
resolved, the result never depends on the order you wrote the definitions in.

Wherever a hierarchy is emitted, the document also carries it as `x-tagGroups` at the root: a list of
`{name, tags}` groups, one per root tag, each listing that root and its descendants in hierarchy
order. `parent` is the OpenAPI-native statement of the same forest; `x-tagGroups` is the de-facto
convention most renderers read a grouped sidebar from, and neither has to be understood for the other
to work. Flat tags emit no `x-tagGroups` at all.

`summary`, `parent` and `kind` are OpenAPI 3.2 only. Exporting 3.1 drops them, each with its own
`downlevel.tag-*` warning — the tags themselves stay. A `parent` dropped from a document that carries
`x-tagGroups` costs only the native member; where nothing carries the groups, the hierarchy really is
flattened and nesting the names ("Billing / Invoices") is the way back.

### `webhooks`

```php
// 'webhooks' => ['dir' => 'app/Webhooks'],
```

Points at a directory of classes carrying [`#[Webhook]`](/laravel/reference/attributes/#webhook).
Every annotated class under it is published under the document's `webhooks` — an operation your API
promises to CALL, rather than one it answers. Absent, the document has none. See
[Documenting webhooks](/laravel/documenting/webhooks/).

### `content`

```php
'content' => [
    'dir' => null, // e.g. 'resources/docs/api'
],
```

Points at a markdown tree compiled into `x-docuccino.content` (pages + a compiled nav tree).
Folders become default nav groups; frontmatter (`title`/`slug`/`summary`/`tags` +
`nav.{group,order,hidden,type,ref}`) overrides. `::operation{...}` / `::schema{...}` directives are
resolved against the document; broken refs become diagnostics. `null` compiles nothing. See
[Adding your own pages](/laravel/guides/narrative-content/) for the full workflow, or the
[UIR content layer](/uir/#content-layer) for how it lives in the raw document.

### `examples`

```php
'examples' => [
    'recordings' => 'docs/recordings', // absent by default
],
```

Points at a directory of response recordings your test suite wrote, one committed file per operation,
named after the operation's stable id. The build **reads** those files and publishes each body beside
the schema documented for that status and media type — no test runs, no route is dispatched, no
database is opened. Recording is opt-in per assertion: a test asks for its response to be published by
naming the scenario it set up (`assertValidResponse(recordAs: 'empty-cart')`), and the names publish
together as an `examples` map, so several scenarios can appear at once. An assertion that names nothing
checks the response and records none of it.

A recorded example sits at the `integration(20)` rung of the precedence ladder — above inference, below
anything you wrote, so an [`#[Example]`](/laravel/reference/attributes/#example) or an `@example`
always wins — though a named recording may join a map of named examples you wrote, since the name is
one you chose too. A body whose status or media type the document doesn't describe is never published,
and neither is one that still looks like it holds a credential.

Absent — the default — publishes no recorded examples. See [Examples your tests
recorded](/laravel/documenting/examples/#examples-your-tests-recorded) for how recordings are written
and reviewed.

### `coverage`

```php
'coverage' => [
    'log' => 'storage/docuccino/coverage', // the default when absent
],
```

Where the contract-coverage recorder writes what your test suite exercised, and where
[`docuccino:coverage`](/laravel/reference/commands/#docuccinocoverage) reads it back. Each process
writing — a single test run, one of twenty parallel workers, one of four shards — appends the documented
responses it exercised to a file of its own; the command unions them after the run and gates on the
percentage.

Nothing in the build reads this. It is a config key rather than an argument at the call site because
the suite writing and the command gating have to arrive at the same directory. Point it somewhere your
repository ignores: the logs are per-run build output, not something to commit. See [Contract
testing](/laravel/guides/contract-testing/#report-the-responses-your-suite-never-proved).

### `overlays`

```php
'overlays' => [
    // 'resources/docs/overlays/*.yaml',
],
```

Globs of [OpenAPI Overlay 1.0](https://spec.openapis.org/overlay/v1.0.0.html) documents applied at
assembly time as the `overlay(45)` precedence layer — a standards-based hand-edit layer that
survives regeneration. See [Customizing the output](/laravel/guides/customizing-output/) for worked
examples.

### `representation`

```php
'representation' => [
    'filters' => 'bracketed',       // bracketed | deepObject (Query Builder filter/field style)
    'lists' => 'comma',             // sort/include documentation style; both keywords emit the ?sort=a,b comma form
    'nullable' => 'type-array',     // type-array (type: [x, null]) | anyof ({type: null} branch)
    'operation_id' => 'route-name', // route-name | controller-method ({ShortController}@{method})
    // 'enums' => [
    //     'naming' => 'names',     // names (both hint spellings) | none | x-enumNames | x-enum-varnames
    //     'components' => true,    // true (hoist each enum to a $ref'd component) | false (inline everywhere)
    // ],
    // 'errors' => [
    //     'components' => true,    // true (hoist a repeated error body to shared components) | false (inline)
    // ],
    // 'pagination' => [
    //     'components' => true,    // true (hoist a paginated envelope to one component per item type
    //                              // and paginator kind) | false (inline on every operation)
    // ],
    // 'examples' => [
    //     'formats' => ['email' => 'jane@example.com'], // format => the sample examples illustrate with
    // ],
],
```

Separates *what was inferred* from *how it is expressed in the spec*. The semantic facts stay
stable in `x-docuccino` regardless of policy, so the diff engine can tell "representation changed"
from "API changed".

| Key | Values | Default | Effect |
| --- | --- | --- | --- |
| `filters` | `bracketed` \| `deepObject` | `bracketed` | Query Builder filter/field style: one flat `filter[status]` / `fields[type]` parameter each (`bracketed`), or a single `filter` / `fields` object parameter with `style: deepObject` (`deepObject`). See [Spatie Query Builder](/laravel/packages/query-builder/). |
| `lists` | `comma` \| `array` | `comma` | Query Builder `sort` / `include` documentation style. Both keywords emit the same shape — an array parameter serialized to the `?sort=a,b` comma form (`style: form, explode: false`) whose `items` enum lists the [allow-list's legal values](/laravel/packages/query-builder/#sorts-and-includes-are-enums-of-the-allow-list). |
| `nullable` | `type-array` \| `anyof` | `type-array` | How nullability is expressed: `type: ["string","null"]` vs a `{type: null}` `anyOf` branch (legacy tooling). |
| `operation_id` | `route-name` \| `controller-method` | `route-name` | `operationId` strategy. |
| `enums.naming` | `names` \| `none` \| `x-enumNames` \| `x-enum-varnames` | `names` | SDK member-name hints on enum schemas. The default `names` emits both spellings (`x-enum-varnames` for OpenAPI Generator and the TypeScript toolchain, `x-enumNames` for NSwag); a single-key keyword pins one tool's shape; `none` turns hints off. Read by the [Enum integration](/laravel/documenting/schemas/#enums) and the [Query Builder sort/include enums](/laravel/packages/query-builder/#sorts-and-includes-are-enums-of-the-allow-list). |
| `enums.components` | `true` \| `false` | `true` | Whether each reflectable enum hoists to a shared `#/components/schemas` entry that properties and query-parameter item schemas `$ref` (`true`), or its `type`/`enum`/`x-enumDescriptions` are inlined at every use site (`false`). |
| `errors.components` | `true` \| `false` | `true` | Whether a repeated error body hoists to shared components — its shape into `#/components/schemas`, and the whole response into `#/components/responses` where operations state it identically (`true`) — or every copy is inlined (`false`). |
| `examples.formats` | a `format` → sample map | `[]` | The value a [synthesized example](/laravel/documenting/requests/#examples-come-with-the-rules) illustrates a JSON Schema `format` with, **merged** over the built-in table: a format you leave out keeps its documentation-reserved default (RFC 2606 / 5737 / 3849), and a format the built-ins don't know can be added. A configured sample is held to the same rule as a derived one — it is validated against the finished keywords of every field carrying that format, and one that a field's rules reject falls back to the built-in sample for that field with a `config.format-sample-rejected` warning naming the format, the value and the keyword. It answers for every value Docuccino invents, so a Postman collection's fabricated bodies, saved examples and URL variables read the same table the schemas do. Configuring a format no schema uses is not an error; examples are demand-driven. It changes the document's `configHash`, so it retires warm fragments. |
| `pagination.components` | `true` \| `false` | `true` | Whether a [paginated envelope](/laravel/documenting/responses/#pagination) hoists to one `#/components/schemas` entry per item type and paginator kind — `ArticleResourcePage`, `ArticleResourceCursorPage` — that every paginated operation `$ref`s, and its `links`/`meta` to one entry per shape (`PaginationLinks`, `PaginationMeta`) that the pages `$ref` in turn (`true`); or the whole envelope is restated on each operation (`false`). Hoisting means an SDK generator mints one page type per item type instead of one per endpoint, over one set of envelope members instead of one per page. An envelope whose item type could not be identified, or whose item schema is not itself a component, keeps the envelope on the operation either way — but still points at the member components, whose shapes never depended on the item type. |

The hoist is narrow — 4xx/5xx only, only bodies that repeat, only responses with `content`, never one
already a `$ref` — and [`docuccino:diff`](/laravel/reference/commands/#docuccinodiff) resolves references
on both sides, so moving a body between inline and shared is not a change. Worked output and the exact
rules: [repeated bodies become shared components](/laravel/documenting/errors/#repeated-bodies-become-shared-components).

### `integrations`

One bag per integration, keyed by the integration's config name; **each integration reads only its
own bag**, and all are optional.

```php
'integrations' => [
    'api_resources' => ['wrap' => true],                          // top-level resource `data` wrapping
    'sanctum'       => ['modes' => ['token', 'stateful'], 'cookie' => 'myapp_session'],
    'passport'      => ['url' => 'https://auth.example.com'],      // oauth2 flow base URL
    'query_builder' => [
        'pagination_terminals' => ['paginateList'],                // extra paginating method names
        'filter_descriptions'  => ['exact' => 'Matches `%field%` exactly.'], // per-kind prose
    ],
    'permission'    => ['enabled' => true],                       // opt in — off by default
],
```

Every bag also accepts **`enabled`** (`bool`). It is resolved per document: an integration
contributes only when its package is installed **and** the document enables it. Every integration
defaults **on** when its package is installed, **except `permission`**, which defaults **off** —
documenting role and permission names would publish your application's internal authorization
taxonomy, so it is explicit opt-in. When a package is installed but its integration is disabled, the
build emits one `integration.disabled` info diagnostic per document, so the switch is discoverable.

Every toggleable bag is keyed by its config name — set `integrations.<key>.enabled` to turn one off
(or, for `permission`, on):

| Key | Package / source | `enabled` default |
| --- | --- | --- |
| `api_resources` | Laravel API resources (built in) | `true` |
| `eloquent` | Eloquent models (built in) | `true` |
| `rate_limit` | Laravel rate limiting (built in) | `true` |
| `spatie_data` | `spatie/laravel-data` | `true` |
| `query_builder` | `spatie/laravel-query-builder` | `true` |
| `json_api_paginate` | `spatie/laravel-json-api-paginate` | `true` |
| `laravel_actions` | `lorisleiva/laravel-actions` | `true` |
| `timacdonald_json_api` | `timacdonald/json-api` | `true` |
| `sanctum` | `laravel/sanctum` | `true` |
| `passport` | `laravel/passport` | `true` |
| `permission` | `spatie/laravel-permission` | **`false`** |

The table below lists the additional options each bag accepts beyond `enabled`.

| Bag | Key | Default | Effect |
| --- | --- | --- | --- |
| _any_ | `enabled` | `true` (**`false` for `permission`**) | Turn the integration on/off for this document. Contributes only when the package is installed and this is `true`. |
| `api_resources` | `wrap` | each resource's own `$wrap` | `false` never wraps (global `withoutWrapping()`); `true` → `'data'`; a string forces that key; omit → each resource's static `$wrap`. |
| `sanctum` | `modes` | `['token','stateful']` | Which Sanctum schemes to expose. |
| `sanctum` | `cookie` | `session.cookie` | Stateful cookie name. |
| `passport` | `url` | `app.url` | oauth2 flow base URL. |
| `query_builder` | `pagination_terminals` | `[]` | Extra method names that count as paginating terminals during the trace — [on a query-builder receiver only](/laravel/packages/query-builder/#custom-pagination-terminals). |
| `query_builder` | `filter_descriptions` | `[]` | Filter kind → the sentence that leads that kind's description, **merged** over the built-in table: a kind you leave out keeps its default. `%field%` is the one supported token and interpolates the filter's **public** name; a sentence with no token is published as written. A key naming no filter kind reports `config.unknown-filter-kind`. An [entry comment](/laravel/packages/query-builder/#a-comment-becomes-the-description) still wins over it, and the `whereIn`/`null`/separator/default notes are still appended — see [overriding the generated prose](/laravel/packages/query-builder/#overriding-the-generated-prose). |
| `permission` | `enabled` | `false` | Opt in to document `role:`/`permission:` requirements (`x-permissions`). Off by default so authorization names are not published unintentionally. |

### `export`

```php
'export' => [
    'path' => 'docs/openapi.json',
],
```

`path` is the default output location for `docuccino:export` and the file
`viewer.source: artifact` serves. On its own it means one artifact, in OpenAPI 3.2.

To emit several artifacts from **one** build, list targets instead:

```php
'export' => [
    'targets' => [
        ['format' => 'openapi-3.2', 'path' => 'docs/openapi.json'],
        ['format' => 'openapi-3.1', 'path' => 'docs/openapi-3.1.yaml'],
        ['format' => 'uir',         'path' => 'docs/api.uir.json'],
        ['format' => 'postman',     'path' => 'docs/collection.json'],
    ],
],
```

Analysis is the expensive half of a build, so three targets cost one analysis and three emits — not
three runs of everything.

| Field | Effect |
| --- | --- |
| `format` | One of [the emit formats](/laravel/reference/commands/#docuccinoexport). Unknown values are an error, never a fallback. |
| `path` | Where this artifact lands. Relative paths resolve against `base_path()`, and missing directories are created. |

Rules the command enforces before it builds anything:

- **`targets` replaces `path`.** Set both and `path` writes nothing; you get one
  `config.export-path-ignored` info diagnostic telling you to delete it.
- **One target per format.** Two `openapi-3.2` targets are rejected, which is what keeps
  `--format` and the viewer's artifact each resolving to exactly one file.
- **No two targets may write the same file**, in one document or across documents — one would
  clobber the other.
- **The extension picks the serialization.** A `.yaml` or `.yml` path emits YAML; anything else emits
  JSON. There is no `yaml` key, because the path already says it.
- **`uir` and `postman` have no YAML form**, so a `.yaml` path on either is an error rather than a
  `.yaml` file holding JSON.

A broken target list fails the command with a `config.export-*` error **before** the build runs, so
you never pay for an analysis to find out a filename was wrong.

One more key sits beside them, shaping what the emitters write rather than where:

```php
'export' => [
    'path' => 'docs/openapi.json',
    'mock_faker_key' => 'x-faker',
],
```

`mock_faker_key` is the member every [`#[Mock]`](/laravel/reference/attributes/#mock) faker
expression is published under in the OpenAPI artifacts. Unset — the default — leaves them out, so a
bare export is pure OpenAPI. The `uir` format carries the hints whichever way this is set, and
turning it on rewrites no byte of the UIR: it shapes the projection, never the document, so
`configHash` and the fragment cache are untouched.

### `viewer`

```php
'viewer' => [
    'route' => '/docs/api', // null disables the runtime endpoints for this document
    'gate' => null,         // Gate ability name; null = local environment only
    'middleware' => ['web', 'throttle:60,1'],
    'source' => 'generate', // generate | artifact | cache
    // 'driver' => 'scalar', // scalar | redoc, or a driver you registered
    // 'cdn' => false,       // true loads the driver's script from a CDN instead of the bundled asset
    // 'configuration' => [], // passed verbatim to Scalar's data-configuration (theme, layout, …)
],
```

| Key | Default | Effect |
| --- | --- | --- |
| `route` | `'/docs/api'` | Base path for the viewer routes: the HTML page, the `.json` spec, the active driver's asset, and the `/reload` channel a [`docuccino:watch`](/laravel/reference/commands/#docuccinowatch) session refreshes the page through. `null` disables them for this document. |
| `gate` | `null` | Gate ability guarding all four routes — the HTML page, the `.json` spec, the asset and the reload channel. `null` = available only in the `local` environment. Every driver goes through it. |
| `middleware` | `['web', 'throttle:60,1']` | Middleware for the viewer routes. Keep `throttle` when exposing the (potentially expensive) spec endpoint publicly. See the warning below if your app is multi-tenant or domain-gated. |
| `source` | `'generate'` | `generate` rebuilds on every request (fine for local/gated); `artifact` re-emits the committed `export.path`; `cache` serves the `docuccino:cache`-warmed payload (cold cache falls back to generate). |
| `driver` | `'scalar'` | Which renderer serves the HTML page: `scalar` (with a try-it-out console) or `redoc` (reference only), or the name of a [driver you registered](/laravel/guides/viewer/#writing-your-own-driver). An unregistered name falls back to `scalar` and logs a warning. |
| `cdn` | `false` | `true` loads the active driver's script from jsDelivr instead of the bundle shipped with the package. |
| `configuration` | `[]` | Passed verbatim to [Scalar's `data-configuration`](https://github.com/scalar/scalar/blob/main/documentation/configuration.md) — theme, layout, `hideModels`, and the rest of Scalar's own options. Ignored by drivers that take no page configuration. |

:::caution[Multi-tenant or domain-gated apps: override `middleware`]
The default includes `web`, which is right for a single-domain app (and a `gate`-protected viewer needs
it for session state). But if your `web` group resolves a **domain or tenant**, the viewer's
domain-less routes cannot satisfy that middleware and the viewer **404s**. Override `middleware` for
those apps — drop `web`, or register your domain — for example:

```php
'middleware' => ['throttle:60,1'],
```
:::

### `versioning`

```php
'versioning' => 'none', // 'semver' | 'date' | 'none'
```

The policy `docuccino:diff --enforce` applies to this document. `semver` requires a major version
bump for breaking changes; `date` requires a new date version; `none` never fails on versioning. See
[`docuccino:diff`](/laravel/reference/commands/#docuccinodiff).

It has a second job on a document that declares [`api_version`](#api_version): it names the order two
versions are in, which is the order a change list applies in. A value that cannot read your versions —
`semver` over dates, say — leaves them unordered, so **no** changes are applied and the version
document comes out saying what the code says (`versioning.unordered`). Say nothing and Docuccino reads
the order off the versions themselves, which is the safer default of the two.

## Extensions

```php
'extensions' => [],
```

Class-strings resolved from the container and merged with programmatic `Docuccino::extend()`
registrations **at build time, never at boot**. See [extension authoring](/extending/extension-authoring/).

## Lint

```php
'lint' => [
    'leakage' => [
        'enabled' => true,
        'allow' => [],   // e.g. ['reset_token', '/components/schemas/Invoice/properties/status']
        // 'patterns' => ['sortcode' => 'a bank sort code', 'iban' => 'an IBAN'],
    ],
    'descriptions' => [
        'enabled' => false,
        'allow' => [],   // e.g. ['GET /api/ping']
    ],
    'operation_ids' => [
        'enabled' => true,
        'allow' => [],   // e.g. ['GET /api/ping', 'list users']
    ],
    'tags' => [
        'enabled' => false,
        'allow' => [],   // e.g. ['Internal']
    ],
    // 'vacuous_union' => [
    //     'enabled' => true,
    //     'allow' => [],   // e.g. ['GET /api/ping']
    // ],
    // 'examples' => [
    //     'enabled' => true,
    //     'allow' => [],   // e.g. ['/components/schemas/Invoice/properties/status/example']
    // ],
    // 'unpinned_redirect' => [
    //     'enabled' => true,
    //     'allow' => [],   // e.g. ['GET /auth/callback']
    // ],
],
```

Every lint is diagnostics-only — none of them can change a byte of the emitted document — and every
default below is set by where the rule was measured to fire, not by whether it would be correct. A rule
that fires where you can do nothing teaches you to ignore the channel, and takes the useful warnings
with it.

| Rule | Default | Warns about |
| --- | --- | --- |
| `leakage` | on | A schema property, example or default that looks like a credential. |
| `descriptions` | **off** | An operation publishing neither a summary nor a description. |
| `operation_ids` | on | An `operationId` a generated client can't name a method after. |
| `tags` | **off** | A tag your operations carry that [`tags.definitions`](#tags) never declares. |
| `vacuous_union` | on | An `anyOf` whose unconstrained branch — `{}`, or the `true` that means the same — accepts anything, so its typed branches add no constraint. |
| `examples` | on | A published example the schema beside it rejects. Its `allow` entries are JSON pointers, or the label the message names. |
| `unpinned_redirect` | on | A redirect the document doesn't say exactly one thing about: the `3XX` range alone, or the range still standing beside a concrete 3xx. |

### Data leakage

The data-leakage pass checks two things.

**Property names.** A name that looks sensitive (`password`, `token`, `secret`, `api_key`, …) warns with
its JSON pointer. Names normalize to lowercase alphanumerics, so `api_key`, `apiKey` and `API-KEY` are
one token.

**Published values.** Every leaf under `example`, `examples`, `const`, `enum` and `default` is matched
against known credential shapes — the check that catches a real secret folded out of a class constant
under an innocent member name:

| Recognized shape | Matches |
| --- | --- |
| A PEM private key | `-----BEGIN PRIVATE KEY-----` and its labeled variants |
| An AWS access key id | `AKIA…`, `ASIA…` |
| A GitHub token | `ghp_…`, `github_pat_…` |
| A live Stripe secret key | `sk_live_…`, `rk_live_…` |
| A Slack token | `xoxb-…`, `xoxp-…` |
| A JWT | `eyJ….….…` |
| A URL with embedded credentials | `postgres://user:password@host/db` |

The warning names the member and pointer, never the matched text — echoing the secret would only move it
into your build log. Shapes only: there is deliberately no entropy scoring (UUIDs, hashes and base64
payloads are what good examples look like) and no internal-hostname heuristic (a private domain is a
legitimate server URL).

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `true` | Turn the pass on/off. |
| `allow` | `[]` | Safelist by property name or JSON pointer. Silences both kinds of finding; for a value, use the pointer. A **name** silences the lint only — the [response recorder](/laravel/documenting/examples/#credentials-never-reach-the-file) redacts by name regardless, and honours a pointer alone. |
| `patterns` | built-in table | Extra token → label heuristics for **names**, merged over the built-in table (key = normalized token, matched when a name *contains* it). |

### Descriptions

`lint.missing-description` warns on an operation that publishes neither a `summary` nor a
`description`, so the document never says what the endpoint does. It's the one completeness hole a
reader can't work around — nothing else in the document carries that sentence. Write a docblock on the
action (its first line becomes the summary, the rest the description) or put one in an
[overlay](/laravel/guides/customizing-output/#openapi-overlays-in-practice); the warning carries the file and line the action was
recovered from, so you can go straight there.

Off by default, and deliberately: on an API that documents nothing this fires once per operation, which
is a backlog rather than a diagnostic. Turn it on when you're closing the gap and want the list.

Deliberately operations only. Parameters and schema properties were measured on a real route set and
fire on 40% and 98% of their populations respectively, almost all of it where there's nothing to write —
a route-model-bound `{invoice}`, a column whose name is the whole story.

[Webhooks](/laravel/documenting/webhooks/) are operations too, and are checked the same way — the
docblock to write is the one on the `#[Webhook]` class. A webhook is named `POST webhooks.invoice.paid`
in the message and in the safelist, since it's published under a name rather than a path.

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `false` | Turn the pass on/off. |
| `allow` | `[]` | Safelist by operation signature (`GET /api/ping`, `POST webhooks.invoice.paid`) or by `operationId`. |

### Operation ids

`lint.operation-id-style` warns on an `operationId` a generated client can't turn into a method name:
empty, starting with a digit, or carrying anything outside letters, digits and the separators
`.` `-` `_` `@`. Your consumers meet the id as the function they call, so a broken one either fails
codegen or arrives renamed to something nobody wrote.

The alphabet is wider than an identifier's on purpose: `.`, `-` and `@` are what the `route-name` and
`controller-method` [id strategies](#representation) mint, and every generator in this space folds them.
So nothing Docuccino produces can trip this rule — a finding is always on a string somebody typed, in
an [`#[OperationId]`](/laravel/reference/attributes/#operationid), a route name or a
[`#[Webhook]`](/laravel/documenting/webhooks/) name, and can be typed differently. That's why it's on by
default.

A webhook is published under its name, so that name *is* its `operationId` and renaming the attribute is
what fixes it — `#[OperationId]` doesn't reach a webhook. The message and the safelist name it
`POST webhooks.invoice.paid`.

Duplicate ids are a separate check with a better vantage point: `route.duplicate-operation-id` reports
them where the pair is met, naming both routes.

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `true` | Turn the pass on/off. |
| `allow` | `[]` | Safelist by operation signature (`GET /api/ping`, `POST webhooks.invoice.paid`) or by the id itself. |

### Undocumented tags

`lint.undocumented-tag` warns on a tag your operations carry that [`tags.definitions`](#tags)
never declares, so it reaches the reader as a bare heading among tags that have a summary, a
description and a place in the hierarchy. A tag a [webhook](/laravel/documenting/webhooks/) puts on
itself with `#[Group]` counts, whether or not any route carries it.

It says nothing at all until the document declares at least one tag. Undeclared tags are the normal,
correct state for an API that never curated them, and "you forgot this one" only means something once
the others have descriptions.

Off besides that guard, for the case the guard can't tell apart: declaring a few nav parents by hand
and letting the rest derive from controller names is a deliberate shape, and firing once per derived tag
there would be noise. Turn it on when your `definitions` are meant to be the complete set.

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `false` | Turn the pass on/off. |
| `allow` | `[]` | Safelist by tag name. |

### Vacuous union

`lint.vacuous-union` warns on an `anyOf` carrying an unconstrained branch — `{}`, or the `true` that says
the same thing: that branch accepts anything, so the typed branches beside it add no constraint and the
schema validates like `mixed` while reading like a contract. It's the trace of an honest widening — one arm of the union recovered as
"anything" — and what it cost is the shape your consumer would otherwise have validated against. The
finding names the operation and the JSON pointer, so you can go to the arm and pin it with a return
docblock or a [`#[Response]`](/laravel/reference/attributes/#response) on the action.

The shape itself is kept: the typed branch still tells a reader what the value usually is, and dropping
it would lose that. A branch carrying only annotations — a `description`, a `title` — counts as
constrained, since it says something a reader acts on.

On by default. Measured against a real application's export it fired once across 221 operations, all of
it where the author could act: a union goes vacuous only where recovery gave up on an arm, which is
exactly the case worth a line. It also can't false-fire on your data — the walk skips `x-` members and
the values of `enum`, `examples`, `example`, `default` and `const`, so an example that happens to be
shaped like a union is read as the value it is.

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `true` | Turn the pass on/off. |
| `allow` | `[]` | Safelist by operation signature (`GET /api/ping`, `POST webhooks.invoice.paid`) or by `operationId`. |

## Diagnostics

```php
'diagnostics' => [
    'accept' => [
        // 'eloquent.no-columns',
    ],
],
```

`accept` is the list of [diagnostic codes](/laravel/reference/diagnostics/) you've read and decided
to live with. An accepted diagnostic **still prints**, marked `accepted` and counted in a closing
line, and stops counting towards [`--fail-on`](/laravel/reference/commands/#docuccinoexport). That's
what makes a stricter gate adoptable: you can turn `--fail-on=info` on today, accept the codes you
can't act on — a vendor model with no readable columns, a validation rule that's genuinely a closure
— and still have the gate catch everything new.

```php
'diagnostics' => [
    'accept' => [
        'eloquent.no-columns',
        'validation.rule-unrecoverable',
    ],
],
```

The unit is a whole code, not a code at one route: a code names a cause, which is the thing you
decide about, and a list scoped by route would be a second copy of your application's shape that goes
stale on the next rename.

**An `error` is never accepted.** An error means the document is wrong or the build lost a whole tier
of facts, so a code that reaches an error keeps failing the run, and the build says so with
`config.accept-refused`. Accepting a code that's *sometimes* an error still covers its `warning`,
`info` and `hint` reports.

Nothing accepted is invisible, and the list can't rot:

| Where | What you see |
| --- | --- |
| The diagnostic itself | `[info, accepted] eloquent.no-columns: …`, exactly where it always printed. |
| The end of each document's block | `Accepted, so --fail-on ignores them: eloquent.no-columns (12)` — every entry that fired, with its hit count. |
| An entry that fired as an error | `config.accept-refused` — acceptance didn't apply, and the run still failed. |
| An entry nothing reported | `config.accept-unused` — the cause is fixed, or the code is misspelled. Delete the line. |

`config.accept-unused` is checked once a run has built **every** document, so `docuccino:export
billing` never reports an entry the document it skipped fires on.

| Key | Default | Effect |
| --- | --- | --- |
| `accept` | `[]` | Diagnostic codes that print but never fail `--fail-on`. Errors are never accepted. |

## Engine

```php
'engine' => [
    'mode' => env('DOCUCCINO_ENGINE', 'in-process'),
    // 'memory_limit' => '2G',
    'project_paths' => ['app'],
    // 'neon' => 'phpstan.neon',
],
```

| Key | Default | Effect |
| --- | --- | --- |
| `mode` | `in-process` | `in-process` runs PHPStan; `null` skips inference entirely (docblocks and attributes still work). Those are the two modes. Set it per environment with `DOCUCCINO_ENGINE`. A boot failure degrades to no inference rather than failing the build. |
| `memory_limit` | unset | PHP memory limit for inference, applied on **console builds only**. Only ever **raises** — an already-higher or unlimited process is left alone, and `-1` isn't accepted here — so the knob can't introduce the exhaustion it exists to prevent. `--memory-limit` on the build commands overrides it. |
| `project_paths` | `['app']` | The **descend** scope: directories the engine follows for general interprocedural analysis (throw classification, inline `Validator::make()` rules). Bounds descent into callee bodies. |
| `neon` | unset | Your own PHPStan config file, included by the one the engine writes for itself. Relative to the application base path. A file that isn't there warns (`config.engine-neon-missing`) and inference runs without it. |

PHP cannot catch memory exhaustion, so it's the one failure that kills a build instead of degrading —
`memory_limit` and `--memory-limit` exist to prevent it. Full walkthrough:
[the export runs out of memory](/laravel/guides/troubleshooting/#the-export-runs-out-of-memory).

Inference needs the dev-only `docuccino/inference-phpstan` package. Without it, every mode but `null`
degrades to no inference and each export carries one `engine.not-installed` warning naming the
install command — `null` is the explicit opt-out and stays silent.

Any other value warns (`engine.mode-unknown`) and runs in-process — a typo in `DOCUCCINO_ENGINE`
costs you a diagnostic, never a failed build.

:::note[`project_paths` is the descend scope, not everything the engine can reach]
There are two scopes, and only this one is configured. `project_paths` bounds **descent**. The wider
**prime** scope — every local PSR-4 source root in your `composer.json`, so a modular `Modules/` root
too — is derived automatically, and the Query Builder trace and the error-response refiner follow
helpers into *any* primed root. That's why a query object or problem renderer in `Modules/…` is
resolved even though it isn't listed here. Vendor code is never primed or followed.

So you rarely need to change this: add a path only to broaden throw/inline-rules descent — not to make
modular helpers resolvable, which priming already handles.
:::

:::tip[Your PHPStan extensions are already Docuccino extensions]
The engine really is PHPStan, so `neon` is the one escape hatch you need when the analyzer can't work
something out on its own. Point it at the `phpstan.neon` you already maintain:

```php
'engine' => [
    'mode' => env('DOCUCCINO_ENGINE', 'in-process'),
    'project_paths' => ['app'],
    'neon' => 'phpstan.neon',
],
```

Every dynamic return-type extension, stub file and service that file registers is in play while your
document is built. A gateway method that hands back a bare `JsonResponse`, and whose real payload only
your extension knows, documents that payload. There's no Docuccino-specific API to learn — it's a
PHPStan config, read by PHPStan.

Docuccino keeps its own analysis level, scanned paths and scratch directory; what your file
contributes are the extensions, stubs and services it registers, alongside the ones the engine ships.
Editing it invalidates the [fragment cache](#cache) — the file's contents are part of the build key,
so a sharpened extension shows up in the next build rather than the one after it.
:::

## Cache

```php
'cache' => [
    'enabled' => env('DOCUCCINO_FRAGMENT_CACHE', false), // fragment cache: incremental builds, off by default
    'store' => null,    // Laravel cache store for the runtime document cache (docuccino:cache)
    // 'path' => null,  // fragment cache directory (defaults to storage_path('docuccino/fragments'))
],
```

| Key | Effect |
| --- | --- |
| `enabled` | Turns on the fragment cache for incremental builds. `DOCUCCINO_FRAGMENT_CACHE` overrides it, which is how [`docuccino:watch`](/laravel/reference/commands/#docuccinowatch) turns it on for the builds it drives without changing your config. The key hashes the tool/spec/identity-algo versions, the document it is being built for, doc config, resolved extension list, route signature, the build environment, and every dependency file the engine reported — so invalidation is sound even for a Query class three calls deep. Assembly/canonicalize/validate always run fresh. A build whose routes are all warm never boots the analyzer at all; [Speeding up builds](/laravel/guides/speeding-up-builds/) covers when to turn this on. |
| `store` | Laravel cache store name for the runtime document cache warmed by `docuccino:cache`. |
| `path` | Fragment cache directory (defaults to `storage_path('docuccino/fragments')`). Docuccino drops a `.gitignore` into the directory it creates — the same `*` / `!.gitignore` pair Laravel ships inside `storage/` — so cached fragments stay out of your repository. An existing `.gitignore` is never overwritten. |
