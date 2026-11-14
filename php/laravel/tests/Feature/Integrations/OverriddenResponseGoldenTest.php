<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\OverriddenResponse\OverriddenResponseController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Data\FormData;

/*
 * An action that returns one Data class and declares another with `#[Response]`. Inference reads the
 * return type and hoists its body first; the declaration outranks it, and what is left of the inferred
 * type is the trail of the `$ref` it overrode. Nothing else in the golden corpus overrides an inferred
 * response's type, so this is the document that stands in for that population.
 */
beforeEach(function (): void {
    $this->engine = static fn (): TypeEngine => WorkbenchEngine::make(analysisOverrides: [
        OverriddenResponseController::class.'::show' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(FormData::class), new SourceLocation(''))]),
    ]);
    $this->routes = static function (Router $router): void {
        $router->get('api/zz-overridden', [OverriddenResponseController::class, 'show']);
    };
});

it('publishes the declared response, and the inferred one only as the trail of what it overrode', function (): void {
    $document = localityBuild($this->routes, $this->engine)->document;

    assertGolden('overridden-response.uir.json', (new UirEmitter)->emit($document));
    assertGolden('overridden-response.openapi31.json', (new OpenApi31DownlevelEmitter)->emit($document));
});
