<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A second undeclared hop, so the `throw` sits two calls from the action rather than one.
 */
final class ManifestRelayQuery
{
    public function __construct(private readonly ManifestReviewQuery $review) {}

    /**
     * @return list<string>
     */
    public function results(bool $missing): array
    {
        return $this->review->results($missing);
    }
}
