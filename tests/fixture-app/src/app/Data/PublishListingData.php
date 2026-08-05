<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ListingStatus;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * A spatie Data class that OVERRIDES validation with a static `rules()` method — the idiomatic spatie
 * escape hatch (docs: validation/manual-rules). It mixes a pipe-string rule with a `Rule::enum(...)`
 * factory descriptor, exactly as spatie's own docs show, so the real-engine proof shows the shared
 * literal+descriptor analysis recovering the override off a static (not instance) `rules()` — and the
 * override then WINS per field over what the promoted-property types would infer. Only ever reflected.
 */
class PublishListingData extends Data
{
    public function __construct(
        public string $title,
        public string $status,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'title' => 'required|string|max:200',
            'status' => ['required', Rule::enum(ListingStatus::class)],
        ];
    }
}
