<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\SpatieDataDigestContributor;
use Docuccino\Laravel\Tests\Fixtures\WrittenDates\EpochTransformer;
use Docuccino\Laravel\Tests\Fixtures\WrittenDates\Ledger;
use Docuccino\Laravel\Tests\Fixtures\WrittenDates\LedgerEntryController;
use Docuccino\Laravel\Tests\Fixtures\WrittenDates\LedgerEntryData;
use Docuccino\Laravel\Tests\Fixtures\WrittenDates\TransformerOrderData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataContainer;
use Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer;

/**
 * A Data class taken and sent by one route, each date property told how to be written a different way —
 * by nothing, by a cast, by laravel-data's own transformer with and without a format or time zone, and by
 * an application's own transformer — under an app `data.date_format` that is a list, as laravel-data
 * allows. Beside it, a model whose `timestamp` and `datetime:U` casts both write the epoch, one as an
 * integer and one as a string. No golden held a date property with a transformer, an app format given as
 * a list, or a `timestamp` cast in a response.
 *
 * @param  array<string, mixed>  $config
 */
function writtenDatesBuild(array $config = ['data.date_format' => ['d/m/Y', 'Y-m-d']]): GenerationResult
{
    bootLaravelData(null);
    config()->set($config);

    $immutable = new ClassT(CarbonImmutable::class);

    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            Ledger::class => new ClassMetadata(Ledger::class, [
                new PropertyMetadata('id', ScalarT::int()),
                new PropertyMetadata('settled_at', ScalarT::int()),
                new PropertyMetadata('reconciled_at', new ClassT(Carbon::class)),
            ]),
            LedgerEntryData::class => new ClassMetadata(LedgerEntryData::class, [
                new PropertyMetadata('postedAt', $immutable),
                new PropertyMetadata('clearedAt', UnionT::of([new ClassT(Carbon::class), new NullT])),
                new PropertyMetadata('recordedAt', new ClassT(DateTimeImmutable::class)),
                new PropertyMetadata('seenAt', new ClassT(DateTimeInterface::class)),
                new PropertyMetadata('expiresAt', $immutable),
                new PropertyMetadata('dueOn', $immutable),
                new PropertyMetadata('stampedAt', $immutable),
                new PropertyMetadata('localAt', $immutable),
                new PropertyMetadata('bookedOn', $immutable),
                new PropertyMetadata('syncedAt', $immutable),
                new PropertyMetadata('pingedAt', UnionT::of([$immutable, new NullT])),
                new PropertyMetadata('openedOn', $immutable),
                new PropertyMetadata('closedAt', $immutable),
            ]),
        ],
        analysisOverrides: [
            LedgerEntryController::class.'::ledger' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(Ledger::class), new SourceLocation(''))]),
            LedgerEntryController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(LedgerEntryData::class), new SourceLocation(''))]),
        ],
    );

    return localityBuild(static function (Router $router): void {
        $router->post('api/zz-ledger-entries', [LedgerEntryController::class, 'store']);
        $router->get('api/zz-ledgers', [LedgerEntryController::class, 'ledger']);
    }, $engine);
}

it('emits the dates a Data class takes and sends byte-identical to their committed golden', function (): void {
    assertGolden('data-written-dates.uir.json', (new UirEmitter)->emit(writtenDatesBuild()->document));
});

/**
 * What laravel-data really writes for {@see LedgerEntryData} under `$config`, through its own service
 * provider: without it no global transformer is registered, and a date goes out as Carbon's own JSON form
 * whatever the app configured.
 *
 * @param  array<string, mixed>  $config
 * @return array{values: array<string, mixed>, nulls: array<string, mixed>}
 */
function writtenDatesWire(array $config): array
{
    writtenDatesBootRuntime($config);

    $instant = CarbonImmutable::parse('2024-01-01 00:00:00', 'UTC');
    $entry = static fn (?Carbon $cleared, ?CarbonImmutable $pinged): LedgerEntryData => new LedgerEntryData(
        $instant, $cleared, new DateTimeImmutable('2024-01-01 00:00:00+00:00'), $instant, $instant, $instant,
        $instant, $instant, $instant, $instant, $pinged, $instant, $instant,
    );

    return [
        'values' => json_decode($entry(Carbon::parse('2024-01-01 00:00:00', 'UTC'), $instant)->toJson(), true, flags: JSON_THROW_ON_ERROR),
        'nulls' => json_decode($entry(null, null)->toJson(), true, flags: JSON_THROW_ON_ERROR),
    ];
}

/** @param  array<string, mixed>  $config */
function writtenDatesBootRuntime(array $config): void
{
    bootLaravelData(null);
    config()->set($config);
    app()->register(LaravelDataServiceProvider::class);
    app()->forgetInstance(DataConfig::class);
    DataContainer::get()->reset();
}

/**
 * Whether `$value` is one the published schema admits, the rule stated from JSON Schema and RFC 3339 rather
 * than asked of the code: a type the value has, a `date-time` or `date` the value spells, and a pattern
 * named in the description that reads the value back to the same bytes. `{}` claims nothing.
 *
 * @param  array<string, mixed>  $schema
 */
function writtenDatesAdmits(array $schema, mixed $value): bool
{
    if ($schema === []) {
        return true;
    }

    $types = (array) ($schema['type'] ?? []);
    $type = match (true) {
        $value === null => 'null',
        is_int($value) => 'integer',
        is_string($value) => 'string',
        is_array($value) => 'object',
        default => get_debug_type($value),
    };

    if (! in_array($type, $types, true)) {
        return false;
    }

    if (! is_string($value)) {
        return true;
    }

    $format = $schema['format'] ?? null;
    if ($format === 'date-time' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
        return false;
    }

    if ($format === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return false;
    }

    if (preg_match('/Serialized using the date format "(.+)"\./', (string) ($schema['description'] ?? ''), $named) === 1) {
        $read = DateTimeImmutable::createFromFormat('!'.$named[1], $value);

        return $read !== false && $read->format($named[1]) === $value;
    }

    return true;
}

/*
 * The response is what laravel-data writes, so the published schema is checked against laravel-data's own
 * output under each way an app can configure it: the format, a list of formats, a leading `!`, a time zone,
 * no date transformer at all, and one of the application's own.
 */
it('publishes a response date every value laravel-data writes for it satisfies', function (array $config): void {
    $document = emittedArray(writtenDatesBuild($config));
    $properties = resolveSchema($document, $document['paths']['/api/zz-ledger-entries']['post']['responses']['201']['content']['application/json']['schema'])['properties'];
    $wire = writtenDatesWire($config);

    expect(array_keys($properties))->toHaveCount(13);
    foreach ($properties as $name => $schema) {
        expect(writtenDatesAdmits($schema, $wire['values'][$name]))->toBeTrue($name.' = '.json_encode($wire['values'][$name]).' against '.json_encode($schema))
            ->and(writtenDatesAdmits($schema, $wire['nulls'][$name]))->toBeTrue($name.' = '.json_encode($wire['nulls'][$name]).' against '.json_encode($schema));
    }
})->with([
    'the package default' => [[]],
    'an app format no keyword names' => [['data.date_format' => 'd/m/Y H:i']],
    'an app format given as a list' => [['data.date_format' => ['d/m/Y', 'Y-m-d']]],
    'an app format resetting the fields it does not read' => [['data.date_format' => '!Y-m-d']],
    'an app time zone' => [['data.date_timezone' => 'America/New_York']],
    'no date transformer configured' => [['data.transformers' => []]],
    'an application transformer configured' => [['data.transformers' => [DateTimeInterface::class => EpochTransformer::class]]],
]);

it('refuses, by the same check, the answers this used to publish', function (): void {
    // The check above has to be able to fail: the cast's integer, the app's ISO default under a list, and
    // a string over an application transformer's epoch are each refused against what is really written.
    $wire = writtenDatesWire(['data.date_format' => ['d/m/Y', 'Y-m-d']])['values'];

    expect(writtenDatesAdmits(['type' => 'integer'], $wire['expiresAt']))->toBeFalse()
        ->and(writtenDatesAdmits(['type' => 'string', 'format' => 'date-time'], $wire['postedAt']))->toBeFalse()
        ->and(writtenDatesAdmits(['type' => 'string', 'format' => 'date-time'], $wire['dueOn']))->toBeFalse()
        ->and(writtenDatesAdmits(['type' => 'string', 'format' => 'date-time'], $wire['syncedAt']))->toBeFalse()
        ->and(writtenDatesAdmits(['type' => 'string', 'description' => 'Serialized using the date format "Y-m-d".'], $wire['postedAt']))->toBeFalse();
});

it('reads a request date from the cast and a response date from the transformer', function (): void {
    // Two sources for two directions: `#[WithCast(…, format: 'U')]` is what a request is parsed with, and
    // `#[WithTransformer(…, format: 'Y-m-d')]` what a response is written with. Neither reaches the other side.
    $document = emittedArray(writtenDatesBuild(['data.date_format' => 'Y-m-d\TH:i:sP']));
    $operation = $document['paths']['/api/zz-ledger-entries']['post'];
    $request = resolveSchema($document, $operation['requestBody']['content']['application/json']['schema'])['properties'];
    $response = resolveSchema($document, $operation['responses']['201']['content']['application/json']['schema'])['properties'];

    expect($request['expiresAt'])->toBe(['description' => 'Unix timestamp (seconds).', 'type' => 'integer'])
        ->and($response['expiresAt'])->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($request['dueOn']['format'])->toBe('date-time')
        ->and($response['dueOn'])->toBe(['type' => 'string', 'format' => 'date'])
        // A transformer that formats with `U` writes the digits as a string, as the transformer's own
        // return type says, and the request still takes the configured format.
        ->and($response['stampedAt'])->toBe(['description' => 'Serialized using the date format "U".', 'type' => 'string'])
        // An application's transformer may write anything, so nothing is claimed for it.
        ->and($response['syncedAt'])->toBe([])
        ->and($response['pingedAt'])->toBe([]);
});

it('writes with the transformer laravel-data picks when a property names two', function (): void {
    // laravel-data 4.14 began looking a `#[WithTransformer]` up before a `#[WithCastAndTransformer]`; before
    // it, the first declared won. The rule is stated from the installed version, and the published schema
    // has to admit the value that version writes either way.
    writtenDatesBootRuntime([]);
    $written = (new TransformerOrderData(CarbonImmutable::parse('2024-01-01 00:00:00', 'UTC')))->toArray()['settledOn'];

    $components = new ComponentRegistry;
    (new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], new StubTypeEngine(classes: [
        TransformerOrderData::class => new ClassMetadata(TransformerOrderData::class, [new PropertyMetadata('settledOn', new ClassT(CarbonImmutable::class))]),
    ]), $components))->toSchema(new ClassT(TransformerOrderData::class));
    $schema = $components->schemas()['TransformerOrderData']['properties']['settledOn'];

    $byKind = version_compare((string) InstalledVersions::getVersion('spatie/laravel-data'), '4.14.0', '>=');

    expect((new DataClassReflector)->propertyTransformer(TransformerOrderData::class, 'settledOn')['class'] ?? null)
        ->toBe($byKind ? DateTimeInterfaceTransformer::class : EpochTransformer::class)
        ->and($schema)->toBe($byKind ? ['type' => 'string', 'format' => 'date'] : [])
        ->and($written)->toBe($byKind ? '2024-01-01' : 1704067200);
});

it('keys the fragment cache on every value that decides how a date is written', function (): void {
    // A list format and the transformers both reshape every Data date and reflect no route file.
    $digest = static function (array $config): string {
        bootLaravelData(null);
        config()->set($config);

        return (new SpatieDataDigestContributor(config()))->digest();
    };

    $base = $digest([]);

    expect($digest(['data.date_format' => ['d/m/Y', 'Y-m-d']]))->not->toBe($base)
        ->and($digest(['data.date_format' => ['Y-m-d', 'd/m/Y']]))->not->toBe($digest(['data.date_format' => ['d/m/Y', 'Y-m-d']]))
        ->and($digest(['data.transformers' => []]))->not->toBe($base)
        ->and($digest(['data.transformers' => [DateTimeInterface::class => EpochTransformer::class]]))->not->toBe($base)
        ->and($digest([]))->toBe($base);
});
