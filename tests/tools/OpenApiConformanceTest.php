<?php

declare(strict_types=1);

use Docuccino\Core\Emit\ServerVariables;
use Docuccino\Core\Spec\UirSpec;
use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use Docuccino\Core\SpecValidation\Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as OpisValidator;

/**
 * The claim the artifact makes in public, held to the two authorities that can settle it: the full
 * artifact is a valid OpenAPI 3.2 document, full stop, and the `x-docuccino` member it carries answers
 * to the extension schema on its own.
 *
 * "Full stop" is the whole point. The artifact used to carry two root members the OpenAPI Object admits
 * neither of, so the one document Docuccino most wants third-party tooling to read was the one no
 * OpenAPI tool would accept. Both halves below are executed against a document written to FAIL them,
 * because the root gate they depend on was absent for as long as the members were there — a guard that
 * only ever passes would have reported this artifact sound throughout.
 *
 * It lives under `tests/tools/` rather than in one package's suite because its subjects come from BOTH
 * packages' fixture trees: asserted from `php/core/tests/`, a standalone `docuccino/core` checkout
 * could not run it at all, and the assertion that most needs running is the one about the adapter's
 * 35 recorded artifacts.
 */

/** The `x-docuccino` member alone, against the standalone extension schema. */
function extensionFindings(mixed $member): array
{
    $schema = json_decode((string) file_get_contents(Validator::defaultExtensionSchemaPath()), flags: JSON_THROW_ON_ERROR);

    $error = (new OpisValidator)->validate($member, $schema)->error();

    return $error === null ? [] : (new ErrorFormatter)->format($error, true);
}

/** One committed artifact as the object graph both oracles read — never an associative decode. */
function committedArtifactGraph(string $path): stdClass
{
    $decoded = json_decode((string) file_get_contents($path), flags: JSON_THROW_ON_ERROR);

    return $decoded instanceof stdClass
        ? $decoded
        : throw new RuntimeException('Not a JSON object: '.$path);
}

/** A committed file by the repo-relative name the repository knows it as. */
function repositoryPath(string $relative): string
{
    return dirname(__DIR__, 2).'/'.$relative;
}

/** The one document in the corpus that is not a conformant OpenAPI 3.2 document, and exactly why. */
const CONFORMANCE_DIVERGENCES = [
    'php/core/tests/Fixtures/postman-surface.uir.json' => [
        '/servers/0/variables/version required: The required properties (default) are missing (schema /$defs/server-variable)',
    ],
];

/**
 * Every UIR document in the repository, with the OpenAPI 3.2 findings it owes — `[]` for all but one,
 * which carries its divergence as a ROW.
 *
 * The corpus is the whole of {@see uirDocuments()}, goldens and hand-written fixtures alike. It used
 * to be the goldens alone, filtered by directory, and the filter was quietly load-bearing: of the ten
 * documents it excluded, NINE answer `[]` at both oracles, so the rule bought silence over nine files
 * in order to hide the tenth — and hid it without naming it, which is worse than the exception list
 * `OpenApiMetaSchemaTest` argues against, because a directory names nothing at all.
 *
 * The tenth is `postman-surface.uir.json`, and it diverges by design: it declares a Server Variable
 * Object with an `enum` and no `default`, which is legal UIR and illegal at every OpenAPI version, so
 * that the per-target recovery has something to recover ({@see ServerVariables}). Its row pins the
 * exact finding, so fixing the fixture and a second fixture acquiring the same shape both fail here.
 *
 * @return array<string, array{string, list<string>}>
 */
function conformanceSubjects(): array
{
    $root = dirname(__DIR__, 2).'/';

    $subjects = [];
    foreach (uirDocuments() as $path) {
        $relative = str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;

        $subjects[$relative] = [$path, CONFORMANCE_DIVERGENCES[$relative] ?? []];
    }

    // A divergence keyed on a file the corpus no longer holds is a row nothing executes, and the
    // fixture it excused could have been renamed into the conformant set with nobody the wiser.
    foreach (array_keys(CONFORMANCE_DIVERGENCES) as $named) {
        if (! isset($subjects[$named])) {
            throw new RuntimeException('A divergence row names a document the corpus does not hold: '.$named);
        }
    }

    return $subjects;
}

it('reads a plausible minimum of UIR documents', function (): void {
    // A discovery that stopped matching would report the whole corpus conformant over an empty set,
    // and one that stopped seeing a package would report it over half of one. 50 today: 40 recorded
    // artifacts (5 core's, 35 the adapter's) and 10 hand-written fixtures.
    $subjects = array_keys(conformanceSubjects());

    $golden = array_filter($subjects, static fn (string $name): bool => str_contains($name, '/tests/Fixtures/golden/'));

    expect(count($subjects))->toBeGreaterThanOrEqual(50)
        ->and(count($golden))->toBeGreaterThanOrEqual(40)
        ->and(count($subjects) - count($golden))->toBeGreaterThanOrEqual(10)
        ->and(count(array_filter($golden, static fn (string $name): bool => str_starts_with($name, 'php/core/'))))->toBeGreaterThanOrEqual(5)
        ->and(count(array_filter($golden, static fn (string $name): bool => str_starts_with($name, 'php/laravel/'))))->toBeGreaterThanOrEqual(35);
});

it('publishes a UIR document that is a valid OpenAPI 3.2 document', function (string $path, array $expected): void {
    expect(OpenApiMetaSchema::findings('openapi-3.2', committedArtifactGraph($path)))->toBe($expected);
})->with(conformanceSubjects());

it('publishes an x-docuccino member that answers to the standalone extension schema', function (string $path): void {
    $document = committedArtifactGraph($path);

    expect($document->{'x-docuccino'} ?? null)->toBeInstanceOf(stdClass::class)
        ->and(extensionFindings($document->{'x-docuccino'}))->toBe([]);
})->with(conformanceSubjects());

/**
 * The emitted artifact each OpenAPI version publishes, as the subject its own root gate is executed
 * against. One per version, because a 3.2 document is not a valid 3.0 one and never was: run the 3.0
 * meta-schema over the 3.2 golden and the stray member vanishes into three findings that were already
 * there, and the refusal proves nothing.
 *
 * @return array<string, array{string, string}>
 */
function rootGateSubjects(): array
{
    $goldens = [
        'openapi-3.2' => 'php/core/tests/Fixtures/golden/kitchen-sink.openapi32.json',
        'openapi-3.1' => 'php/core/tests/Fixtures/golden/kitchen-sink.openapi31.json',
        'openapi-3.0' => 'php/core/tests/Fixtures/golden/kitchen-sink.openapi30.json',
    ];

    // The union, asserted rather than assumed: the gate is claimed for every format the oracle answers
    // for, so every one of them owes a subject. A version added to SCHEMAS with no golden beside it
    // fails here instead of going ungated in silence.
    $missing = array_diff(array_keys(OpenApiMetaSchema::SCHEMAS), array_keys($goldens));

    if ($missing !== []) {
        throw new RuntimeException('No root-gate subject for '.implode(', ', $missing));
    }

    $subjects = [];
    foreach ($goldens as $format => $relative) {
        $subjects[$format] = [$format, repositoryPath($relative)];
    }

    return $subjects;
}

/*
 * The refusals, executed rather than claimed, at EVERY version the oracle answers for rather than at
 * the one the full artifact happens to be written in. The gate's own docblock divides the domain
 * across three — 3.2 and 3.1 recover it from `unevaluatedProperties`, 3.0 never lost it — and a guard
 * that runs one of the three states the rule and checks a third of it.
 *
 * The first two members are the ones UIR 2.0 moved: named individually because moving them is what
 * this conformance is FOR, and a regression would put exactly one of them back.
 */
it('refuses a document carrying a root member OpenAPI does not define', function (string $format, string $path, string $member, mixed $value): void {
    $document = committedArtifactGraph($path);

    // The artifact is conformant as committed, so the failure below is caused by the member and by
    // nothing else in the document.
    expect(OpenApiMetaSchema::findings($format, $document))->toBe([]);

    $document->{$member} = $value;

    expect(OpenApiMetaSchema::findings($format, $document))
        ->toHaveCount(1)
        // By NAME rather than by pointer: 3.2 and 3.1 report the key against the root's gate patterns
        // and 3.0 reports it as an additional property of the root, so the member itself is the only
        // part of the message all three versions spell the same way.
        ->and(OpenApiMetaSchema::findings($format, $document)[0])->toContain($member);
})->with(rootGateSubjects())->with([
    'the schema URL that moved' => ['$schema', 'https://spec.docuccino.app/uir/2.0/schema.json'],
    'the spec version that moved' => ['uir', '2.0.0'],
    'any other stray' => ['generatedBy', 'something'],
]);

it('accepts a root member OpenAPI leaves open to a vendor', function (string $format, string $path): void {
    $document = committedArtifactGraph($path);

    // An object graph, like every other subject here. A PHP array would reach the meta-schema as a
    // JSON array, and "an empty object read as an empty array" is a defect class this repository has
    // a name for — a positive control that quietly asked a different question would be the worst
    // place for it to land.
    $document->{'x-somebody-else'} = (object) ['their' => 'member'];

    // The other half of the gate: `^x-` is the door the extension itself comes through, so a gate that
    // refused everything would refuse `x-docuccino` too and prove nothing.
    expect(OpenApiMetaSchema::findings($format, $document))->toBe([]);
})->with(rootGateSubjects());

it('refuses an x-docuccino member the extension schema does not define', function (): void {
    $document = committedArtifactGraph(repositoryPath('php/core/tests/Fixtures/golden/kitchen-sink.uir.json'));
    $extension = $document->{'x-docuccino'};

    expect(extensionFindings($extension))->toBe([]);

    $extension->generator->builtAt = '2026-09-22T00:00:00Z';

    // A timestamp is the shape the extension is closed against by design: it is the one member that
    // would make a rebuild of unchanged code produce different bytes.
    expect(extensionFindings($extension))->not->toBe([]);
});

/*
 * And the claim that makes it an EXTENSION rather than a format: the schema applies on top of an
 * OpenAPI document Docuccino did not build. Nothing in it may depend on the document around it.
 */
it('applies to the x-docuccino member of a document it did not build', function (): void {
    $foreign = json_decode((string) json_encode([
        'openapi' => '3.2.0',
        'info' => ['title' => 'Somebody else\'s API', 'version' => '4.1.0'],
        'paths' => new stdClass,
        'x-docuccino' => [
            'document' => ['id' => 'doc:theirs'],
            'generator' => [
                'name' => 'docuccino/laravel',
                'version' => '0.20.0',
                'specVersion' => UirSpec::VERSION,
                'schema' => UirSpec::schemaUrl(),
            ],
        ],
    ]), flags: JSON_THROW_ON_ERROR);

    expect($foreign)->toBeInstanceOf(stdClass::class)
        ->and(OpenApiMetaSchema::findings('openapi-3.2', $foreign))->toBe([])
        ->and(extensionFindings($foreign->{'x-docuccino'}))->toBe([]);

    $foreign->{'x-docuccino'}->generator->specVersion = 'two point oh';

    expect(extensionFindings($foreign->{'x-docuccino'}))->not->toBe([]);
});

/*
 * The divergence the corpus names, executed against THE FILE that has it rather than against a shape
 * synthesised beside it. Synthesised from `kitchen-sink`, this passed whether or not
 * `postman-surface.uir.json` still diverged and whether or not a second fixture had acquired the same
 * defect — so it proved the meta-schema's behaviour and nothing whatever about the claim its row
 * makes.
 *
 * A Server Variable Object with no `default` is legal in a full artifact and illegal in OpenAPI, so an
 * application that declares one publishes a document the meta-schema refuses at that position, and
 * each OpenAPI target completes it its own way on the way out. Recorded rather than allowed for,
 * because a reader of the conformance claim has to know exactly where it stops.
 */
it('refuses the server variable the one divergent fixture declares, which only the emitters complete', function (): void {
    $relative = 'php/core/tests/Fixtures/postman-surface.uir.json';
    $path = repositoryPath($relative);

    expect(conformanceSubjects()[$relative][1])
        ->toBe(['/servers/0/variables/version required: The required properties (default) are missing (schema /$defs/server-variable)'])
        ->and(OpenApiMetaSchema::findings('openapi-3.2', committedArtifactGraph($path)))->toBe(conformanceSubjects()[$relative][1]);

    // And what the emitters do about it, so the two halves of the fact sit together: the enum's own
    // first value stands in, the emission says so, and what it publishes is conformant.
    $diagnostics = [];
    $completed = ServerVariables::complete(
        json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
        $diagnostics,
    );

    expect($completed['servers'][0]['variables']['version']['default'])->toBe('v1')
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('server.variable-no-default')
        ->and(OpenApiMetaSchema::findings('openapi-3.2', json_decode((string) json_encode($completed), flags: JSON_THROW_ON_ERROR)))->toBe([]);
});
