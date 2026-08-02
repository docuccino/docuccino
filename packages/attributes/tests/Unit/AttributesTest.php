<?php

declare(strict_types=1);

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\CaseDescription;
use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\DeprecatedOperation;
use Docuccino\Attributes\DescriptionFromFile;
use Docuccino\Attributes\Example;
use Docuccino\Attributes\ExcludeFromDocs;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\Hidden;
use Docuccino\Attributes\IgnoreParam;
use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\InDocs;
use Docuccino\Attributes\Internal;
use Docuccino\Attributes\OperationId;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\ResponseHeader;
use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;
use Docuccino\Attributes\Unauthenticated;

/**
 * A fixture carrying repeated + stacked attributes, reflected below to prove repeatability is
 * legal (a non-repeatable attribute declared twice would throw at getAttributes()).
 */
final class AttributesFixture
{
    #[Response(200, description: 'ok')]
    #[Response(404, description: 'missing')]
    #[QueryParameter('page')]
    #[QueryParameter('per_page')]
    public function index(): void {}

    #[Hidden('secret', 'token')]
    #[InDocs('public', 'internal')]
    public function meta(): void {}
}

/**
 * The full v1 attribute set with its expected `#[Attribute]` flag bitmask.
 *
 * @return array<string, array{class-string, int}>
 */
function attributeCatalogue(): array
{
    $classFn = Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION;

    return [
        'Response' => [Response::class, $classFn | Attribute::IS_REPEATABLE],
        'Hidden' => [Hidden::class, Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY],
        'QueryParameter' => [QueryParameter::class, $classFn | Attribute::IS_REPEATABLE],
        'PathParameter' => [PathParameter::class, $classFn | Attribute::IS_REPEATABLE],
        'HeaderParameter' => [HeaderParameter::class, $classFn | Attribute::IS_REPEATABLE],
        'CookieParameter' => [CookieParameter::class, $classFn | Attribute::IS_REPEATABLE],
        'BodyParameter' => [BodyParameter::class, $classFn | Attribute::IS_REPEATABLE],
        'Group' => [Group::class, $classFn | Attribute::IS_REPEATABLE],
        'ExcludeFromDocs' => [ExcludeFromDocs::class, $classFn],
        'Unauthenticated' => [Unauthenticated::class, $classFn],
        'OperationId' => [OperationId::class, Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION],
        'InDocs' => [InDocs::class, $classFn],
        'Example' => [Example::class, Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_FUNCTION | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE],
        'CaseDescription' => [CaseDescription::class, Attribute::TARGET_CLASS_CONSTANT],
        'SchemaId' => [SchemaId::class, Attribute::TARGET_CLASS],
        'SchemaName' => [SchemaName::class, Attribute::TARGET_CLASS],
        'DeprecatedOperation' => [DeprecatedOperation::class, $classFn],
        'Internal' => [Internal::class, $classFn | Attribute::TARGET_PROPERTY],
        'IgnoreParam' => [IgnoreParam::class, $classFn | Attribute::IS_REPEATABLE],
        'IgnoreResponse' => [IgnoreResponse::class, $classFn | Attribute::IS_REPEATABLE],
        'ResponseHeader' => [ResponseHeader::class, $classFn | Attribute::IS_REPEATABLE],
        'DescriptionFromFile' => [DescriptionFromFile::class, $classFn | Attribute::TARGET_PROPERTY],
    ];
}

/**
 * @param  class-string  $class
 */
function attributeFlags(string $class): int
{
    $reflection = new ReflectionClass($class);
    $attributes = $reflection->getAttributes(Attribute::class);

    expect($attributes)->toHaveCount(1);

    /** @var Attribute $instance */
    $instance = $attributes[0]->newInstance();

    return $instance->flags;
}

it('declares the exact attribute targets and repeatability', function (string $class, int $expectedFlags): void {
    expect(class_exists($class))->toBeTrue();
    expect(attributeFlags($class))->toBe($expectedFlags);
})->with(attributeCatalogue());

it('marks the repeatable attributes as repeatable and the rest as not', function (string $class, int $expectedFlags): void {
    $isRepeatable = ($expectedFlags & Attribute::IS_REPEATABLE) === Attribute::IS_REPEATABLE;
    $flags = attributeFlags($class);

    expect(($flags & Attribute::IS_REPEATABLE) === Attribute::IS_REPEATABLE)->toBe($isRepeatable);
})->with(attributeCatalogue());

it('instantiates every attribute with its documented defaults', function (string $class): void {
    $instance = new $class(...defaultArgs($class));

    expect($instance)->toBeInstanceOf($class);
})->with(array_map(static fn (array $row): array => [$row[0]], attributeCatalogue()));

/**
 * Minimal required constructor arguments per attribute (defaults cover the rest).
 *
 * @param  class-string  $class
 * @return list<mixed>
 */
function defaultArgs(string $class): array
{
    return match ($class) {
        QueryParameter::class, PathParameter::class, HeaderParameter::class,
        CookieParameter::class, BodyParameter::class, Group::class,
        IgnoreParam::class, ResponseHeader::class => ['name'],
        OperationId::class, SchemaId::class => ['id'],
        SchemaName::class => ['name'],
        CaseDescription::class => ['a description'],
        IgnoreResponse::class => [200],
        DescriptionFromFile::class => ['docs/x.md'],
        default => [],
    };
}

it('collects Hidden variadic args into a string list', function (): void {
    $hidden = new Hidden('one', 'two');

    expect($hidden->properties)->toBe(['one', 'two']);

    expect((new Hidden)->properties)->toBe([]);
});

it('collects InDocs variadic args into a string list', function (): void {
    $inDocs = new InDocs('public', 'partners');

    expect($inDocs->documents)->toBe(['public', 'partners']);
});

it('legally stacks repeatable attributes on one symbol', function (): void {
    $method = new ReflectionMethod(AttributesFixture::class, 'index');

    $responses = $method->getAttributes(Response::class);
    $queries = $method->getAttributes(QueryParameter::class);

    expect($responses)->toHaveCount(2)
        ->and($queries)->toHaveCount(2);

    expect($responses[0]->newInstance()->status)->toBe(200)
        ->and($responses[1]->newInstance()->status)->toBe(404);
});
