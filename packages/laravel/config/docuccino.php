<?php

declare(strict_types=1);

use Docuccino\Laravel\Engine\TypeEngineMode;

return [

    'enabled' => env('DOCUCCINO_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    |
    | Each entry is an independent pipeline run: its own route filters, info,
    | servers, security and export target, sharing route contexts and the
    | TypeEngine in-process. Phase 3a plumbs the whole map but ships one document.
    */
    'documents' => [
        'default' => [
            'info' => [
                'title' => 'API Documentation',
                'version' => '1.0.0',
                // 'description' => ['file' => 'resources/docs/api/description.md'],
            ],
            'servers' => [
                // ['url' => 'https://api.example.com'],
            ],
            'routes' => [
                'include' => ['api/*'],
                'exclude' => [],
                'closure' => null, // fn (RouteDescriptor $route): bool => ...
            ],
            'security' => [
                // Routes whose middleware matches this wildcard get the `default` requirement below.
                'auto_detect_middleware' => 'auth*',
                // Optional (omitted here to keep the default document unauthenticated):
                //   'schemes' => [                              // → components.securitySchemes
                //       'bearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                //       'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                //       'oauth2' => ['type' => 'oauth2', 'flows' => [...]],
                //       'oidc'   => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://…'],
                //   ],
                //   'default'  => [['bearer' => []]],           // per-op requirement for auth-detected routes
                //   'document' => [['bearer' => []]],           // document-wide `security` requirement
            ],
            // Error-response strategy: 'default' documents the framework-default JSON error shapes,
            // 'problem-details' the RFC 9457 (application/problem+json) preset, and 'none' emits no
            // error responses at all.
            'error_responses' => 'default', // 'default' | 'problem-details' | 'none'
            'tags' => [
                // Raw tag => display tag. An exact match wins, else the first prefix the tag starts with.
                'map' => [],
                // Optional keys (omitted here to keep the default config minimal):
                //   'mapper' => Custom::class,   // container-resolved TagMapper; defaults to PrefixTagMapper over `map`.
                //   'definitions' => [           // OAS top-level `tags`, sorted by weight then name:
                //       ['name' => 'Forms', 'description' => '…', 'weight' => 0],
                //   ],
            ],
            'content' => [
                'dir' => null,                     // FUTURE (design §9 content.pages): not read yet.
            ],
            'overlays' => [
                // 'resources/docs/overlays/*.yaml',
            ],
            'representation' => [
                'filters' => 'bracketed',          // FUTURE (filter styles): not read yet | bracketed | deepObject
                'nullable' => 'type-array',        // type-array (type: [x, null]) | anyof ({type: null} branch)
                'operation_id' => 'route-name',    // route-name | controller-method ({ShortController}@{method})
                // 'enums' => ['naming' => 'none'], // none | x-enumNames | x-enum-varnames (codegen name hints)
            ],
            // Per-integration document-level knobs (design §9). Each key is an integration's directory
            // name (kebab-cased); an integration reads only its own bag. All optional — omit the whole
            // `integrations` bag (as here) to use every documented default.
            //   'integrations' => [
            //       // API Resources: top-level resource `data`-wrapping (Laravel JsonResource::$wrap).
            //       'api_resources' => [
            //           'wrap' => true,   // false → never wrap (a global withoutWrapping()); true → 'data';
            //                             // a string → force that key; omit → each resource's own $wrap.
            //       ],
            //       // Sanctum auto-config (only when laravel/sanctum is installed):
            //       'sanctum' => [
            //           'modes'  => ['token', 'stateful'], // which schemes to expose (default: both)
            //           'cookie' => 'myapp_session',       // stateful cookie name (default: session.cookie)
            //       ],
            //       // Passport auto-config (only when laravel/passport is installed):
            //       'passport' => [
            //           'url' => 'https://auth.example.com', // oauth2 flow base URL (default: app.url)
            //       ],
            //       // Query Builder pagination (only when spatie/laravel-query-builder is installed):
            //       'query_builder' => [
            //           'pagination_terminals' => ['paginateList'], // extra paginating method names
            //       ],
            //   ],
            'export' => [
                'path' => 'docs/openapi.json',
                'formats' => ['openapi-3.2'],      // FUTURE (multi-format export): not read yet; --format selects the emitter today
            ],
            'viewer' => [
                'driver' => 'scalar',         // FUTURE (Phase 5) selectable viewer driver; only Scalar ships today
                'route' => '/docs/api',       // null disables the runtime endpoints for this document
                'gate' => null,               // Gate ability name; null = local environment only
                // Middleware for the registered viewer routes. `throttle` protects the (potentially
                // expensive) spec endpoint from abuse; keep it when exposing the viewer publicly.
                'middleware' => ['web', 'throttle:60,1'],
                // generate | artifact (export.path) | cache (docuccino:cache). NOTE: `generate`
                // rebuilds the whole document on every request — fine for local/gated use, but for an
                // exposed viewer prefer `cache` (warmed by `docuccino:cache`) or `artifact`.
                'source' => 'generate',
                // 'cdn' => false,            // true loads Scalar from a CDN instead of the bundled asset
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions
    |--------------------------------------------------------------------------
    |
    | Class-strings resolved from the container and merged with programmatic
    | Docuccino::extend() registrations at build time (never at boot).
    */
    'extensions' => [],

    /*
    |--------------------------------------------------------------------------
    | Lint
    |--------------------------------------------------------------------------
    |
    | Document-level lint rules (diagnostics only — never mutate the output). The
    | data-leakage pass warns on schema properties whose names look sensitive
    | (password/token/secret/api_key/…). Safelist known-good properties by name or
    | JSON pointer; add domain-specific heuristics via `patterns`; set enabled=false
    | to turn it off.
    */
    'lint' => [
        'leakage' => [
            'enabled' => true,
            'allow' => [],   // e.g. ['reset_token', '#/components/schemas/Widget/properties/status']
            // Extra sensitive-name heuristics MERGED over the built-in table (existing tokens keep
            // their label). Key = normalized token (lower-cased, non-alphanumerics stripped), matched
            // when a property name CONTAINS it; value = the human label used in the diagnostic.
            //   'patterns' => ['sortcode' => 'a bank sort code', 'iban' => 'an IBAN'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inference engine
    |--------------------------------------------------------------------------
    |
    | Which TypeEngine backs inference. A boot failure always degrades to the
    | NullTypeEngine so docblock/attribute-only docs still build.
    */
    'engine' => [
        'mode' => env('DOCUCCINO_ENGINE', TypeEngineMode::InProcess->value),
        'project_paths' => ['app'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-route failure & fragment cache
    |--------------------------------------------------------------------------
    */
    'on_route_error' => 'skeleton', // 'skeleton' | 'omit'

    'cache' => [
        'enabled' => false,   // OperationFragment cache (design §10): incremental builds, off by default
        'store' => null,      // Laravel cache store for the runtime document cache (docuccino:cache)
        // 'path' => null,    // fragment cache directory (defaults to storage_path('docuccino/fragments'))
    ],
];
