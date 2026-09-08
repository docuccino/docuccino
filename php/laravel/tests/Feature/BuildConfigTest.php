<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Engine\TypeEngineMode;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\BuildSettings;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Docuccino\Laravel\Watch\ArtisanBuildRunner;

/*
 * The adapter reading `docuccino.yaml`: that a build's report carries what the READER refused as well
 * as what the build itself found, and that the two environment variables which used to be `env()`
 * calls in the framework config still override the one setting each.
 */

/**
 * Set one environment variable for the body and take it away again, whatever the body does. A leaked
 * variable is not a tidiness problem here: the next test in this file reads the same two names, and
 * would be answering the last one's question.
 */
function withDocuccinoEnv(string $name, string $value, Closure $body): void
{
    putenv($name.'='.$value);

    try {
        $body();
    } finally {
        putenv($name);
    }
}

it('reports what the configuration reader refused, in the build that read it', function (): void {
    // A refusal is only useful where somebody sees it, and nothing at a container bind has anywhere to
    // put one. `1.10` is the canonical case: YAML reads it as the float 1.1, and a converting reader
    // would publish "1.1" — a different version number in whatever client is generated from this.
    BuildSettings::yaml(<<<'YAML'
        documents:
          default:
            info:
              title: 'Forms API'
              version: 1.10
            routes:
              include: ['api/forms']
        YAML);

    bindStubEngine();
    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());
    $refusals = diagnosticsCoded($result->diagnostics, 'config.value-type');

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0]->severity)->toBe(Severity::Warning)
        ->and($refusals[0]->message)->toContain('documents.default.info.version')
        ->and($refusals[0]->help)->toContain('Quote it')
        // And the document says the default rather than the number the parser made up.
        ->and($result->document->toArray()['info']['version'] ?? null)->toBe('1.0.0');
});

it('reports a configuration file that is not a map at all', function (): void {
    BuildSettings::yaml("- documents\n- lint\n");

    bindStubEngine();
    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    expect(diagnosticsCoded($result->diagnostics, 'config.file-not-a-map'))->toHaveCount(1);
});

it('refuses a documents section written as a list rather than inventing documents from it', function (): void {
    BuildSettings::yaml(<<<'YAML'
        documents:
          - default
        YAML);

    expect(app(BuildConfig::class)->documents())->toBe([])
        ->and(app(BuildConfig::class)->values()->diagnostics()[0]->message)
        ->toContain('documents is a list, where the setting takes a map of settings');
});

it('reads on_route_error off the file, and refuses a value that is not text', function (): void {
    BuildSettings::set('on_route_error', 'omit');
    expect(app(DocumentBuilder::class)->config('default')->onRouteError)->toBe('omit');

    BuildSettings::set('on_route_error', true);
    expect(app(DocumentBuilder::class)->config('default')->onRouteError)->toBe('skeleton')
        ->and(app(BuildConfig::class)->values()->diagnostics()[0]->message)
        ->toContain('on_route_error is the boolean true, where the setting takes text');
});

// --- The two environment levers -------------------------------------------------------------------

it('lets the environment override the one setting each variable names, and nothing else', function (): void {
    // The closed list, stated literally: a run has an opinion about these two, and everything else
    // belongs in the file where it can be reviewed and committed.
    expect(BuildConfig::ENV_OVERRIDES)->toBe([
        'DOCUCCINO_ENGINE' => 'engine.mode',
        'DOCUCCINO_FRAGMENT_CACHE' => 'cache.enabled',
    ]);
});

it('switches inference off from the environment, in the spelling the diagnostics ask for', function (): void {
    // Four diagnostics' help text tells a reader to set this, and the framework reads the word `null`
    // as PHP null — so a lever that passed that through would leave the mode unrecognised while every
    // one of those four sentences claimed otherwise.
    BuildSettings::set('engine.mode', 'in-process');

    withDocuccinoEnv(BuildConfig::ENGINE_MODE, 'null', function (): void {
        expect(app(BuildConfig::class)->engine()['mode'])->toBe(TypeEngineMode::Null->value);
    });
});

it('turns the fragment cache on from the environment, which is how a watch session does it', function (): void {
    // `docuccino:watch` sets this for the builds it drives rather than editing anybody's file.
    BuildSettings::set('cache.enabled', false);

    withDocuccinoEnv(ArtisanBuildRunner::FRAGMENT_CACHE, 'true', function (): void {
        expect(app(BuildConfig::class)->cache()['enabled'])->toBeTrue();
    });
});

it('leaves the file alone when the environment says nothing', function (): void {
    BuildSettings::set('engine.mode', 'null');
    BuildSettings::set('cache.enabled', true);

    expect(app(BuildConfig::class)->engine()['mode'])->toBe('null')
        ->and(app(BuildConfig::class)->cache()['enabled'])->toBeTrue();
});

it('hands a value the environment set on to the reader that owns the setting, unconverted', function (): void {
    // `1` is not a switch, and this is not the place that decides what to do about that: the flag
    // catalogue refuses it and names it, exactly as it would a `1` written in the file.
    BuildSettings::set('cache.enabled', false);

    withDocuccinoEnv(ArtisanBuildRunner::FRAGMENT_CACHE, '1', function (): void {
        expect(app(BuildConfig::class)->cache()['enabled'])->toBe('1');
    });
});
