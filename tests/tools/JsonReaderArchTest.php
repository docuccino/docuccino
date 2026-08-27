<?php

declare(strict_types=1);

/*
 * One reader pulls JSON into a document.
 *
 * `{}` and `[]` are the same PHP array, so the distinction travels out of band: an empty (or
 * index-keyed) JSON object is a `stdClass`, and `Docuccino\Core\Support\JsonValue` is the single place
 * that decides which. An associative `json_decode` throws that away silently — the document that comes
 * back is valid, self-consistent and wrong, and a JSON Schema validator then refuses the `[]` it wrote
 * where `type: object` was promised.
 *
 * It has been re-introduced four times in four places. It is not a thing to remember, so it is a thing
 * to check: every associative decode under a package's `src/` is either named below with the reason it
 * is safe, or it is a defect.
 */

/**
 * The associative decodes that do NOT read a document, each with what makes that true. A new entry is a
 * claim about the value's destination, so it needs the same kind of sentence.
 *
 * @return array<string, string>
 */
function allowedAssociativeJsonDecodes(): array
{
    return [
        // Reads a package manifest for its `name`. Never a document.
        'php/core/src/Extensions/ResolvedExtensions.php:278' => 'composer.json → package name',

        // Reads the application's composer.json for `autoload.psr-4` directories. Never a document.
        'php/laravel/src/Engine/TypeEngineFactory.php:186' => 'composer.json → autoload paths',

        // Pulls `dependencies[].file` PATH STRINGS out of stored fragments for `docuccino:watch`. The
        // fragment BODY is read by `FragmentCache`, which goes through JsonValue; nothing here reaches it.
        'php/laravel/src/Pipeline/FragmentStore.php:46' => 'fragment manifest → dependency paths',

        // A `{format, payload}` envelope where the payload is the emitted document as a STRING, handed
        // to the response untouched. The bytes are never decoded here, so there is nothing to flatten.
        'php/laravel/src/Runtime/DocumentCache.php:48' => 'cache envelope → format + string payload',

        // The contract index keeps the original JSON text and re-decodes it as an OBJECT graph
        // (`ContractIndex::graph()`) wherever `{}` versus `[]` decides an answer — schema validation,
        // and the typed model the semantic diff reads (`ContractIndex::comparable()`). The associative
        // copy is only ever walked to LOCATE a node — operation ids, paths, methods, pointer segments —
        // and is never re-emitted or compared.
        'php/core/src/Contract/ContractIndex.php:69' => 'contract lookup index; validation reads graph()',

        // The decoded value lands on `additionalProperties` — a SCHEMA, and every position inside a
        // schema that a PHP array cannot spell is one canonicalisation restores from the keyword's own
        // contract. Measured against the recorded goldens: 41 such schemas, none carrying an `example`,
        // which is the only free-form position that would survive to the document. It also cannot use
        // JsonValue — an integration may import only the public core surface (`IntegrationsArchTest`).
        'php/laravel/src/Integrations/Validation/Transformers/AdditionalPropertiesRuleTransformer.php:57' => 'rule parameter → additionalProperties schema',

        // Asks the same carrier for its value schema's `type` WORD and nothing else, to tell whether a
        // map's values are objects. The decoded value is discarded on the next line; the schema that
        // reaches the document is the carrier's own JSON, decoded by the transformer above.
        'php/laravel/src/Integrations/SpatieData/DataValidationRules.php:231' => 'rule parameter → value container word',
    ];
}

/** @return list<string> */
function packageSourceAssociativeJsonDecodes(): array
{
    $found = [];

    foreach (['attributes', 'core', 'inference-phpstan', 'laravel'] as $package) {
        $found = [...$found, ...associativeJsonDecodesIn(dirname(__DIR__, 2).'/php/'.$package.'/src')];
    }

    sort($found);

    return $found;
}

it('lets nothing but JsonValue read JSON associatively into a document', function (): void {
    $unexplained = array_values(array_diff(
        packageSourceAssociativeJsonDecodes(),
        array_keys(allowedAssociativeJsonDecodes()),
    ));

    expect($unexplained)->toBe([]);
});

/**
 * An allow-list entry that has moved, or whose call has been fixed, is a line that no longer guards
 * anything — and the next reader takes it for a statement about code that is still there.
 */
it('names no allowed decode that is not there any more', function (): void {
    $stale = array_values(array_diff(
        array_keys(allowedAssociativeJsonDecodes()),
        packageSourceAssociativeJsonDecodes(),
    ));

    expect($stale)->toBe([]);
});

/**
 * A scan that matches nothing passes forever. These are the counts the assertions above are worth: the
 * allow-list is not empty, the scanner still finds `json_decode` at all, and the reader it exists to
 * protect is still the one every document path uses.
 */
it('is scanning something', function (): void {
    $jsonValue = dirname(__DIR__, 2).'/php/core/src/Support/JsonValue.php';

    expect(packageSourceAssociativeJsonDecodes())->not->toBeEmpty()
        ->and(allowedAssociativeJsonDecodes())->not->toBeEmpty()
        // JsonValue itself decodes to OBJECTS, so it must never appear in the scan's own results.
        ->and(associativeJsonDecodesIn(dirname($jsonValue)))->not->toContain('php/core/src/Support/JsonValue.php:39')
        ->and(file_get_contents($jsonValue))->toContain('json_decode($json, false');
});

/**
 * The scanner's own proof. Every spelling that asks `json_decode` for arrays, and the ones that do not,
 * plus the shapes a `^json_decode.*true` grep would get wrong in both directions: a `true` that belongs
 * to a nested call rather than to this one, and a call written inside a string.
 */
it('sees every spelling that asks for arrays, and only those', function (): void {
    $source = <<<'PHP'
        <?php

        final class Sneaky
        {
            public const string ADVICE = 'never write json_decode($raw, true) here';

            public function run(string $raw): void
            {
                json_decode($raw, true);
                json_decode($raw, associative: true);
                json_decode($raw, flags: JSON_OBJECT_AS_ARRAY);
                json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                json_decode($raw, 512 > 1, JSON_THROW_ON_ERROR);
                json_decode(
                    $raw,
                    true,
                );

                // A leading `\` is inert to PHP, so it is inert here too — on the function, on the
                // literal in the slot, and on the flag alike.
                \json_decode($raw, true);
                \json_decode($raw, associative: \true);
                \json_decode($raw, flags: \JSON_OBJECT_AS_ARRAY);
                json_decode($raw, \true);

                // json_decode($raw, true) in a comment is not a call.
                json_decode($raw);
                json_decode($raw, false);
                json_decode($raw, null, 512, JSON_THROW_ON_ERROR);
                json_decode(json_encode(['a' => true]), false);
                $this->json_decode($raw, true);
                Other::json_decode($raw, true);
                \json_decode($raw, \false);
                \Sneaky\json_decode($raw, true);
            }

            private function json_decode(string $raw, bool $associative): void {}
        }
        PHP;

    // Every line above that really does ask for arrays, reported where the CALL opens — and `512 > 1`
    // (line 13) is not one of them: an expression in the slot is not the literal, and a guard that
    // guessed would flag a call it cannot read. `\Sneaky\json_decode` (line 34) is not one either: a
    // namespaced function sharing the short name is not this one.
    expect(associativeJsonDecodeLines($source))->toBe([9, 10, 11, 12, 14, 21, 22, 23, 24]);
});
