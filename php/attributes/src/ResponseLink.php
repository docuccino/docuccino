<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Declares a link from this operation's response to another operation — an OpenAPI response link: the
 * operation a client can call next, and what to pass it from this exchange. Repeatable; usable on actions
 * and closure routes.
 *
 * Links the code proves — an id a response gives back taken by the routes that bind its model — are
 * written without one. This is for the rest: a body no model shapes, a value a route takes under a name
 * of its own.
 *
 * `operation` names the target the way the document does: its operationId, or `GET /api/things/{thing}`.
 * `parameters` maps each of the target's parameters to a runtime expression (`$response.body#/data/id`,
 * `$request.path.order`) or a literal; a parameter may be qualified by its location (`path.id`). A target,
 * status, parameter or response member the document does not have is reported rather than linked.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class ResponseLink
{
    /**
     * @param  string  $operation  the operation the link leads to: an operationId, or `METHOD /path`
     * @param  array<string, mixed>  $parameters  the target's parameter name => a runtime expression or a literal
     * @param  string  $name  the link's name in the response's `links`, or empty for the target's operationId
     * @param  int|null  $status  the response it sits on, or null for the operation's first success response
     * @param  string  $description  what following the link does, for the consumer
     */
    public function __construct(
        public string $operation,
        public array $parameters = [],
        public string $name = '',
        public ?int $status = null,
        public string $description = '',
    ) {}
}
