<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Change;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\DiffFailure;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\IncomparableDocumentsException;
use Docuccino\Core\Diff\Pairing;
use Docuccino\Core\Reader\DocumentNode;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\NodeKind;

/*
 * OpenAPI 3.2's `additionalOperations` maps a method a Path Item has no fixed field for — `COPY`, `PURGE`,
 * `LINK` — to an operation, and that operation is as much the contract as one under `get`. The differ
 * walked the fixed fields alone, so a custom operation's every edit, and its removal, reported no change
 * while the node index listed it. The answer for a custom operation is stated here against the answer for
 * the same operation under `get`, which the rest of the suite already holds to the contract.
 *
 * Documents are read the way an upload is, through the reader.
 */

/**
 * Two documents, compared as the reader hands them over.
 *
 * @param  array<string, mixed>  $old
 * @param  array<string, mixed>  $new
 */
$diff = static fn (array $old, array $new): Changeset => (new DocumentDiffer)->diff(
    (new DocumentReader)->read((string) json_encode($old))->uir(),
    (new DocumentReader)->read((string) json_encode($new))->uir(),
);

/**
 * One path item holding one operation — under `get`, or under the custom method $at — with a parameter
 * the item shares, after $edit has had the operation and the item. Ids on the operation, its parameters
 * and its responses where $ids, so the pair is made by identity; none, and it is made by method and path.
 *
 * @return array<string, mixed>
 */
$document = static function (string $at, bool $ids, ?Closure $edit = null, bool $absent = false): array {
    $id = static fn (string $id): array => $ids ? ['x-docuccino' => ['id' => $id]] : [];

    $operation = [
        ...$id('op:v1:aaaaaaaaaaaaaaaa'),
        'operationId' => 'forms.copy',
        'summary' => 'Copy a form',
        'tags' => ['Forms'],
        'parameters' => [
            [...$id('par:v1:bbbbbbbbbbbbbbbb'), 'name' => 'form', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            [...$id('par:v1:cccccccccccccccc'), 'name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['draft', 'published']]],
        ],
        'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]]]],
        'responses' => [
            '200' => [...$id('res:v1:dddddddddddddddd'), 'description' => 'The copy', 'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string']],
            ]]]],
            '404' => ['description' => 'Not found'],
        ],
    ];

    $item = ['parameters' => [[...$id('par:v1:eeeeeeeeeeeeeeee'), 'name' => 'X-Tenant', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']]]];

    if ($edit !== null) {
        $edit($operation, $item);
    }

    if (! $absent) {
        $item += $at === 'get' ? ['get' => $operation] : ['additionalOperations' => [$at => $operation]];
    }

    return [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms', 'version' => '1'],
        'paths' => ['/forms/{form}' => $item],
        // An identified node besides the operation, so a side the operation is absent from still pairs by
        // identity.
        'components' => ['schemas' => ['Form' => [...$id('sch:v1:ffffffffffffffff'), 'type' => 'object']]],
    ];
};

/**
 * What a change says, in the order the changeset lists it.
 *
 * @return list<array<string, mixed>>
 */
$said = static fn (Changeset $changeset): array => array_map(static fn (Change $change): array => $change->toArray(), $changeset->changes);

it('diffs a custom operation as it diffs the same operation under get', function (Closure $edit, array $codes, bool $ids) use ($diff, $document, $said): void {
    $changes = static fn (string $at): Changeset => $diff($document($at, $ids), $document($at, $ids, $edit));

    $fixed = $said($changes('get'));
    $custom = $said($changes('COPY'));

    // Under `get`, which the rest of the suite holds to the contract: the edit is what each row says it is.
    expect(array_combine(array_column($fixed, 'code'), array_column($fixed, 'breaking')))->toEqualCanonicalizing($codes);

    // A custom method's are the same changes, flagged the same way, at the same nodes: its path names the
    // method it sends where `get`'s names `GET`, and with no identity to pair on its key is where it is
    // written. Identities are the operation's own either way.
    $expected = array_map(static function (array $change) use ($ids): array {
        $change['path'] = 'COPY'.substr($change['path'], strlen('GET'));
        if (! $ids) {
            $change['id'] = '_additionalOperations/COPY'.substr($change['id'], strlen('_GET'));
        }

        return $change;
    }, $fixed);

    expect($custom)->toBe($expected);
})->with([
    'a response re-coded from 200 to 201' => [static function (array &$operation): void {
        $operation['responses']['201'] = $operation['responses']['200'];
        unset($operation['responses']['200']);
    }, ['response.removed' => true, 'response.added' => false]],
    'the summary rewritten' => [static function (array &$operation): void {
        $operation['summary'] = 'Duplicate a form';
    }, ['operation.summary-changed' => false]],
    'the operationId renamed' => [static function (array &$operation): void {
        $operation['operationId'] = 'forms.duplicate';
    }, ['operation.operationId-changed' => false]],
    'a description written' => [static function (array &$operation): void {
        $operation['description'] = 'Copies the form and its fields.';
    }, ['operation.description-changed' => false]],
    'deprecated' => [static function (array &$operation): void {
        $operation['deprecated'] = true;
    }, ['operation.deprecated-changed' => false]],
    'retagged' => [static function (array &$operation): void {
        $operation['tags'] = ['Copies'];
    }, ['operation.tags-changed' => false]],
    'a security requirement added' => [static function (array &$operation): void {
        $operation['security'] = [['apiKey' => []]];
    }, ['operation.security-added' => true]],
    'a query parameter becoming required' => [static function (array &$operation): void {
        $operation['parameters'][1]['required'] = true;
    }, ['parameter.became-required' => true]],
    'a required parameter added' => [static function (array &$operation): void {
        $operation['parameters'][] = ['name' => 'into', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']];
    }, ['parameter.added-required' => true]],
    'a parameter removed' => [static function (array &$operation): void {
        array_pop($operation['parameters']);
    }, ['parameter.removed' => true]],
    'a parameter the path item shares becoming required' => [static function (array &$operation, array &$item): void {
        $item['parameters'][0]['required'] = true;
    }, ['parameter.became-required' => true]],
    'a response dropped' => [static function (array &$operation): void {
        unset($operation['responses']['404']);
    }, ['response.removed' => true]],
    'a response media type dropped' => [static function (array &$operation): void {
        $operation['responses']['200']['content'] = ['text/csv' => ['schema' => ['type' => 'string']]];
    }, ['response.content-removed' => true, 'response.content-added' => false]],
    'a property gone from the response body' => [static function (array &$operation): void {
        unset($operation['responses']['200']['content']['application/json']['schema']['properties']['title']);
    }, ['schema.property-removed' => true]],
    'a request property narrowed' => [static function (array &$operation): void {
        $operation['requestBody']['content']['application/json']['schema']['properties']['title']['maxLength'] = 80;
    }, ['schema.refinement-narrowed' => true]],
    'the request body behind a pointer the document does not declare' => [static function (array &$operation): void {
        $operation['requestBody'] = ['$ref' => '#/components/requestBodies/Copy'];
    }, ['requestBody.unresolved-ref' => true]],
])->with(['paired by identity' => [true], 'paired by method and path' => [false]]);

it('reports a custom operation added or removed as it reports one under get', function (bool $added, string $code, bool $breaking, bool $ids) use ($diff, $document): void {
    $changes = static function (string $at) use ($diff, $document, $added, $ids): array {
        [$old, $new] = [$document($at, $ids, absent: true), $document($at, $ids)];

        return $added ? $diff($old, $new)->changes : $diff($new, $old)->changes;
    };

    $fixed = $changes('get');
    $custom = $changes('COPY');

    expect($fixed)->toHaveCount(1)
        ->and($fixed[0]->code)->toBe($code)
        ->and($fixed[0]->breaking)->toBe($breaking)
        ->and($custom)->toHaveCount(1)
        ->and($custom[0]->code)->toBe($code)
        ->and($custom[0]->breaking)->toBe($breaking)
        ->and($custom[0]->path)->toBe('COPY /forms/{form}')
        ->and($custom[0]->id)->toBe($ids ? 'op:v1:aaaaaaaaaaaaaaaa' : '_additionalOperations/COPY /forms/{form}');
})->with([
    'added' => [true, 'operation.added', false],
    'removed' => [false, 'operation.removed', true],
])->with(['paired by identity' => [true], 'paired by method and path' => [false]]);

it('pairs a custom operation by its identity across a renamed path parameter', function () use ($diff, $document): void {
    $old = $document('COPY', true);
    $new = $old;
    $new['paths'] = ['/forms/{id}' => $old['paths']['/forms/{form}']];
    $new['paths']['/forms/{id}']['additionalOperations']['COPY']['parameters'][0]['name'] = 'id';
    $new['paths']['/forms/{id}']['additionalOperations']['COPY']['summary'] = 'Duplicate a form';

    $changeset = $diff($old, $new);

    // Paired by identity, the operation under its new path is the one under its old: the summary is what
    // changed, and nothing was removed or added, as it would be by method and path.
    expect($changeset->pairing)->toBe(Pairing::Identity)
        ->and(array_map(static fn (Change $change): array => [$change->code, $change->id, $change->path], $changeset->changes))
        ->toBe([['operation.summary-changed', 'op:v1:aaaaaaaaaaaaaaaa', 'COPY /forms/{id}']]);
});

it('pairs by identity where only custom operations carry one', function () use ($diff): void {
    // The algorithm version a document's ids are minted by is read off its operations first, so a document
    // whose only operations are custom ones paired structurally and compared nothing by identity.
    $doc = static fn (string $id): array => [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Cache', 'version' => '1'],
        'paths' => ['/cache' => ['additionalOperations' => ['PURGE' => [
            'x-docuccino' => ['id' => $id],
            'summary' => 'Purge',
            'responses' => ['204' => ['description' => 'Purged']],
        ]]]],
    ];

    expect($diff($doc('op:v1:aaaaaaaaaaaaaaaa'), $doc('op:v1:aaaaaaaaaaaaaaaa'))->pairing)->toBe(Pairing::Identity);

    try {
        $diff($doc('op:v1:aaaaaaaaaaaaaaaa'), $doc('op:v2:aaaaaaaaaaaaaaaa'));
        $this->fail('Documents of two identity algorithms were compared.');
    } catch (IncomparableDocumentsException $exception) {
        expect($exception->failure)->toBe(DiffFailure::AlgorithmMismatch);
    }
});

it('counts a custom operation\'s id when judging overlap', function () use ($diff): void {
    $doc = static fn (string $copy, string $move): array => [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms', 'version' => '1'],
        'paths' => ['/forms' => ['additionalOperations' => [
            'COPY' => ['x-docuccino' => ['id' => $copy], 'responses' => ['204' => ['description' => 'Copied']]],
            'MOVE' => ['x-docuccino' => ['id' => $move], 'responses' => ['204' => ['description' => 'Moved']]],
        ]]],
    ];

    expect($diff($doc('op:v1:1111111111111111', 'op:v1:2222222222222222'), $doc('op:v1:3333333333333333', 'op:v1:4444444444444444'))->disjointIdentities)->toBe(['operation'])
        ->and($diff($doc('op:v1:1111111111111111', 'op:v1:2222222222222222'), $doc('op:v1:1111111111111111', 'op:v1:4444444444444444'))->disjointIdentities)->toBe([]);
});

it('reads a body only a custom operation sends as written by the client', function (string $at) use ($diff): void {
    // A component nothing but a request reaches compares with request semantics, so a value added to its
    // enum is a widening the server accepts rather than a value a client must handle.
    $operation = ['requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CopyRequest']]]], 'responses' => ['204' => ['description' => 'Copied']]];
    $doc = static fn (array $states): array => [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms', 'version' => '1'],
        'paths' => ['/forms' => $at === 'put' ? ['put' => $operation] : ['additionalOperations' => [$at => $operation]]],
        'components' => ['schemas' => ['CopyRequest' => ['type' => 'object', 'properties' => ['state' => ['type' => 'string', 'enum' => $states]]]]],
    ];

    $changes = $diff($doc(['draft']), $doc(['draft', 'final']))->changes;

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->code)->toBe('schema.enum-value-added')
        ->and($changes[0]->breaking)->toBeFalse();
})->with(['under put' => ['put'], 'under a custom method' => ['COPY']]);

it('keeps a custom method apart from a fixed one it spells in another case', function (string $edited, string $path, string $id) use ($diff): void {
    // A custom method is sent as its key is written, so a custom `get` or `Get` is a method of its own beside
    // the fixed `get`, which is sent as `GET`. OpenAPI forbids `GET` as a key, because `get` states it, but
    // nothing stops an artifact writing one: it sends the fixed operation's request line from somewhere
    // else. Keyed by request line, two operations would claim one key and an edit to either would read as
    // both replaced; keyed by where each is written, every edit reads as the edit it is.
    $operation = static fn (string $summary): array => ['summary' => $summary, 'responses' => ['204' => ['description' => 'Done']]];
    $doc = static fn (array $summaries): array => [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms', 'version' => '1'],
        'paths' => ['/forms' => [
            'get' => $operation($summaries['fixed get']),
            'additionalOperations' => [
                'get' => $operation($summaries['custom get']),
                'Get' => $operation($summaries['custom Get']),
                'GET' => $operation($summaries['custom GET']),
            ],
        ]],
    ];
    $summaries = ['fixed get' => 'a', 'custom get' => 'b', 'custom Get' => 'c', 'custom GET' => 'd'];

    $changes = $diff($doc($summaries), $doc([$edited => 'edited'] + $summaries))->changes;

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->code)->toBe('operation.summary-changed')
        ->and($changes[0]->path)->toBe($path)
        ->and($changes[0]->id)->toBe($id)
        ->and($changes[0]->fields[0]->old)->toBe($summaries[$edited]);

    // Gone, each reads as itself gone, under a key none of the others holds.
    $removed = $diff($doc($summaries), ['openapi' => '3.2.0', 'info' => ['title' => 'Forms', 'version' => '1'], 'paths' => (object) []])->changes;

    expect(array_map(static fn (Change $change): string => $change->id, $removed))->toEqualCanonicalizing([
        '_GET /forms', '_additionalOperations/get /forms', '_additionalOperations/Get /forms', '_additionalOperations/GET /forms',
    ])->and(array_column(array_map(static fn (Change $change): array => $change->toArray(), $removed), 'code'))->each->toBe('operation.removed');
})->with([
    'the fixed get' => ['fixed get', 'GET /forms', '_GET /forms'],
    'a custom get' => ['custom get', 'get /forms', '_additionalOperations/get /forms'],
    'a custom Get' => ['custom Get', 'Get /forms', '_additionalOperations/Get /forms'],
    'a custom GET, which OpenAPI forbids' => ['custom GET', 'GET /forms', '_additionalOperations/GET /forms'],
]);

it('points every change to a custom operation at the node the index lists for it', function (Closure $edit, int $expected) use ($diff): void {
    $id = static fn (string $id): array => ['x-docuccino' => ['id' => $id]];
    $operation = static fn (string $n): array => [
        ...$id("op:v1:{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}"),
        'summary' => 'Copy',
        'parameters' => [[...$id("par:v1:{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}a"), 'name' => 'into', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']]],
        'responses' => ['200' => [...$id("res:v1:{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}{$n}b"), 'description' => 'Copied']],
    ];
    $old = [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms', 'version' => '1'],
        'paths' => ['/forms' => ['additionalOperations' => ['COPY' => $operation('1'), 'C~P' => $operation('2')]]],
        'webhooks' => ['formCopied' => ['additionalOperations' => ['COPY' => $operation('3')]]],
    ];
    $new = $edit($old);

    $changes = $diff($old, $new)->changes;

    // Where a node was removed it is the OLD document's index that lists it.
    $nodes = [
        ...(new DocumentReader)->read((string) json_encode($old))->nodes(),
        ...(new DocumentReader)->read((string) json_encode($new))->nodes(),
    ];
    $listed = static fn (string $identity): array => array_values(array_unique(array_map(
        static fn (DocumentNode $node): string => $node->kind->value.' '.$node->pointer,
        array_filter($nodes, static fn (DocumentNode $node): bool => $node->identity === $identity),
    )));

    expect($changes)->toHaveCount($expected);

    foreach ($changes as $change) {
        $node = $listed($change->id);

        expect($node)->toHaveCount(1)
            ->and($node[0])->toMatch('#^'.preg_quote($change->target->value, '#').' /(paths/~1forms|webhooks/formCopied)/additionalOperations/(COPY|C~0P)(/|$)#');
    }
})->with([
    'a summary rewritten' => [static function (array $doc): array {
        $doc['paths']['/forms']['additionalOperations']['COPY']['summary'] = 'Duplicate';

        return $doc;
    }, 1],
    'a parameter becoming required, under a method the pointer escapes' => [static function (array $doc): array {
        $doc['paths']['/forms']['additionalOperations']['C~P']['parameters'][0]['required'] = true;

        return $doc;
    }, 1],
    'a response re-coded under a webhook' => [static function (array $doc): array {
        $doc['webhooks']['formCopied']['additionalOperations']['COPY']['responses'] = ['204' => ['x-docuccino' => ['id' => 'res:v1:333333333333333c'], 'description' => 'Copied']];

        return $doc;
    }, 2],
    'an operation removed' => [static function (array $doc): array {
        unset($doc['paths']['/forms']['additionalOperations']['COPY']);

        return $doc;
    }, 1],
]);

it('keys a custom operation it cannot pair by identity where the index points', function () use ($diff): void {
    // With no identity, a custom operation is keyed by where it is written below its path item, spelt as the
    // index's pointer spells it — `~` included — so the key names the node the index lists.
    $operation = ['responses' => ['204' => ['description' => 'Done']]];
    $old = [
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms', 'version' => '1'],
        'paths' => ['/forms' => ['additionalOperations' => ['COPY' => $operation, 'C~P' => $operation, 'get' => $operation]]],
    ];

    $removed = $diff($old, ['openapi' => '3.2.0', 'info' => ['title' => 'Forms', 'version' => '1'], 'paths' => (object) []])->changes;
    $pointers = array_map(
        static fn (DocumentNode $node): string => $node->pointer,
        array_filter((new DocumentReader)->read((string) json_encode($old))->nodes(), static fn (DocumentNode $node): bool => $node->kind === NodeKind::Operation),
    );

    expect(array_map(static fn (Change $change): string => $change->id, $removed))
        ->toEqualCanonicalizing(array_map(static fn (string $pointer): string => '_'.substr($pointer, strlen('/paths/~1forms/')).' /forms', $pointers))
        ->and($pointers)->toHaveCount(3);
});

it('removes, operation for operation, what the index lists for every fixture', function () use ($diff): void {
    // The differ and the node index are two readers of which operations a document states, and every
    // fixture is held to their agreeing: emptied of its paths and webhooks, a document reports one removal
    // per operation the index lists, of the method the index names and with the identity it lists. A walk
    // that misses a member holding operations — `webhooks` once, `additionalOperations` until now — makes
    // the counts part.
    $method = static fn (string $spelt): string => explode(' ', $spelt, 2)[0];
    $compared = 0;
    $custom = 0;

    foreach (openApiDocuments() as $path) {
        $text = (string) file_get_contents($path);
        $read = (new DocumentReader)->read($text);
        /** @var array<string, mixed> $document */
        $document = json_decode($read->json(), true, 4096);
        $emptied = ['paths' => (object) [], 'webhooks' => (object) []] + $document;

        $operations = array_values(array_filter($read->nodes(), static fn (DocumentNode $node): bool => $node->kind === NodeKind::Operation));
        $changeset = $diff($document, $emptied);
        $removed = array_values(array_filter($changeset->changes, static fn (Change $change): bool => $change->code === 'operation.removed'));

        $byIdentity = $changeset->pairing === Pairing::Identity;

        // By identity, an operation carrying one is keyed by it, and one carrying none by where it is written.
        expect(array_map(static fn (Change $change): string => $method($change->path).($byIdentity && ! str_starts_with($change->id, '_') ? ' '.$change->id : ''), $removed))
            ->toEqualCanonicalizing(array_map(static fn (DocumentNode $node): string => $method($node->key).($byIdentity && $node->identity !== null ? ' '.$node->identity : ''), $operations), basename($path));

        $compared++;
        $custom += count(array_filter($operations, static fn (DocumentNode $node): bool => str_contains($node->pointer, '/additionalOperations/')));
    }

    // A scan that compared nothing would pass; the tree holds over a hundred documents, and one fixture
    // carries a custom operation.
    expect($compared)->toBeGreaterThan(50)
        ->and($custom)->toBeGreaterThanOrEqual(1);
});
