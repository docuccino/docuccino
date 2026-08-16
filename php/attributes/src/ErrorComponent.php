<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Names the shared component the error this exception produces is published under, so a client catches
 * a `ResourceMissing` rather than an `Error404`. Inherited: a base exception carrying it names every
 * subclass that declares nothing of its own, and the nearest declaration wins.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ErrorComponent
{
    public function __construct(
        public string $name,
    ) {}
}
