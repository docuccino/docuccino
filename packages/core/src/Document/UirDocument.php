<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Support\Arr;

/**
 * The immutable in-memory model of a UIR document. Modelled members are typed; anything
 * not modelled (including arbitrary `x-*` members) is preserved verbatim in `rest`, so
 * `fromArray()` → `toArray()` is a faithful round trip.
 */
final readonly class UirDocument
{
    /**
     * @param  array<string, mixed>|null  $info
     * @param  list<array<string, mixed>>|null  $servers
     * @param  list<array<string, mixed>>|null  $security
     * @param  list<array<string, mixed>>|null  $tags
     * @param  array<string, PathItem>|null  $paths
     * @param  array<string, PathItem>|null  $webhooks
     * @param  array<string, mixed>  $rest
     */
    public function __construct(
        public string $uir,
        public string $openapi,
        public ?string $schema = null,
        public ?string $jsonSchemaDialect = null,
        public ?array $info = null,
        public ?array $servers = null,
        public ?array $security = null,
        public ?array $tags = null,
        public ?array $paths = null,
        public ?array $webhooks = null,
        public ?Components $components = null,
        public ?DocumentExtension $xUir = null,
        public array $rest = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $schema = $data['$schema'] ?? null;
        $uir = $data['uir'] ?? '';
        $openapi = $data['openapi'] ?? '';
        $jsonSchemaDialect = $data['jsonSchemaDialect'] ?? null;

        $info = null;
        if (isset($data['info']) && is_array($data['info'])) {
            /** @var array<string, mixed> $info */
            $info = $data['info'];
        }

        $servers = self::listOfMaps($data['servers'] ?? null);
        $security = self::listOfMaps($data['security'] ?? null);
        $tags = self::listOfMaps($data['tags'] ?? null);

        $paths = self::pathMap($data['paths'] ?? null);
        $webhooks = self::pathMap($data['webhooks'] ?? null);

        $components = isset($data['components']) && is_array($data['components'])
            ? Components::fromArray(Arr::stringKeyed($data['components']))
            : null;

        $xUir = isset($data['x-uir']) && is_array($data['x-uir'])
            ? DocumentExtension::fromArray(Arr::stringKeyed($data['x-uir']))
            : null;

        unset(
            $data['$schema'], $data['uir'], $data['openapi'], $data['jsonSchemaDialect'],
            $data['info'], $data['servers'], $data['security'], $data['tags'],
            $data['paths'], $data['webhooks'], $data['components'], $data['x-uir'],
        );

        return new self(
            uir: is_string($uir) ? $uir : '',
            openapi: is_string($openapi) ? $openapi : '',
            schema: is_string($schema) ? $schema : null,
            jsonSchemaDialect: is_string($jsonSchemaDialect) ? $jsonSchemaDialect : null,
            info: $info,
            servers: $servers,
            security: $security,
            tags: $tags,
            paths: $paths,
            webhooks: $webhooks,
            components: $components,
            xUir: $xUir,
            rest: $data,
        );
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function listOfMaps(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array<string, PathItem>|null
     */
    private static function pathMap(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];
        foreach ($value as $template => $item) {
            if (is_array($item)) {
                $out[(string) $template] = PathItem::fromArray(Arr::stringKeyed($item));
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->schema !== null) {
            $out['$schema'] = $this->schema;
        }

        $out['uir'] = $this->uir;
        $out['openapi'] = $this->openapi;

        if ($this->jsonSchemaDialect !== null) {
            $out['jsonSchemaDialect'] = $this->jsonSchemaDialect;
        }

        if ($this->info !== null) {
            $out['info'] = $this->info;
        }

        if ($this->servers !== null) {
            $out['servers'] = $this->servers;
        }

        if ($this->security !== null) {
            $out['security'] = $this->security;
        }

        if ($this->tags !== null) {
            $out['tags'] = $this->tags;
        }

        if ($this->paths !== null) {
            $out['paths'] = array_map(
                static fn (PathItem $item): array => $item->toArray(),
                $this->paths,
            );
        }

        if ($this->webhooks !== null) {
            $out['webhooks'] = array_map(
                static fn (PathItem $item): array => $item->toArray(),
                $this->webhooks,
            );
        }

        if ($this->components !== null) {
            $out['components'] = $this->components->toArray();
        }

        if ($this->xUir !== null) {
            $out['x-uir'] = $this->xUir->toArray();
        }

        return $out + $this->rest;
    }

    public function withDocumentExtension(DocumentExtension $xUir): self
    {
        return new self(
            uir: $this->uir,
            openapi: $this->openapi,
            schema: $this->schema,
            jsonSchemaDialect: $this->jsonSchemaDialect,
            info: $this->info,
            servers: $this->servers,
            security: $this->security,
            tags: $this->tags,
            paths: $this->paths,
            webhooks: $this->webhooks,
            components: $this->components,
            xUir: $xUir,
            rest: $this->rest,
        );
    }
}
