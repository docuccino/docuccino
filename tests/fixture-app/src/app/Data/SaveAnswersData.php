<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * Request DTO for an auto-save tick: a keyed answer map plus a list of the fields the tick touched. Its
 * generics are written in the constructor `@param` block, so the property types really are recovered —
 * this is the request-side shape, where the recovered map/list then has to survive the validation-rule
 * vocabulary on its way to a schema. Only ever reflected.
 */
final class SaveAnswersData extends Data
{
    /**
     * @param  array<string, mixed>|null  $answers
     * @param  list<string>  $touched_fields
     */
    public function __construct(
        /**
         * Zone key from the application's pinned blueprint version.
         *
         * @example candidate-profile
         */
        #[Required, StringType]
        public readonly string $zone_key,

        /**
         * Answers keyed by form-field id. Deep-merge semantics on save, so a missing payload is a valid
         * no-op autosave.
         */
        #[Nullable, ArrayType]
        public readonly ?array $answers = null,

        /** Form-field ids this tick touched, in the order the candidate touched them. */
        public readonly array $touched_fields = [],
    ) {}
}
