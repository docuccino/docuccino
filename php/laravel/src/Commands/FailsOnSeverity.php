<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Pipeline\GenerationResult;
use Illuminate\Console\Command;

/**
 * The `--fail-on` policy shared by the commands: a floor on {@see Severity}, where anything reported
 * at that severity or louder makes the run exit non-zero, and `none` never fails.
 *
 * The floor reaches `info` and `hint` as well as `warning` and `error`, because `info` is where the
 * build reports that it had to widen — an unrecoverable payload, a model with no readable columns, a
 * validation rule it could not read. Those are the reports a pipeline gating on inference certainty
 * wants, and no other value on this option reaches them.
 *
 * A value we don't recognise is rejected by {@see validateFailOn()} rather than coerced: coercing a
 * typo would answer "never fail", which silently removes the gate the flag was added to CI to be.
 *
 * @mixin Command
 */
trait FailsOnSeverity
{
    /** @var list<string> Loudest first, so the printed list reads as the ladder it is. */
    private const FAIL_ON_VALUES = ['none', 'error', 'warning', 'info', 'hint'];

    protected function failsOn(GenerationResult $result): bool
    {
        $floor = Severity::tryFrom($this->failOn());

        return $floor !== null && $result->hasAtLeast($floor);
    }

    /** False (after printing why) when `--fail-on` names something we don't know. */
    protected function validateFailOn(): bool
    {
        if (in_array($this->failOn(), self::FAIL_ON_VALUES, true)) {
            return true;
        }

        $this->error(sprintf(
            'Unknown --fail-on "%s"; expected one of: %s.',
            $this->failOn(),
            implode(', ', self::FAIL_ON_VALUES),
        ));

        return false;
    }

    /** The flag as given; `--fail-on` with no value at all is the same as not passing it. */
    private function failOn(): string
    {
        $value = $this->option('fail-on');

        return is_string($value) ? $value : 'none';
    }
}
