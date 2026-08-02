<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;

/**
 * A union → nullable type-array when it is a single type plus null (`type: [string, null]`,
 * the JSON Schema 2020-12 idiom), otherwise `anyOf` of its members (with a `{type: null}`
 * branch appended when nullable).
 */
final class UnionTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof UnionT;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof UnionT) {
            return null;
        }

        $hasNull = false;
        $nonNull = [];
        foreach ($type->members as $member) {
            if ($member instanceof NullT) {
                $hasNull = true;

                continue;
            }
            $nonNull[] = $member;
        }

        if (count($nonNull) === 1) {
            $schema = $context->convert($nonNull[0]);

            if ($hasNull) {
                $schema = self::makeNullable($schema);
            }

            return new SchemaResult($schema);
        }

        $anyOf = array_map(static fn (DType $member): array => $context->convert($member), $nonNull);
        if ($hasNull) {
            $anyOf[] = ['type' => 'null'];
        }

        return new SchemaResult(['anyOf' => $anyOf]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function makeNullable(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            $schema['type'] = [$type, 'null'];

            return $schema;
        }

        // Not a simple typed schema (e.g. a $ref or anyOf) — express nullability as a branch.
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }
}
