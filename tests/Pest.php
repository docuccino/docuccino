<?php

declare(strict_types=1);

/*
 * Monorepo Pest bootstrap. Test files live alongside their package
 * (packages/<pkg>/tests); this root file binds Pest's test case to them and exposes
 * shared fixture helpers.
 */

uses()->in(dirname(__DIR__).'/packages/core/tests');

/**
 * @return array<string, mixed>
 */
function loadFixture(string $name): array
{
    $path = dirname(__DIR__).'/packages/core/tests/Fixtures/'.$name;
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Fixture not found: '.$path);
    }

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * @return array<string, mixed>
 */
function workedExample(): array
{
    return loadFixture('worked-example.json');
}
