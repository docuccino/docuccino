<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;
use ReflectionClass;

/**
 * Reads the Docuccino `#[SchemaName]` (component display name) and `#[SchemaId]` (diff identity)
 * attributes off a class — the pair every integration that hoists a class to a component honours, so
 * the reading stays identical whether the source is a Data class, an API Resource, or an Eloquent model.
 */
final class SchemaIdentity
{
    /** The `#[SchemaName]` display name, else null (caller defaults to the short class name). */
    public static function name(string $fqcn): ?string
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        foreach ((new ReflectionClass($fqcn))->getAttributes(SchemaName::class) as $attribute) {
            return $attribute->newInstance()->name;
        }

        return null;
    }

    /** The `#[SchemaId]` identity, else null (caller defaults to the FQCN). */
    public static function id(string $fqcn): ?string
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        foreach ((new ReflectionClass($fqcn))->getAttributes(SchemaId::class) as $attribute) {
            return $attribute->newInstance()->id;
        }

        return null;
    }
}
