<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ManifestRejectedException;

/**
 * The guard an action delegates to, with nothing declared about what it raises — so the throw reaches the
 * action as a bare `Throwable` and only descent can see the factory that names the status.
 */
final class ManifestReviewQuery
{
    /**
     * @return list<string>
     */
    public function results(bool $missing): array
    {
        if ($missing) {
            throw ManifestRejectedException::notFound();
        }

        return ['manifest-1'];
    }
}
