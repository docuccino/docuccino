<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Responses a statement reassigns from a chain made on themselves (`$response = $response->header(…)`), the
 * shape a helper adding the same headers to every response takes: in a helper handed the response, with one
 * header or several, twice in a row, through a second local, and with the media type set; the helper's
 * result named or reassigned in the action; the statement inline in an action; and, beside them, the same
 * helper returning the chain without naming it. Only ever analysed, never dispatched.
 */
class ReassignedResponseController extends Controller
{
    public function helper(): JsonResponse
    {
        return $this->withCors(response()->json(['queued' => true], 202));
    }

    public function helperReturning(): JsonResponse
    {
        return $this->corsReturned(response()->json(['queued' => true], 202));
    }

    public function helperHeaders(): JsonResponse
    {
        return $this->withCorsHeaders(response()->json(['queued' => true], 202));
    }

    public function helperTwice(): JsonResponse
    {
        return $this->withCorsTwice(response()->json(['queued' => true], 202));
    }

    public function helperThroughLocal(): JsonResponse
    {
        return $this->withCorsThroughLocal(response()->json(['queued' => true], 202));
    }

    public function helperProblem(): JsonResponse
    {
        return $this->asProblem(response()->json(['title' => 'Gone'], 410));
    }

    public function helperProblemReturning(): JsonResponse
    {
        return $this->problemReturned(response()->json(['title' => 'Gone'], 410));
    }

    public function named(): JsonResponse
    {
        $response = $this->withCors(response()->json(['queued' => true], 202));

        return $response;
    }

    public function reassigned(): JsonResponse
    {
        $response = response()->json(['queued' => true], 202);
        $response = $this->withCors($response);

        return $response;
    }

    public function inline(): JsonResponse
    {
        $response = response()->json(['queued' => true], 202);
        $response = $response->header('Access-Control-Allow-Origin', '*');

        return $response;
    }

    public function handed(JsonResponse $response): JsonResponse
    {
        $response = $response->header('Access-Control-Allow-Origin', '*');

        return $response;
    }

    private function withCors(JsonResponse $response): JsonResponse
    {
        $response = $response->header('Access-Control-Allow-Origin', '*');

        return $response;
    }

    private function corsReturned(JsonResponse $response): JsonResponse
    {
        return $response->header('Access-Control-Allow-Origin', '*');
    }

    private function withCorsHeaders(JsonResponse $response): JsonResponse
    {
        $response = $response->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST',
        ]);

        return $response;
    }

    private function withCorsTwice(JsonResponse $response): JsonResponse
    {
        $response = $response->header('Access-Control-Allow-Origin', '*');
        $response = $response->header('Access-Control-Allow-Methods', 'GET, POST');

        return $response;
    }

    private function withCorsThroughLocal(JsonResponse $response): JsonResponse
    {
        $allowed = $response->header('Access-Control-Allow-Origin', '*');
        $response = $allowed->header('Access-Control-Allow-Methods', 'GET, POST');

        return $response;
    }

    private function asProblem(JsonResponse $response): JsonResponse
    {
        $response = $response->header('Content-Type', 'application/problem+json');

        return $response;
    }

    private function problemReturned(JsonResponse $response): JsonResponse
    {
        return $response->header('Content-Type', 'application/problem+json');
    }
}
