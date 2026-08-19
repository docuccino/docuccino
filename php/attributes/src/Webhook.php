<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Publishes the class it is on as a webhook: an operation the API promises to CALL, keyed by
 * `$name` under the document's `webhooks`. The annotated class IS the payload unless `$payload`
 * names another type, so an event or a payload DTO documents itself.
 *
 * `#[Group]`, `#[Response]`, `#[DeprecatedOperation]`, `#[InDocs]` and `#[ExcludeFromDocs]` read on a
 * webhook class exactly as they do on a controller.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Webhook
{
    /**
     * @param  string  $name  the key the webhook is published under
     * @param  string  $method  the HTTP method the receiving endpoint must implement
     * @param  string|null  $payload  a type string for the delivered body; the annotated class when null
     * @param  string  $mediaType  the media type the body is delivered as
     */
    public function __construct(
        public string $name,
        public string $method = 'post',
        public ?string $payload = null,
        public string $mediaType = 'application/json',
    ) {}
}
