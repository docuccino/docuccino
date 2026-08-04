<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * One recovered Query-Builder allow-list entry. Its public `name` is the value a client sends —
 * `filter[status]`, `sort=name`, `include=author`; the `kind` is the bare string form or the factory
 * method (`exact`, `partial`, `scope`, `callback`, `custom`, `operator`, `trashed`, `belongsTo`,
 * `field`, `relationship`, `count`, `exists`, `aggregate`). The remaining members carry the richer
 * facts the trace recovers when present (design §Representation policies — facts stay stable in the
 * UIR regardless of how a policy later expresses them):
 *
 *   - `internal`: the second factory argument (`AllowedFilter::exact('status', 'status_code')`) — the
 *     underlying column the cast lookup keys on. The public `name` stays what is documented.
 *   - `hasDefault`/`default`: a constant-folded `->default(…)` modifier → the parameter's
 *     `schema.default`.
 *   - `nullable`: a `->nullable()` modifier → a description note (never an added enum case).
 *   - `comment`: the first sentence(s) of a line or block comment directly above the array entry —
 *     an integration-layer description overriding the generic kind description.
 *   - `columnSchema`/`enumTyped`: the base column schema the extension enriches an exact filter with
 *     from the subject model's cast (an enum's backing values + `x-enumDescriptions`, or a native
 *     cast type). `enumTyped` drives the comma/whereIn array modelling.
 */
final readonly class QbEntry
{
    /**
     * @param  array<string, mixed>|null  $columnSchema
     */
    public function __construct(
        public string $name,
        public string $kind,
        public ?string $internal = null,
        public bool $hasDefault = false,
        public string|int|float|bool|null $default = null,
        public bool $nullable = false,
        public ?string $comment = null,
        public ?array $columnSchema = null,
        public bool $enumTyped = false,
    ) {}

    /** The underlying column a cast lookup keys on: the recovered internal name, else the public name. */
    public function column(): string
    {
        return $this->internal ?? $this->name;
    }

    /**
     * A copy carrying the recovered column schema (an enum's values / a native cast type) — the
     * extension's enrichment step, keeping the pure trace-recovered entry immutable.
     *
     * @param  array<string, mixed>|null  $columnSchema
     */
    public function withColumn(?array $columnSchema, bool $enumTyped): self
    {
        return new self(
            $this->name,
            $this->kind,
            $this->internal,
            $this->hasDefault,
            $this->default,
            $this->nullable,
            $this->comment,
            $columnSchema,
            $enumTyped,
        );
    }
}
