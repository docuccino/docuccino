<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Validation\Transformers\AdditionalPropertiesRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\AnnotationRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;

/*
 * The guard behind the "N named Laravel rules" claim on the comparison pages. The same number is
 * quoted twice on each of them, and a count nothing checks is a promise to remember — this one was
 * six rules stale in all four places before anyone noticed. It reads the shipped chain instead, so
 * teaching Docuccino a rule fails here until the pages agree.
 */

/**
 * The rules a reader can actually write in a Laravel rules array. The chain also handles the
 * `#[RuleSchema]` annotation trio and Docuccino's own `additional_properties`, which Laravel would
 * reject — they are part of the machinery, not of the vocabulary the pages are counting.
 */
function namedLaravelRuleCount(): int
{
    $handled = [];
    $internal = [];

    foreach (ValidationIntegration::transformers() as $transformer) {
        $isInternal = $transformer instanceof AnnotationRuleTransformer
            || $transformer instanceof AdditionalPropertiesRuleTransformer;

        foreach ($transformer->handledRuleNames() as $name) {
            if ($isInternal) {
                $internal[$name] = true;
            } else {
                $handled[$name] = true;
            }
        }
    }

    return count(array_diff_key($handled, $internal));
}

/** @return list<int> */
function quotedRuleCounts(string $page): array
{
    preg_match_all('/\b(\d+)\s+named Laravel rules\b/', $page, $matches);

    return array_values(array_map(intval(...), $matches[1]));
}

it('quotes the vocabulary the package actually maps, on every page that quotes one', function (string $page): void {
    $quoted = quotedRuleCounts((string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/guides/'.$page,
    ));

    expect($quoted)->not->toBeEmpty()
        ->and(array_values(array_unique($quoted)))->toBe([namedLaravelRuleCount()]);
})->with(['vs-scramble.mdx', 'vs-scribe.mdx']);
