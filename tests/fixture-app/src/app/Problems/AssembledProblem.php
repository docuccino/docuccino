<?php

declare(strict_types=1);

namespace App\Problems;

/** A problem with no constructor, whose members whoever builds it assigns. */
final class AssembledProblem
{
    public string $title;

    public int $status;
}
