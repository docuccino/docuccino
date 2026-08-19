<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Actions that assemble a response's arguments somewhere else and spread them into the call — the shape an
 * app reaches for once several endpoints share one envelope helper. The payload and the status are both in
 * that sequence, so nothing at the call site says what either is, and `index()` is the same envelope
 * written where it can be read. Only ever analysed.
 */
class SpreadResponseController extends Controller
{
    /** The envelope written at the call site: payload and status both readable. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => ['id' => 1]], 201);
    }

    public function show(): JsonResponse
    {
        return response()->json(...$this->envelope());
    }

    public function store(): JsonResponse
    {
        return new JsonResponse(...$this->envelope());
    }

    public function destroy(): Response
    {
        return response()->noContent(...$this->resetStatus());
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function envelope(): array
    {
        return [['data' => ['id' => 1]], 201];
    }

    /**
     * @return array{0: int}
     */
    private function resetStatus(): array
    {
        return [205];
    }
}
