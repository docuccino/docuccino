<?php

declare(strict_types=1);

namespace App\Data;

use App\Rules\MaxJsonByteSize;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

/**
 * A DTO whose static `rules()` restates `array` over properties whose generics the constructor `@param`
 * block already recovered — the commonest shape of override there is, since `array` is the only word
 * Laravel has for an array and an author writing it means "and nothing more". `config` restates it with
 * a presence rule, `metadata` alongside a size-only custom rule, and `touched_fields` over a list, whose
 * item field rides on a key of its own. Only ever reflected.
 */
final class ActionPreviewData extends Data
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $metadata
     * @param  list<string>  $touched_fields
     */
    public function __construct(
        /** The node's current config, as the editor last rendered it. */
        public readonly array $config = [],

        /** Arbitrary metadata stored as JSON. */
        #[Nullable]
        public readonly ?array $metadata = null,

        /** Form-field ids this tick touched, in the order the candidate touched them. */
        public readonly array $touched_fields = [],
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            // `sometimes` (not `nullable`): an explicit `config: null` must fail validation rather than
            // hydrate onto the non-nullable property.
            'config' => ['sometimes', 'array'],
            'metadata' => ['array', new MaxJsonByteSize],
            'touched_fields' => ['sometimes', 'array'],
        ];
    }
}
