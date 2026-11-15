<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExportFault;
use App\Http\Responses\GoneJsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Responses an action hands the framework inside an exception rather than returning them: thrown in an
 * `HttpResponseException` built each way a response is, thrown from a helper, and handed to `abort()`; one a
 * catch takes, and one handed to `abort()` inside a catch for the `HttpException` it is not. The framework
 * sends each carried response as it is. Only ever analysed, never dispatched.
 */
class CarriedResponseController extends Controller
{
    public function helperJson(): JsonResponse
    {
        throw new HttpResponseException(response()->json(['error' => 'locked'], 423));
    }

    public function facadeJson(): JsonResponse
    {
        throw new HttpResponseException(Response::json(['error' => 'locked'], 423));
    }

    public function newJson(): JsonResponse
    {
        throw new HttpResponseException(new JsonResponse(['error' => 'locked'], 423));
    }

    public function text(): JsonResponse
    {
        throw new HttpResponseException(response('Already submitted.', 409));
    }

    public function redirect(): JsonResponse
    {
        throw new HttpResponseException(redirect('/login'));
    }

    public function subclass(): JsonResponse
    {
        throw new HttpResponseException(new GoneJsonResponse);
    }

    public function viaHelper(): JsonResponse
    {
        $this->refuse();

        return response()->json(['ok' => true]);
    }

    public function caseHelper(): JsonResponse
    {
        throw new HttpResponseException($this->faulted(ExportFault::Locked));
    }

    public function caseChosen(Request $request): JsonResponse
    {
        $fault = $request->boolean('locked') ? ExportFault::Locked : ExportFault::Gone;

        throw new HttpResponseException(response()->json(['error' => $fault->name], $fault->value));
    }

    public function abortJson(): JsonResponse
    {
        abort(response()->json(['error' => 'locked'], 423));
    }

    public function abortFacade(): JsonResponse
    {
        abort(Response::json(['error' => 'locked'], 423));
    }

    public function abortIf(Request $request): JsonResponse
    {
        abort_if($request->boolean('locked'), response()->json(['error' => 'locked'], 423));

        return response()->json(['ok' => true]);
    }

    public function caught(): JsonResponse
    {
        try {
            throw new HttpResponseException(response()->json(['error' => 'locked'], 423));
        } catch (HttpResponseException) {
            return response()->json(['ok' => true]);
        }
    }

    public function abortCaughtAsHttp(): JsonResponse
    {
        try {
            abort(response()->json(['error' => 'locked'], 423));
        } catch (HttpException) {
            return response()->json(['ok' => true]);
        }
    }

    public function abortStatus(): JsonResponse
    {
        abort(423);
    }

    protected function refuse(): void
    {
        throw new HttpResponseException(response()->json(['error' => 'locked'], 423));
    }

    protected function faulted(ExportFault $fault): JsonResponse
    {
        return response()->json(['error' => $fault->name], $fault->value);
    }
}
