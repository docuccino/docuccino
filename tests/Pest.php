<?php

declare(strict_types=1);
use Docuccino\Laravel\Tests\TestCase;

/*
 * Monorepo Pest bootstrap. Test files live alongside their package
 * (packages/<pkg>/tests); this root file binds Pest's test case to them and exposes
 * shared fixture helpers.
 */

uses()->in(dirname(__DIR__).'/packages/core/tests');
uses(TestCase::class)->in(dirname(__DIR__).'/packages/laravel/tests');

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

/**
 * A maximal valid UIR document exercising every modelled surface (requestBody, securitySchemes +
 * operation-level security, servers with variables, webhooks, path/header/cookie params, the 3.2
 * `query` method, multiple media types, examples, externalDocs, deprecated, content pages, floats
 * and unicode keys).
 *
 * @return array<string, mixed>
 */
function kitchenSink(): array
{
    return loadFixture('kitchen-sink.uir.json');
}

/**
 * Reads a committed golden artifact byte-for-byte.
 */
function loadGolden(string $name): string
{
    $path = dirname(__DIR__).'/packages/core/tests/Fixtures/golden/'.$name;
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Golden not found: '.$path);
    }

    return $contents;
}

/**
 * Gate for the fixture-app-dependent integration tests. Normally a missing
 * fixture app skips the test (contributors without it still get a green local
 * run). Under `DOCUCCINO_REQUIRE_FIXTURE=1` — the CI fixture job — a missing
 * fixture app is instead a hard FAILURE, so the suite can never silently pass by
 * skipping the very tests the job exists to run.
 */
function ensureFixtureAvailable(bool $available): void
{
    if ($available) {
        return;
    }

    if (getenv('DOCUCCINO_REQUIRE_FIXTURE') === '1') {
        throw new RuntimeException(
            'Fixture app required (DOCUCCINO_REQUIRE_FIXTURE=1) but absent — provision per spikes/fixture-app-setup.md',
        );
    }

    test()->markTestSkipped('fixture app absent — recreate per spikes/fixture-app-setup.md');
}
