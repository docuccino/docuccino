<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * The class-level opt-out from replacement: with `#[MergeValidationRules]` spatie's resolver APPENDS the
 * `rules()` entries to what it inferred for the property instead of overwriting them, so the attribute
 * rules keep applying alongside the override. Only ever reflected.
 */
#[MergeValidationRules]
final class MergedRulesData extends Data
{
    public function __construct(
        /** Human-readable display name. */
        #[Max(255)]
        public readonly string $name,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['min:3'],
        ];
    }
}
