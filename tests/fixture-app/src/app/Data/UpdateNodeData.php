<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * A partial-update DTO whose static `rules()` names a field the class has NO property for: `label` is
 * immutable after creation, so the override rejects it outright with `prohibited` and a matching
 * message. It also constrains one key INSIDE the metadata blob with a dotted rule, the way Laravel
 * validates nested payloads, and carries its canvas coordinates as a POSITIONAL tuple — the shape a real
 * DTO writes for a fixed-length pair. Only ever reflected.
 */
final class UpdateNodeData extends Data
{
    /**
     * @param  array<string, mixed>|Optional|null  $metadata
     * @param  array{float, float}|Optional  $position
     */
    public function __construct(
        /**
         * Human-readable display name for the node.
         *
         * @example Engineering
         */
        #[StringType, Max(255)]
        public readonly Optional|string $name = new Optional,

        /** Arbitrary metadata stored as JSON. */
        #[Nullable]
        public readonly Optional|array|null $metadata = new Optional,

        /** Canvas coordinates, as an `[x, y]` pair. */
        public readonly Optional|array $position = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'metadata' => ['array'],
            'metadata.retention.mode' => ['required_with:metadata', 'string'],
            'label' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'label.prohibited' => 'The label cannot be changed on update. Use the move operation instead.',
        ];
    }
}
