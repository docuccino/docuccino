<?php

declare(strict_types=1);

namespace App\Problems;

/** A problem other problems extend, assigning its own members in its own constructor. */
class BaseProblem
{
    public string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }
}
