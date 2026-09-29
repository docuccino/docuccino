<?php

declare(strict_types=1);

namespace App\Problems;

/** A problem whose constructor fills it through a trait's helper. */
final class FilledProblem
{
    use FillsAttributes;

    public string $title;

    public int $status;

    public function __construct(string $title, int $status)
    {
        $this->fill(['title' => $title, 'status' => $status]);
    }
}
