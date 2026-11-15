<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Docuccino\Core\Emit\UirEmitter;
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
use Docuccino\Laravel\Tests\Fixtures\WrittenDates\Ledger;
use Docuccino\Laravel\Tests\Fixtures\WrittenDates\LedgerEntryController;
use Docuccino\Laravel\Tests\Fixtures\WrittenDates\LedgerEntryData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * A Data class taken and sent by one route, each date property told how to be written a different way —
 * by nothing, by a cast, by laravel-data's own transformer with and without a format or time zone, and by
 * an application's own transformer — under an app `data.date_format` that is a list, as laravel-data
 * allows. Beside it, a model whose `timestamp` and `datetime:U` casts both write the epoch, one as an
 * integer and one as a string. No golden held a date property with a transformer, an app format given as
 * a list, or a `timestamp` cast in a response.
 */
function writtenDatesBuild(): GenerationResult
{
    bootLaravelData(null);
    config()->set('data.date_format', ['d/m/Y', 'Y-m-d']);

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
