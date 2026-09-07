<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Extensions\BuiltIn\AttributeExamplesExtension;
use Docuccino\Laravel\Support\OverrideHint;
use Docuccino\Laravel\Support\ParameterLocations;

/*
 * The parameter locations, as the tables that read them agree on.
 *
 * Two halves. The reader is a mapping table, so it owes a row per entry plus unknown-entry
 * degradation. The SET is a fact several tables hold independently — one folds an author's word
 * against it, one ranks a published parameter by it, one looks a named parameter up in it, one names
 * the attribute that owns it — and covering the four proves nothing about whether they AGREE, which is
 * why the set is written out literally below and every site is held to that rather than to whichever
 * of them is read first.
 */

it('reads every location it declares, whatever case or padding the author wrote it in', function (string $location): void {
    expect(ParameterLocations::read($location))->toBe($location)
        ->and(ParameterLocations::read(strtoupper($location)))->toBe($location)
        ->and(ParameterLocations::read(ucfirst($location)))->toBe($location)
        ->and(ParameterLocations::read('  '.$location.' '))->toBe($location);
})->with(ParameterLocations::ALL);

/*
 * The degradation, which is the half a caller acts on: a value naming no location comes back as null so
 * the caller can quote the author's own word back at them rather than guessing at a location and
 * renaming or dropping a parameter nobody named.
 */
it('reads a word that names no location as no location at all', function (string $written): void {
    expect(ParameterLocations::read($written))->toBeNull();
})->with([
    'a location OpenAPI has not got' => ['body'],
    'nothing at all' => [''],
    'whitespace only' => ['   '],
    'a location spelled as a sentence' => ['in the query'],
    'a location with an inner space' => ['qu ery'],
    'plural' => ['queries'],
]);

/*
 * OAS 3.2 declares a FIFTH parameter location, and this is its row rather than its absence.
 *
 * `querystring` describes the WHOLE query string as one value, so it names no parameter: there is no
 * `name` for a rename to move, for an example declaration to look up, or for an attribute to own.
 * Nothing this product mints publishes one either. So every table keyed on a location is keyed on a
 * NAMED location and reads this as no location at all — and one an overlay writes is ordered after the
 * four the canonicaliser knows, which is a position rather than a crash.
 */
it('reads OAS 3.2\'s querystring as no location, because it names no parameter', function (): void {
    expect(ParameterLocations::read('querystring'))->toBeNull()
        ->and(ParameterLocations::ALL)->not->toContain('querystring')
        ->and(parameterInRank())->not->toHaveKey('querystring')
        ->and(max(parameterInRank()))->toBeLessThan(parameterUnknownRank());
});

it('quotes every location it declares, and nothing that is not one', function (): void {
    $quoted = ParameterLocations::quoted();

    foreach (ParameterLocations::ALL as $location) {
        expect($quoted)->toContain('`'.$location.'`');
    }

    expect(substr_count($quoted, '`'))->toBe(2 * count(ParameterLocations::ALL));
});

/**
 * The rank the canonicaliser publishes parameters by, and the rank it gives a location it does not
 * know — read off the canonicaliser itself, because a guard that asked the set for its own answer
 * would agree with whatever the set says.
 *
 * @return array<string, int>
 */
function parameterInRank(): array
{
    $constant = (new ReflectionClass(Canonicalizer::class))->getReflectionConstant('PARAMETER_IN_RANK');

    /** @var array<string, int> $rank */
    $rank = $constant === false ? [] : $constant->getValue();

    return $rank;
}

/** Where a location the canonicaliser does not know is ordered, as its own source states it. */
function parameterUnknownRank(): int
{
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/core/src/Canonical/Canonicalizer.php');

    preg_match('/PARAMETER_IN_RANK\[\$parameter\[\x27in\x27\]\] \?\? (\d+)/', $source, $matches);

    return (int) ($matches[1] ?? -1);
}

/**
 * A private const of `$class`, by name.
 *
 * @return array<array-key, mixed>
 */
function parameterLocationTable(string $class, string $name): array
{
    $constant = (new ReflectionClass($class))->getReflectionConstant($name);

    /** @var array<array-key, mixed> $value */
    $value = $constant === false ? [] : $constant->getValue();

    return $value;
}

/**
 * Every site that holds the set, and how to read the set out of it. The union of the four is the
 * domain: a site missing from here is a copy nothing holds to the others.
 *
 * @return array<string, list<string>>
 */
function parameterLocationSites(): array
{
    $sorted = static function (array $locations): array {
        $locations = array_values(array_map('strval', $locations));
        sort($locations, SORT_STRING);

        return $locations;
    };

    return [
        // The author's word, folded against the set.
        'ParameterLocations::ALL' => $sorted(ParameterLocations::ALL),
        // The order a published document lists its parameters in.
        'Canonicalizer::PARAMETER_IN_RANK' => $sorted(array_keys(parameterInRank())),
        // Where a `parameter:` example declaration's name is looked for.
        'AttributeExamplesExtension::PARAMETER_LOCATIONS' => $sorted(parameterLocationTable(AttributeExamplesExtension::class, 'PARAMETER_LOCATIONS')),
        // The attribute that owns a parameter of each location.
        'OverrideHint::PARAMETER_ATTRIBUTES' => $sorted(array_keys(parameterLocationTable(OverrideHint::class, 'PARAMETER_ATTRIBUTES'))),
    ];
}

/*
 * The agreement, stated independently. Four locations name a parameter; a fifth that turned up in one
 * table and not the others would be a document ordering a parameter one way and refusing to rename it,
 * or an example declaration reaching a location no attribute can write.
 */
it('holds every table that reads a parameter location to one set', function (): void {
    $declared = ['cookie', 'header', 'path', 'query'];

    expect(parameterLocationSites())->not->toBe([]);

    foreach (parameterLocationSites() as $site => $locations) {
        expect($locations)->toBe($declared, $site.' does not hold the declared set');
    }
});

/*
 * And the two tables that carry an ORDER carry the SAME one, because there is one conventional order
 * for the locations rather than one per reader: the order a document publishes them in is the order a
 * name is looked for in, and a reader meeting the two would have to know which was which.
 */
it('holds the two ordered tables to one order', function (): void {
    expect(array_keys(parameterInRank()))
        ->toBe(array_values(array_map('strval', parameterLocationTable(AttributeExamplesExtension::class, 'PARAMETER_LOCATIONS'))));
});
