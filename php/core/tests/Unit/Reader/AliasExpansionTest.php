<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Identity\ContentHasher;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\Syntax;
use Docuccino\Core\Reader\UnreadableDocumentException;
use Docuccino\Core\Support\JsonValue;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/*
 * YAML aliases are bounded by what they expand to, in values AND in the text those values hold. One long
 * string named again and again is one value a use, so a value count alone reads it in milliseconds and
 * leaves `json()` to write it out: 107 KB became 100 MB. Text is every string and member name, counted at
 * each place the expanded document holds it, and a document may hold eight bytes of it for every byte of
 * its own (1 MiB for a small one) before it is refused, while it is read and before anything walks it.
 *
 * Those budgets, not the count of aliases, are what refuse a document. symfony/yaml stops at 128 aliases to
 * a map or a list on its own, which refused real descriptions that alias a shared response at every
 * operation, so the reader lifts that cap wherever the text cannot spell a merge key: a merge copies the map
 * it names while the parser reads, before any budget here can see it.
 */

$head = "openapi: 3.1.0\ninfo:\n  title: Shared\n  version: '1'\npaths: {}\n";

/** Nested lists, each naming the one below it $fan times, $levels deep over one $leaf. */
$laughs = static function (int $fan, int $levels, string $leaf) use ($head): string {
    $yaml = $head."x-l0: &l0 [{$leaf}]\n";
    foreach (range(1, $levels) as $level) {
        $yaml .= "x-l{$level}: &l{$level} [".implode(', ', array_fill(0, $fan, '*l'.($level - 1)))."]\n";
    }

    return $yaml;
};

it('refuses a document whose aliases repeat text far past its size, cheaply and before anything walks it', function (string $yaml, string $refusal): void {
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    try {
        (new DocumentReader)->read($yaml);
        $this->fail('A document expanding to gigabytes of text was read.');
    } catch (UnreadableDocumentException $exception) {
        $limit = $refusal === 'values' ? max(10_000, strlen($yaml)) : max(1_048_576, 8 * strlen($yaml));

        expect($exception->failure)->toBe(ReadFailure::AliasExpansion)
            ->and($exception->reason)->toContain(sprintf('expands to more than %d %s from %d bytes', $limit, $refusal, strlen($yaml)))
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
            // Written out, each of these is hundreds of megabytes at least.
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(16 * 1024 * 1024);
    }
})->with([
    // `json()` wrote 100 MB of these 107 KB, at a 288 MB peak.
    'a 100 KB string named 1,000 times' => [$head.'x-text: &s '.str_repeat('x', 100_000)."\nx-copies:\n".str_repeat("  - *s\n", 1_000), 'bytes of text'],
    // And 10 GB of these 1.07 MB, which no memory limit survives.
    'a 1 MB string named 10,000 times' => [$head.'x-text: &s '.str_repeat('x', 1_000_000)."\nx-copies:\n".str_repeat("  - *s\n", 10_000), 'bytes of text'],
    'a map with a 100 KB member name, named 100 times' => [$head.'x-map: &m {'.str_repeat('k', 100_000).": 1}\nx-copies:\n".str_repeat("  - *m\n", 100), 'bytes of text'],
    'a map holding a 100 KB string, merged into 100 maps' => [$head.'x-map: &m {a: '.str_repeat('x', 100_000)."}\nx-merged:\n".implode('', array_map(static fn (int $i): string => "  m{$i}:\n    <<: *m\n", range(1, 100))), 'bytes of text'],
    // 11,111 values, inside the value budget of this 100 KB, holding 1 GB of text.
    'four levels of ten aliases over a 100 KB string' => [$laughs(10, 4, str_repeat('x', 100_000)), 'bytes of text'],
    // A date is one value and 32 bytes of text, so the text passes 1 MiB at half this 68 KB's value budget.
    'a list of 2,000 dates named 100 times' => [$head.'x-dates: &d ['.implode(', ', array_fill(0, 2_000, '2024-01-01T10:00:00.123456+02:00'))."]\nx-tens: &t [".implode(', ', array_fill(0, 10, '*d'))."]\nx-hundreds: [".implode(', ', array_fill(0, 10, '*t'))."]\n", 'bytes of text'],
    // A billion values from 625 bytes, which the value budget stops first.
    'nine levels of ten aliases over one letter' => [$laughs(10, 9, 'x'), 'values'],
]);

it('admits exactly the text its limit allows and refuses one byte more, in both regimes', function (int $length, int $uses, int $text, ?int $bytes, bool $admitted): void {
    // Three names, a string of $length bytes written once and named $uses more times, and a second string
    // padding the text to $text; a comment then pads the document to $bytes without adding any text.
    $yaml = 'x: &s '.str_repeat('a', $length)."\ny:\n".str_repeat("- *s\n", $uses).'z: '.str_repeat('b', $text - 3 - $length * (1 + $uses))."\n";
    if ($bytes !== null) {
        $yaml .= '#'.str_repeat(' ', $bytes - strlen($yaml) - 2)."\n";
    }
    expect(strlen($yaml))->toBe($bytes ?? strlen($yaml));

    try {
        $read = json_decode((new DocumentReader)->read($yaml)->json(), true, 512, JSON_THROW_ON_ERROR);
        expect($admitted)->toBeTrue()
            ->and($read['y'])->toHaveCount($uses)
            ->and(strlen(implode('', [...array_keys($read), ...$read['y'], $read['x'], $read['z']])))->toBe($text);
    } catch (UnreadableDocumentException $exception) {
        expect($admitted)->toBeFalse()
            ->and($exception->failure)->toBe(ReadFailure::AliasExpansion)
            ->and($exception->reason)->toContain(sprintf('more than %d bytes of text from %d bytes', $text - 1, strlen($yaml)));
    }
})->with([
    // Under 128 KiB of document, the floor is the limit: 1 MiB.
    'the floor, exactly' => [10_000, 100, 1_048_576, null, true],
    'the floor, one byte past' => [10_000, 100, 1_048_577, null, false],
    // Past it, eight bytes of text per byte: 1,600,000 for 200,000.
    'eight per byte, exactly' => [10_000, 150, 1_600_000, 200_000, true],
    'eight per byte, one byte past' => [10_000, 150, 1_600_001, 200_000, false],
]);

it('reads every document in the fixture trees to the graph its parser reads, json and content hash alike', function (): void {
    $documents = [
        ...uirDocuments(),
        ...(array) glob(dirname(__DIR__, 3).'/tests/Fixtures/golden/*.openapi3*.{json,yaml}', GLOB_BRACE),
    ];

    $yaml = 0;
    foreach ($documents as $path) {
        $bytes = (string) file_get_contents((string) $path);
        $parsed = str_ends_with((string) $path, '.json')
            ? json_decode($bytes, false, 512, JSON_THROW_ON_ERROR)
            : Yaml::parse($bytes, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        $yaml += str_ends_with((string) $path, '.yaml') ? 1 : 0;
        expect($parsed)->toBeInstanceOf(stdClass::class);

        $document = (new DocumentReader)->read($bytes);

        expect($document->json())->toBe((new CanonicalJsonSerializer)->serialize($parsed), (string) $path)
            ->and($document->contentHash())->toBe((new ContentHasher)->hash(array_map(JsonValue::normalize(...), get_object_vars($parsed))), (string) $path);
    }

    // A scan that found nothing would agree with itself forever.
    expect(count($documents))->toBeGreaterThan(40)
        ->and($yaml)->toBeGreaterThan(1);
});

/*
 * The two shapes generators dump, each past the parser's own 128: PyYAML anchors a parameter list and each
 * response the first time it writes them (`&id001`) and names them at every operation after, and js-yaml
 * style dumps (`&ref_0`) name lists and maps, inside flow collections too. The twin is the same document
 * built as PHP values and encoded as JSON, so nothing in it came through a YAML parser.
 */
it('reads a document naming more aliases than the parser allows on its own, as the JSON it stands for', function (string $yaml, array $document): void {
    $read = (new DocumentReader)->read($yaml);
    $twin = (new DocumentReader)->read(json_encode($document, JSON_THROW_ON_ERROR));

    expect($read->inspect()->syntax)->toBe(Syntax::Yaml)
        ->and($read->json())->toBe($twin->json())
        ->and($read->contentHash())->toBe($twin->contentHash())
        ->and($read->nodes())->toEqual($twin->nodes());

    // A document the parser refuses when left to itself, so this proves the cap lifted rather than unmet.
    expect(fn (): mixed => Yaml::parse($yaml))->toThrow(ParseException::class, 'Maximum number of collection aliases (128) exceeded');
})->with(static function (): array {
    $parameters = [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]];
    $unauthorized = ['description' => 'Unauthorized', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]]]]];
    $missing = ['description' => 'Not found'];

    // 99 operations naming three blocks the first one anchored: 297 aliases.
    $pyyaml = "openapi: 3.1.0\ninfo:\n  title: Orders\n  version: '1'\npaths:\n";
    $paths = [];
    foreach (range(0, 99) as $i) {
        $pyyaml .= $i === 0
            ? "  /orders/{id}/v0:\n    get:\n      parameters: &id001\n      - name: id\n        in: path\n        required: true\n        schema:\n          type: string\n      responses:\n        '401': &id002\n          description: Unauthorized\n          content:\n            application/json:\n              schema:\n                type: object\n                properties:\n                  message:\n                    type: string\n        '404': &id003\n          description: Not found\n"
            : "  /orders/{id}/v{$i}:\n    get:\n      parameters: *id001\n      responses:\n        '401': *id002\n        '404': *id003\n";
        $paths["/orders/{id}/v{$i}"] = ['get' => ['parameters' => $parameters, 'responses' => ['401' => $unauthorized, '404' => $missing]]];
    }

    // 39 schemas naming two blocks and, inside a flow list, two maps the first one anchored: 156 aliases.
    $refs = "openapi: 3.1.0\ninfo: {title: Orders, version: '1'}\npaths: {}\ncomponents:\n  schemas:\n";
    $schemas = [];
    foreach (range(0, 39) as $i) {
        $refs .= $i === 0
            ? "    S0:\n      type: object\n      required: &ref_0 [id, name]\n      properties: &ref_1\n        id: {type: integer}\n        name: {type: string}\n      allOf: [&ref_2 {description: Base}, &ref_3 {title: Entity}]\n"
            : "    S{$i}:\n      type: object\n      required: *ref_0\n      properties: *ref_1\n      allOf: [*ref_2, *ref_3]\n";
        $schemas["S{$i}"] = ['type' => 'object', 'required' => ['id', 'name'], 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], 'allOf' => [['description' => 'Base'], ['title' => 'Entity']]];
    }

    return [
        'PyYAML, a block anchored once and named at every operation' => [$pyyaml, ['openapi' => '3.1.0', 'info' => ['title' => 'Orders', 'version' => '1'], 'paths' => $paths]],
        'js-yaml, lists and maps named in block and flow' => [$refs, ['openapi' => '3.1.0', 'info' => ['title' => 'Orders', 'version' => '1'], 'paths' => new stdClass, 'components' => ['schemas' => $schemas]]],
    ];
});

it("keeps the parser's own 128 wherever the text could spell a merge key, and only there", function (string $spelt, bool $capped): void {
    // 129 aliases to a one-member map, which no budget refuses, before anything is spelt: so only the
    // parser's cap can refuse it, and every release meets it at the same alias.
    $yaml = "openapi: 3.1.0\nx-m: &m {a: 1}\nx-copies:\n".str_repeat("  - *m\n", 129).$spelt;

    try {
        $read = json_decode((new DocumentReader)->read($yaml)->json(), true, 512, JSON_THROW_ON_ERROR);
        expect($capped)->toBeFalse()
            ->and($read['x-copies'])->toHaveCount(129);
    } catch (UnreadableDocumentException $exception) {
        expect($capped)->toBeTrue()
            ->and($exception->failure)->toBe(ReadFailure::Syntax)
            ->and($exception->reason)->toStartWith('The document is not valid YAML: Maximum number of collection aliases (128) exceeded.');
    }
})->with([
    'nothing' => ["x-n: 1\n", false],
    'a `<` alone' => ["x-n: 'a < b'\n", false],
    'a merge key' => ["x-n:\n  <<: *m\n", true],
    // Releases before 7.4.21 and 8.1.9 merge a quoted key too, and an escape is a way to write `<`.
    'a quoted merge key' => ["x-n:\n  '<<': *m\n", true],
    'a double-quoted merge key' => ["x-n:\n  \"<<\": *m\n", true],
    'a merge key escaped' => ["x-n:\n  \"\\x3c\\x3c\": *m\n", true],
    'a merge key escaped by code point' => ["x-n:\n  \"\\u003c<\": *m\n", true],
    'a merge key escaped at length, in capitals' => ["x-n:\n  \"\\U0000003C\\x3C\": *m\n", true],
    'a merge key split over an escaped line break' => ["x-n: {\"<\\\n  <\": *m}\n", true],
    // The text could spell one, which is all the reader asks: `<<` in prose keeps the cap as well.
    'a `<<` in prose' => ["x-n: 'shift with <<'\n", true],
]);

it("refuses an alias bomb past the parser's 128 at the budgets, and a merge bomb at the parser's cap, cheaply", function (string $yaml, ReadFailure $failure, string $reason): void {
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    try {
        (new DocumentReader)->read($yaml);
        $this->fail('A document that expands far past its size was read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe($failure)
            ->and($exception->reason)->toContain($reason)
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
            // With the cap lifted, the parser spends 787 MB merging the 230 KB of 20,000 merges below.
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1024 * 1024);
    }
})->with(static function () use ($head, $laughs): array {
    $members = static fn (int $count): string => implode('', array_map(static fn (int $i): string => "  k{$i}: 1\n", range(1, $count)));
    $capped = 'Maximum number of collection aliases (128) exceeded';

    return [
        // 180 aliases over one letter: ten to the eleventh values, refused at ten thousand.
        'twenty aliases a level, nine levels deep' => [$laughs(20, 9, 'x'), ReadFailure::AliasExpansion, 'values from'],
        'a map of 10,000 members named 10,000 times' => [$head."x-base: &b\n".$members(10_000)."x-copies:\n".str_repeat("  - *b\n", 10_000), ReadFailure::AliasExpansion, 'values from'],
        'a map of 1,000 members merged into 20,000 maps' => [$head."x-base: &b\n".$members(1_000)."x-merged:\n".str_repeat("  - <<: *b\n", 20_000), ReadFailure::Syntax, $capped],
        'the same, under an escaped merge key' => [$head."x-base: &b\n".$members(1_000)."x-merged:\n".str_repeat("  - \"\\x3c\\x3c\": *b\n", 20_000), ReadFailure::Syntax, $capped],
        'a chain of 4,000 maps, each merging the one before' => [$head."x-chain:\n  m0: &m0 {a: 1}\n".implode('', array_map(static fn (int $i): string => "  m{$i}: &m{$i}\n    <<: *m".($i - 1)."\n    k{$i}: 1\n", range(1, 4_000))), ReadFailure::Syntax, $capped],
    ];
});
