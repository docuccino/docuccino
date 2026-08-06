<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Support\Facades\Validator;

/**
 * Inline GET-parameter validation via `Validator::make()` INSIDE a Queries class — the Eos read-
 * endpoint pattern (validation rules live in the query object, not the controller). Reached one hop
 * from the controller action, so the engine's bounded descent must recover the literal rule array
 * here. Deliberately NOT in any orchestrated-pool action path, so it never perturbs the worker-
 * determinism fixture. Only ever analysed — never executed.
 */
final readonly class ListingFilterValidation
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        return Validator::make($input, [
            'status' => 'required|string',
            'per_page' => 'nullable|integer',
        ])->validated();
    }
}
