<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Declares a response for an operation. Repeatable so one action can document several statuses;
 * usable on controllers, actions and closure routes.
 *
 * `errorComponent:` names the shared component this status's ERROR body publishes under. It and
 * `#[ErrorComponent]` differ by what they are ABOUT, not by which bodies they can reach: that one names
 * an error wherever it is raised, so every operation answering with it publishes the same name, and this
 * one names one status of one operation, whatever produced the body — including one an exception the
 * action throws produced, where it wins as the declaration nearest the operation. A response component
 * covers all of a status's content, so the name is the whole status's, and the nearest declaration wins
 * where two spell it differently. Nothing below 400 shares an error body, so a name there names nothing.
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
