<?php

declare(strict_types=1);

use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\RecordedExample;

/**
 * The recording model: which of a suite's many responses gets published, and — the determinism half —
 * when a committed body is left exactly as it is.
 */
it('publishes the body that fills in the most of the contract', function (): void {
    $sparse = RecordedExample::of('200', 'application/json', ['id' => 1, 'note' => null]);
    $full = RecordedExample::of('200', 'application/json', ['id' => 1, 'note' => 'paid in full']);

    expect($full->outranks($sparse))->toBeTrue()
        ->and($sparse->outranks($full))->toBeFalse();
});

it('prefers the shorter of two bodies that show the same amount', function (): void {
    $long = RecordedExample::of('200', 'application/json', ['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaa']);
    $short = RecordedExample::of('200', 'application/json', ['id' => 'a']);

    expect($short->outranks($long))->toBeTrue();
});

it('ranks two equally good bodies the same way whichever arrives first', function (): void {
    $a = RecordedExample::of('200', 'application/json', ['id' => 'aaa']);
    $b = RecordedExample::of('200', 'application/json', ['id' => 'bbb']);

    expect($a->outranks($b))->toBeTrue()
        ->and($b->outranks($a))->toBeFalse();
});

it('leaves a committed body alone while its shape is unchanged', function (): void {
    $committed = RecordedExample::of('200', 'application/json', ['id' => 1, 'created_at' => '2026-01-01T00:00:00Z']);
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [$committed]);

    $rerecorded = $recording->with(
        RecordedExample::of('200', 'application/json', ['id' => 9, 'created_at' => '2026-08-18T09:14:02Z']),
    );

    expect($rerecorded->toArray())->toBe($recording->toArray());
});

it('replaces a committed body when the shape really did move', function (): void {
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [
        RecordedExample::of('200', 'application/json', ['id' => 1]),
    ]);

    $rerecorded = $recording->with(RecordedExample::of('200', 'application/json', ['id' => 1, 'total' => 10]));

    expect($rerecorded->find('200', 'application/json')?->body)->toBe(['id' => 1, 'total' => 10])
        ->and($rerecorded->responses)->toHaveCount(1);
});

it('keeps a response this run never exercised', function (): void {
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [
        RecordedExample::of('404', 'application/json', ['message' => 'Not found.']),
    ]);

    $rerecorded = $recording->with(RecordedExample::of('200', 'application/json', ['id' => 1]));

    expect(array_map(static fn (RecordedExample $e): string => $e->key(), $rerecorded->responses))
        ->toBe(['200 application/json', '404 application/json']);
});

it('orders its responses by status and media type, never by arrival', function (): void {
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /x', [
        RecordedExample::of('404', 'application/problem+json', []),
        RecordedExample::of('200', 'application/json', []),
        RecordedExample::of('200', 'application/hal+json', []),
    ]);

    expect(array_map(static fn (RecordedExample $e): string => $e->key(), $recording->responses))
        ->toBe(['200 application/hal+json', '200 application/json', '404 application/problem+json']);
});

it('round-trips through its own array form', function (): void {
    $recording = ExampleRecording::of('op:v1:abcdefgh12345678', 'GET /api/invoices', [
        RecordedExample::of('200', 'application/json', ['id' => 1]),
    ]);

    expect(ExampleRecording::fromArray($recording->toArray())?->toArray())->toBe($recording->toArray());
});

it('refuses anything that is not a recording it understands', function (array $data): void {
    expect(ExampleRecording::fromArray($data))->toBeNull();
})->with([
    'no format marker' => [['operation' => 'op:v1:abcdefgh12345678', 'responses' => []]],
    'a format from the future' => [['docuccino' => 'recording/9', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => []]],
    'no operation' => [['docuccino' => 'recording/1', 'responses' => []]],
    'an empty operation' => [['docuccino' => 'recording/1', 'operation' => '', 'responses' => []]],
    'no responses' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678']],
    'responses that are not a list' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => 'many']],
    'a response that is not an object' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => ['x']]],
    'a response with no status' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => [['mediaType' => 'application/json', 'body' => []]]]],
    'a response with no media type' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => [['status' => '200', 'body' => []]]]],
    'a response with no body' => [['docuccino' => 'recording/1', 'operation' => 'op:v1:abcdefgh12345678', 'responses' => [['status' => '200', 'mediaType' => 'application/json']]]],
]);

it('takes a null body as a body, since a response may legitimately be null', function (): void {
    $recording = ExampleRecording::fromArray([
        'docuccino' => 'recording/1',
        'operation' => 'op:v1:abcdefgh12345678',
        'responses' => [['status' => '200', 'mediaType' => 'application/json', 'body' => null]],
    ]);

    expect($recording?->responses[0]->body)->toBeNull();
});

it('takes a missing endpoint label as no label rather than as a broken file', function (): void {
    $recording = ExampleRecording::fromArray([
        'docuccino' => 'recording/1',
        'operation' => 'op:v1:abcdefgh12345678',
        'responses' => [],
    ]);

    expect($recording?->endpoint)->toBe('');
});
