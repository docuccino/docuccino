<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\ResolvedExtensions;

require_once dirname(__DIR__, 2).'/tools/conventional-commit.php';

/**
 * Every `php/<package>/composer.json` this monorepo ships, decoded and keyed by package name.
 *
 * @return array<string, array<mixed>>
 */
function shippedPackageManifests(): array
{
    $manifests = [];
    foreach (glob(dirname(__DIR__, 2).'/php/*/composer.json') ?: [] as $manifest) {
        $decoded = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $name = is_array($decoded) ? ($decoded['name'] ?? null) : null;
        if (is_array($decoded) && is_string($name)) {
            $manifests[$name] = $decoded;
        }
    }

    return $manifests;
}

/**
 * The composer packages this monorepo ships, read off every `php/<package>/composer.json`.
 *
 * @return list<string>
 */
function shippedPackageNames(): array
{
    $names = array_keys(shippedPackageManifests());

    sort($names);

    return $names;
}

/*
 * Two hand-written lists name those packages — the fragment cache's trust gate
 * ({@see ResolvedExtensions::SHIPPED_PACKAGES}) and the changelog's scope map — and a list only
 * proves the rows it carries. So both are held to the directories rather than to each other: a
 * package added to the monorepo and left out of the trust gate would be treated as somebody else's,
 * and left out of the scope map its entries would reach no changelog.
 */
it('ships exactly the packages the two hand-written lists name', function (): void {
    $shipped = shippedPackageNames();

    $trusted = ResolvedExtensions::SHIPPED_PACKAGES;
    sort($trusted);

    $changelogged = array_values(CONVENTIONAL_PACKAGE_NAMES);
    sort($changelogged);

    // A glob matching nothing would otherwise agree with a list that had gone empty too.
    expect(count($shipped))->toBeGreaterThanOrEqual(4)
        ->and($shipped)->toContain('docuccino/core')
        ->and($trusted)->toBe($shipped)
        ->and($changelogged)->toBe($shipped);
});

/*
 * Membership is held to the directories above; this holds each member's own metadata the same way,
 * because a package added to `php/` inherits neither field from the monorepo. Packagist renders
 * `description` as the package's one-line summary and searches `keywords`, so a package shipping
 * without either is mute in the one place a PHP developer looks for it.
 *
 * `openapi` is the family keyword — the term that makes the packages findable as one set — and every
 * shipped package carries it, so the expectation is over all of them rather than a subset. A package
 * that ever earns an exemption belongs here as a row naming it and why, never as a weakened
 * assertion. This says nothing about WHICH words a description uses; that is an editorial judgement,
 * and a test asserting it would only repeat whichever wording landed with it.
 *
 * The monorepo's own root manifest is deliberately outside the denominator: it is one fixed file
 * rather than a population that grows, and it is not a package anything installs.
 */
it('gives every shipped package its own description and keywords', function (): void {
    $familyKeyword = 'openapi';

    $manifests = shippedPackageManifests();

    // Same positive control as above: a glob matching nothing must fail, not vacuously pass.
    expect(count($manifests))->toBeGreaterThanOrEqual(4)
        ->and(array_keys($manifests))->toContain('docuccino/core');

    $withoutDescription = [];
    $withoutKeywords = [];
    $withoutFamilyKeyword = [];

    foreach ($manifests as $name => $manifest) {
        $description = $manifest['description'] ?? null;
        if (! is_string($description) || trim($description) === '') {
            $withoutDescription[] = $name;
        }

        $declared = $manifest['keywords'] ?? null;
        $keywords = is_array($declared) ? array_filter($declared, is_string(...)) : [];

        if ($keywords === []) {
            $withoutKeywords[] = $name;
        } elseif (! in_array($familyKeyword, $keywords, true)) {
            $withoutFamilyKeyword[] = $name;
        }
    }

    expect($withoutDescription)->toBe([])
        ->and($withoutKeywords)->toBe([])
        ->and($withoutFamilyKeyword)->toBe([]);
});
