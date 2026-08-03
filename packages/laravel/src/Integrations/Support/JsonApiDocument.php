<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Integrations\ApiResources\ToArrayObject;

/**
 * The shared JSON:API resource-object document builder. A JSON:API resource — whether Laravel 13's
 * first-party `JsonApiResource` or the pre-13 `timacdonald/json-api` base it was upstreamed from —
 * exposes the same member surface, so both integrations feed this one builder rather than
 * duplicating the document shape. It emits `{data: {id, type, attributes?, links?, meta?}}`, hoisting
 * the resource to a reusable component (including the self-reference cycle-break) via
 * {@see ComponentHoist}.
 *
 * `id` and `type` are emitted as `string` unconditionally (the JSON:API contract), not analysed from
 * `toId`/`toType`; `attributes`, `links` and `meta` ARE analysed from their `to*` methods into object
 * schemas ({@see ToArrayObject}).
 *
 * `relationships` is deliberately OMITTED. Both packages express relationships as closures
 * (`'author' => fn () => new AuthorResource(...)`), which the type engine reports as `CallableT` — a
 * flat `toArray`-style analysis of `toRelationships` cannot produce JSON:API's `{data: {type, id}}`
 * linkage object, so emitting it would document a shape the resource never yields. It is left out
 * until the linkage object can be modelled from real relationship resolution.
 *
 * Each schema mapper holds its OWN instance (the hoist carries per-mapper recursion state), so there
 * is no shared mutable state between the first-party and timacdonald mappers.
 */
final class JsonApiDocument
{
    /**
     * The JSON:API resource-object members analysed from their `to*` methods (relationships omitted;
     * see the class docblock).
     *
     * @var array<string, string>
     */
    private const MEMBERS = [
        'attributes' => 'toAttributes',
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
