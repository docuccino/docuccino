<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Change;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\ChangesetRenderer;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\IncomparableDocumentsException;
use Docuccino\Core\Document\UirDocument;

/**
 * A rich base document (extends the design §11 worked example): one operation with a path param,
 * a query param carrying an enum, a JSON response with an object schema, an error response, a
 * named component schema, and a content page — enough surface to exercise every diff rule.
 *
 * @return array<string, mixed>
 */
function diffBase(): array
{
    return [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms API', 'version' => '1.0.0'],
        'paths' => [
            '/api/v1/forms/{id}' => [
                'get' => [
                    'x-uir' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
                    'operationId' => 'forms.show',
                    'summary' => 'Show a form',
                    'tags' => ['Forms'],
                    'parameters' => [
                        [
                            'x-uir' => ['id' => 'par:v1:bbbbbbbbbbbbbbbb'],
                            'name' => 'id', 'in' => 'path', 'required' => true,
                            'schema' => ['type' => 'integer'],
                        ],
                        [
                            'x-uir' => ['id' => 'par:v1:cccccccccccccccc'],
                            'name' => 'status', 'in' => 'query', 'required' => false,
                            'schema' => ['type' => 'string', 'enum' => ['draft', 'published', 'archived']],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'x-uir' => ['id' => 'res:v1:dddddddddddddddd'],
                            'description' => 'The form',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'id' => ['type' => 'integer'],
                                            'title' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '404' => ['description' => 'Not found'],
                    ],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'FormData' => [
                    'x-uir' => ['id' => 'sch:v1:eeeeeeeeeeeeeeee'],
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                ],
            ],
        ],
        'x-uir' => [
            'content' => [
                'pages' => [
                    ['id' => 'page:v1:ffffffffffffffff', 'slug' => 'getting-started', 'title' => 'Getting started', 'content' => 'Welcome.'],
                ],
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $old
 * @param  array<string, mixed>  $new
 */
function diffOf(array $old, array $new): Changeset
{
    return (new DocumentDiffer)->diff(UirDocument::fromArray($old), UirDocument::fromArray($new));
}

/**
 * @return array<string, Change>
 */
function changesByCode(Changeset $changeset): array
{
    $out = [];
    foreach ($changeset->changes as $change) {
        $out[$change->code] = $change;
    }

    return $out;
}

it('reports no changes for an identical document', function (): void {
    expect(diffOf(diffBase(), diffBase())->isEmpty())->toBeTrue();
});

it('treats a path-parameter rename as cosmetic (same op id, no change)', function (): void {
    $new = diffBase();
    $paths = $new['paths'];
    $paths['/api/v1/forms/{formId}'] = $paths['/api/v1/forms/{id}'];
    unset($paths['/api/v1/forms/{id}']);
    $new['paths'] = $paths;

    expect(diffOf(diffBase(), $new)->isEmpty())->toBeTrue();
});

it('ignores provenance-only differences', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['x-uir']['provenance'] = [
        ['producer' => 'overlay', 'layer' => 'overlay', 'fields' => ['summary']],
    ];

    expect(diffOf(diffBase(), $new)->isEmpty())->toBeTrue();
});

it('refuses to diff documents built with different identity-algorithm versions', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['x-uir']['id'] = 'op:v2:aaaaaaaaaaaaaaaa';

    expect(fn () => diffOf(diffBase(), $new))->toThrow(IncomparableDocumentsException::class);
});

// --- Breaking rules ---------------------------------------------------------

it('classifies a removed operation as breaking', function (): void {
    $new = diffBase();
    $new['paths'] = [];

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('operation.removed');
    expect($changes['operation.removed']->breaking)->toBeTrue();
});

it('classifies a removed parameter as breaking', function (): void {
    $new = diffBase();
    array_pop($new['paths']['/api/v1/forms/{id}']['get']['parameters']);

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('parameter.removed');
    expect($changes['parameter.removed']->breaking)->toBeTrue();
});

it('classifies a removed response status as breaking', function (): void {
    $new = diffBase();
    unset($new['paths']['/api/v1/forms/{id}']['get']['responses']['404']);

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('response.removed');
    expect($changes['response.removed']->breaking)->toBeTrue();
});

it('classifies a parameter becoming required as breaking', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['required'] = true;

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('parameter.became-required');
    expect($changes['parameter.became-required']->breaking)->toBeTrue();
});

it('classifies an added required parameter as breaking', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][] = [
        'x-uir' => ['id' => 'par:v1:9999999999999999'],
        'name' => 'tenant', 'in' => 'query', 'required' => true,
        'schema' => ['type' => 'string'],
    ];

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('parameter.added-required');
    expect($changes['parameter.added-required']->breaking)->toBeTrue();
});

it('classifies a narrowed type as breaking', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema'] = ['type' => ['string', 'integer']];
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema'] = ['type' => 'string'];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('schema.type-narrowed');
    expect($changes['schema.type-narrowed']->breaking)->toBeTrue();
});

it('classifies a removed enum value as breaking', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema']['enum'] = ['draft', 'published'];

    $changes = changesByCode(diffOf(diffBase(), $new));
    expect($changes)->toHaveKey('schema.enum-value-removed');
    expect($changes['schema.enum-value-removed']->breaking)->toBeTrue();
});

it('classifies a required request property added as breaking', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['put'] = [
        'x-uir' => ['id' => 'op:v1:1111111111111111'],
        'operationId' => 'forms.update',
        'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]]]],
        'responses' => ['200' => ['description' => 'ok']],
    ];
    $new = $old;
    $new['paths']['/api/v1/forms/{id}']['put']['requestBody']['content']['application/json']['schema']['required'] = ['title'];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('schema.required-added');
    expect($changes['schema.required-added']->breaking)->toBeTrue();
});

// --- Non-breaking set -------------------------------------------------------

it('classifies additions, widenings and prose edits as non-breaking', function (): void {
    $new = diffBase();
    // Added optional parameter.
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][] = [
        'x-uir' => ['id' => 'par:v1:8888888888888888'],
        'name' => 'include', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'],
    ];
    // Added enum value.
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema']['enum'] = ['draft', 'published', 'archived', 'trashed'];
    // Prose edit.
    $new['paths']['/api/v1/forms/{id}']['get']['summary'] = 'Show one form';
    // Added response.
    $new['paths']['/api/v1/forms/{id}']['get']['responses']['500'] = ['description' => 'Server error'];
    // Deprecation.
    $new['paths']['/api/v1/forms/{id}']['get']['deprecated'] = true;
    // Added response-schema property.
    $new['paths']['/api/v1/forms/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['createdAt'] = ['type' => 'string', 'format' => 'date-time'];

    $changeset = diffOf(diffBase(), $new);

    expect($changeset->isBreaking())->toBeFalse();

    $codes = array_keys(changesByCode($changeset));
    expect($codes)->toContain('parameter.added');
    expect($codes)->toContain('schema.enum-value-added');
    expect($codes)->toContain('operation.summary-changed');
    expect($codes)->toContain('response.added');
    expect($codes)->toContain('operation.deprecated-changed');
    expect($codes)->toContain('schema.property-added');
});

it('classifies a widened type as non-breaking', function (): void {
    $old = diffBase();
    $old['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema'] = ['type' => 'string'];
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['schema'] = ['type' => ['string', 'integer']];

    $changes = changesByCode(diffOf($old, $new));
    expect($changes)->toHaveKey('schema.type-widened');
    expect($changes['schema.type-widened']->breaking)->toBeFalse();
});

it('diffs content pages as non-breaking prose changes', function (): void {
    $new = diffBase();
    $new['x-uir']['content']['pages'][0]['content'] = 'Welcome, updated.';
    $new['x-uir']['content']['pages'][] = ['id' => 'page:v1:1010101010101010', 'slug' => 'auth', 'title' => 'Authentication', 'content' => 'Use a token.'];

    $changeset = diffOf(diffBase(), $new);

    expect($changeset->isBreaking())->toBeFalse();
    $codes = array_keys(changesByCode($changeset));
    expect($codes)->toContain('page.content-changed');
    expect($codes)->toContain('page.added');
});

// --- Determinism, model and rendering --------------------------------------

it('produces a deterministic toArray with breaking-first ordering', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['summary'] = 'Changed';
    unset($new['paths']['/api/v1/forms/{id}']['get']['responses']['404']);

    $array = diffOf(diffBase(), $new)->toArray();

    expect($array['breaking'])->toBeTrue();
    expect($array['counts']['breaking'])->toBe(1);
    // First change is the breaking one.
    expect($array['changes'][0]['breaking'])->toBeTrue();
    expect($array['changes'][0]['code'])->toBe('response.removed');
});

it('renders a terminal report grouping breaking changes first', function (): void {
    $new = diffBase();
    $new['paths']['/api/v1/forms/{id}']['get']['summary'] = 'Changed';
    $new['paths']['/api/v1/forms/{id}']['get']['parameters'][1]['required'] = true;

    $rendered = (new ChangesetRenderer)->render(diffOf(diffBase(), $new));

    expect($rendered)->toContain('(1 breaking)');
    expect($rendered)->toContain('BREAKING');
    expect($rendered)->toContain('NON-BREAKING');
    expect(strpos($rendered, 'BREAKING'))->toBeLessThan(strpos($rendered, 'NON-BREAKING'));
    expect($rendered)->toContain('parameter.became-required');
});

it('renders a clean message when there are no changes', function (): void {
    expect((new ChangesetRenderer)->render(diffOf(diffBase(), diffBase())))->toBe("No API changes.\n");
});
