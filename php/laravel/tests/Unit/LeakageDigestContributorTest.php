<?php

declare(strict_types=1);

use Docuccino\Core\Examples\ExampleRedaction;
use Docuccino\Laravel\Config\LeakageOptions;
use Docuccino\Laravel\Support\LeakageDigestContributor;

/*
 * The digest that keys the fragment cache on `lint.leakage`, over every part of the bag that reaches it.
 *
 * The rule is stated from the CONSUMER rather than from the contributor: a bag that changes what
 * redaction answers about a body owes a moved digest, because that answer decides whether a recorded
 * example is published at all. Asking the contributor whether it digests what it digests would agree
 * with whatever it does.
 */
/** @param array<string, mixed> $leakage */
function leakageDigest(array $leakage): string
{
    return (new LeakageDigestContributor(LeakageOptions::fromConfig($leakage, honourSwitch: false)))->digest();
}

/** What redaction makes of a body carrying a plausible credential under a plausible name. */
function leakageFindings(array $leakage, mixed $body): array
{
    return (new ExampleRedaction(LeakageOptions::fromConfig($leakage, honourSwitch: false)))->findings($body);
}

it('moves the digest for every leakage bag that changes what redaction answers', function (array $leakage, mixed $body): void {
    $baseline = ['allow' => [], 'patterns' => []];

    // Soundness, from the consumer's side: this bag really does change the answer …
    expect(leakageFindings($leakage, $body))->not->toBe(leakageFindings($baseline, $body))
        // … so the key the cache files that answer under has to change with it.
        ->and(leakageDigest($leakage))->not->toBe(leakageDigest($baseline));
})->with([
    // A safelisted pointer un-flags the one value it names, which publishes an example that was withheld.
    'a safelisted pointer' => [['allow' => ['/api_key']], (object) ['api_key' => 'sk_live_abcdefghijklmnop']],
    // The same entry written as the URI fragment a $ref uses — one reading, two spellings.
    'a safelisted pointer as a fragment' => [['allow' => ['#/api_key']], (object) ['api_key' => 'sk_live_abcdefghijklmnop']],
    // An extra token teaches redaction a member name it did not know, which withholds an example that published.
    'an added heuristic' => [['patterns' => ['sortcode' => 'a bank sort code']], (object) ['sortcode' => '112233']],
]);

it('leaves the digest alone for a leakage bag that changes no answer', function (array $one, array $two): void {
    $probe = (object) ['api_key' => 'sk_live_abcdefghijklmnop', 'reset_token' => 'tok_abcdefghijklmnop'];

    expect(leakageFindings($one, $probe))->toBe(leakageFindings($two, $probe))
        ->and(leakageDigest($one))->toBe(leakageDigest($two));
})->with([
    // Membership, not order: the safelist is consulted with in_array, so re-ordering the config bag must
    // not cost an application a rebuild.
    'a re-ordered safelist' => [
        ['allow' => ['/api_key', '/reset_token']],
        ['allow' => ['/reset_token', '/api_key']],
    ],
    // The off switch never reaches redaction — turning a report off is not a request to publish
    // credentials — so it never reaches a fragment, and keying it would retire every one of them.
    'the report switch' => [
        ['allow' => ['/api_key'], 'enabled' => true],
        ['allow' => ['/api_key'], 'enabled' => false],
    ],
]);

it('keys the heuristics table as WRITTEN, because the first token a name contains wins', function (): void {
    // Two tables with the same members in two orders answer differently — a name matches a token it
    // CONTAINS, so which entry is reached first is part of what the table says. Sorting these for
    // tidiness would key two different answers alike. Neither token is a built-in, since the built-ins
    // merge ahead of both and would answer first.
    $one = ['patterns' => ['sortcode' => 'a bank sort code', 'sort' => 'a sort order']];
    $two = ['patterns' => ['sort' => 'a sort order', 'sortcode' => 'a bank sort code']];

    expect(LeakageOptions::fromConfig($one)->match('sortcode'))
        ->not->toBe(LeakageOptions::fromConfig($two)->match('sortcode'))
        ->and(leakageDigest($one))->not->toBe(leakageDigest($two));
});

it('digests an empty bag to a value of its own rather than to nothing', function (): void {
    // A digest that collapsed to '' for the default bag would make the whole segment invisible, and an
    // aggregate that reads the same with a contributor missing is a contributor nothing would miss.
    expect(leakageDigest([]))->not->toBe('')
        ->and(leakageDigest([]))->toBe(leakageDigest(['allow' => [], 'patterns' => []]));
});
