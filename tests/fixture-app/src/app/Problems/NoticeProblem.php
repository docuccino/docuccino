<?php

declare(strict_types=1);

namespace App\Problems;

/** A problem whose constructor asks an overridable helper for its detail. */
class NoticeProblem
{
    public string $title;

    public string $detail;

    public function __construct(string $title)
    {
        $this->title = $title;
        $this->describe();
    }

    protected function describe(): void
    {
        $this->detail = 'See the documentation for this error.';
    }
}
