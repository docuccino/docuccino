<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PlaceOrderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * Request headers read by name through each receiver the framework offers, beside a response header SET
 * through the same method name — the one a reader matching on names alone would take for a read.
 */
final class RequestHeaderController
{
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        return response()->json($request->toInput(), 201);
    }

    public function trace(Request $request): JsonResponse
    {
        $id = $request->header('X-Request-Id');
        $fresh = RequestFacade::hasHeader('If-None-Match');
        $tenant = request()->header('X_Tenant');
        $parent = \Request::header('X-Trace-Parent');
        $forwarded = $request->header('X-Forwarded-For');

        return response()->json(['id' => $id, 'fresh' => $fresh, 'tenant' => $tenant, 'parent' => $parent, 'forwarded' => $forwarded])
            ->header('X-Served-By', 'fixture');
    }
}
