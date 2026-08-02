<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Context;

use Docuccino\Core\Extensions\Contracts\DocumentTransformer;

/**
 * The context handed to a {@see DocumentTransformer}: the
 * document configuration and its resolved identity. Deliberately small in Phase 3a — grows as
 * whole-document extensions gain more to work with.
 */
final readonly class DocumentContext
{
    public function __construct(
        public DocumentConfig $config,
        public string $documentId,
    ) {}
}
