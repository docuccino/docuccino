<?php

declare(strict_types=1);

namespace App\Problems;

/** A problem handing its title to a parent constructor that fills members by name. */
final class LockedProblem extends AttributedProblem
{
    public function __construct(string $title)
    {
        parent::__construct(['title' => $title]);
    }
}
