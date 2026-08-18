<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use RuntimeException;

/**
 * The response recorder was asked to record a run it cannot record. Both cases are wiring, and both
 * messages name the line to change.
 */
final class UnrecordableRun extends RuntimeException
{
    public static function parallel(?string $worker): self
    {
        return new self(sprintf(
            "Response recordings cannot be written from inside a parallel test run%s.\n".
            "Each worker sees its own share of the suite, so which response gets published would be\n".
            "decided by which worker happened to finish last rather than by the responses themselves.\n".
            'Record in a single-process job (drop --parallel); every other contract assertion is unaffected.',
            $worker === null ? '' : ' (worker '.$worker.')',
        ));
    }

    public static function unconfigured(string $document): self
    {
        return new self(sprintf(
            "There is nowhere to write response recordings for the \"%s\" document.\n".
            "Say where they live in config/docuccino.php:\n".
            "    'examples' => ['recordings' => 'docs/recordings'],\n".
            "Or name a directory at the call site: ApiContract::record('docs/recordings').",
            $document,
        ));
    }
}
