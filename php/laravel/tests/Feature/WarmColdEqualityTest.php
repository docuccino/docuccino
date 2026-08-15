<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Fixtures\ComponentNames\ClaimController;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\SsoController;
use Docuccino\Laravel\Tests\Fixtures\RouteBindings\BindingController;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ErrorsController;
use Docuccino\Laravel\Tests\Support\LocalityEngine;
use Illuminate\Routing\Router;

/**
 * A fragment cache is only sound if a warm build is indistinguishable from a cold one — bytes AND
 * diagnostics. The existing cache suite holds the route set constant between warming and building,
 * which is the degenerate case; the defects live where the route set CHANGES between the two, because
 * that is when a fragment outlives the document it was cached for and nothing invalidates it.
 *
 * Each row warms on one route set and documents another. See {@see assertWarmEqualsCold()}.
 */

// A shared error body across two operations, and two same-short-name classes contesting one component
// name — the two things a warm build reassembles rather than recomputes.
$base = static function (Router $r): void {
    $r->get('api/zz-denied', [ErrorsController::class, 'denied']);
    $r->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
    $r->post('api/zz-sso-a', [SsoController::class, 'store']);
    $r->get('api/zz-sso-b', [SsoController::class, 'show']);
};

// The same set with one of the two contestants gone — the app that deleted an endpoint.
$survivor = static function (Router $r): void {
    $r->get('api/zz-denied', [ErrorsController::class, 'denied']);
    $r->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
    $r->post('api/zz-sso-a', [SsoController::class, 'store']);
};

it('serves a warm build exactly what a cold one would', function (callable $before, callable $after): void {
    assertWarmEqualsCold($before, $after, LocalityEngine::factory());
})->with([

    // The shape the existing cache suite already covers, kept as the row it is: nothing changed, so
    // every fragment hits and nothing has to be noticed.
    'the same route set twice' => [$base, $base],

    // The added route contests a component name with one already cached, so the warm build has to
    // rename a fragment it did not rebuild.
    'a route added' => [$survivor, $base],

    // The one nothing invalidates: no fragment belongs to a route that is gone, so the survivor's
    // cached `$ref` — settled while it was contested — has to come back to the plain name by itself.
    'a route removed' => [$base, $survivor],

    // The route name is the operationId, so a fragment keyed without it answers under the old name.
    'a route renamed' => [
        static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-named', [ClaimController::class, 'show'])->name('portal.show');
        },
        static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-named', [ClaimController::class, 'show'])->name('portal.read');
        },
    ],

    // Laravel parses `:slug` out of `uri()`, so these two are the same signature down to the byte while
    // typing their parameter off different columns. A key that leaves the column out serves the first
    // one's integer for the second's slug.
    'a route that named a binding column' => [
        static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-bound/{blank}', [BindingController::class, 'blank']);
        },
        static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-bound/{blank:slug}', [BindingController::class, 'blank']);
        },
    ],

    // The diagnostic half. An untypable binding column is reported by the route's own fragment, so a
    // warm hit — which reassembles rather than rebuilds — has to replay it or the warm build is quietly
    // more confident than the cold one.
    'a binding column nothing types, twice' => [
        $untypedColumn = static function (Router $r) use ($base): void {
            $base($r);
            $r->get('api/zz-bound/{blank:slug}', [BindingController::class, 'blank']);
        },
        $untypedColumn,
    ],

    // A catch-all reports and emits nothing, and its report is a document-level one — so it must survive
    // a build where every fragment beside it came back warm.
    'a fallback route added' => [
        $base,
        static function (Router $r) use ($base): void {
            $base($r);
            $r->prefix('api')->group(static function (Router $g): void {
                $g->fallback([ClaimController::class, 'show']);
            });
        },
    ],

    // Method, URI, name, middleware and action all agree — the host is the only thing between the two
    // routes, so a key that leaves it out serves the first host's fragment for the second.
    'a route rehosted' => [
        static function (Router $r) use ($base): void {
            $base($r);
            $r->domain('a.example.com')->get('api/zz-hosted', [ClaimController::class, 'show']);
        },
        static function (Router $r) use ($base): void {
            $base($r);
            $r->domain('b.example.com')->get('api/zz-hosted', [ClaimController::class, 'show']);
        },
    ],
]);
