<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * One query parameter the Query-Builder integration contributes, already resolved through the
 * representation policy (a flat `filter[status]` vs a `filter` deep-object, a comma string vs an
 * exploded array). A plain, assertable value the {@see QueryBuilderParameters} builder returns and
 * the extension writes onto the operation draft — always `in: query`, always optional.
 */
final readonly class QueryParameterSpec
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public string $name,
        public array $schema,
        public ?string $description = null,
        public ?string $style = null,
        public ?bool $explode = null,
    ) {}
}
