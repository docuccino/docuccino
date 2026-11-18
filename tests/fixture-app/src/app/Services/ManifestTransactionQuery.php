<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ManifestRejectedException;
use Illuminate\Support\Facades\DB;

/**
 * A declared guard whose `throw` is written inside the transaction it runs in — the shape a guard takes
 * where the check and the write have to agree under a lock.
 */
final class ManifestTransactionQuery
{
    /**
     * @return list<string>
     *
     * @throws ManifestRejectedException
     */
    public function commit(bool $missing): array
    {
        return DB::transaction(function () use ($missing): array {
            if ($missing) {
                throw ManifestRejectedException::notFound();
            }

            return ['manifest-4'];
        });
    }
}
