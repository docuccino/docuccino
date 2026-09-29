<?php

declare(strict_types=1);

namespace App\Problems;

/** A problem only its named constructor builds, which assigns what the constructor leaves out. */
final class RateLimitProblem
{
    public string $title;

    public int $retryAfter;

    private function __construct(string $title)
    {
        $this->title = $title;
    }

    public static function retryAfter(int $seconds): self
    {
        $problem = new self('Too Many Requests');
        $problem->retryAfter = $seconds;

        return $problem;
    }
}
