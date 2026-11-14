<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ConditionalPresenceAddressData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ConditionalPresenceController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ConditionalPresenceData;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Spatie's `#[Required]` and `#[Prohibited]` handed a condition, beside the cross-field `#[RequiredIf]` and
 * `#[ProhibitedIf]`, on scalars and on nested Data, locked in emitted bytes. No golden held one.
 */
function spatieConditionalPresenceBuild(): GenerationResult
{
    bootLaravelData(null);

    $string = ScalarT::string();
    $optional = new ClassT(DataClassReflector::OPTIONAL);
    $address = new ClassT(ConditionalPresenceAddressData::class);
    $engine = static fn (): TypeEngine => WorkbenchEngine::make(
        classOverrides: [
            ConditionalPresenceData::class => new ClassMetadata(ConditionalPresenceData::class, [
                new PropertyMetadata('name', $string),
                new PropertyMetadata('kind', UnionT::of([$string, $optional])),
                new PropertyMetadata('email', $string),
                new PropertyMetadata('phone', $string),
                new PropertyMetadata('vat', $string),
                new PropertyMetadata('legacy', UnionT::of([$string, $optional])),
                new PropertyMetadata('nickname', UnionT::of([$string, $optional])),
                new PropertyMetadata('referrer', UnionT::of([$string, $optional])),
                new PropertyMetadata('home', $address),
                new PropertyMetadata('office', $address),
                new PropertyMetadata('billing', UnionT::of([$address, $optional])),
                new PropertyMetadata('archive', UnionT::of([$address, $optional])),
            ]),
            ConditionalPresenceAddressData::class => new ClassMetadata(ConditionalPresenceAddressData::class, [
                new PropertyMetadata('line', $string),
            ]),
        ],
        analysisOverrides: [
            ConditionalPresenceController::class.'::store' => new ActionAnalysis(
                returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))],
            ),
        ],
    );

    return localityBuild(
        static fn (Router $router) => $router->post('api/zz-conditional-presence', [ConditionalPresenceController::class, 'store']),
        $engine,
    );
}

it('emits Spatie presence attributes handed a condition byte-identical to their committed golden', function (): void {
    assertGolden('spatie-conditional-presence.uir.json', (new UirEmitter)->emit(spatieConditionalPresenceBuild()->document));
});
