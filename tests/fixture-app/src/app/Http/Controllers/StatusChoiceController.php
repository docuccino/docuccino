<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A status chosen between constants in one expression — `$ok ? 200 : 503` — in every place a response
 * takes its status, beside the same endpoint written as two returns; a status nothing can read; and the
 * success helpers that take a status parameter with a default, called with and without one.
 */
class StatusChoiceController extends Controller
{
    public function chain(Request $request): JsonResponse
    {
        $ok = $request->boolean('ready');

        return response()->json(['ok' => $ok])->setStatusCode($ok ? 200 : 503);
    }

    public function json(Request $request): JsonResponse
    {
        $ok = $request->boolean('ready');

        return response()->json(['ok' => $ok], $ok ? 200 : 503);
    }

    public function constructed(Request $request): JsonResponse
    {
        $ok = $request->boolean('ready');

        return new JsonResponse(['ok' => $ok], $ok ? 200 : 503);
    }

    public function branches(Request $request): JsonResponse
    {
        $ok = $request->boolean('ready');
        if ($ok) {
            return response()->json(['ok' => $ok]);
        }

        return response()->json(['ok' => $ok], 503);
    }

    public function upsert(Request $request): JsonResponse
    {
        $created = $request->boolean('fresh');

        return (new UserResource(User::query()->firstOrFail()))->response()->setStatusCode($created ? 201 : 200);
    }

    public function requested(Request $request): JsonResponse
    {
        return response()->json(['ok' => true])->setStatusCode($request->integer('code'));
    }

    public function requestedJson(Request $request): JsonResponse
    {
        return response()->json(['ok' => true], $request->integer('code'));
    }

    public function emptied(Request $request): JsonResponse
    {
        return response()->noContent($request->boolean('reset') ? 205 : 204);
    }

    public function refused(Request $request): JsonResponse
    {
        abort($request->boolean('gone') ? 410 : 404);
    }

    public function helperDefault(): JsonResponse
    {
        return $this->ok(['ok' => true]);
    }

    public function helperPassed(): JsonResponse
    {
        return $this->ok(['ok' => true], 201);
    }

    public function helperChoice(Request $request): JsonResponse
    {
        return $this->ok(['ok' => true], $request->boolean('fresh') ? 201 : 200);
    }

    public function constructedDefault(): JsonResponse
    {
        return $this->respond(['ok' => true]);
    }

    public function constructedPassed(): JsonResponse
    {
        return $this->respond(['ok' => true], 201);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function ok(array $data, int $code = 200): JsonResponse
    {
        return response()->json($data, $code);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function respond(array $data, int $status = 202): JsonResponse
    {
        return new JsonResponse($data, $status);
    }
}
