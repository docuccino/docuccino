<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Declares a response for an operation. Repeatable so one action can document several statuses;
 * usable on controllers, actions and closure routes.
 *
 * `component:` names the shared component an ERROR response publishes under, for the author who
 * declared the body and is the only one who can say what it is — `#[ErrorComponent]` names bodies that
 * arise from a throw, and never sees one an operation states itself. A response component covers all of
 * a status's content, so the name is the whole status's, and one status named twice differently is
 * reported rather than settled.
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
        public ?string $component = null,
    ) {}
}
