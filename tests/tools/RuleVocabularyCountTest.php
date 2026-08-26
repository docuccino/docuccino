<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\Validation\Transformers\AdditionalPropertiesRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\AnnotationRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\Transformers\DateWireRuleTransformer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;

/*
 * The guards behind the two claims the shipped rule chain makes on the site: the "N named Laravel
 * rules" count on the comparison pages, and "the complete rule vocabulary" on the requests page. A
 * count nothing checks is a promise to remember — that one was six rules stale in all four places
 * before anyone noticed — and a reference nothing checks is the same promise written as a list. Both
 * read the shipped chain, so teaching Docuccino a rule fails here until the pages agree.
 */

/**
 * The transformers whose rule names are Docuccino's OWN rather than Laravel's: a recovered fact the rule
 * vocabulary has no word for, stated outright by the integration that recovered it — an `array<string, V>`
 * property's value schema, a date-typed property's wire format. Laravel would reject the name and nobody
 * ever types one, so there is nothing here for a count or a reference to tell a reader; the exclusion is
 * structural, not a name on a preference list.
 *
 * @return list<class-string>
 */
function synthesisedRuleTransformers(): array
{
    return [AdditionalPropertiesRuleTransformer::class, DateWireRuleTransformer::class];
}

/**
 * The rules a reader can actually write in a Laravel rules array — the synthesised names above plus the
 * `#[RuleSchema]` annotation trio are machinery, not the vocabulary the pages are counting.
 */
function namedLaravelRuleCount(): int
{
    $handled = [];
    $internal = [];

    foreach (ValidationIntegration::transformers() as $transformer) {
        $isInternal = $transformer instanceof AnnotationRuleTransformer
            || in_array($transformer::class, synthesisedRuleTransformers(), true);

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

/**
 * Every rule name a reader could write, mapped to the transformer that handles it — the synthesised
 * names left out for the reason {@see synthesisedRuleTransformers()} gives.
 *
 * @return array<string, string>
 */
function authorWritableRules(): array
{
    $rules = [];

    foreach (ValidationIntegration::transformers() as $transformer) {
        if (in_array($transformer::class, synthesisedRuleTransformers(), true)) {
            continue;
        }

        foreach ($transformer->handledRuleNames() as $name) {
            $rules[$name] = $transformer::class;
        }
    }

    ksort($rules);

    return $rules;
}

/**
 * The rule names the stretch of the requests page that claims to be the whole vocabulary spells out —
 * from the reference heading to the heading after the file rules, since the file rules are documented in
 * prose in the section next door.
 *
 * Fenced blocks come out first: an example that happens to use a rule is not a reference entry for it,
 * and a fence's own backticks desynchronise every pairing after it, so a scan that kept them would answer
 * about the wrong text entirely. What is left is read as rule SYNTAX — the page writes `min:n` and
 * `regex:/^INV-\d+$/`, so a span is cut at a colon. A colon followed by a SPACE is not rule syntax but a
 * schema fragment (`format: email`), and those are dropped rather than read as a mention.
 *
 * @return list<string>
 */
function ruleReferenceNames(): array
{
    $page = (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/documenting/requests.mdx',
    );

    $start = strpos($page, "\n## Validation rule reference\n");
    expect($start)->not->toBeFalse();

    $section = substr($page, (int) $start);
    $end = strpos($section, "\n## Headers and cookies\n");
    expect($end)->not->toBeFalse();

    $section = (string) preg_replace('/^```.*?^```/ms', '', substr($section, 0, (int) $end));

    preg_match_all('/`([^`\n]+)`/', $section, $matches);

    $names = [];

    foreach ($matches[1] as $span) {
        $at = strpos($span, ':');

        if ($at === false) {
            $names[$span] = true;

            continue;
        }

        if (($span[$at + 1] ?? ' ') !== ' ') {
            $names[substr($span, 0, $at)] = true;
        }
    }

    return array_keys($names);
}

it('names every rule the chain handles in the reference that claims to be complete', function (): void {
    // The page says "the complete rule vocabulary" and nothing read it — a RuleTransformer taught a new
    // name shipped undocumented with the suite green, which is the sentence quietly becoming false.
    $named = ruleReferenceNames();

    $undocumented = [];
    foreach (authorWritableRules() as $rule => $handledBy) {
        if (! in_array($rule, $named, true)) {
            $undocumented[] = $rule.' (handled by '.$handledBy.')';
        }
    }

    expect($undocumented)->toBe([], 'rules the chain handles with no entry in the reference: '.implode(', ', $undocumented))
        // Anti-vacuity on both sides: a section boundary that moved, or a chain that stopped answering,
        // would leave nothing to disagree about.
        ->and(count($named))->toBeGreaterThan(80)
        ->and(count(authorWritableRules()))->toBeGreaterThanOrEqual(83);
});
