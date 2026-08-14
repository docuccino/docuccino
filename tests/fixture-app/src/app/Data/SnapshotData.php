<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * Frozen-at-submit application record — the response shape a real app writes when every label, key and
 * value is materialised inline. Its array members are typed the way a real Data class types them: the
 * generic lives in the PROMOTED PARAMETER's own `@var` tag, right above the property it describes,
 * because that is where the prose describing the member already sits.
 *
 * `context` is the contrasting form — its generic is written once in the constructor's `@param` block,
 * with no `@var` of its own. Only ever reflected, never dispatched.
 */
final class SnapshotData extends Data
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        /**
         * Snapshot schema version. Bumped if the shape changes; renderers branch on this.
         *
         * @example 1
         */
        public readonly int $snapshot_schema_version,

        /** Inline request context as it stood at submit. */
        public readonly array $context,

        /**
         * Inline candidate profile state as it stood at submit: identity, contact details and whatever
         * else the tenant's profile schema carried.
         *
         * @var array<string, mixed>
         */
        public readonly array $candidate,

        /**
         * Theme colour and typography values, keyed by mode then by token.
         *
         * @var array<string, array<string, string|null>>
         */
        public readonly array $theme_data,

        /**
         * One entry per form zone in the pinned blueprint version's candidate-application tab.
         *
         * @var list<SnapshotFormData>
         */
        public readonly array $forms,

        /**
         * Flat list of permission strings the candidate held at submit.
         *
         * @var array<int, string>
         *
         * @example ["listing.view", "listing.create"]
         */
        public readonly array $permissions,

        /**
         * Attachments carried alongside the snapshot, documented with the analyser-prefixed tag some
         * teams standardise on.
         *
         * @phpstan-var list<SnapshotFormData>
         */
        public readonly array $attachments,
    ) {}
}
