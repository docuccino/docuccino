<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Names the shared component an error is published under, so a client catches a `ResourceMissing`
 * rather than an `Error404`. Two anchors: on an EXCEPTION CLASS, naming the error that class stands
 * for; or on a RENDER METHOD, naming the body that method answers with — which is the only anchor that
 * separates several bodies rendered from one exception class. Both are inherited, and the nearest
 * declaration wins: the nearest class up the hierarchy, and the outermost method on the render path.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class ErrorComponent
{
    public function __construct(
        public string $name,
    ) {}
}
