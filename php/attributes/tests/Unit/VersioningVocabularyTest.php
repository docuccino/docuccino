<?php

declare(strict_types=1);

use Docuccino\Attributes\Versioning\AddedEnumValue;
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeRequestFieldOptional;
use Docuccino\Attributes\Versioning\MadeResponseFieldOptional;
use Docuccino\Attributes\Versioning\MadeResponseFieldRequired;
use Docuccino\Attributes\Versioning\RemovedEnumValue;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Docuccino\Attributes\Versioning\RenamedParameter;
use Docuccino\Attributes\Versioning\RenamedRequestField;
use Docuccino\Attributes\Versioning\RenamedResponseField;

/*
 * The version-change vocabulary is the one part of the package Docuccino has to read WITHOUT running
 * the application: a version delta describes a shape that is not in the code any more, so there is
 * nothing to infer and the declaration is all there is. PHP already refuses a closure in an attribute
 * argument, but it permits `new`, so the second half of the guarantee is the parameter types
 * themselves — an object cannot satisfy a scalar, and an argument that cannot be read degrades to a
 * diagnostic instead of being believed.
 */

/**
 * A change class as someone might reach for it once the vocabulary grows a verb that wants logic.
 * Nothing registers it; it exists so the foldability guard can be RUN against what it must refuse.
 */
final readonly class UnfoldableChangeProbe
{
    public function __construct(
        public Closure $transform,
        public object $target,
        public ?string $note,
        public string|Closure $either,
    ) {}
}

/**
 * The vocabulary, read off the directory rather than listed here, so a verb added later is covered the
 * day it lands instead of the day someone remembers this file. Read RECURSIVELY: a flat `*.php` is the
 * pattern that goes silent the moment somebody groups the verbs into a subdirectory, and the sub-package
 * name is what a class in one would be namespaced under.
 *
 * @return list<class-string>
 */
function versionChangeVocabulary(): array
{
    $root = dirname(__DIR__, 2).'/src/Versioning';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    $classes = [];
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);

        /** @var class-string $class */
        $class = 'Docuccino\\Attributes\\Versioning\\'.str_replace('/', '\\', $relative);
        $classes[] = $class;
    }
    sort($classes);

    return $classes;
}

/**
 * The guard itself, as a function so it can be run against a set it should refuse: every constructor
 * parameter a build could not read off a declaration. Foldable means a non-nullable scalar, or an array
 * of them — anything else is either an object the build would have to construct or a null that says
 * nothing.
 *
 * @param  list<class-string>  $classes
 * @return list<string>
 */
function unfoldableChangeParameters(array $classes): array
{
    $offenders = [];
    foreach ($classes as $class) {
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if (! foldableChangeParameter($type)) {
                $offenders[] = $class.'::$'.$parameter->getName().': '.($type === null ? 'untyped' : (string) $type);
            }
        }
    }

    return $offenders;
}

/**
 * Whether a build could read a value of this type off a declaration, which is the whole of what
 * foldable means: a non-nullable scalar, an array of them, or a UNION whose every member is one.
 *
 * A union is foldable for exactly the reason a single type is — PHP has already constructed every
 * branch of it by the time the attribute is instantiated, and none of them is an object to build or a
 * null to interpret. The vocabulary needs one because a backed enum's value is `string|int` on the
 * wire: narrowing the declaration to either half would make the other spell its value as the type it
 * is not, and an enum member published as `"3"` where the server sends `3` is a value a generated
 * client cannot match.
 */
function foldableChangeParameter(?ReflectionType $type): bool
{
    if ($type instanceof ReflectionNamedType) {
        return ! $type->allowsNull() && in_array($type->getName(), ['string', 'int', 'float', 'bool', 'array'], true);
    }

    // An intersection is of objects by construction, and anything else unnamed says nothing at all.
    if (! $type instanceof ReflectionUnionType) {
        return false;
    }

    foreach ($type->getTypes() as $member) {
        if (! foldableChangeParameter($member)) {
            return false;
        }
    }

    return true;
}

/** How many constructor parameters the vocabulary declares in total. */
function changeVocabularyParameterCount(): int
{
    $count = 0;
    foreach (versionChangeVocabulary() as $class) {
        $count += (new ReflectionClass($class))->getConstructor()?->getNumberOfParameters() ?? 0;
    }

    return $count;
}

it('keeps every version-change declaration readable without running the application', function (): void {
    // A scan that stopped seeing the vocabulary must fail rather than pass forever on an empty set.
    expect(count(versionChangeVocabulary()))->toBeGreaterThanOrEqual(8)
        ->and(changeVocabularyParameterCount())->toBeGreaterThanOrEqual(22)
        ->and(unfoldableChangeParameters(versionChangeVocabulary()))->toBe([]);
});

it('refuses a parameter no declaration could carry', function (): void {
    // The guard EXECUTED rather than asserted: a verb reaching for a closure, an object, a null or a
    // union with any of them in it is the failure it has to produce, and each of the four is named. The
    // union is here because the guard learned to accept one: a rule that now says yes to some unions
    // has to be shown still saying no to the rest, or accepting `string|int` quietly accepted anything.
    expect(unfoldableChangeParameters([UnfoldableChangeProbe::class]))->toBe([
        UnfoldableChangeProbe::class.'::$transform: Closure',
        UnfoldableChangeProbe::class.'::$target: object',
        UnfoldableChangeProbe::class.'::$note: ?string',
        UnfoldableChangeProbe::class.'::$either: Closure|string',
    ]);
});

it('declares what the API did before the version the change shipped in', function (): void {
    $change = new ApiVersionChange(
        since: '2026-09-01',
        description: 'Invoices publish `title` where they used to publish `name`.',
    );

    expect($change->since)->toBe('2026-09-01')
        ->and($change->description)->toBe('Invoices publish `title` where they used to publish `name`.');
});

it('names the field the code publishes today and the one older versions published', function (string $class): void {
    // `to` is today's name and `from` is the old one — the pair read backwards renames the wrong end, so
    // the direction is pinned rather than left to whoever reads the constructor next. Both halves of the
    // wire, because a rename is the one difference that really is one sentence read in two directions:
    // the field is published under both names either way, and only what it is CALLED moves.
    /** @var object{schema: string, from: string, to: string} $renamed */
    $renamed = new $class(
        schema: 'App\\Http\\Resources\\InvoiceResource',
        from: 'name',
        to: 'title',
    );

    expect($renamed->schema)->toBe('App\\Http\\Resources\\InvoiceResource')
        ->and($renamed->from)->toBe('name')
        ->and($renamed->to)->toBe('title');
})->with([
    'the response half' => [RenamedResponseField::class],
    'the request half' => [RenamedRequestField::class],
]);

/*
 * The one verb that names no class. A parameter stands on the OPERATION rather than in a body, so there
 * is nothing about it to name but where it travels and what it is called — and `in:` is a closed set,
 * which is why the location is a string the adapter reads against the four OAS locations rather than a
 * free-form word.
 */
it('names where a parameter travels and what it used to be called', function (): void {
    $renamed = new RenamedParameter(in: 'query', from: 'q', to: 'search');

    expect($renamed->in)->toBe('query')
        ->and($renamed->from)->toBe('q')
        ->and($renamed->to)->toBe('search')
        ->and((new ReflectionClass(RenamedParameter::class))->getConstructor()?->getNumberOfParameters())->toBe(3);
});

/*
 * The required-ness verbs, each pinned to the sentence it makes. All three name the field as the code
 * spells it TODAY, so the direction is the same one the rename's `to:` runs in — and the pair of
 * response verbs is the pair, because a `required` entry arriving narrows a REQUEST and moves nothing
 * on a response. There is no `#[MadeRequestFieldRequired]`, and that is the asymmetry rather than an
 * omission.
 */
it('names the field and the shape whose required-ness moved', function (string $class, string $sentence): void {
    /** @var object{schema: string, field: string} $verb */
    $verb = new $class(schema: 'App\\Http\\Resources\\InvoiceResource', field: 'title');

    expect($verb->schema)->toBe('App\\Http\\Resources\\InvoiceResource')
        ->and($verb->field)->toBe('title')
        ->and($sentence)->not->toBe('');
})->with([
    'a response field the change started guaranteeing' => [MadeResponseFieldRequired::class, 'older versions could omit it'],
    'a response field the change stopped guaranteeing' => [MadeResponseFieldOptional::class, 'older versions always sent it'],
    'a request field the change stopped demanding' => [MadeRequestFieldOptional::class, 'older versions refused a body without it'],
]);

it('spells no verb for the combination the wire has no honest sentence for', function (): void {
    // `required` arriving narrows a request and moves nothing on a response, so the fourth cell of the
    // grid is deliberately empty rather than merely unbuilt.
    expect(class_exists('Docuccino\\Attributes\\Versioning\\MadeRequestFieldRequired'))->toBeFalse()
        ->and(versionChangeVocabulary())->toBe([
            AddedEnumValue::class,
            ApiVersionChange::class,
            'Docuccino\\Attributes\\Versioning\\AppliesTo',
            MadeRequestFieldOptional::class,
            MadeResponseFieldOptional::class,
            MadeResponseFieldRequired::class,
            RemovedEnumValue::class,
            RemovedResponseField::class,
            RenamedParameter::class,
            RenamedRequestField::class,
            RenamedResponseField::class,
        ]);
});

/*
 * The one verb whose fact is gone from the code, so the one that declares a shape. Everything it
 * carries is a non-nullable scalar — the foldability guard above is what makes that a rule rather than
 * a habit — and `field:` is the name the versions BEFORE the change published, which is the opposite
 * direction from every other verb because there is no name in the code today to run in the other one.
 */
it('names the field a version deleted, and the shape nothing in the code can still describe', function (): void {
    $removed = new RemovedResponseField(
        schema: 'App\\Http\\Resources\\InvoiceResource',
        field: 'subtotal',
        type: 'integer',
        required: true,
        description: 'The invoice total before tax, in cents.',
    );

    expect($removed->schema)->toBe('App\\Http\\Resources\\InvoiceResource')
        ->and($removed->field)->toBe('subtotal')
        ->and($removed->type)->toBe('integer')
        ->and($removed->required)->toBeTrue()
        ->and($removed->description)->toBe('The invoice total before tax, in cents.');
});

it('lets a removal state nothing but the field it took away', function (): void {
    // Every argument but the pair has a default, so the shortest honest form is "it was there, and
    // nobody now knows what it held" — which publishes an unconstrained field rather than a guess.
    $removed = new RemovedResponseField(schema: 'App\\Http\\Resources\\InvoiceResource', field: 'subtotal');

    expect($removed->type)->toBe('')
        ->and($removed->required)->toBeFalse()
        ->and($removed->description)->toBe('');
});

it('stacks a change and its renames on one class', function (): void {
    $reflection = new ReflectionClass(RenamedInvoiceFieldsFixture::class);

    expect($reflection->getAttributes(ApiVersionChange::class))->toHaveCount(1)
        ->and($reflection->getAttributes(RenamedResponseField::class))->toHaveCount(2);

    $renames = array_map(
        static function (ReflectionAttribute $declaration): array {
            $rename = $declaration->newInstance();

            return [$rename->from, $rename->to];
        },
        $reflection->getAttributes(RenamedResponseField::class),
    );

    expect($renames)->toBe([['name', 'title'], ['total', 'amount_in_cents']]);
});

/** One registered change: the description, the version it shipped in, and what it did to the shape. */
#[ApiVersionChange(since: '2026-09-01', description: 'Invoices publish `title` and `amount_in_cents`.')]
#[RenamedResponseField(schema: 'App\\Http\\Resources\\InvoiceResource', from: 'name', to: 'title')]
#[RenamedResponseField(schema: 'App\\Http\\Resources\\InvoiceResource', from: 'total', to: 'amount_in_cents')]
final class RenamedInvoiceFieldsFixture {}

/*
 * The value-set pair, which is the first verb in the vocabulary to carry a value rather than a name —
 * and so the first whose declaration has to spell what the wire carries. `string|int` is the whole
 * reason the foldability guard above learned to read a union.
 */
it('names the value a version added to a published set', function (): void {
    $added = new AddedEnumValue(enum: 'App\\Enums\\Status', value: 'archived');

    expect($added->enum)->toBe('App\\Enums\\Status')
        ->and($added->value)->toBe('archived')
        ->and((new ReflectionClass(AddedEnumValue::class))->getConstructor()?->getNumberOfParameters())->toBe(2);
});

it('carries an int-backed value as an int rather than as its spelling', function (): void {
    // The half a `string`-only declaration would have got wrong: a set typed `integer` publishes `3`,
    // and a declaration that could only say `'3'` would name no member of it.
    $added = new AddedEnumValue(enum: 'App\\Enums\\Priority', value: 3);

    expect($added->value)->toBe(3)->and($added->value)->not->toBe('3');
});

it('names the value a version took away, and what older clients called it', function (): void {
    $removed = new RemovedEnumValue(
        enum: 'App\\Enums\\Status',
        value: 'pending_review',
        name: 'PendingReview',
        description: 'Waiting for an editor to approve it.',
    );

    expect($removed->enum)->toBe('App\\Enums\\Status')
        ->and($removed->value)->toBe('pending_review')
        ->and($removed->name)->toBe('PendingReview')
        ->and($removed->description)->toBe('Waiting for an editor to approve it.');
});

it('lets a removed value state nothing but itself', function (): void {
    // The shortest honest form: the member name is minted from the value, which is a pure function of
    // it, and the set publishes no prose for a value nobody wrote any for.
    $removed = new RemovedEnumValue(enum: 'App\\Enums\\Status', value: 'pending_review');

    expect($removed->name)->toBe('')->and($removed->description)->toBe('');
});
