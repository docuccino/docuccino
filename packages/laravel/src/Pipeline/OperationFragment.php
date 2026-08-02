<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Pipeline;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Document\Operation;

/**
 * The result of processing one route (design §5): the frozen operation, the OAS path template it
 * belongs under, its HTTP method, and any diagnostics raised while building it. Referenced
 * components are hoisted into the document-wide registry rather than carried here (the
 * per-fragment component cache is Phase 3b).
 */
final readonly class OperationFragment
{
    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    public function __construct(
        public string $path,
        public string $method,
        public Operation $operation,
        public string $routeSignature,
        public array $diagnostics = [],
    ) {}
}
