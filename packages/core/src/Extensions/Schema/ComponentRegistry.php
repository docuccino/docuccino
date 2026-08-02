<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\Json;

/**
 * Accumulates the reusable schema/response components hoisted during a build, deduping
 * structurally-equal registrations and giving genuine name collisions a deterministic numeric
 * suffix plus a warning diagnostic (design §5 hoist/dedupe). The `schemaId` hint (an FQCN) is
 * remembered per component so the assembler can pin its diff identity via
 * {@see IdentityGenerator::namedSchemaId()}.
 */
final class ComponentRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $schemas = [];

    /**
     * @var array<string, string>
     */
    private array $schemaIds = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $responses = [];

    /**
     * @var list<Diagnostic>
     */
    private array $diagnostics = [];

    /**
     * Register a named schema and return the `{"$ref": …}` array pointing at its final name.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function reference(string $name, array $schema, ?string $schemaId = null): array
    {
        return ['$ref' => '#/components/schemas/'.$this->registerSchema($name, $schema, $schemaId)];
    }

    /**
     * Register a named schema, returning the final component name (suffixed on genuine
     * collision). A structurally-identical re-registration under the same name is deduped.
     *
     * @param  array<string, mixed>  $schema
     */
    public function registerSchema(string $name, array $schema, ?string $schemaId = null): string
    {
        // A component with this exact identity (e.g. the same class FQCN) already exists —
        // reuse it so one class hoists to one component regardless of how often it is referenced.
        if ($schemaId !== null) {
            $existing = array_search($schemaId, $this->schemaIds, true);
            if ($existing !== false) {
                return (string) $existing;
            }
        }

        $name = self::sanitize($name);

        if (! isset($this->schemas[$name])) {
            $this->schemas[$name] = $schema;
            if ($schemaId !== null) {
                $this->schemaIds[$name] = $schemaId;
            }

            return $name;
        }

        if (self::structurallyEqual($this->schemas[$name], $schema)) {
            return $name;
        }

        $suffixed = $name;
        $n = 1;
        while (isset($this->schemas[$suffixed]) && ! self::structurallyEqual($this->schemas[$suffixed], $schema)) {
            $n++;
            $suffixed = $name.'_'.$n;
        }

        if (! isset($this->schemas[$suffixed])) {
            $this->schemas[$suffixed] = $schema;
            if ($schemaId !== null) {
                $this->schemaIds[$suffixed] = $schemaId;
            }
            $this->diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'components.name-collision',
                message: sprintf('Two distinct schemas claimed component name "%s"; the second was hoisted as "%s".', $name, $suffixed),
                help: 'Disambiguate with #[SchemaName] on one of the source classes.',
            );
        }

        return $suffixed;
    }

    /**
     * A restorable snapshot of the whole registry, so a route that fails mid-pipeline after
     * registering components can be rolled back — leaving no orphaned schemas/responses/diagnostics
     * from a route that never made it into the document (design §5 isolated try/catch).
     *
     * @return array{schemas: array<string, array<string, mixed>>, schemaIds: array<string, string>, responses: array<string, array<string, mixed>>, diagnostics: list<Diagnostic>}
     */
    public function snapshot(): array
    {
        return [
            'schemas' => $this->schemas,
            'schemaIds' => $this->schemaIds,
            'responses' => $this->responses,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /**
     * @param  array{schemas: array<string, array<string, mixed>>, schemaIds: array<string, string>, responses: array<string, array<string, mixed>>, diagnostics: list<Diagnostic>}  $snapshot
     */
    public function restore(array $snapshot): void
    {
        $this->schemas = $snapshot['schemas'];
        $this->schemaIds = $snapshot['schemaIds'];
        $this->responses = $snapshot['responses'];
        $this->diagnostics = $snapshot['diagnostics'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schemas(): array
    {
        return $this->schemas;
    }

    /**
     * @return array<string, string>
     */
    public function schemaIds(): array
    {
        return $this->schemaIds;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function registerResponse(string $name, array $response): string
    {
        $name = self::sanitize($name);
        $this->responses[$name] ??= $response;

        return $name;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function responses(): array
    {
        return $this->responses;
    }

    /**
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Record a diagnostic raised while building components (e.g. a validation rule no transformer
     * handled). The assembler folds these into the document's diagnostic channel.
     */
    public function addDiagnostic(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    private static function sanitize(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.-]/', '', $name);
        $clean = is_string($clean) ? $clean : '';

        return $clean === '' ? 'Schema' : $clean;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function structurallyEqual(array $a, array $b): bool
    {
        return Json::stable($a) === Json::stable($b);
    }
}
