<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

use Docuccino\Core\Document\DocuccinoExtension;
use Docuccino\Core\Document\Parameter;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchGuard;
use Docuccino\Core\Patch\PatchResult;

/**
 * A mutable OAS parameter builder, keyed in its parent operation by `(in, name)`. `in` and
 * `name` are fixed at construction (they form the identity); every other field is guarded.
 */
final class ParameterDraft
{
    private readonly PatchGuard $guard;

    private readonly SchemaDraft $schema;

    private ?string $id = null;

    public function __construct(
        public readonly string $in,
        public readonly string $name,
    ) {
        $this->guard = new PatchGuard;
        $this->schema = new SchemaDraft;
    }

    public function setDescription(?string $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('description', $value, $by);
    }

    public function setRequired(?bool $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('required', $value, $by);
    }

    public function setDeprecated(?bool $value, Contribution $by): PatchResult
    {
        return $this->guard->apply('deprecated', $value, $by);
    }

    public function set(string $field, mixed $value, Contribution $by): PatchResult
    {
        return $this->guard->apply($field, $value, $by);
    }

    public function schema(): SchemaDraft
    {
        return $this->schema;
    }

    public function guard(): PatchGuard
    {
        return $this->guard;
    }

    public function withId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function key(): string
    {
        return $this->in.':'.$this->name;
    }

    public function freeze(): Parameter
    {
        $resolved = $this->guard->resolved();

        $description = self::stringOrNull($resolved['description'] ?? null);
        $required = self::boolOrNull($resolved['required'] ?? null);
        $deprecated = self::boolOrNull($resolved['deprecated'] ?? null);

        unset($resolved['description'], $resolved['required'], $resolved['deprecated']);

        $schema = $this->schema->freeze();
        $schemaOrNull = $schema->toArray() === [] ? null : $schema;

        $docuccino = new DocuccinoExtension(id: $this->id, provenance: $this->guard->provenance());

        return new Parameter(
            name: $this->name,
            in: $this->in,
            description: $description,
            required: $required,
            deprecated: $deprecated,
            schema: $schemaOrNull,
            docuccino: $docuccino->isEmpty() ? null : $docuccino,
            rest: $resolved,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
