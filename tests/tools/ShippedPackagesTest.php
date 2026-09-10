<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\ResolvedExtensions;

require_once dirname(__DIR__, 2).'/tools/conventional-commit.php';

/**
 * The composer packages this monorepo ships, read off every `php/<package>/composer.json`.
 *
 * @return list<string>
 */
function shippedPackageNames(): array
{
    $names = [];
    foreach (glob(dirname(__DIR__, 2).'/php/*/composer.json') ?: [] as $manifest) {
        $decoded = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $name = is_array($decoded) ? ($decoded['name'] ?? null) : null;
        if (is_string($name)) {
            $names[] = $name;
        }
    }

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
