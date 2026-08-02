<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests;

use Docuccino\Laravel\DocuccinoServiceProvider;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Http\Controllers\BrokenController;
use Workbench\App\Http\Controllers\FormController;
use Workbench\App\Http\Controllers\SecretController;
use Workbench\App\Http\Controllers\WidgetController;

/**
 * The base testbench case for the Laravel adapter: registers the package provider and the
 * workbench routes exercised by the feature tests (plain, route-model-bound, attribute-decorated,
 * closure, excluded, and a deliberately-broken route).
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DocuccinoServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('api/forms', [FormController::class, 'index']);
        $router->get('api/forms/{form}', [FormController::class, 'show']);
        $router->post('api/widgets', [WidgetController::class, 'store']);
        $router->get('api/secret', [SecretController::class, 'index']);
        $router->get('api/broken', [BrokenController::class, 'ghost']);
        $router->get('api/ping', static fn () => response()->json(['pong' => true]));
    }
}
