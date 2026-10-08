<?php

declare(strict_types=1);

namespace App\Problems;

/** A notice that runs the inherited constructor and has nothing to describe. */
final class SilentNotice extends NoticeProblem
{
    protected function describe(): void {}
}
