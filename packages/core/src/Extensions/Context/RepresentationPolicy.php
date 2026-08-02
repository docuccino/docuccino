<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

/**
 * The per-document representation policy (design §Representation policies): it separates *what was
 * inferred* from *how it is expressed in the spec*. Each field is a keyword with a behaviour-
 * preserving default, so an absent config reproduces today's output byte-for-byte:
 *
 * - `operationId`: `route-name` (default) | `controller-method`.
 * - `enumNaming`: `none` (default) | `x-enumNames` | `x-enum-varnames` — codegen name hints
 *   emitted alongside the enum, never changing the `enum` member itself.
 * - `nullable`: `type-array` (default, `type: [x, null]`) | `anyof` (a `{type: null}` branch) —
 *   how a "single type plus null" union is expressed.
 *
 * Query filter styles remain Phase 4; the seam is deliberately open for them.
 */
final readonly class RepresentationPolicy
{
    public function __construct(
        public string $operationId = 'route-name',
        public string $enumNaming = 'none',
        public string $nullable = 'type-array',
    ) {}

    /**
     * @param  array<string, mixed>  $representation  the document's `representation` config
     */
    public static function fromConfig(array $representation): self
    {
        $enums = $representation['enums'] ?? null;
        $enumNaming = is_array($enums) ? ($enums['naming'] ?? null) : null;

        return new self(
            operationId: self::keyword($representation['operation_id'] ?? null, 'route-name'),
            enumNaming: self::keyword($enumNaming, 'none'),
            nullable: self::keyword($representation['nullable'] ?? null, 'type-array'),
        );
    }

    private static function keyword(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
