<?php

declare(strict_types=1);

/*
 * One reading turns a configured value into an on/off switch.
 *
 * Four sites used to read the same kind of key three different ways, and they disagreed about a value
 * that is neither `true` nor `false`. `is_bool($v) ? $v : $default` took the default;
 * `($v ?? $default) !== false` and `(bool) ($v ?? false)` both read `'no'` as ON; `$v === true` read it
 * as OFF. Three ONs and one OFF, and not a diagnostic anywhere. The cast is the worst of them:
 * `(bool) 'no'` is `true`, so it turns a switch on for the author who wrote it off — and `no`, `off`,
 * `yes` and `on` are all STRINGS to a YAML parser, which is the first spelling an author reaches for.
 *
 * So `Docuccino\Core\Support\ConfiguredFlag` is the single reading, and the two shapes a switch read is
 * written in — a boolean default coalesced off a lookup, and a `(bool)` cast — are scanned for. Every
 * one under a package's `src/` is either named below with what makes it something other than a
 * configured switch, or it is a defect.
 *
 * The third shape, `is_bool($v) ? $v : $default`, is deliberately NOT scanned. It answers exactly what
 * the one reading answers, so a site rolling it would publish nothing wrong — only silence, which the
 * refusal tests catch where the diagnostic is owed. Scanning it would add twenty allow-list entries
 * for schema and document readers that have no author at the other end, and a guard whose population
 * is four-fifths noise stops being read.
 */

/**
 * The switch-shaped reads that do NOT read a configured switch, each as `[how many, what makes that
 * true]`. A new entry is a claim about where the value came from, so it needs the same kind of
 * sentence — and the COUNT is part of the claim, because keying on the function rather than the line
 * is what stops the list rotting on drift, and a second read in an already-explained function would
 * otherwise hide behind the entry standing for the first.
 *
 * @return array<string, array{0: int, 1: string}>
 */
function allowedSwitchReads(): array
{
    return [
        // ---- UIR/OAS document members. A document is machine-written and schema-validated, so a
        // ---- member at a boolean position is a boolean or the document is invalid. There is no
        // ---- author at the other end to tell anything, and `false` is the spec's own default.
        'php/core/src/Contract/ContractChecker.php::requestBody' => [1, 'OAS request-body `required`'],
        'php/core/src/Contract/ContractIndex.php::parameters' => [1, 'OAS parameter `required`'],
        'php/core/src/Contract/ResponseHeaders.php::of' => [1, 'OAS response-header `required`'],
        'php/core/src/Emit/Postman/Description.php::request' => [1, 'OAS operation `deprecated`'],
        'php/core/src/Emit/Postman/Url.php::expand' => [1, 'OAS path-parameter `required`'],
        'php/core/src/Emit/Postman/Url.php::headers' => [2, 'OAS header- and cookie-parameter `required`'],
        'php/core/src/Emit/Postman/Url.php::raw' => [1, 'a Postman query row this emitter wrote, `disabled`'],

        // ---- Fragment/DType serialisation. Both ends are this package: what is read back is what
        // ---- `toArray()` wrote, so a non-boolean there is a bug in us and not a value to report.
        'php/core/src/Inference/DType/ArrayShapeField.php::fromArray' => [2, 'own serialised DType `optional`'],
        'php/core/src/Inference/DType/ArrayShapeT.php::fromArray' => [2, 'own serialised DType `isList`'],
        'php/core/src/Inference/DType/LiteralT.php::fromArray' => [1, 'a `bool` literal type restored to its value'],

        // ---- Internal lookups whose value this package put there itself.
        'php/core/src/Document/ChangedFieldExamples.php::flatten' => [1, 'own reachability set membership'],
        'php/core/src/Document/DocumentGraph.php::componentsReaching' => [1, 'own reachability set membership'],
        'php/core/src/Document/DocumentGraph.php::nodeReaches' => [1, 'own reachability set membership'],
        'php/core/src/Emit/Formats.php::checksEmittedArtifact' => [1, 'own format table column'],
        'php/core/src/Emit/Formats.php::serialisesYaml' => [1, 'own format table column'],
        'php/laravel/src/Versioning/ApiVersionTransformer.php::rewrite' => [1, 'own reachability set membership'],

        // ---- Not configuration: a property declared in application code and read through
        // ---- reflection. Eloquent types `$timestamps` as `bool` on its own base class, so anything
        // ---- else there is a type error the analyser reports, not a switch someone spelled wrong.
        'php/laravel/src/Integrations/Eloquent/EloquentModelReflector.php::facts' => [1, 'Eloquent `$timestamps` property default'],

        // ---- Another package's config file, read as its own grammar. These belong to the class and
        // ---- are NOT converted: an integration may import only the allow-listed public core surface
        // ---- (IntegrationsArchTest), and widening that list is a maintainer decision. Their reading
        // ---- — a `false` default tested with `=== true` — already answers what the one reading
        // ---- answers for every non-boolean, so nothing reads a switch backwards; what is missing is
        // ---- the diagnostic, and it stays missing until the allow-list question is settled.
        'php/laravel/src/Integrations/JsonApiPaginate/JsonApiPaginateConfig.php::mode' => [2, 'spatie/laravel-json-api-paginate `use_cursor_pagination`, `use_simple_pagination`'],
        'php/laravel/src/Integrations/QueryBuilder/QueryBuilderConfig.php::fromArray' => [3, 'spatie/laravel-query-builder `disable_invalid_*_query_exception`'],
    ];
}

/** @return list<string> */
function packageSourceSwitchReads(): array
{
    $found = [];

    foreach (['attributes', 'core', 'inference-phpstan', 'laravel'] as $package) {
        $found = [...$found, ...switchReadsIn(dirname(__DIR__, 2).'/php/'.$package.'/src')];
    }

    sort($found);

    return $found;
}

it('lets nothing but ConfiguredFlag read a configured value as a switch', function (): void {
    $unexplained = array_values(array_unique(
        array_diff(packageSourceSwitchReads(), array_keys(allowedSwitchReads())),
    ));

    expect($unexplained)->toBe([]);
});

/**
 * An allow-list entry whose read has moved or been fixed guards nothing, and the next reader takes it
 * for a statement about code that is still there.
 */
it('names no allowed switch read that is not there any more', function (): void {
    $stale = array_values(array_diff(array_keys(allowedSwitchReads()), packageSourceSwitchReads()));

    expect($stale)->toBe([]);
});

/**
 * Keying an explained read on its function rather than its line is what stops the list rotting on
 * drift — but it means a SECOND switch-shaped read in an already-explained function hides behind the
 * entry standing for the first. So the counts are held to each other too.
 */
it('accounts for every read, so a second in the same function cannot hide behind the first', function (): void {
    $found = array_count_values(packageSourceSwitchReads());

    $miscounted = [];
    foreach (allowedSwitchReads() as $site => [$count, $reason]) {
        if (($found[$site] ?? 0) !== $count) {
            $miscounted[] = sprintf('%s: %d explained, %d found', $site, $count, $found[$site] ?? 0);
        }
    }

    expect($miscounted)->toBe([])
        ->and(count(packageSourceSwitchReads()))
        ->toBe(array_sum(array_column(allowedSwitchReads(), 0)));
});

/**
 * A scan that matches nothing passes forever. These are the counts the assertions above are worth —
 * and the reading this exists to protect must never appear in its own results, because it uses neither
 * shape: `array_key_exists()` decides presence and `is_bool()` decides the answer.
 */
it('is scanning something, and the one reading is not one of the sites', function (): void {
    $flag = dirname(__DIR__, 2).'/php/core/src/Support/ConfiguredFlag.php';

    expect(packageSourceSwitchReads())->not->toBeEmpty()
        ->and(allowedSwitchReads())->not->toBeEmpty()
        ->and(switchReads((string) file_get_contents($flag)))->toBe([])
        ->and(file_get_contents($flag))->toContain('array_key_exists($key, $bag)')
        ->and(file_get_contents($flag))->toContain('is_bool($value)');
});

/**
 * The scanner's own proof, executed rather than asserted: every spelling of the two shapes, the ones
 * that are neither, and the two a `grep '?? false'` would get wrong in both directions — a `??` whose
 * default is a boolean-shaped EXPRESSION rather than a literal, and one written inside a string.
 */
it('sees both shapes of a switch read, and only those', function (): void {
    $source = <<<'PHP'
        <?php

        final class Sneaky
        {
            public const string ADVICE = 'never write $bag["k"] ?? false here';

            /** Nor in a docblock: `(bool) $x`. */
            public function coalesced(array $bag): bool
            {
                return ($bag['a'] ?? false) === true;
            }

            public function qualified(array $bag): bool
            {
                return $bag['a'] ?? \true;
            }

            public function assigned(array &$bag): void
            {
                $bag['a'] ??= false;
            }

            public function cast(mixed $v): bool
            {
                return (bool) $v;
            }

            public function notASwitch(array $bag, bool $fallback): mixed
            {
                // A non-boolean default, a boolean default that is an expression rather than a
                // literal, and casts to something else: none of these read a switch.
                return [$bag['a'] ?? 'false', $bag['b'] ?? $fallback, (int) $bag['c'], (string) $bag['d']];
            }
        }
        PHP;

    expect(switchReadSites($source))->toBe(['coalesced', 'qualified', 'assigned', 'cast'])
        ->and(switchReadLines($source))->toBe([10, 15, 20, 25]);
});
