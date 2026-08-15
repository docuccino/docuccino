<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\MediaCollections;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * A request DTO whose static `rules()` allow-lists a property against a list only the runtime knows:
 * `Rule::in(MediaCollections::validNames())`. The property still carries its native `string` type and a
 * `#[StringType]` attribute, so what property inference alone would document is not in question. Only
 * ever reflected.
 */
final class UploadPolicyData extends Data
{
    public function __construct(
        /**
         * Media collection the upload belongs to.
         *
         * @example avatars
         */
        #[StringType]
        public readonly string $collection = 'default',
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'collection' => [Rule::in(MediaCollections::validNames())],
        ];
    }
}
