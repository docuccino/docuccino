<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A plain PHP response DTO — no Data base class, no model, no resource — documenting its properties the
 * only way a plain class can: a docblock per property. The generic class mapper is the one that reads
 * it, and it is the one shape of the three named on the ledger where an `@example` is actually
 * reachable: an Eloquent model's columns are magic `@property` tags with no docblock of their own, and
 * an idiomatic resource publishes `toArray` keys rather than properties.
 *
 * `n/a` on an `int` is the literal with no reading at all — the degradation that must stay a
 * diagnostic rather than a published lie. Only ever reflected.
 */
final class RetentionPolicy
{
    public function __construct(
        /**
         * The plan this policy belongs to.
         *
         * @example enterprise
         */
        public readonly string $plan = 'enterprise',
        /**
         * How many days of history the plan retains.
         *
         * @example 90
         */
        public readonly int $days = 90,
        /**
         * Whether deletions are irreversible once the window closes.
         *
         * @example true
         */
        public readonly bool $irreversible = true,
        /**
         * The regions the policy is enforced in.
         *
         * @example ["eu-west-1", "us-east-1"]
         *
         * @var list<string>
         */
        public readonly array $regions = [],
        /**
         * The grace window, where the plan grants one.
         *
         * @example n/a
         */
        public readonly int $grace_days = 0,
    ) {}
}
