---
title: Configuration reference
description: Every live key in config/docuccino.php and what it does.
---


The published `config/docuccino.php` drives everything. Every key is listed in the file itself —
required keys active, optional ones commented out — so you can discover the whole surface by
scrolling through it. This page is the long-form version: what each key does, what it defaults to,
and where its behavior is explained in full. The file is plain data — no imports, no class
references — so it stays safe to load even where Docuccino itself isn't installed.

## Top level

```php
return [
    'enabled' => env('DOCUCCINO_ENABLED', true),
    'documents' => [ /* … */ ],
    'extensions' => [],
    'lint' => [ /* … */ ],
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
Every path in a document — `info.description.file`, `content.dir`, `overlays`, `export.path` — may be
written relative to your application root or as an absolute path; both resolve to the same file. Write
them however you like: a path inside your application is **stored relative to the application root**,
so the generated document is identical on your laptop, in CI, and in a container, wherever the app is
checked out.

A path pointing *outside* your application is kept exactly as you wrote it — it has to be, or the file
could not be read — which makes the generated document specific to machines that have that path. Builds
say so, once per key, with a `config.machine-dependent-path` info diagnostic.
:::

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
],
```

Emitted verbatim as OAS `servers`, including server variables. For a worked multitenant subdomain
example (`{tenant}.example.com`), see [Deploying to production](/laravel/guides/production/#multitenant-base-urls).

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
'error_responses' => 'default', // 'default' | 'problem-details' | 'none'

// Or a bag, to also choose the Problem Details 422 `errors` shape:
'error_responses' => ['preset' => 'problem-details', 'errors_shape' => 'pointer-list'],
```

Selects the error-response strategy. `default` documents Laravel's stock JSON error shapes;
`problem-details` activates the [RFC 9457 preset](/laravel/documenting/errors/) (`application/problem+json`);
`none` emits no error responses. In every case, an [inferred exception handler](/laravel/documenting/errors/)
that recovers your app's real error shape wins ahead of this fallback. The strategy also governs the
[implicit 401/422/404/403 responses](/laravel/documenting/errors/#implicit-responses-middleware-bindings--validation)
(none of them are emitted under `none`).

`errors_shape` (only meaningful with the Problem Details preset) chooses how the `422` body models
`errors`: `map` (a field-keyed map of message lists, the default) or `pointer-list` (a list of
`{detail, pointer}` JSON-Pointer objects).

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

`summary`, `parent` and `kind` are OpenAPI 3.2 only. Exporting 3.1 drops them, each with its own
`downlevel.tag-*` warning — the tags themselves stay, flattened.

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
[Guides, pages & prose](/laravel/guides/narrative-content/) for the full workflow, or the
[UIR content layer](/uir/#content-layer) for how it lives in the raw document.

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
    'lists' => 'comma',             // comma | array (Query Builder sort/include list style)
    'nullable' => 'type-array',     // type-array (type: [x, null]) | anyof ({type: null} branch)
    'operation_id' => 'route-name', // route-name | controller-method ({ShortController}@{method})
    // 'enums' => [
    //     'naming' => 'none',      // none | x-enumNames | x-enum-varnames
    //     'components' => true,    // true (hoist each enum to a $ref'd component) | false (inline everywhere)
    // ],
],
```

Separates *what was inferred* from *how it is expressed in the spec*. The semantic facts stay
stable in `x-docuccino` regardless of policy, so the diff engine can tell "representation changed"
from "API changed".

| Key | Values | Default | Effect |
| --- | --- | --- | --- |
| `filters` | `bracketed` \| `deepObject` | `bracketed` | Query Builder filter/field style: one flat `filter[status]` / `fields[type]` parameter each (`bracketed`), or a single `filter` / `fields` object parameter with `style: deepObject` (`deepObject`). Read at [`RepresentationPolicy`](/laravel/packages/query-builder/), drives the deepObject/bracketed rendering. |
| `lists` | `comma` \| `array` | `comma` | Query Builder `sort` / `include` list style: a single comma-separated string (`comma`), or an exploded array parameter with `style: form, explode: false` and an `items` enum (`array`). |
| `nullable` | `type-array` \| `anyof` | `type-array` | How nullability is expressed: `type: ["string","null"]` vs a `{type: null}` `anyOf` branch (legacy tooling). |
| `operation_id` | `route-name` \| `controller-method` | `route-name` | `operationId` strategy. |
| `enums.naming` | `none` \| `x-enumNames` \| `x-enum-varnames` | `none` | Codegen name hints on enum schemas (off by default); read by the [Enum integration](/laravel/documenting/schemas/#enums). |
| `enums.components` | `true` \| `false` | `true` | Whether each reflectable enum hoists to a shared `#/components/schemas` entry that properties and query-parameter item schemas `$ref` (`true`), or its `type`/`enum`/`x-enumDescriptions` are inlined at every use site (`false`). |
| `errors.components` | `true` \| `false` | `true` | Whether an error body repeated across operations hoists to one shared `#/components/responses` entry each operation `$ref`s (`true`), or every copy is inlined (`false`). |

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
    'query_builder' => ['pagination_terminals' => ['paginateList']], // extra paginating method names
    'permission'    => ['enabled' => true],                       // opt in — off by default
],
```

Every bag also accepts **`enabled`** (`bool`). It is resolved per document: an integration
contributes only when its package is installed **and** the document enables it. Every integration
defaults **on** when its package is installed, **except `permission`**, which defaults **off** —
documenting role and permission names would publish your application's internal authorization
taxonomy, so it is explicit opt-in. When a package is installed but its integration is disabled, the
build emits one `integration.disabled` info diagnostic per document, so the switch is discoverable.

There are **eleven** toggleable bags, each keyed by its config name — set
`integrations.<key>.enabled` to turn one off (or, for `permission`, on):

| Bag key | Package / source | `enabled` default |
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
| `query_builder` | `pagination_terminals` | `[]` | Extra method names that count as paginating terminals during the trace. |
| `permission` | `enabled` | `false` | Opt in to document `role:`/`permission:` requirements (`x-permissions`). Off by default so authorization names are not published unintentionally. |

### `export`

```php
'export' => [
    'path' => 'docs/openapi.json',
],
```

`path` is the default output location for `docuccino:export` and the file
`viewer.source: artifact` serves. The output format is chosen per run by
[`docuccino:export --format`](/laravel/reference/commands/#docuccinoexport).

### `viewer`

```php
'viewer' => [
    'route' => '/docs/api', // null disables the runtime endpoints for this document
    'gate' => null,         // Gate ability name; null = local environment only
    'middleware' => ['web', 'throttle:60,1'],
    'source' => 'generate', // generate | artifact | cache
    // 'cdn' => false,       // true loads Scalar from a CDN instead of the bundled asset
],
```

| Key | Default | Effect |
| --- | --- | --- |
| `route` | `'/docs/api'` | Base path for the viewer/spec/asset routes. `null` disables them for this document. |
| `gate` | `null` | Gate ability guarding the HTML + `.json` routes. `null` = available only in the `local` environment. The static asset route is never gated. |
| `middleware` | `['web', 'throttle:60,1']` | Middleware for the viewer routes. Keep `throttle` when exposing the (potentially expensive) spec endpoint publicly. See the warning below if your app is multi-tenant or domain-gated. |
| `source` | `'generate'` | `generate` rebuilds on every request (fine for local/gated); `artifact` re-emits the committed `export.path`; `cache` serves the `docuccino:cache`-warmed payload (cold cache falls back to generate). |
| `cdn` | `false` | `true` loads Scalar from jsDelivr instead of the local bundle. |

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
        'allow' => [],   // e.g. ['reset_token', '#/components/schemas/Invoice/properties/status']
        // 'patterns' => ['sortcode' => 'a bank sort code', 'iban' => 'an IBAN'],
    ],
],
```

The data-leakage pass is diagnostics-only — it never mutates the document — and checks two things.

**Property names.** A name that looks sensitive (`password`, `token`, `secret`, `api_key`, …) warns with
its JSON pointer. Names normalize to lowercase alphanumerics, so `api_key`, `apiKey` and `API-KEY` are
one token.

**Published values.** Every leaf under `example`, `examples`, `const`, `enum` and `default` is matched
against known credential shapes — the check that catches a real secret folded out of a class constant
under an innocent member name:

| Recognized shape | Matches |
| --- | --- |
| A PEM private key | `-----BEGIN PRIVATE KEY-----` and its labelled variants |
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
| `allow` | `[]` | Safelist by property name or JSON pointer. Silences both kinds of finding; for a value, use the pointer. |
| `patterns` | built-in table | Extra token → label heuristics for **names**, merged over the built-in table (key = normalized token, matched when a name *contains* it). |

## Engine

```php
'engine' => [
    'mode' => env('DOCUCCINO_ENGINE', 'in-process'),
    // 'memory_limit' => '2G',
    'project_paths' => ['app'],
],
```

| Key | Default | Effect |
| --- | --- | --- |
| `mode` | `in-process` | `in-process` runs PHPStan; `null` skips inference entirely (docblocks and attributes still work). Those are the two working modes. Set it per environment with `DOCUCCINO_ENGINE`. A boot failure degrades to no inference rather than failing the build. |
| `memory_limit` | unset | PHP memory limit for inference, applied on **console builds only**. Only ever **raises** — an already-higher or unlimited process is left alone, and `-1` isn't accepted here — so the knob can't introduce the exhaustion it exists to prevent. `--memory-limit` on the build commands overrides it. |
| `project_paths` | `['app']` | The **descend** scope: directories the engine follows for general interprocedural analysis (throw classification, inline `Validator::make()` rules). Bounds descent into callee bodies. |

PHP cannot catch memory exhaustion, so it's the one failure that kills a build instead of degrading —
`memory_limit` and `--memory-limit` exist to prevent it. Full walkthrough:
[the export runs out of memory](/laravel/guides/troubleshooting/#the-export-runs-out-of-memory).

Inference needs the dev-only `docuccino/inference-phpstan` package. Without it, every mode but `null`
degrades to no inference and each export carries one `engine.not-installed` warning naming the
install command — `null` is the explicit opt-out and stays silent.

The engine also carries `orchestrated` and `caching` worker compositions, but the adapter does not
plumb them through yet: setting either warns (`engine.mode-not-wired`) and runs in-process. Stay on
`in-process` or `null`.

:::note[`project_paths` is the descend scope, not everything the engine can reach]
There are two scopes, and only this one is configured. `project_paths` bounds **descent**. The wider
**prime** scope — every local PSR-4 source root in your `composer.json`, so a modular `Modules/` root
too — is derived automatically, and the Query Builder trace and the error-response refiner follow
helpers into *any* primed root. That's why a query object or problem renderer in `Modules/…` is
resolved even though it isn't listed here. Vendor code is never primed or followed.

So you rarely need to change this: add a path only to broaden throw/inline-rules descent — not to make
modular helpers resolvable, which priming already handles.
:::

## Cache

```php
'cache' => [
    'enabled' => false, // OperationFragment cache: incremental builds, off by default
    'store' => null,    // Laravel cache store for the runtime document cache (docuccino:cache)
    // 'path' => null,  // fragment cache directory (defaults to storage_path('docuccino/fragments'))
],
```

| Key | Effect |
| --- | --- |
| `enabled` | Turns on the `OperationFragment` cache for incremental builds. The key hashes the tool/spec/identity-algo versions, doc config, resolved extension list, route signature, and every dependency file the engine reported — so invalidation is sound even for a Query class three calls deep. Assembly/canonicalize/validate always run fresh. |
| `store` | Laravel cache store name for the runtime document cache warmed by `docuccino:cache`. |
| `path` | Fragment cache directory (defaults to `storage_path('docuccino/fragments')`). |
