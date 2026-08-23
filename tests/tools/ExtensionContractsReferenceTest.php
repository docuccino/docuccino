<?php

declare(strict_types=1);

/*
 * The guard behind the extension-authoring page's contract tables. The contracts ARE the public API an
 * extension author writes against, and one shipped absent from the page — a viewer contract did — is a
 * capability nobody outside this repo can find. So the page is held to the shipped interfaces, both
 * ways: every one has a row, and no row names a contract that no longer exists.
 */

/**
 * The contracts `Docuccino\Core\Extensions\Contracts` ships: its interfaces, minus anything `@internal`.
 * An enum in there is vocabulary a contract's method returns rather than a contract of its own, so
 * reflection settles that too — no list to keep.
 *
 * @return list<string>
 */
function shippedExtensionContracts(): array
{
    $contracts = [];

    foreach ((array) glob(dirname(__DIR__, 2).'/php/core/src/Extensions/Contracts/*.php') as $file) {
        $class = 'Docuccino\Core\Extensions\Contracts\\'.basename((string) $file, '.php');
        $reflection = new ReflectionClass($class);

        if (! $reflection->isInterface() || str_contains((string) $reflection->getDocComment(), '@internal')) {
            continue;
        }

        $contracts[] = $reflection->getShortName();
    }

    sort($contracts);

    return $contracts;
}

function extensionAuthoringPage(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 2).'/website/src/content/docs/extending/extension-authoring.mdx',
    );
}

/**
 * The contracts the page's two tables list, by name. Rows only: the first cell of a contract row is a
 * bare backticked name, so a mention in prose or an Aside is not a catalogue entry.
 *
 * @return list<string>
 */
function referencedExtensionContracts(string $page): array
{
    preg_match_all('/^\| `(\w+)` \|/m', $page, $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

/**
 * The two interfaces the page documents somewhere other than a contract row, with the reason. Each is
 * still checked — it has to exist, and the page has to name it — so an exception cannot outlive what it
 * was written for.
 */
const CONTRACTS_DOCUMENTED_ELSEWHERE = [
    // Its own section, `### Using the SchemaContext`, whose table lists the methods.
    'SchemaContext',
    // Reached through `RouteContext::converter()`, and documented in that context's table.
    'TypeSchemaConverter',
];

it('gives every shipped extension contract a row, and names none it does not ship', function (): void {
    $shipped = array_values(array_diff(shippedExtensionContracts(), CONTRACTS_DOCUMENTED_ELSEWHERE));
    $referenced = referencedExtensionContracts(extensionAuthoringPage());

    // The page's tables carry only contract rows, so the two sets are the same set.
    expect($referenced)->toBe($shipped);
});

it('documents the contracts held out of the tables, and holds out nothing it ships no more', function (): void {
    $shipped = shippedExtensionContracts();
    $page = extensionAuthoringPage();

    foreach (CONTRACTS_DOCUMENTED_ELSEWHERE as $contract) {
        expect($shipped)->toContain($contract)
            ->and($page)->toContain($contract);
    }
});

it('reads a plausible number of contracts, and reads them as contracts', function (): void {
    // A glob that stopped matching, or an `@internal` filter that started eating everything, would leave
    // the comparison above a test of nothing.
    $shipped = shippedExtensionContracts();

    expect(count($shipped))->toBeGreaterThanOrEqual(15)
        ->and($shipped)->toContain('TypeToSchema', 'OperationExtension', 'DocumentTransformer', 'ViewerSpecVersion')
        // An enum beside them is not a contract, and neither is anything outside the directory.
        ->and($shipped)->not->toContain('OperationPhase', 'RouteContext');
});
