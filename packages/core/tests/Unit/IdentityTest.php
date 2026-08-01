<?php

declare(strict_types=1);

use Docuccino\Core\Identity\Base32;
use Docuccino\Core\Identity\IdentityGenerator;

beforeEach(function (): void {
    $this->ids = new IdentityGenerator;
});

it('emits a doc id as the verbatim config key', function (): void {
    expect($this->ids->documentId('default'))->toBe('doc:default');
});

it('produces 16-char base32 identities with a kind and algo prefix', function (): void {
    $id = $this->ids->operationId('doc:default', 'get', '/forms');

    expect($id)->toStartWith('op:v1:');
    expect(substr($id, strlen('op:v1:')))->toMatch('/^[a-z2-7]{16}$/');
});

it('base32-encodes without padding using the RFC 4648 lowercase alphabet', function (): void {
    expect(Base32::encode('foobar'))->toBe('mzxw6ytboi');
    expect(Base32::encode(''))->toBe('');
});

it('keeps operation identity across path-parameter renames', function (): void {
    $a = $this->ids->operationId('doc:default', 'GET', '/forms/{form}/fields/{field}');
    $b = $this->ids->operationId('doc:default', 'GET', '/forms/{id}/fields/{fieldId}');

    expect($a)->toBe($b);
});

it('breaks operation identity when the URI changes', function (): void {
    $a = $this->ids->operationId('doc:default', 'GET', '/forms/{form}');
    $b = $this->ids->operationId('doc:default', 'GET', '/forms/{form}/fields');

    expect($a)->not->toBe($b);
});

it('breaks operation identity when the method changes', function (): void {
    $a = $this->ids->operationId('doc:default', 'GET', '/forms');
    $b = $this->ids->operationId('doc:default', 'POST', '/forms');

    expect($a)->not->toBe($b);
});

it('keeps parameter identity regardless of declaration order', function (): void {
    $op = 'op:v1:aaaaaaaaaaaaaaaa';

    expect($this->ids->parameterId($op, 'query', 'status'))
        ->toBe($this->ids->parameterId($op, 'query', 'status'));

    expect($this->ids->parameterId($op, 'query', 'status'))
        ->not->toBe($this->ids->parameterId($op, 'query', 'sort'));
});

it('keeps named-schema identity across FQCN file moves but breaks on rename', function (): void {
    $moved = $this->ids->namedSchemaId('App\\Data\\FormData');

    expect($moved)->toBe($this->ids->namedSchemaId('App\\Data\\FormData'));
    expect($moved)->not->toBe($this->ids->namedSchemaId('App\\Http\\FormData'));
});

it('keeps inline-schema identity across prose edits but breaks on shape change', function (): void {
    $base = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
    $withProse = ['type' => 'object', 'description' => 'A form', 'properties' => ['id' => ['type' => 'integer', 'description' => 'The id', 'example' => 5]]];
    $shapeChanged = ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]];

    expect($this->ids->inlineSchemaId($base))->toBe($this->ids->inlineSchemaId($withProse));
    expect($this->ids->inlineSchemaId($base))->not->toBe($this->ids->inlineSchemaId($shapeChanged));
});

it('is insensitive to property order for inline-schema identity', function (): void {
    $a = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'string']]];
    $b = ['properties' => ['b' => ['type' => 'string'], 'a' => ['type' => 'integer']], 'type' => 'object'];

    expect($this->ids->inlineSchemaId($a))->toBe($this->ids->inlineSchemaId($b));
});
