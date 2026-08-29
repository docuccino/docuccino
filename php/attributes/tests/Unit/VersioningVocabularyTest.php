<?php

declare(strict_types=1);

use Docuccino\Attributes\Versioning\ApiVersionChange;
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
    $foldable = ['string', 'int', 'float', 'bool', 'array'];

    $offenders = [];
    foreach ($classes as $class) {
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->allowsNull() || ! in_array($type->getName(), $foldable, true)) {
                $offenders[] = $class.'::$'.$parameter->getName().': '.($type === null ? 'untyped' : (string) $type);
            }
        }
    }

    return $offenders;
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
    expect(count(versionChangeVocabulary()))->toBeGreaterThanOrEqual(2)
        ->and(changeVocabularyParameterCount())->toBeGreaterThanOrEqual(5)
        ->and(unfoldableChangeParameters(versionChangeVocabulary()))->toBe([]);
});

it('refuses a parameter no declaration could carry', function (): void {
    // The guard EXECUTED rather than asserted: a verb reaching for a closure, an object or a null is the
    // failure it has to produce, and each of the three is named.
    expect(unfoldableChangeParameters([UnfoldableChangeProbe::class]))->toBe([
        UnfoldableChangeProbe::class.'::$transform: Closure',
        UnfoldableChangeProbe::class.'::$target: object',
        UnfoldableChangeProbe::class.'::$note: ?string',
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

it('names the field the code publishes today and the one older versions published', function (): void {
    // `to` is today's name and `from` is the old one — the pair read backwards renames the wrong end, so
    // the direction is pinned rather than left to whoever reads the constructor next.
    $renamed = new RenamedResponseField(
        schema: 'App\\Http\\Resources\\InvoiceResource',
        from: 'name',
        to: 'title',
    );

    expect($renamed->schema)->toBe('App\\Http\\Resources\\InvoiceResource')
        ->and($renamed->from)->toBe('name')
        ->and($renamed->to)->toBe('title');
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
