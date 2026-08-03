<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Integrations\Support\ComponentHoist;

/**
 * The shared JSON:API resource-object document builder. A JSON:API resource — whether Laravel 13's
 * first-party `JsonApiResource` or the pre-13 `timacdonald/json-api` base it was upstreamed from —
 * exposes the same member surface (`toId`/`toType`/`toAttributes`/`toRelationships`/`toLinks`/
 * `toMeta`), so both integrations feed this one builder rather than duplicating the document shape.
 * It emits `{data: {id, type, attributes?, relationships?, links?, meta?}}`, analysing each `to*`
 * method into an object schema ({@see ToArrayObject}) and hoisting the resource to a reusable
 * component (including the self-reference cycle-break) via {@see ComponentHoist}.
 *
 * Each schema mapper holds its OWN instance (the hoist carries per-mapper recursion state), so there
 * is no shared mutable state between the first-party and timacdonald mappers.
 */
final class JsonApiDocument
{
    /**
     * The JSON:API resource-object members and the resource method each is analysed from.
     *
     * @var array<string, string>
     */
    private const MEMBERS = [
        'attributes' => 'toAttributes',
        'relationships' => 'toRelationships',
        'links' => 'toLinks',
        'meta' => 'toMeta',
    ];

    public function __construct(
        private readonly ToArrayObject $toArray = new ToArrayObject,
        private readonly ComponentHoist $hoist = new ComponentHoist,
    ) {}

    public function build(ClassT $type, SchemaContext $context): SchemaResult
    {
        return $this->hoist->hoist($context, $type->fqcn, function () use ($type, $context): array {
            $data = [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                ],
                'required' => ['id', 'type'],
            ];

            foreach (self::MEMBERS as $member => $method) {
                $object = $this->toArray->analyze($type->fqcn, $method, $context);
                if ($object !== null && ($object['properties'] ?? []) !== []) {
                    $data['properties'][$member] = $object;
                }
            }

            return [
                'type' => 'object',
                'properties' => ['data' => $data],
                'required' => ['data'],
            ];
        });
    }
}
