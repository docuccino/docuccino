<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use DateTimeInterface;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use JsonSerializable;

/**
 * A date-time that states its own JSON form → the string it writes, superseding {@see ClassTypeToSchema}
 * by running earlier: reflecting one into an object publishes members no serializer ever sends, and the
 * richer the class's docblock the worse it gets — a `@property`-documented date-time hoists a component
 * of some two hundred calendar fields for a value that is one string.
 *
 * PHP forbids userland implementations of `DateTimeInterface`, so the domain is closed to its own
 * `DateTime`/`DateTimeImmutable` and their subclasses, and `JsonSerializable` divides it exactly: a
 * subclass stating a JSON form writes an RFC 3339 string, and one stating none is left alone here
 * because `json_encode` really does write it as an object (its `date`/`timezone_type`/`timezone` bag).
 * A producer that knows the wire format better still wins — it runs earlier and pins the shape itself.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class DateTimeTypeToSchema implements TypeToSchema
{
    /** The RFC 3339 string a date-time's own `jsonSerialize()` renders, read off the bytes in test. */
    public const SCHEMA = ['type' => 'string', 'format' => 'date-time'];

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && self::statesItsOwnJsonForm($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $this->supports($type)) {
            return null;
        }

        return new SchemaResult(self::SCHEMA, 0.9);
    }

    /** Whether the class is a date-time whose value serialises through a JSON form it declares. */
    private static function statesItsOwnJsonForm(string $fqcn): bool
    {
        return is_a($fqcn, DateTimeInterface::class, true) && is_a($fqcn, JsonSerializable::class, true);
    }
}
