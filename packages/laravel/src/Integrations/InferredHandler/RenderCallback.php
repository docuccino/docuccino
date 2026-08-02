<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

/**
 * A render callback discovered on the booted exception handler (`$exceptions->render(fn (T $e) => …)`):
 * the closure's source location (the engine analyses it by file+line) plus its first parameter — the
 * name to narrow and the exception type it handles (`Throwable`/`Exception` = a catch-all).
 */
final readonly class RenderCallback
{
    public function __construct(
        public string $file,
        public int $line,
        public string $parameterName,
        public string $exceptionType,
    ) {}
}
