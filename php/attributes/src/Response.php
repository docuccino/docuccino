<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Declares a response for an operation. Repeatable so one action can document several statuses;
 * usable on controllers, actions and closure routes.
 *
 * Which attribute names what: `#[ErrorComponent]` names an error where the error is defined,
 * `errorComponent:` here names one status of one operation, `#[SchemaName]` names a class's schema, and
 * `type:` points at the class. Nothing below 400 shares an error body, so a name there names nothing.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Response
{
    /**
     * What a body with no `mediaType:` is published under. The parameter itself defaults to null so a
     * reader can tell a media type the author NAMED from one nobody mentioned — different answers to
     * whoever asks whether a vaguer body a producer recovered has been superseded.
     */
    public const string DEFAULT_MEDIA_TYPE = 'application/json';

    public function __construct(
        public int $status = 200,
        public ?string $type = null,
        public ?string $description = null,
        public ?string $mediaType = null,
        public ?string $errorComponent = null,
    ) {}
}
