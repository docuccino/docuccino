<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Workflows;

use Docuccino\Core\Extensions\Context\RouteContext;
use JsonException;

/**
 * The workflow steps one route declared, on the {@see RouteContext::notes()} channel the assembly
 * drains.
 *
 * A notes channel because the two halves are separate passes, and — the load-bearing half — because a
 * note rides the OPERATION FRAGMENT. A step recorded straight into the assembly would be lost on a warm
 * cache hit, and a workflow that quietly loses a step on the second build is worse than one that never
 * assembled: the document would publish a shorter sequence and say nothing about it.
 *
 * The value is the step as JSON. A note carries strings, and a step is structured — the alternative,
 * one channel per member, would put the members of one step back together by position across several
 * lists, which is the shape that goes wrong the first time a member is absent.
 *
 * @internal
 */
final class DeclaredSteps
{
    public const string CHANNEL = 'workflow.step';

    /**
     * @param  array<string, mixed>  $step
     */
    public static function record(RouteContext $context, string $workflow, array $step): void
    {
        try {
            $context->notes()->record(self::CHANNEL, $workflow, json_encode($step, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            // A declaration PHP built but JSON cannot carry — a resource or a float that is not a
            // number. Dropped rather than recorded half-encoded; the assembly reports the workflow it
            // leaves short, which is the report the author can act on.
        }
    }

    /**
     * The steps `$values` carry, decoded. Anything that does not decode to a map is dropped: the only
     * writer is {@see record()}, so a value that is not one came from a cache somebody edited.
     *
     * @param  list<string>  $values
     * @return list<array<string, mixed>>
     */
    public static function decode(array $values): array
    {
        $steps = [];

        foreach ($values as $value) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $steps[] = $decoded;
            }
        }

        return $steps;
    }
}
