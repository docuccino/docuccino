<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\GoneJsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Responses built into a local whose status, body or headers a later statement restates —
 * `$response->setStatusCode(202);` on a line of its own rather than chained onto the call that built it — for
 * every kind of response, and for one thrown for the framework to send; beside a status set on one branch,
 * set twice, set before the local is replaced, a helper handed the response that sets it, statements
 * setting the content or a header, and statements that only read the response. Only ever analysed, never
 * dispatched.
 */
class StatementStatusController extends Controller
{
    public function json(): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        $response->setStatusCode(202);

        return $response;
    }

    public function plain(): Response
    {
        $response = response('queued');
        $response->setStatusCode(202);

        return $response;
    }

    public function stream(): StreamedResponse
    {
        $response = response()->stream(static function (): void {
            echo 'chunk';
        });
        $response->setStatusCode(202);

        return $response;
    }

    public function redirect(): RedirectResponse
    {
        $response = redirect('/exports');
        $response->setStatusCode(303);

        return $response;
    }

    public function constructed(): JsonResponse
    {
        $response = new JsonResponse(['queued' => true]);
        $response->setStatusCode(202);

        return $response;
    }

    public function subclass(): GoneJsonResponse
    {
        $response = new GoneJsonResponse;
        $response->setStatusCode(404);

        return $response;
    }

    public function branch(Request $request): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        if ($request->boolean('defer')) {
            $response->setStatusCode(202);
        }

        return $response;
    }

    public function twice(): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        $response->setStatusCode(201);
        $response->setStatusCode(202);

        return $response;
    }

    public function replaced(): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        $response->setStatusCode(202);
        $response = response()->json(['created' => true], 201);

        return $response;
    }

    public function helper(): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        $this->accept($response);

        return $response;
    }

    public function chainedStatement(): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        $response->setStatusCode(202)->header('Retry-After', '30');

        return $response;
    }

    public function unread(Request $request): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        $response->setStatusCode($request->integer('status'));

        return $response;
    }

    public function content(): Response
    {
        $response = response('queued', 202);
        $response->setContent('accepted');

        return $response;
    }

    public function header(): Response
    {
        $response = response('id,total', 202);
        $response->header('Content-Type', 'text/csv');

        return $response;
    }

    public function headerBag(): JsonResponse
    {
        $response = response()->json(['queued' => true], 202);
        $response->headers->set('X-Queue', 'exports');

        return $response;
    }

    public function reads(): JsonResponse
    {
        $response = response()->json(['queued' => true], 202);
        logger()->info('queued', ['status' => $response->getStatusCode(), 'body' => $response->getData(true)]);

        return $response;
    }

    public function thrown(): never
    {
        $response = response()->json(['queued' => false]);
        $response->setStatusCode(409);

        throw new HttpResponseException($response);
    }

    private function accept(JsonResponse $response): void
    {
        $response->setStatusCode(202);
    }
}
