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
            'error_responses' => 'default', // 'default' | 'none' (presets: Phase 4)
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
                'dir' => null,
            ],
            'overlays' => [
                // 'resources/docs/overlays/*.yaml',
            ],
            'representation' => [
                'filters' => 'bracketed',          // bracketed | deepObject (filter styles: Phase 4)
                'nullable' => 'type-array',        // type-array (type: [x, null]) | anyof ({type: null} branch)
                'operation_id' => 'route-name',    // route-name | controller-method ({ShortController}@{method})
                // 'enums' => ['naming' => 'none'], // none | x-enumNames | x-enum-varnames (codegen name hints)
            ],
            'export' => [
                'path' => 'docs/openapi.json',
                'formats' => ['openapi-3.2'],
            ],
            'viewer' => [
                'driver' => 'scalar',
                'route' => '/docs/api',
                'gate' => null,
                'source' => 'generate',
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
        'enabled' => false,
        'store' => null,
    ],
];
