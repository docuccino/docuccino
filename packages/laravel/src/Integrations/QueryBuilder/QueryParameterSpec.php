<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Patch\Contribution;

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

    /**
     * Write this spec onto a query {@see ParameterDraft}: optional, plus description/style/explode
     * when present and every schema keyword. One applier so no consumer re-implements it (and drops
     * style/explode). Attributed to $contribution.
     */
    public function applyTo(ParameterDraft $parameter, Contribution $contribution): void
    {
        $parameter->setRequired(false, $contribution);

        if ($this->description !== null) {
            $parameter->setDescription($this->description, $contribution);
        }
        if ($this->style !== null) {
            $parameter->set('style', $this->style, $contribution);
        }
        if ($this->explode !== null) {
            $parameter->set('explode', $this->explode, $contribution);
        }

        foreach ($this->schema as $keyword => $value) {
            $parameter->schema()->set((string) $keyword, $value, $contribution);
        }
    }
}
