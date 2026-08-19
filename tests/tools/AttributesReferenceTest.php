<?php

declare(strict_types=1);

/*
 * The guard behind the attribute reference's counts. The page says "all N attributes" in two places
 * and lists every one in an at-a-glance table, and a count nothing checks is a promise to remember —
 * the number that goes stale is always the one you didn't edit. This reads the shipped package
 * instead, so adding or removing an attribute fails here until both halves of the page agree.
 */
/** @return list<string> */
function shippedAttributeNames(): array
{
    $names = [];
    foreach (glob(dirname(__DIR__, 2).'/php/attributes/src/*.php') ?: [] as $file) {
        $names[] = basename($file, '.php');
    }
    sort($names);

    return $names;
}

function attributesReferencePage(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/laravel/reference/attributes.md',
    );
}

/**
 * The attributes the page's at-a-glance table lists, by name. Rows only — a mention in prose is not a
 * catalogue entry, and the sections below each have a heading of their own.
 *
 * @return list<string>
 */
function referencedAttributeNames(string $page): array
{
    preg_match_all('/^\| \[`#\[(\w+)]`]\(#\w+\) \|/m', $page, $matches);

    $names = $matches[1];
    sort($names);

    return $names;
}

it('lists every attribute the package ships, and nothing it does not', function (): void {
    expect(referencedAttributeNames(attributesReferencePage()))->toBe(shippedAttributeNames());
});

it('states the count the package actually ships, everywhere it states one', function (): void {
    $expected = count(shippedAttributeNames());
    $page = attributesReferencePage();

    preg_match_all('/\b(\d+) attributes\b/', $page, $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(array_unique(array_map(intval(...), $matches[1])))->toBe([$expected]);
});

it('gives every attribute a reference section of its own', function (): void {
    $page = attributesReferencePage();

    $missing = array_values(array_filter(
        shippedAttributeNames(),
        static fn (string $name): bool => ! str_contains($page, '### `#['.$name.']`'),
    ));

    expect($missing)->toBe([]);
});
