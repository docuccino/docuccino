<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

/**
 * Whether this process is one worker of a parallel test run.
 *
 * Paratest — which is what `pest --parallel` runs — splits the suite across worker processes and sets
 * `PARATEST` in each of them. That matters for exactly one thing: coverage. A worker sees the
 * operations ITS share of the suite exercised and no others, and no worker can know when the others
 * have finished, so a coverage verdict taken from inside one is a guess wearing a number.
 *
 * Merging per-worker files does not fix that — it fixes the data, not the timing — so the coverage
 * assertions refuse here rather than name operations the suite exercised perfectly well.
 */
final class ParallelRun
{
    /** Paratest's own marker, set in every worker it spawns. */
    private const string MARKER = 'PARATEST';

    public static function active(): bool
    {
        return getenv(self::MARKER) !== false;
    }

    /** Which worker this is, for a message that tells the reader what they are looking at. */
    public static function worker(): ?string
    {
        foreach (['UNIQUE_TEST_TOKEN', 'TEST_TOKEN'] as $variable) {
            $token = getenv($variable);

            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        return null;
    }
}
