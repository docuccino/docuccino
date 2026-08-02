<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * One recovered Query-Builder allow-list entry: its public `name` (the value a client sends —
 * `filter[status]`, `sort=name`, `include=author`) and the `kind` that produced it (the bare
 * string form, or the factory method — `exact`, `partial`, `scope`, `callback`, `custom`,
 * `operator`, `trashed`, `belongsTo`, `field`, `relationship`, `count`, `exists`, `aggregate`).
 * The kind drives the human description; the name drives the parameter/enum.
 */
final readonly class QbEntry
{
    public function __construct(
        public string $name,
        public string $kind,
    ) {}
}
