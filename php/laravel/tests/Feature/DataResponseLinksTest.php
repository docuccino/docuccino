<?php

declare(strict_types=1);

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
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\FleetVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\PaintedVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\VanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Van;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\VanController;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * Response links from a Data body: a response built from a model with laravel-data, linked to the
 * operations whose path parameters are matched on a column it copies. Which construction a body came from
 * is read where the action builds it (the walk is scripted here; the real engine's reading of each spelling
 * is proved in `DataSourceVisitorTest`), and what laravel-data makes of it in `DataColumnsTest`. These rows
 * prove the two meet the join and land on the member the published body documents.
 */
beforeEach(function (): void {
    $this->routes = static function (Router $router): void {
        $router->post('api/vans', [VanController::class, 'store']);
        $router->get('api/vans/{van}', [VanController::class, 'show']);
        $router->get('api/plated-vans/{van:plate}', [VanController::class, 'byPlate']);
        $router->post('api/fleet', [VanController::class, 'enlist']);
        $router->post('api/fleet/in-order', [VanController::class, 'enlistInOrder']);
        $router->post('api/unwrapped-vans', [VanController::class, 'unwrapped']);
        $router->post('api/painted-vans', [VanController::class, 'painted']);
    };

    $this->engine = static function (): TypeEngine {
        $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))]);
        $controller = VanController::class.'::';
        $file = dirname(__DIR__).'/Fixtures/ResponseLinks/VanController.php';
        $walk = static fn (string $method): callable => TraceScript::forMethod($file, VanController::class, $method, [
            'van' => new ClassT(Van::class),
            'data' => new ClassT(VanData::class),
        ]);
        $methods = ['store' => VanData::class, 'show' => VanData::class, 'byPlate' => VanData::class, 'enlist' => FleetVanData::class, 'enlistInOrder' => FleetVanData::class, 'unwrapped' => VanData::class, 'painted' => PaintedVanData::class];

        return WorkbenchEngine::make(
            classOverrides: [
                Van::class => new ClassMetadata(Van::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('plate', ScalarT::string()),
                    new PropertyMetadata('fleet_number', ScalarT::string()),
                    new PropertyMetadata('colour', ScalarT::string()),
                ]),
                VanData::class => new ClassMetadata(VanData::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('plate', ScalarT::string()),
                    new PropertyMetadata('colour', ScalarT::string()),
                ]),
                FleetVanData::class => new ClassMetadata(FleetVanData::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('fleetNumber', ScalarT::string()),
                    new PropertyMetadata('reg', ScalarT::string()),
                ]),
                PaintedVanData::class => new ClassMetadata(PaintedVanData::class, [
                    new PropertyMetadata('vin', ScalarT::string()),
                    new PropertyMetadata('plate', ScalarT::string()),
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('model_name', ScalarT::string()),
                    new PropertyMetadata('make', ScalarT::string()),
                    new PropertyMetadata('mileage', UnionT::of([ScalarT::int(), new NullT])),
                    new PropertyMetadata('weight', ScalarT::float()),
                ]),
            ],
            analysisOverrides: array_combine(
                array_map(static fn (string $method): string => $controller.$method, array_keys($methods)),
                array_map($returns, $methods),
            ),
            traceOverrides: array_combine(
                array_map(static fn (string $method): string => $controller.$method, array_keys($methods)),
                array_map($walk, array_keys($methods)),
            ),
        );
    };
});

it('publishes the links its Data bodies prove, warm as cold', function (): void {
    $warm = assertWarmEqualsCold($this->routes, $this->routes, $this->engine);

    assertGolden('data-response-links.uir.json', (new UirEmitter)->emit($warm->document));
});
