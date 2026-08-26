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
 * DTO writes for a fixed-length pair. `theme` is the keyed-map case: the override restates `array` and
 * bounds each value through `theme.*`, neither of which can say the keys are strings. Only ever reflected.
 */
final class UpdateNodeData extends Data
{
    /**
     * @param  array<string, mixed>|Optional|null  $metadata
     * @param  array{float, float}|Optional  $position
     * @param  array<string, array<string, mixed>>|Optional  $theme
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

        /** Per-section rendering overrides, keyed by section id then by setting name. */
        public readonly Optional|array $theme = new Optional,
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
            // Restates the container in the one word Laravel has for it, and bounds each VALUE — which
            // says nothing about whether the keys are numeric.
            'theme' => ['sometimes', 'array', 'max:20'],
            'theme.*' => ['array', 'max:50'],
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
