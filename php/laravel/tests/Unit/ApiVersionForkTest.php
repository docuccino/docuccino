<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Laravel\Versioning\ApiVersionTransformer;
use Docuccino\Laravel\Versioning\VersionChangeCollector;
use Workbench\App\Data\FormTreeData;

/**
 * The one shape the fork cannot produce: a schema that contains itself.
 *
 * A scoped change gives the operations in scope a private copy of the schema, and a copy of a
 * self-referential schema still points at the shared component one level down — so the operation would
 * publish the older name at the top and today's name inside it. The guard refuses to write that.
 *
 * Built by hand rather than through a build: the document is the input, and the recovery chain will not
 * publish a self-referential component off a plain data class, so a fixture app would prove the chain
 * rather than this. The fork's ordinary branches are proven against real builds in
 * `Feature/Versioning/VersionChangeScopeTest`.
 */
function selfReferentialDocument(): array
{
    $id = (new IdentityGenerator)->namedSchemaId(FormTreeData::class);

    $operation = static fn (string $operationId): array => [
        'x-docuccino' => ['id' => 'op:v1:'.$operationId],
        'operationId' => $operationId,
        'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => [
            'schema' => ['$ref' => '#/components/schemas/FormTree'],
        ]]]],
    ];

    return [
        'info' => ['title' => 'Trees', 'version' => '2026-06-01'],
        'paths' => [
            '/api/versioned-trees' => ['get' => $operation('listTrees')],
            '/api/versioned-trees/archived' => ['get' => $operation('listArchivedTrees')],
        ],
        'components' => ['schemas' => ['FormTree' => [
            'x-docuccino' => ['id' => $id],
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FormTree']],
            ],
            'required' => ['id', 'title'],
        ]]],
    ];
}

/**
 * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
 */
function transformedVersion(array $document, string $dir): array
{
    $config = new DocumentConfig(
        key: 'v',
        info: ['title' => 'Trees', 'version' => '2026-06-01'],
        raw: ['info' => ['version' => '2026-06-01'], 'api_version' => ['changes' => ['dir' => $dir]]],
    );

    $draft = new UirDocumentDraft($document);
    $context = new DocumentContext($config, 'doc:v');

    (new ApiVersionTransformer(new VersionChangeCollector(dirname(__DIR__, 2))))->transform($draft, $context);

    return [$draft->toArray(), $context->diagnostics->all()];
}

it('refuses to fork a schema that contains itself, and leaves the operation at the shape the code publishes', function (): void {
    [$document, $diagnostics] = transformedVersion(selfReferentialDocument(), 'tests/Fixtures/Versioning/ScopedSelfReferential');

    $codes = array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $diagnostics);
    $messages = implode("\n", array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $diagnostics));

    expect($codes)->toContain('versioning.change-invalid')
        ->and($messages)->toContain('refers to itself')
        ->and($messages)->toContain('GET /api/versioned-trees')
        // Nothing half-written: both operations still share the component, and it still says `title`.
        ->and($document['paths']['/api/versioned-trees']['get']['responses']['200']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/FormTree'])
        ->and(array_keys($document['components']['schemas']['FormTree']['properties']))->toBe(['id', 'title', 'children']);
});

/*
 * The counter-case, and the reason the guard is not simply "self-referential schemas are never
 * touched": covering every operation renames the component in place, which a self-referential schema
 * takes exactly as well as any other because there is only one copy of it.
 */
it('renames a self-referential component in place when nothing has to fork', function (): void {
    [$document, $diagnostics] = transformedVersion(selfReferentialDocument(), 'tests/Fixtures/Versioning/SelfReferentialEverywhere');

    expect(array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $diagnostics))->toBe([])
        ->and(array_keys($document['components']['schemas']['FormTree']['properties']))->toBe(['id', 'name', 'children'])
        ->and($document['components']['schemas']['FormTree']['required'])->toBe(['id', 'name']);
});
