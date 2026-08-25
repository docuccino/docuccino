<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shape a receipt endpoint takes once the status is not the one the body-builder defaults to: the
 * response is built, and the status — sometimes the media type too — is stamped on afterwards through the
 * fluent setter Symfony gives every response. Only ever analysed, never dispatched.
 */
final class WebhookReceiptController
{
    /** Accepts the delivery for later processing — the body says queued, the status says accepted. */
    public function store(Request $request): JsonResponse
    {
        return response()->json(['queued' => true, 'id' => $request->string('delivery_id')->toString()])
            ->setStatusCode(202);
    }

    /** Rejects a delivery whose signature did not verify, as RFC 9457 problem JSON. */
    public function reject(Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://errors.example.test/signature',
            'title' => 'Signature mismatch',
        ])
            ->header('X-Delivery', $request->string('delivery_id')->toString())
            ->header('Content-Type', 'application/problem+json')
            ->setStatusCode(422);
    }

    /** Replays a batch: a multi-status built as a response object and stamped afterwards. */
    public function replay(Request $request): JsonResponse
    {
        return (new JsonResponse(['replayed' => $request->integer('count')]))
            ->withHeaders(['X-Replay' => 'true'])
            ->setStatusCode(207);
    }

    /** Relays whatever the upstream answered, status included — so nothing here states one. */
    public function relay(Request $request): JsonResponse
    {
        return response()->json(['relayed' => true])
            ->setStatusCode($request->integer('upstream_status'));
    }

    /** Rejects a stale replay as problem JSON, tagged with whichever correlation header the deployment set. */
    public function stale(Request $request): JsonResponse
    {
        return (new JsonResponse(
            ['type' => 'https://errors.example.test/replay-window', 'title' => 'Replay window closed'],
            409,
            ['Content-Type' => 'application/problem+json'],
        ))->header((string) config('webhooks.correlation_header'), $request->string('delivery_id')->toString());
    }

    /** Fills the body in after the response object exists, which is not a shape the engine follows. */
    public function digest(): JsonResponse
    {
        return (new JsonResponse)
            ->setData(['digest' => 'ok'])
            ->setStatusCode(202);
    }
}
