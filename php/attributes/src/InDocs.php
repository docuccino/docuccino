<?php

declare(strict_types=1);

namespace Docuccino\Attributes;

use Attribute;

/**
 * Pins a route to one or more named documents; usable on controllers, actions and closure routes.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class InDocs
{
    /**
     * @var list<string>
     */
    public array $documents;

    public function __construct(string ...$documents)
    {
        $this->documents = array_values($documents);
    }
}
