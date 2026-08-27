<?php

declare(strict_types=1);

use Docuccino\Core\Draft\SchemaKeywords;

/*
 * The guard that keeps the two pages naming annotation keywords honest. Both spell the set out in
 * prose — a reader decides from the page whether a rewritten description can fail their pipeline — and
 * prose is the half no test reaches, so the set could widen in code and leave the pages describing a
 * gate that no longer works the way they say.
 *
 * Both directions, because both go wrong: a keyword the code treats as an annotation and the page does
 * not name reads as a gate the reader will not expect, and a keyword the page names that the code has
 * dropped reads as a promise the build stopped keeping.
 */

/** @return list<string> */
function annotationReferencePages(): array
{
    $root = dirname(__DIR__, 2).'/website/src/content/docs/laravel/';

    return [
        $root.'reference/commands.md',
        $root.'guides/contract-testing.mdx',
    ];
}

/** The keywords a page names, read from the one paragraph that lists them as inline code. */
function annotationKeywordsNamedOn(string $page): array
{
    $text = (string) file_get_contents($page);

    $found = [];
    foreach (SchemaKeywords::annotationOnly() as $keyword) {
        if (str_contains($text, '`'.$keyword.'`')) {
            $found[] = $keyword;
        }
    }

    sort($found);

    return $found;
}

it('names every annotation keyword on every page that describes the set', function (string $page): void {
    $expected = SchemaKeywords::annotationOnly();
    sort($expected);

    expect(annotationKeywordsNamedOn($page))->toBe($expected);
})->with(annotationReferencePages());

it('names no keyword the page calls an annotation that the code does not', function (string $page): void {
    // The other direction. A page naming `default` in the annotation paragraph would tell a reader their
    // pipeline cannot fail on it, which is the opposite of what the differ does with it.
    $text = (string) file_get_contents($page);

    $wrong = [];
    foreach (['default', 'readOnly', 'writeOnly', 'deprecated'] as $excluded) {
        if (! str_contains($text, '`'.$excluded.'`')) {
            continue;
        }

        // Named is fine — every page names them as the exclusions. Named as one of the set is not.
        if (preg_match('/annotation[^.]{0,200}`'.preg_quote($excluded, '/').'`/s', $text) === 1) {
            $wrong[] = $excluded;
        }
    }

    expect($wrong)->toBe([]);
})->with(annotationReferencePages());

it('is reading pages that exist and a set that is not empty', function (): void {
    // The plausible minimum: a scan that matched nothing would satisfy both tests above forever.
    expect(SchemaKeywords::annotationOnly())->not->toBeEmpty();

    foreach (annotationReferencePages() as $page) {
        expect(file_exists($page))->toBeTrue()
            ->and(annotationKeywordsNamedOn($page))->not->toBeEmpty();
    }
});
