<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Closure;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\Hydrate;

/**
 * Builds a framework-agnostic {@see DocumentConfig} from one `config('docuccino.documents.*')`
 * entry, resolving an `info.description.file` reference to the file's contents so the pipeline
 * never touches the filesystem.
 */
final readonly class DocumentConfigFactory
{
    public function __construct(
        private string $basePath,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function make(string $key, array $config, string $onRouteError): DocumentConfig
    {
        $routes = self::arr($config['routes'] ?? []);
        $security = self::arr($config['security'] ?? []);

        $closure = $routes['closure'] ?? null;

        return new DocumentConfig(
            key: $key,
            info: $this->resolveInfo(self::arr($config['info'] ?? [])),
            servers: Hydrate::listOfMaps($config['servers'] ?? null) ?? [],
            routeInclude: self::stringList($routes['include'] ?? []),
            routeExclude: self::stringList($routes['exclude'] ?? []),
            routeFilter: $closure instanceof Closure ? $closure : null,
            authMiddleware: is_string($security['auto_detect_middleware'] ?? null) ? $security['auto_detect_middleware'] : null,
            errorResponses: is_string($config['error_responses'] ?? null) ? $config['error_responses'] : 'none',
            overlays: self::stringList($config['overlays'] ?? []),
            onRouteError: $onRouteError,
            security: $security,
            tags: self::arr($config['tags'] ?? []),
            representation: self::arr($config['representation'] ?? []),
            viewer: self::arr($config['viewer'] ?? []),
            versioning: is_string($config['versioning'] ?? null) ? $config['versioning'] : 'none',
            raw: $config,
        );
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    private function resolveInfo(array $info): array
    {
        $description = $info['description'] ?? null;

        if (is_array($description) && isset($description['file']) && is_string($description['file'])) {
            $path = $this->basePath.'/'.ltrim($description['file'], '/');
            $contents = @file_get_contents($path);
            $info['description'] = $contents === false ? '' : rtrim($contents, "\n");
        }

        $info['title'] = is_string($info['title'] ?? null) ? $info['title'] : 'API Documentation';
        $info['version'] = self::stringifyVersion($info['version'] ?? '1.0.0');

        return $info;
    }

    private static function stringifyVersion(mixed $version): string
    {
        return is_string($version) ? $version : (is_scalar($version) ? (string) $version : '1.0.0');
    }

    /**
     * @return array<string, mixed>
     */
    private static function arr(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
