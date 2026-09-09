<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ManifestRejectedException;

/**
 * The same guard with its exception DECLARED, which is how most applications write one. The declaration
 * reaches the action as a concrete type, so the action's throw point carries no construction at all.
 */
final class ManifestDeclaredQuery
{
    /**
     * @return list<string>
     *
     * @throws ManifestRejectedException
     */
    public function results(bool $missing): array
    {
        if ($missing) {
            throw ManifestRejectedException::notCustom();
        }

        return ['manifest-2'];
    }
}
