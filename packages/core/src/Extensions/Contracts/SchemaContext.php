<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\TypeEngine;

/**
 * The services a {@see TypeToSchema} mapper needs while converting a type (design §6):
 * chain recursion for nested types, component hoisting for named schemas, the engine for
 * lazy class expansion, and a provenance helper to lower the running confidence when a
 * conversion is imprecise.
 */
interface SchemaContext
{
    /**
     * Convert a nested type through the full mapper chain, returning its JSON Schema array.
     * Never returns null — an unresolvable type yields `{}`.
     *
     * @return array<string, mixed>
     */
    public function convert(DType $type): array;

    /**
     * Hoist a named component schema into `components.schemas` (deduping structurally-equal
     * registrations, suffixing genuine collisions) and return a `{"$ref": …}` array pointing
     * at it. `$schemaId` pins the component's diff identity when known (an FQCN).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function reference(string $name, array $schema, ?string $schemaId = null): array;

    /** The inference engine, for {@see TypeEngine::classMetadata()} class expansion. */
    public function engine(): TypeEngine;

    /** Record that the current conversion is imprecise; the lowest value seen wins. */
    public function lowerConfidence(float $confidence): void;
}
