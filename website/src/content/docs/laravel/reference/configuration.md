---
title: Configuration reference
description: Every live key in config/docuccino.php and what it does.
---


The published `config/docuccino.php` drives everything. This page documents **every key that is
read today**. A handful of keys are marked FUTURE in the shipped file (multi-format export,
selectable viewer driver, `representation.filters`) — those are not yet wired and are called out
where they appear.

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

### `info`

```php
'info' => [
    'title' => 'API Documentation',
    'version' => '1.0.0',
    // 'description' => ['file' => 'resources/docs/api/description.md'],
],
```

Maps to OAS `info`. `description` accepts `['file' => '…md']` to load your API's introduction from a
Markdown file. `version` is the value the [versioning policy](#versioning) evaluates during
`docuccino:diff --enforce`.

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
],
```

Route selection. `include`/`exclude` are URI globs; `closure` is an optional predicate that runs
after the globs for arbitrary logic. A route must pass includes, fail excludes, and satisfy the
closure to be documented.

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

| Key | Effect |
| --- | --- |
| `auto_detect_middleware` | Wildcard matched against each route's middleware; a match applies the `default` requirement. |
| `schemes` | `components.securitySchemes` — full breadth: http bearer/basic, apiKey (header/query/cookie), oauth2 flow builders, OpenID Connect. |
| `default` | The per-operation `security` requirement applied to auth-detected routes. |
| `document` | A document-wide `security` requirement. |

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
    //     ['name' => 'Forms', 'description' => '…', 'weight' => 0],
    // ],
],
```

`default_strategy` tags an operation that has no `#[Group]`: `controller` (the default — the
controller's short name with a trailing `Controller` stripped, e.g. `FormController` → `Form`, then
run through `map`) or `none` (leave it untagged). Closure routes are never auto-tagged.
`map` is a raw-tag → display-tag table (exact match wins, else the first matching prefix).
`mapper` swaps in a custom `TagMapper`. `definitions` supplies OAS top-level tag objects.

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
    'filters' => 'bracketed',       // FUTURE: not read yet | bracketed | deepObject
    'nullable' => 'type-array',     // type-array (type: [x, null]) | anyof ({type: null} branch)
    'operation_id' => 'route-name', // route-name | controller-method ({ShortController}@{method})
    // 'enums' => ['naming' => 'none'], // none | x-enumNames | x-enum-varnames
],
```

Separates *what was inferred* from *how it is expressed in the spec*. The semantic facts stay
stable in `x-docuccino` regardless of policy, so the diff engine can tell "representation changed"
from "API changed".

| Key | Values | Effect |
| --- | --- | --- |
| `filters` | `bracketed` \| `deepObject` | **FUTURE** — not read yet. Query filter style for the Query Builder integration. |
| `nullable` | `type-array` \| `anyof` | How nullability is expressed: `type: ["string","null"]` vs a `{type: null}` `anyOf` branch (legacy tooling). |
| `operation_id` | `route-name` \| `controller-method` | `operationId` strategy. |
| `enums.naming` | `none` \| `x-enumNames` \| `x-enum-varnames` | Codegen name hints on enum schemas (off by default); read by the [Enum integration](/laravel/documenting/schemas/#enums). |

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
    'formats' => ['openapi-3.2'], // FUTURE: not read yet; --format selects the emitter today
],
```

`path` is the default output location for `docuccino:export` and the source for
`viewer.source: artifact`. `formats` is **FUTURE** (multi-format export) — today the emitter is
chosen by the `--format` flag.

### `viewer`

```php
'viewer' => [
    'driver' => 'scalar',   // FUTURE: selectable viewer driver; only Scalar ships
    'route' => '/docs/api', // null disables the runtime endpoints for this document
    'gate' => null,         // Gate ability name; null = local environment only
    'middleware' => ['web', 'throttle:60,1'],
    'source' => 'generate', // generate | artifact | cache
    // 'cdn' => false,       // true loads Scalar from a CDN instead of the bundled asset
],
```

| Key | Default | Effect |
| --- | --- | --- |
| `driver` | `'scalar'` | **FUTURE** — only the bundled Scalar viewer ships. |
| `route` | `'/docs/api'` | Base path for the viewer/spec/asset routes. `null` disables them for this document. |
| `gate` | `null` | Gate ability guarding the HTML + `.json` routes. `null` = available only in the `local` environment. The static asset route is never gated. |
| `middleware` | `['web', 'throttle:60,1']` | Middleware for the viewer routes. Keep `throttle` when exposing the (potentially expensive) spec endpoint publicly. |
| `source` | `'generate'` | `generate` rebuilds on every request (fine for local/gated); `artifact` re-emits the committed `export.path`; `cache` serves the `docuccino:cache`-warmed payload (cold cache falls back to generate). |
| `cdn` | `false` | `true` loads Scalar from jsDelivr instead of the local bundle. |

### `versioning`

```php
'versioning' => 'none', // 'semver' | 'date' | 'none'
```

Add this key to a document to choose the policy that `docuccino:diff --enforce` applies (default
`none`). `semver` requires a major version bump for breaking changes; `date` requires a new date
version; `none` never fails on versioning. See [`docuccino:diff`](/laravel/reference/commands/#docuccinodiff).

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

The data-leakage pass is a diagnostics-only `DocumentTransformer` (it never mutates output). It
warns on schema properties whose names look sensitive (`password`/`token`/`secret`/`api_key`/…).

| Key | Effect |
| --- | --- |
| `enabled` | Turn the pass on/off. |
| `allow` | Safelist known-good properties by name or JSON pointer. |
| `patterns` | Extra token → human-label heuristics merged over the built-in table (key = normalized token, matched when a property name *contains* it). |

## Engine

```php
'engine' => [
    'mode' => env('DOCUCCINO_ENGINE', TypeEngineMode::InProcess->value),
    'project_paths' => ['app'],
],
```

| Key | Effect |
| --- | --- |
| `mode` | Which `TypeEngine` backs inference (in-process by default). A boot failure always degrades to the `NullTypeEngine` so docblock/attribute-only docs still build. |
| `project_paths` | Directories the engine treats as project (vs vendor) code — bounds descent into callee bodies. |

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
