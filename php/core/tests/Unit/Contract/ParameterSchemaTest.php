<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractParameter;
use Docuccino\Core\Contract\ParameterSchema;
use Docuccino\Core\Contract\ParameterSchemaKind;

/**
 * The three-way answer a documented parameter makes about what its values can be held to.
 *
 * A nullable schema said "no" three different ways — no member, a member no validator can take, and a
 * `content` object documented instead — and the boolean beside it that told the last two apart was
 * opt-in. Every shape a document can put there is a row here, and each names its KIND, so a reader
 * that starts answering "absent" where the document wrote `content` fails rather than quietly
 * changing which sentence a suite is warned with.
 */
it('reads every shape a document can put where a parameter schema goes', function (array $definition, ParameterSchemaKind $kind, ?array $node): void {
    $schema = ParameterSchema::of($definition);

    expect($schema->kind)->toBe($kind)
        ->and($schema->node)->toBe($node);
})->with([
    'a schema' => [['schema' => ['type' => 'integer']], ParameterSchemaKind::Checkable, ['type' => 'integer']],
    // `[]` is how associative decoding spells `{}`, and the empty schema accepts everything.
    'the empty schema' => [['schema' => []], ParameterSchemaKind::Checkable, []],
    // `true` and `false` ARE schemas; there are simply no keywords on them to read a wire value back
    // against, which is the same nothing `{}` offers. The validator gets the node by pointer regardless.
    'the boolean schema true' => [['schema' => true], ParameterSchemaKind::Checkable, null],
    'the boolean schema false' => [['schema' => false], ParameterSchemaKind::Checkable, null],
    'a content object' => [['content' => ['application/json' => ['schema' => ['type' => 'integer']]]], ParameterSchemaKind::Content, null],
    // Presence is not the question on either member: a `content` that is not a map of media types is
    // not the content object the note would name, any more than `schema: 'integer'` is a schema.
    'a content member that is not an object' => [['content' => 'application/json'], ParameterSchemaKind::Absent, null],
    'a type name where a schema belongs' => [['schema' => 'integer'], ParameterSchemaKind::Absent, null],
    'a number where a schema belongs' => [['schema' => 42], ParameterSchemaKind::Absent, null],
    'an explicit null' => [['schema' => null], ParameterSchemaKind::Absent, null],
    'neither member' => [['name' => 'page', 'in' => 'query'], ParameterSchemaKind::Absent, null],
    // A schema wins over a content object documented beside it: it is the one this check can read.
    'both, which is a schema' => [
        ['schema' => ['type' => 'string'], 'content' => ['application/json' => []]],
        ParameterSchemaKind::Checkable,
        ['type' => 'string'],
    ],
]);

it('is what a documented parameter answers with, not a schema beside a flag', function (): void {
    $parameter = new ContractParameter('page', 'query', false, ['schema' => ['type' => 'integer']], ['paths', '/a', 'get', 'parameters', '0']);

    expect($parameter->schema())->toBeInstanceOf(ParameterSchema::class)
        ->and($parameter->schema()->kind)->toBe(ParameterSchemaKind::Checkable)
        ->and($parameter->schema()->node)->toBe(['type' => 'integer'])
        ->and($parameter->schemaSegments())->toBe(['paths', '/a', 'get', 'parameters', '0', 'schema']);
});

/**
 * The kinds are a closed set and the checker matches over all of them, so a case added here without a
 * sentence written for it is a build failure rather than a note nobody sees. This guards the OTHER
 * direction: a case REMOVED, or renamed, silently shortens every dataset that lists them.
 */
it('names every kind a parameter schema can be', function (): void {
    expect(array_map(static fn (ParameterSchemaKind $kind): string => $kind->name, ParameterSchemaKind::cases()))
        ->toBe(['Checkable', 'Content', 'Absent']);
});
