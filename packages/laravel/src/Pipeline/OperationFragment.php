<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\Operation;

/**
 * The result of processing one route (design §5 / §10): the frozen operation, the OAS path it
 * belongs under, its HTTP method, the diagnostics raised while building it, and the reusable
 * component schemas it contributed (its delta of the document-wide registry). Carrying the
 * components + diagnostics makes the fragment the self-contained cache unit — a warm cache hit can
 * reconstruct the operation, restore its components, and replay its diagnostics without touching
 * the type engine.
 */
final readonly class OperationFragment
{
    /**
     * @param  list<Diagnostic>  $diagnostics
     * @param  array<string, array<string, mixed>>  $componentSchemas  name → schema this route added
     * @param  array<string, string>  $componentSchemaIds  name → schemaId (FQCN) for diff identity
     */
    public function __construct(
        public string $path,
        public string $method,
        public Operation $operation,
        public string $routeSignature,
        public array $diagnostics = [],
        public array $componentSchemas = [],
        public array $componentSchemaIds = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'method' => $this->method,
            'operation' => $this->operation->toArray(),
            'routeSignature' => $this->routeSignature,
            'diagnostics' => array_map(static fn (Diagnostic $d): array => $d->toArray(), $this->diagnostics),
            'componentSchemas' => $this->componentSchemas,
            'componentSchemaIds' => $this->componentSchemaIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $operation */
        $operation = is_array($data['operation'] ?? null) ? $data['operation'] : [];

        return new self(
            path: is_string($data['path'] ?? null) ? $data['path'] : '',
            method: is_string($data['method'] ?? null) ? $data['method'] : 'get',
            operation: Operation::fromArray($operation),
            routeSignature: is_string($data['routeSignature'] ?? null) ? $data['routeSignature'] : '',
            diagnostics: self::diagnostics($data['diagnostics'] ?? null),
            componentSchemas: self::schemas($data['componentSchemas'] ?? null),
            componentSchemaIds: self::stringMap($data['componentSchemaIds'] ?? null),
        );
    }

    /**
     * @return list<Diagnostic>
     */
    private static function diagnostics(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[] = Diagnostic::fromArray($entry);
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function schemas(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $name => $schema) {
            if (is_string($name) && is_array($schema)) {
                /** @var array<string, mixed> $schema */
                $out[$name] = $schema;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $name => $id) {
            if (is_string($name) && is_string($id)) {
                $out[$name] = $id;
            }
        }

        return $out;
    }
}
