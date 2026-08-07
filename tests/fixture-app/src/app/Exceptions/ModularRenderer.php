<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Modules\Billing\ModularProblemResponse;

/**
 * Real-engine fixture: an error renderer that lives in `app/` (descend + prime scope) but builds its
 * response through a helper in a modular `Modules\…` root (prime scope only —
 * {@see ModularProblemResponse}). The refiner must follow the indirection across the module boundary and
 * recover the 451 problem shape, proving the containment gate follows any PRIMED root, not just descend
 * scope. (Descend-scoped, the refiner would decline the module and leave the shape bare.)
 */
final class ModularRenderer
{
    public function render(): JsonResponse
    {
        return ModularProblemResponse::make('https://errors.test/problems/modular', 451);
    }
}
