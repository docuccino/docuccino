<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ManifestRejectedException;

/**
 * A declared guard that keeps the check in a private method of its own, which the method it documents
 * calls first.
 */
final class ManifestGuardedQuery
{
    /**
     * @return list<string>
     *
     * @throws ManifestRejectedException
     */
    public function results(bool $missing): array
    {
        $this->guard($missing);

        return ['manifest-5'];
    }

    private function guard(bool $missing): void
    {
        if ($missing) {
            throw ManifestRejectedException::notCustom();
        }
    }
}
