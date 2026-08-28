<?php

declare(strict_types=1);

use Docuccino\Attributes\Abilities;
use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\CaseDescription;
use Docuccino\Attributes\CookieParameter;
use Docuccino\Attributes\DeprecatedOperation;
use Docuccino\Attributes\Description;
use Docuccino\Attributes\ErrorComponent;
use Docuccino\Attributes\Example;
use Docuccino\Attributes\ExcludeFromDocs;
use Docuccino\Attributes\Group;
use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\Hidden;
use Docuccino\Attributes\HiddenFromRequest;
use Docuccino\Attributes\IgnoreParam;
use Docuccino\Attributes\IgnoreResponse;
use Docuccino\Attributes\InDocs;
use Docuccino\Attributes\Internal;
use Docuccino\Attributes\Mock;
use Docuccino\Attributes\OperationId;
use Docuccino\Attributes\OptionallyAuthenticated;
use Docuccino\Attributes\PathParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Attributes\Response;
use Docuccino\Attributes\ResponseHeader;
use Docuccino\Attributes\RuleSchema;
use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;
use Docuccino\Attributes\Security;
use Docuccino\Attributes\Summary;
use Docuccino\Attributes\Unauthenticated;
use Docuccino\Attributes\Webhook;

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
        'HiddenFromRequest' => [HiddenFromRequest::class, Attribute::TARGET_PROPERTY],
        'QueryParameter' => [QueryParameter::class, $classFn | Attribute::IS_REPEATABLE],
        'PathParameter' => [PathParameter::class, $classFn | Attribute::IS_REPEATABLE],
        'HeaderParameter' => [HeaderParameter::class, $classFn | Attribute::IS_REPEATABLE],
        'CookieParameter' => [CookieParameter::class, $classFn | Attribute::IS_REPEATABLE],
        'BodyParameter' => [BodyParameter::class, $classFn | Attribute::IS_REPEATABLE],
        'Group' => [Group::class, $classFn | Attribute::IS_REPEATABLE],
        'ExcludeFromDocs' => [ExcludeFromDocs::class, $classFn],
        'Unauthenticated' => [Unauthenticated::class, $classFn],
        'OptionallyAuthenticated' => [OptionallyAuthenticated::class, $classFn],
        'Security' => [Security::class, $classFn | Attribute::IS_REPEATABLE],
        'Abilities' => [Abilities::class, $classFn],
        'OperationId' => [OperationId::class, Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION],
        'InDocs' => [InDocs::class, $classFn],
        'Example' => [Example::class, Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE],
        'CaseDescription' => [CaseDescription::class, Attribute::TARGET_CLASS_CONSTANT],
        'SchemaId' => [SchemaId::class, Attribute::TARGET_CLASS],
        'SchemaName' => [SchemaName::class, Attribute::TARGET_CLASS],
        'DeprecatedOperation' => [DeprecatedOperation::class, $classFn],
        'Internal' => [Internal::class, $classFn | Attribute::TARGET_PROPERTY],
        'IgnoreParam' => [IgnoreParam::class, $classFn | Attribute::IS_REPEATABLE],
        'IgnoreResponse' => [IgnoreResponse::class, $classFn | Attribute::IS_REPEATABLE],
        'ResponseHeader' => [ResponseHeader::class, $classFn | Attribute::IS_REPEATABLE],
        'Summary' => [Summary::class, $classFn],
        'Description' => [Description::class, $classFn | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE],
        'RuleSchema' => [RuleSchema::class, Attribute::TARGET_CLASS],
        'ErrorComponent' => [ErrorComponent::class, Attribute::TARGET_CLASS | Attribute::TARGET_METHOD],
        'Webhook' => [Webhook::class, Attribute::TARGET_CLASS],
        'Mock' => [Mock::class, Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE],
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
        SchemaName::class, Security::class, ErrorComponent::class, Webhook::class => ['name'],
        CaseDescription::class => ['a description'],
        IgnoreResponse::class => [200],
        Summary::class => ['Create an invoice'],
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

// The catalogue above calls itself the full attribute set, which is only true while someone keeps it
// that way. `#[Webhook]` shipped without an entry and the dataset still passed, because a dataset
// only ever proves the rows it lists. This reads the directory instead, so a new attribute fails
// here until it is catalogued.
it('catalogues every attribute the package ships', function (): void {
    $shipped = [];
    foreach (glob(__DIR__.'/../../src/*.php') ?: [] as $file) {
        $shipped[] = 'Docuccino\\Attributes\\'.basename($file, '.php');
    }
    sort($shipped);

    $catalogued = array_map(
        static fn (array $row): string => $row[0],
        array_values(attributeCatalogue()),
    );
    sort($catalogued);

    expect($shipped)->not->toBeEmpty()
        ->and($catalogued)->toBe($shipped);
});

it('keeps every #[Mock] parameter optional and null by default', function (): void {
    // Each one is optional on its own: a hint may be a faker expression, a seed group, or both, and the
    // class-level form adds the property it names.
    $mock = new Mock;

    expect([$mock->faker, $mock->seedGroup, $mock->property])->toBe([null, null, null]);

    $named = new Mock(faker: 'safeEmail', seedGroup: 'person', property: 'email');

    expect([$named->faker, $named->seedGroup, $named->property])->toBe(['safeEmail', 'person', 'email']);
});

it('keeps a written #[BodyParameter] optionality distinguishable from an unwritten one', function (): void {
    // This one patches a body a recovery has already proved requirements for, so "the author said
    // optional" and "the author said nothing" must not arrive as the same value: reading the default as
    // optional de-requires a field the server insists on, and publishing a contract a generated client
    // can build a rejected request from. The three states are the whole point of the nullable type.
    expect((new BodyParameter(name: 'nickname'))->required)->toBeNull()
        ->and((new BodyParameter(name: 'nickname', required: false))->required)->toBeFalse()
        ->and((new BodyParameter(name: 'nickname', required: true))->required)->toBeTrue();
});
