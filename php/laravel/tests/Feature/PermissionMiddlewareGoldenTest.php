<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Workbench\App\Http\Controllers\FormController;

/**
 * Routes guarded by spatie/laravel-permission's middleware, under byte-lock in a document that publishes
 * its error responses and enables the integration: each of the three middleware, an any-of list, a guard
 * suffix, the class-name spelling `::using()` renders, and one beside the framework's authenticator. No
 * other committed document carries both these routes and error responses.
 */
beforeEach(function (): void {
    refreshWithoutHttpKernel();
    app()->instance(TypeEngine::class, WorkbenchEngine::make());

    // The package registers no aliases; these are the names its documentation tells an application to.
    registerAppMiddlewareAliases([
        'role' => RoleMiddleware::class,
        'permission' => PermissionMiddleware::class,
        'role_or_permission' => RoleOrPermissionMiddleware::class,
    ]);

    /** @var Router $router */
    $router = app('router');
    $router->get('api/permissions/edit', [FormController::class, 'index'])->middleware('permission:edit');
    $router->get('api/permissions/any-permission', [FormController::class, 'index'])->middleware('permission:edit|publish');
    $router->get('api/permissions/guarded', [FormController::class, 'index'])->middleware('permission:edit,web');
    $router->get('api/permissions/role', [FormController::class, 'index'])->middleware('role:admin');
    $router->get('api/permissions/role-or-permission', [FormController::class, 'index'])->middleware('role_or_permission:editor|edit');
    $router->get('api/permissions/by-class', [FormController::class, 'index'])->middleware(PermissionMiddleware::using('edit'));
    $router->get('api/permissions/signed-in', [FormController::class, 'index'])->middleware(['auth', 'permission:edit']);
    $router->get('api/permissions/public', [FormController::class, 'index']);
    $router->getRoutes()->refreshNameLookups();

    setDocuments([
        'permissions' => [
            'info' => ['title' => 'Permission Middleware', 'version' => '1.0.0'],
            'routes' => ['include' => ['api/permissions/*']],
            'error_responses' => 'default',
            'integrations' => ['permission' => ['enabled' => true]],
        ],
    ]);
});

/** @return array<string, mixed> */
function permissionMiddlewareDocument(): array
{
    /** @var array<string, mixed> $raw */
    $raw = documentSettings('permissions');
    $config = app(DocumentConfigFactory::class)->make('permissions', $raw, 'skeleton');

    return app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document->toArray();
}

it('emits the permission-middleware routes byte-identical to the golden', function (): void {
    assertGolden('workbench-permission-middleware.uir.json', (new UirEmitter)->emit(UirDocument::fromArray(permissionMiddlewareDocument())));
});
