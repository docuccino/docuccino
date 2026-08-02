<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\Json;

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
     * @param  TagMapper|null  $tagMapper  resolved tag mapper (identity when null)
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
        public ?TagMapper $tagMapper = null,
        public array $raw = [],
    ) {}

    /** Apply the document's tag mapper (identity when none is configured). */
    public function mapTag(string $tag): string
    {
        return $this->tagMapper?->map($tag) ?? $tag;
    }

    /**
     * The per-integration config sub-bag `integrations.<name>` (design §9), or `[]` when absent —
     * the single home for an integration's document-level knobs (Sanctum modes/cookie, Passport
     * url, Query Builder pagination terminals, API-resources wrapping …).
     *
     * @return array<string, mixed>
     */
    public function integration(string $name): array
    {
        $integrations = Hydrate::map($this->raw['integrations'] ?? null);

        return Hydrate::map($integrations[$name] ?? null);
    }

    /**
     * A deterministic fingerprint of this document's configuration — the single owner of the
     * config-hash (a fragment-cache key input, design §10, and the document's `configHash`). Folds
     * the whole raw config bag through the order-insensitive {@see Json::stable()} encoder so key
     * order cannot perturb the hash; falls back to the document key when the bag cannot be encoded.
     */
    public function hash(): string
    {
        $stable = Json::stable($this->raw);

        return hash('sha256', $stable === '' ? $this->key : $stable);
    }

    /**
     * Document-level tag definitions from `tags.definitions`: each `{name, description?, weight?}`,
     * sorted deterministically by ascending weight (default 0) then name, ready for the OAS
     * top-level `tags` array. Malformed entries (no string `name`) are skipped.
     *
     * @return list<array{name: string, description?: string}>
     */
    public function tagDefinitions(): array
    {
        $definitions = $this->tags['definitions'] ?? null;
        if (! is_array($definitions)) {
            return [];
        }

        $rows = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['name'] ?? null)) {
                continue;
            }

            $entry = ['name' => $definition['name']];
            if (is_string($definition['description'] ?? null)) {
                $entry['description'] = $definition['description'];
            }

            $weight = $definition['weight'] ?? 0;
            $rows[] = ['weight' => is_int($weight) ? $weight : 0, 'entry' => $entry];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['weight'], $a['entry']['name']] <=> [$b['weight'], $b['entry']['name']]);

        return array_map(static fn (array $row): array => $row['entry'], $rows);
    }

    /**
     * The `security.schemes` map (name → OAS security-scheme object) for `components.securitySchemes`.
     * Malformed entries (non-array values) are dropped so a typo can't break the document.
     *
     * @return array<string, array<string, mixed>>
     */
    public function securitySchemes(): array
    {
        return Hydrate::mapOfArrays($this->security['schemes'] ?? null);
    }

    /**
     * The document-level security requirement from `security.document` (an OAS `security` array —
     * a list of `{scheme: scopes[]}` requirement objects), or null when none is configured.
     *
     * @return list<array<string, mixed>>|null
     */
    public function documentSecurity(): ?array
    {
        return Hydrate::listOfMaps($this->security['document'] ?? null);
    }

    /**
     * The default per-operation requirement from `security.default`, applied by the auto-detect
     * middleware layer to routes whose middleware matches {@see $authMiddleware}.
     *
     * @return list<array<string, mixed>>|null
     */
    public function defaultSecurity(): ?array
    {
        return Hydrate::listOfMaps($this->security['default'] ?? null);
    }

    /**
     * The configured export target from `export.path` (the file the `docuccino:export` artifact is
     * written to and the viewer's `artifact` source reads back), defaulting to `docs/openapi.json`.
     * Framework-agnostic: may be relative — the adapter resolves it against the app base path.
     */
    public function exportPath(): string
    {
        $export = is_array($this->raw['export'] ?? null) ? $this->raw['export'] : [];

        return is_string($export['path'] ?? null) ? $export['path'] : 'docs/openapi.json';
    }
}
