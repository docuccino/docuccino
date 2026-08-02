<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

/**
 * One document's resolved configuration (design §9). Framework-agnostic: the adapter builds
 * this from `config/docuccino.php`. Typed accessors cover what the pipeline and built-in
 * extensions read; the untouched `raw` bag keeps everything else (representation policies,
 * viewer, tag map …) available without modelling every key here in Phase 3a.
 */
final readonly class DocumentConfig
{
    /**
     * @param  array<string, mixed>  $info  OAS info object (title, version, description …)
     * @param  list<array<string, mixed>>  $servers
     * @param  list<string>  $routeInclude  wildcard patterns of URIs to include
     * @param  list<string>  $routeExclude  wildcard patterns of URIs to exclude
     * @param  callable(RouteDescriptor): bool|null  $routeFilter  optional closure filter
     * @param  string|null  $authMiddleware  wildcard matched against middleware to require auth
     * @param  list<string>  $overlays  glob patterns of Overlay 1.0 documents
     * @param  array<string, mixed>  $raw  the full config array for this document
     */
    public function __construct(
        public string $key,
        public array $info,
        public array $servers = [],
        public array $routeInclude = [],
        public array $routeExclude = [],
        public mixed $routeFilter = null,
        public ?string $authMiddleware = null,
        public string $errorResponses = 'none',
        public array $overlays = [],
        public string $onRouteError = 'skeleton',
        public array $raw = [],
    ) {}
}
