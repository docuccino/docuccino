<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests;

use Docuccino\Laravel\DocuccinoServiceProvider;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Http\Controllers\BrokenController;
use Workbench\App\Http\Controllers\FormController;
use Workbench\App\Http\Controllers\IntegrationsController;
use Workbench\App\Http\Controllers\SecretController;
use Workbench\App\Http\Controllers\ValidationController;
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

    /**
     * The default `viewer.middleware` includes the `web` group, whose session/cookie encryption
     * needs an application key — set a fixed one so the viewer feature tests run as a real app would.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:AckfSECXIvnK5r28GVIWUAxmbBSjTsmF0FYqwoDL18E=');
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('api/forms', [FormController::class, 'index']);
        $router->get('api/forms/{form}', [FormController::class, 'show']);
        $router->post('api/widgets', [WidgetController::class, 'store']);
        $router->post('api/tickets', [ValidationController::class, 'store']);
        $router->get('api/secret', [SecretController::class, 'index']);
        $router->get('api/broken', [BrokenController::class, 'ghost']);
        $router->get('api/ping', static fn () => response()->json(['pong' => true]));

        // Phase-4 integration routes (Spatie Data, API Resources, JSON:API, Eloquent, status codes).
        $router->post('api/articles', [IntegrationsController::class, 'storeArticle']);
        $router->get('api/article-resources', [IntegrationsController::class, 'listArticleResources']);
        $router->get('api/article-resources/{id}', [IntegrationsController::class, 'showArticleResource']);
        $router->get('api/jsonapi-articles/{id}', [IntegrationsController::class, 'showJsonApiArticle']);
        $router->get('api/model-widgets/{id}', [IntegrationsController::class, 'showWidget']);
        $router->delete('api/model-widgets/{id}', [IntegrationsController::class, 'destroyWidget']);
        $router->post('api/reports', [IntegrationsController::class, 'storeReport']);
    }
}
