<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Config\DeclaredSettings;
use Docuccino\Laravel\Engine\TypeEngineMode;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\BuildSettings;
use Docuccino\Laravel\Tests\Support\SettingReadingTransformer;
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

// --- A section that holds no section ---------------------------------------------------------------

/**
 * The sections the shipped file declares that nothing asks the typed reader about, and why each owes
 * no refusal. Every other declared section owes one, which is what the dataset below is over.
 *
 * Both reasons are about what the section HOLDS rather than about who reads it. Judging either would
 * report a defect in a correct file: `info` is an OAS Info Object published as written, so its
 * `description` is legitimately a line of markdown OR a `{ file: … }` map, and a scheme name holds
 * whatever OAS says a Security Scheme Object is. The list entries are not keys at all — an author
 * writes `- { format: …, path: … }`, and an entry that is no map is the entry reader's business.
 *
 * @return array<string, string>
 */
function unaskedSections(): array
{
    return [
        'documents.*.info.description' => 'below documents.*.info, an OAS object published as written',
        'documents.*.security.schemes.apiKey' => 'below documents.*.security.schemes, whose members are your scheme names',
        'documents.*.security.schemes.bearer' => 'below documents.*.security.schemes, whose members are your scheme names',
        'documents.*.export.targets.*' => 'an entry of a list, not a key',
        'documents.*.security.default.*' => 'an entry of a list, not a key',
        'documents.*.security.document.*' => 'an entry of a list, not a key',
        'documents.*.tags.definitions.*' => 'an entry of a list, not a key',
    ];
}

it('refuses a section that holds no section, over every section the shipped file declares', function (string $path): void {
    // The same ground ConfigFile refuses a whole FILE that is not a map on: the build would otherwise
    // run on every default and produce a plausible document, so the author's file looks applied and is
    // not. It does not weaken one level down, and `routes` is where it bites hardest — a glob written
    // there leaves the include list empty, an empty include list is read as no filter at all, and the
    // author who wrote a filter gets every route in the application.
    //
    // Over the declared set rather than a sample of it, because a section added to the shipped file
    // and not to whatever asks about it would go straight back to degrading in silence.
    BuildSettings::only($path, 'nope');
    bindStubEngine();

    $refusals = diagnosticsCoded(
        app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->diagnostics,
        'config.value-type',
    );

    if (array_key_exists($path, unaskedSections())) {
        expect($refusals)->toBe([]);

        return;
    }

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0]->severity)->toBe(Severity::Warning)
        ->and($refusals[0]->message)->toBe(sprintf(
            '%s is the text "nope", where the setting takes a map of settings — an empty section is used instead.',
            str_replace('*', 'default', $path),
        ))
        ->and($refusals[0]->help)->toContain('Write the section as `key: value` pairs');
})->with(fn (): array => array_combine(
    DeclaredSettings::sections(),
    array_map(static fn (string $path): array => [$path], DeclaredSettings::sections()),
));

it('reads its sections off the shipped file by shape, and tells the two collections apart', function (): void {
    // The dataset above is only worth what this answers, so a plausible minimum stands beside it: a
    // reader that stopped recognising sections would leave every row vacuous and green.
    //
    // The rule is written out rather than read back off the file: a section is a map with keys under
    // it. A LIST holds entries and contributes the same `*` segment a keyed map does, so a set derived
    // from the dotted paths alone would demand a map where the shipped file itself shows a list — and a
    // leaf is no section at all.
    expect(count(DeclaredSettings::sections()))->toBeGreaterThan(40)
        ->and(DeclaredSettings::sections())
        ->toContain('documents.*.routes')
        ->toContain('documents.*.security')
        ->toContain('lint.leakage')
        ->toContain('documents.*.integrations.query_builder')
        // Lists: entries, not keys.
        ->not->toContain('documents.*.export.targets')
        ->not->toContain('documents.*.tags.definitions')
        // Leaves: nothing sits under them.
        ->not->toContain('documents.*.routes.include')
        ->not->toContain('on_route_error');
});

it('names only declared sections among the ones it deliberately says nothing about', function (): void {
    // An exemption that stopped naming a real section would silently excuse nothing, and the row it
    // came from would go on looking like a considered decision.
    expect(array_keys(unaskedSections()))->each->toBeIn(DeclaredSettings::sections());
});

it('reports a section once, however many keys under it were read', function (): void {
    // `documents.default` is asked about by the section pass, and every setting the build reads under
    // it walks through the same key. One defect is one line to go and fix.
    BuildSettings::yaml("documents:
  default: 'nope'
");
    bindStubEngine();

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    expect(diagnosticsCoded($result->diagnostics, 'config.value-type'))->toHaveCount(1)
        // And the document says the defaults, which is what the refusal claims was used instead.
        ->and($result->document->toArray()['info']['title'] ?? null)->toBe('API Documentation');
});

it('reports a setting refused while the build ran, not only the ones read before it', function (): void {
    // A setting is refused where it is READ, and an extension's hooks run inside the build. Collected
    // before generating, the report was missing exactly the refusals the build itself provoked — the
    // same shape as a diagnostic raised while building and lost on a warm cache hit, one layer out.
    BuildSettings::set('extensions', [SettingReadingTransformer::class]);
    BuildSettings::set(SettingReadingTransformer::SETTING, true);
    bindStubEngine();

    $refusals = diagnosticsCoded(
        app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->diagnostics,
        'config.value-type',
    );

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0]->message)->toStartWith(SettingReadingTransformer::SETTING.' is the boolean true, ');
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

/**
 * `on_route_error` names one of two behaviours, so it is read as the closed set it is rather than as
 * text that happens to be compared against two words. That is why a wrong-typed value is reported by
 * the keyword catalogue and not by the typed reader: the setting's defect is never "this is not text",
 * it is "this is not one of skeleton and omit" — and the advice a reader can act on is the two values,
 * where "quote it" would leave a quoted typo behaving exactly as the unquoted one did. Reported ONCE,
 * for the same reason: two readers refusing one key is two pieces of advice about one line.
 */
it('reads on_route_error off the file as a keyword, and refuses anything else once', function (): void {
    BuildSettings::set('on_route_error', 'omit');
    expect(app(DocumentBuilder::class)->config('default')->onRouteError)->toBe('omit');

    BuildSettings::set('on_route_error', true);

    $refusals = diagnosticsCoded(
        app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->diagnostics,
        'config.unknown-value',
    );

    expect(app(DocumentBuilder::class)->config('default')->onRouteError)->toBe('skeleton')
        ->and($refusals)->toHaveCount(1)
        ->and($refusals[0]->message)->toBe(
            'on_route_error is the boolean true, which is none of the values it takes'
            .' — it is read as "skeleton", its default.',
        )
        ->and($refusals[0]->help)->toBe('Write one of: "skeleton", "omit".')
        // And the typed reader keeps out of it, so the author is sent to one line with one answer.
        ->and(diagnosticsCoded(app(BuildConfig::class)->values()->diagnostics(), 'config.value-type'))->toBe([]);
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
