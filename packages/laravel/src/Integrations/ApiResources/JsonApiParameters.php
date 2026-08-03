<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Patch\Contribution;

/**
 * The shared applier for the two JSON:API query parameters both JSON:API resource families resolve —
 * `include` (compound documents) and `fields[TYPE]` (sparse fieldsets). Laravel's first-party
 * `JsonApiRequest` and `timacdonald/json-api`'s `Support\Includes`/`Support\Fields` read the same
 * parameter shapes, so each integration's parameters extension supplies its own family predicate +
 * provenance contribution and defers the actual parameter writes here.
 */
final class JsonApiParameters
{
    public static function apply(OperationDraft $operation, Contribution $contribution): void
    {
        $include = $operation->parameter('query', 'include');
        $include->setDescription('Comma-separated list of relationships to include as compound-document data.', $contribution);
        $include->setRequired(false, $contribution);
        $include->schema()->set('type', 'string', $contribution);

        // Sparse fieldsets: fields[TYPE]=a,b — a deepObject of comma-separated strings keyed by type.
        $fields = $operation->parameter('query', 'fields');
        $fields->setDescription('Sparse fieldsets per resource type (fields[TYPE]=field1,field2).', $contribution);
        $fields->setRequired(false, $contribution);
        $fields->set('style', 'deepObject', $contribution);
        $fields->set('explode', true, $contribution);
        $fields->schema()->set('type', 'object', $contribution);
        $fields->schema()->set('additionalProperties', ['type' => 'string'], $contribution);
    }
}
