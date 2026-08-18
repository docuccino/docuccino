<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\ContractMessages;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;

it('names the operation, the failing member and the producer that wrote the schema', function (): void {
    $exchange = contractExchange('GET', '/api/invoices/42', responseBody: '{"reference":"INV-1","total":"12.50"}');
    $result = checkContract($exchange);

    $message = ContractMessages::exchange($result->operation, $exchange, $result);

    expect($message)
        ->toContain('GET /api/invoices/42 does not match the documented contract.')
        ->toContain('operation  GET /api/invoices/{invoice}  op:v1:aaaainvoiceshow')
        ->toContain('status     200')
        ->toContain('the response body at /total')
        ->toContain('must match the type: number')
        ->toContain('schema   /components/schemas/Invoice/properties/total')
        ->toContain('from     integration:eloquent (integration) — app/Models/Invoice.php:31 in App\Models\Invoice::$total');
});

it('stops listing violations before the list stops being read', function (): void {
    $properties = [];
    $body = [];
    foreach (range(1, 15) as $n) {
        $properties['field'.$n] = ['type' => 'string'];
        $body['field'.$n] = $n;
    }

    $index = contractIndex(static function (array $document) use ($properties): array {
        $document['components']['schemas']['Invoice']['properties'] = $properties;
        $document['components']['schemas']['Invoice']['required'] = [];

        return $document;
    });

    $exchange = contractExchange('GET', '/api/invoices/42', responseBody: (string) json_encode($body));
    $result = (new ContractChecker($index))->check($exchange);

    expect(ContractMessages::exchange($result->operation, $exchange, $result))->toContain('… and 5 more.');
});

it('tells a reader which paths the contract does document for the method they tried', function (): void {
    $message = ContractMessages::undocumented(
        contractExchange('GET', '/api/credits'),
        contractIndex(),
        'Rebuild it: php artisan docuccino:export',
    );

    expect($message)
        ->toContain('GET /api/credits is not documented.')
        ->toContain('The contract documents these GET paths:')
        ->toContain('/api/invoices/{invoice}')
        ->toContain('Rebuild it: php artisan docuccino:export');
});

it('says plainly when the contract documents no such method at all', function (): void {
    expect(ContractMessages::undocumented(contractExchange('PATCH', '/api/invoices'), contractIndex()))
        ->toContain('The contract documents no PATCH operation at all.');
});

it('caps the list of documented paths', function (): void {
    $index = contractIndex(static function (array $document): array {
        foreach (range(1, 12) as $n) {
            $document['paths']['/api/thing'.$n] = ['get' => ['responses' => []]];
        }

        return $document;
    });

    expect(ContractMessages::undocumented(contractExchange('GET', '/api/credits'), $index))->toContain('… and 8 more.');
});

it('counts the examples it checked as well as the ones that lied', function (): void {
    $report = (new ExampleAudit(contractIndex(static function (array $document): array {
        $document['components']['schemas']['Invoice']['properties']['total']['example'] = 'lots';

        return $document;
    })))->run();

    expect(ContractMessages::examples($report))
        ->toContain('1 of 3 documented examples do not match the schema beside them.')
        ->toContain('components/schemas/Invoice')
        ->toContain('at /components/schemas/Invoice/properties/total/example')
        ->toContain('from     integration:eloquent (integration) — app/Models/Invoice.php:31');
});

it('renders a breaking changeset the way the diff command does, and adds who wrote it', function (): void {
    $old = loadFixture('contract.uir.json');
    $new = $old;
    // A removed response is a breaking change the differ classifies on its own.
    unset($new['paths']['/api/invoices/{invoice}']['get']['responses']['200']);

    $changeset = (new DocumentDiffer)->diff(UirDocument::fromArray($old), UirDocument::fromArray($new));

    $message = ContractMessages::breaking(
        $changeset,
        ContractIndex::fromArray($new),
        ContractIndex::fromArray($old),
        'Re-export it: php artisan docuccino:export',
    );

    expect($changeset->isBreaking())->toBeTrue()
        ->and($message)
        ->toContain('The current document makes 1 breaking change to the committed contract.')
        ->toContain('BREAKING')
        ->toContain('Re-export it: php artisan docuccino:export');
});

it('reads the provenance of a broken node off whichever side still has it', function (): void {
    $old = loadFixture('contract.uir.json');
    $new = $old;
    $new['components']['schemas']['Invoice']['properties']['total']['type'] = 'string';

    $changeset = (new DocumentDiffer)->diff(UirDocument::fromArray($old), UirDocument::fromArray($new));

    expect(ContractMessages::breaking($changeset, ContractIndex::fromArray($new), ContractIndex::fromArray($old)))
        ->toContain('Where those changes came from:')
        ->toContain('integration:eloquent (integration) — app/Models/Invoice.php:22');
});

it('leaves the provenance block out when neither side recorded any', function (): void {
    $old = stripDocuccinoRecursive(loadFixture('contract.uir.json'));
    $new = $old;
    unset($new['paths']['/api/invoices/{invoice}']['get']['responses']['200']);

    $changeset = (new DocumentDiffer)->diff(UirDocument::fromArray($old), UirDocument::fromArray($new));

    expect(ContractMessages::breaking($changeset, ContractIndex::fromArray($new), ContractIndex::fromArray($old)))
        ->not->toContain('Where those changes came from:');
});

it('says which file is stale and what changed in it', function (): void {
    $old = loadFixture('contract.uir.json');
    $new = $old;
    $new['paths']['/api/invoices/{invoice}']['get']['summary'] = 'Show one invoice.';

    $changeset = (new DocumentDiffer)->diff(UirDocument::fromArray($old), UirDocument::fromArray($new));

    expect(ContractMessages::stale('docs/uir.json', $changeset, ContractIndex::fromArray($new), ContractIndex::fromArray($old), 'Regenerate it.'))
        ->toContain('docs/uir.json is out of date.')
        ->toContain('What changed since it was written:')
        ->toContain('Regenerate it.');
});

it('distinguishes a byte-level difference from a contract change', function (): void {
    expect(ContractMessages::stale('docs/uir.json', new Changeset, contractIndex(), contractIndex()))
        ->toContain('The contract itself is unchanged — the artifact differs only in bytes the emitters');
});

it('admits when a stale artifact cannot be compared semantically at all', function (): void {
    expect(ContractMessages::stale('docs/collection.postman.json', null, null, null, 'Regenerate it.'))
        ->toContain('docs/collection.postman.json is out of date.')
        ->toContain('not a document that can be')
        ->toContain('Regenerate it.');
});
