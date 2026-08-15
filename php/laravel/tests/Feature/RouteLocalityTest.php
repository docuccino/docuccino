<?php

declare(strict_types=1);

use Docuccino\Laravel\Tests\Fixtures\ComponentNames\ClaimController;
use Docuccino\Laravel\Tests\Fixtures\ComponentNames\SsoController;
use Docuccino\Laravel\Tests\Fixtures\RouteBindings\BindingController;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ErrorsController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Admin\ReportController as AdminReportController;
use Docuccino\Laravel\Tests\Fixtures\TagNames\Api\ReportController as ApiReportController;
use Docuccino\Laravel\Tests\Support\LocalityEngine;
use Illuminate\Routing\Router;

/**
 * Determinism says the same code emits the same bytes. LOCALITY says the rest: adding a route may add
 * operations and may never change one it did not touch. Every defect of this class found so far was
 * silent and green — the build repeated perfectly, it just repeated something different.
 *
 * One row per shape that mints a name or shares structure ACROSS routes, each held to byte-identical
 * output for a subject operation the extra route has nothing to do with — its own node and every
 * component it transitively `$ref`s. See {@see assertUnaffectedByUnrelatedRoute()}.
 */
it('does not move a route it did not touch', function (callable $baseline, callable $extra, string $subject, ?callable $engine): void {
    assertUnaffectedByUnrelatedRoute($baseline, $extra, $subject, $engine);
})->with([

    // One class, two shapes. The request side used to land on `Portal`/`Portal_2` by route order, so
    // documenting the read endpoint renamed the write endpoint's body.
    'the same class hoisted as a request and as a response' => [
        static fn (Router $r) => $r->post('api/zz-portal', [ClaimController::class, 'store']),
        static fn (Router $r) => $r->get('api/zz-portal', [ClaimController::class, 'show']),
        'POST /api/zz-portal',
        LocalityEngine::factory(),
    ],

    // `GET api/aaa-unrelated` sorts before both SSO routes and reaches the INPUT shape, flipping which
    // of the two same-short-name classes registers first.
    'two same-short-name classes in different namespaces' => [
        static function (Router $r): void {
            $r->post('api/zz-sso-a', [SsoController::class, 'store']);
            $r->get('api/zz-sso-b', [SsoController::class, 'show']);
        },
        static fn (Router $r) => $r->get('api/aaa-unrelated', [SsoController::class, 'unrelated']),
        'GET /api/zz-sso-b',
        LocalityEngine::factory(),
    ],

    // Both pins carry no namespace, so there is nothing to walk and both fall to the hash rung — which
    // has to be derived from the pin, not from the order the routes arrived in.
    'a #[SchemaId]-pinned pair whose pins carry no namespace' => [
        static function (Router $r): void {
            $r->get('api/zz-user-api', [ClaimController::class, 'apiUser']);
            $r->get('api/zz-user-admin', [ClaimController::class, 'adminUser']);
        },
        static fn (Router $r) => $r->get('api/aaa-user', [ClaimController::class, 'apiUser']),
        'GET /api/zz-user-api',
        LocalityEngine::factory(),
    ],

    // The unexpandable class reserved `Gizmo` and published nothing, pushing the working one onto
    // `Gizmo_2` — renamed by a route that contributed nothing, with no collision to warn about.
    'a class the analyser cannot expand, beside one it can' => [
        static fn (Router $r) => $r->get('api/zz-gizmo-working', [ClaimController::class, 'workingGizmo']),
        static fn (Router $r) => $r->get('api/zz-gizmo-broken', [ClaimController::class, 'brokenGizmo']),
        'GET /api/zz-gizmo-working',
        LocalityEngine::factory(),
    ],

    // A status already carrying two shapes gains a third. Every published name is a hash of its own
    // body by then, so the arrival must be additive — a positional suffix would renumber the others.
    'a further distinct 4xx body on a status that already has two' => [
        static function (Router $r): void {
            $r->get('api/zz-denied', [ErrorsController::class, 'denied']);
            $r->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
            $r->get('api/zz-blocked', [ErrorsController::class, 'blocked']);
            $r->get('api/zz-blocked-again', [ErrorsController::class, 'blockedAgain']);
        },
        static function (Router $r): void {
            $r->get('api/aaa-refused', [ErrorsController::class, 'refused']);
            $r->get('api/aaa-refused-again', [ErrorsController::class, 'refusedAgain']);
        },
        'GET /api/zz-denied',
        null,
    ],

    // The threshold itself: a body stated once stays inline, and a second occurrence lifts it into
    // `components`. That hoist is the third shape's business and may not reach the first two.
    'a repeated error body crossing the shared-response threshold' => [
        static function (Router $r): void {
            $r->get('api/zz-denied', [ErrorsController::class, 'denied']);
            $r->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);
            $r->get('api/zz-blocked', [ErrorsController::class, 'blocked']);
            $r->get('api/zz-blocked-again', [ErrorsController::class, 'blockedAgain']);
            $r->get('api/aaa-refused', [ErrorsController::class, 'refused']);
        },
        static fn (Router $r) => $r->get('api/aaa-refused-again', [ErrorsController::class, 'refusedAgain']),
        'GET /api/zz-denied',
        null,
    ],

    // A catch-all contributes a diagnostic and no operation. Nothing it reports may reach the routes it
    // shares a document with — least of all the paths, which it would otherwise claim all of.
    'a fallback route arriving beside an ordinary one' => [
        static fn (Router $r) => $r->get('api/zz-catch', [ApiReportController::class, 'index']),
        static fn (Router $r) => $r->prefix('api')->group(static function (Router $g): void {
            $g->fallback([ApiReportController::class, 'index']);
        }),
        'GET /api/zz-catch',
        null,
    ],

    // `{blank:slug}` types its parameter off a column and reports the one it cannot type. Both are the
    // arriving route's business: the sibling bound the ordinary way keeps its route-key integer. The
    // baseline is two bound routes so the implicit 404 they share is already hoisted — otherwise the
    // row would be re-proving the shared-error threshold above rather than the binding column.
    'a route naming a binding column beside routes that do not' => [
        static function (Router $r): void {
            $r->get('api/zz-bound/{blank}', [BindingController::class, 'blank']);
            $r->get('api/zz-bound-again/{blank}', [BindingController::class, 'blank']);
        },
        static fn (Router $r) => $r->get('api/zz-bound-column/{blank:slug}', [BindingController::class, 'blank']),
        'GET /api/zz-bound/{blank}',
        null,
    ],

    // Two hosts on one URI are two operations and OpenAPI has room for one, so the sibling is reported
    // rather than emitted. The host-less route keeps the URI, and keeps the identity it was emitted
    // under before the sibling existed.
    'a host-bound sibling arriving beside a host-less route' => [
        static fn (Router $r) => $r->get('api/zz-hosts', [ApiReportController::class, 'index']),
        static fn (Router $r) => $r->domain('admin.example.com')->get('api/zz-hosts', [AdminReportController::class, 'index']),
        'GET /api/zz-hosts',
        null,
    ],
]);
