<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A subclass that adds a factory of its own beside the base's. The class is genuinely built two ways — 503
 * from the base, 413 from here — so nothing but the throw site can say which one a response is.
 */
final class ExportOfflineException extends ProbeProblemBase
{
    public static function tooLarge(): self
    {
        return new self(413, 'The export is too large.');
    }
}
