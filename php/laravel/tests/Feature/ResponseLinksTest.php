<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Parcel;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\ParcelController;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\ParcelResource;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * Response links the code proves: a response that sends back a model's column, linked to the operations
 * whose path parameters are matched on that column. Each one is a call a client can make with what the
 * response handed it, so the rows state which proof makes it true, and the refusals which one is missing.
 */
beforeEach(function (): void {
    $this->routes = static function (Router $router): void {
        $router->bind('consignment', static fn (string $value): Parcel => new Parcel);

        $router->post('api/parcels', [ParcelController::class, 'store']);
        $router->get('api/parcels/{parcel}', [ParcelController::class, 'show']);
        $router->patch('api/parcels/{parcel}', [ParcelController::class, 'update']);
        $router->delete('api/parcels/{parcel}', [ParcelController::class, 'destroy']);
        $router->get('api/tracked-parcels/{parcel:tracking}', [ParcelController::class, 'track']);
        $router->post('api/raw-parcels', [ParcelController::class, 'storeRaw']);
        $router->get('api/consignments/{consignment}', [ParcelController::class, 'consigned']);
    };

    $this->engine = static function (): TypeEngine {
        $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))]);
        $controller = ParcelController::class.'::';

        return WorkbenchEngine::make(
            classOverrides: [
                Parcel::class => new ClassMetadata(Parcel::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('tracking', ScalarT::string()),
                    new PropertyMetadata('label', ScalarT::string()),
                ]),
            ],
            analysisOverrides: [
                $controller.'store' => $returns(ParcelResource::class),
                $controller.'show' => $returns(ParcelResource::class),
                $controller.'update' => $returns(ParcelResource::class),
                $controller.'track' => $returns(ParcelResource::class),
                $controller.'storeRaw' => $returns(Parcel::class),
                $controller.'consigned' => $returns(ParcelResource::class),
                ParcelResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('id', ScalarT::int()),
                    new ArrayShapeField('tracking', ScalarT::string()),
                    new ArrayShapeField('label', ScalarT::string()),
                ]), new SourceLocation(''))]),
            ],
        );
    };
});

it('publishes the links its routes prove, warm as cold', function (): void {
    $warm = assertWarmEqualsCold($this->routes, $this->routes, $this->engine);

    assertGolden('response-links.uir.json', (new UirEmitter)->emit($warm->document));
});
