<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Declares that this operation is a step of a named workflow — a sequence of calls a consumer follows
 * to get something done, published as an Arazzo description beside the API document.
 *
 * The workflow needs declaring nowhere else. Writing this on the operations that take part is the whole
 * of it, and the sequence is assembled from what they say; `documents.*.workflows` in `docuccino.yaml`
 * only ENRICHES one, the way `tags.definitions` enriches a tag that `#[Group]` created. A correct
 * document with no configuration is the point.
 *
 * `order` is stated rather than taken from the order routes happen to be registered in, because a
 * sequence derived from registration order changes when an unrelated route is added — and a workflow
 * whose steps reorder themselves is worse than one that will not build.
 *
 * `parameters`, `body` and `outputs` carry Arazzo's own runtime expressions — `$inputs.x`,
 * `$steps.<id>.outputs.<name>`, `$response.body#/pointer` — so a later step can use what an earlier one
 * returned. A parameter is named the way the operation declares it and its location is read from there;
 * naming one the operation does not declare is a diagnostic rather than a guess.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class WorkflowStep
{
    /**
     * @param  string  $workflow  the workflow this operation is a step of
     * @param  int  $order  where the step runs in the sequence, low to high
     * @param  string  $id  what later steps call this one, or empty to mint one from the operation
     * @param  string  $description  what this step does, for the consumer following the sequence
     * @param  array<string, mixed>  $parameters  parameter name => a literal or a runtime expression
     * @param  array<string, mixed>  $body  the request payload, members literal or runtime expressions
     * @param  string  $contentType  the media type the body is sent as
     * @param  array<string, string>  $outputs  name => the runtime expression that reads it out
     */
    public function __construct(
        public string $workflow,
        public int $order,
        public string $id = '',
        public string $description = '',
        public array $parameters = [],
        public array $body = [],
        public string $contentType = 'application/json',
        public array $outputs = [],
    ) {}
}
