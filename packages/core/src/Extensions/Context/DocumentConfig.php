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
     * @param  array<string, mixed>  $security  the `security` config (schemes + document requirement)
     * @param  array<string, mixed>  $tags  the `tags` config (map + mapper class-string + list)
     * @param  array<string, mixed>  $representation  the `representation` policy config
     * @param  array<string, mixed>  $viewer  the `viewer` config (driver/route/gate/source/cdn)
     * @param  string  $versioning  the versioning policy keyword (`semver|date|none`)
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
        public array $security = [],
        public array $tags = [],
        public array $representation = [],
        public array $viewer = [],
        public string $versioning = 'none',
        public array $raw = [],
    ) {}

    /**
     * The value of a `representation.*` policy keyword, or the given default. Kept behaviour-
     * preserving: an absent key returns the default (which encodes today's behaviour).
     */
    public function representationPolicy(string $key, string $default): string
    {
        $value = $this->representation[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
