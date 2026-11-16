<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExportFault;
use App\Enums\TokenRefusal;
use App\Exceptions\ExportConflictException;
use App\Exceptions\ExportFaultException;
use App\Exceptions\ExportLockedException;
use App\Exceptions\Refused;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Requests refused through `throw_if()` and `throw_unless()`, each in an action that otherwise answers with a
 * 200. Laravel throws the exception it is handed, builds one from a class name and the parameters after it,
 * calls a closure for one, or makes a `RuntimeException` of a message. Some guards, `abort_if()` and
 * `abort_unless()` among them, are handed a condition they never throw on, and `report_if()` throws nothing.
 * Only ever analysed, never dispatched.
 */
class ThrowIfController extends Controller
{
    /** Exports are never read-only here, so no guard on it can throw. */
    private const READ_ONLY = false;

    public function ifInstance(Request $request): JsonResponse
    {
        throw_if($request->boolean('busy'), new HttpException(409, 'The export is busy.'));

        return response()->json(['ok' => true]);
    }

    public function unlessInstance(Request $request): JsonResponse
    {
        throw_unless($request->boolean('open'), new ExportLockedException(423, 'The export is locked.'));

        return response()->json(['ok' => true]);
    }

    public function ifClassString(Request $request): JsonResponse
    {
        throw_if($request->boolean('locked'), ExportLockedException::class, 423, 'The export is locked.');

        return response()->json(['ok' => true]);
    }

    public function unlessClassString(Request $request): JsonResponse
    {
        throw_unless($request->boolean('open'), HttpException::class, 409, 'The export is busy.');

        return response()->json(['ok' => true]);
    }

    public function ifMessage(Request $request): JsonResponse
    {
        throw_if($request->boolean('broken'), 'The export is broken.');

        return response()->json(['ok' => true]);
    }

    public function ifDefault(Request $request): JsonResponse
    {
        throw_if($request->boolean('broken'));

        return response()->json(['ok' => true]);
    }

    public function ifEnumInstance(Request $request): JsonResponse
    {
        throw_if($request->boolean('locked'), new ExportFaultException(ExportFault::Locked));

        return response()->json(['ok' => true]);
    }

    public function ifEnumClassString(Request $request): JsonResponse
    {
        throw_if($request->boolean('gone'), ExportFaultException::class, ExportFault::Gone);

        return response()->json(['ok' => true]);
    }

    public function unlessReasonClassString(Request $request): JsonResponse
    {
        throw_unless($request->boolean('fresh'), Refused::class, TokenRefusal::Expired);

        return response()->json(['ok' => true]);
    }

    public function ifFactory(Request $request): JsonResponse
    {
        throw_if($request->boolean('taken'), ExportConflictException::notPermitted());

        return response()->json(['ok' => true]);
    }

    public function ifLocal(Request $request): JsonResponse
    {
        $locked = new ExportLockedException(423, 'The export is locked.');
        throw_if($request->boolean('locked'), $locked);

        return response()->json(['ok' => true]);
    }

    public function ifNamed(Request $request): JsonResponse
    {
        throw_if(exception: new HttpException(409), condition: $request->boolean('busy'));

        return response()->json(['ok' => true]);
    }

    public function ifClosure(Request $request): JsonResponse
    {
        throw_if($request->boolean('gone'), fn () => new HttpException(410));

        return response()->json(['ok' => true]);
    }

    public function ifCarried(Request $request): JsonResponse
    {
        throw_if($request->boolean('slow'), new HttpResponseException(response()->json(['error' => 'slow down'], 429)));

        return response()->json(['ok' => true]);
    }

    public function ifNamedParameter(Request $request): JsonResponse
    {
        throw_if($request->boolean('locked'), ExportLockedException::class, statusCode: 423);

        return response()->json(['ok' => true]);
    }

    public function ifChosen(Request $request, int $status): JsonResponse
    {
        throw_if($request->boolean('refuse'), new HttpException($status));

        return response()->json(['ok' => true]);
    }

    public function ifClassStringChosen(Request $request, int $status): JsonResponse
    {
        throw_if($request->boolean('refuse'), HttpException::class, $status);

        return response()->json(['ok' => true]);
    }

    public function unlessReasonChosen(Request $request, TokenRefusal $reason): JsonResponse
    {
        throw_unless($request->boolean('fresh'), Refused::class, $reason);

        return response()->json(['ok' => true]);
    }

    public function ifCaught(Request $request): JsonResponse
    {
        try {
            throw_if($request->boolean('locked'), ExportLockedException::class, 423);
        } catch (ExportLockedException) {
            return response()->json(['ok' => false]);
        }

        return response()->json(['ok' => true]);
    }

    public function declaredGuard(Request $request): JsonResponse
    {
        $this->refuseBusy($request->boolean('busy'));

        return response()->json(['ok' => true]);
    }

    public function ifReadOnly(): JsonResponse
    {
        throw_if(self::READ_ONLY, new HttpException(503));

        return response()->json(['ok' => true]);
    }

    public function abortIfReadOnly(): JsonResponse
    {
        abort_if(self::READ_ONLY, 503);

        return response()->json(['ok' => true]);
    }

    public function ifDocumentedFalse(Request $request): JsonResponse
    {
        /** @var false $busy */
        $busy = $request->boolean('busy');
        throw_if($busy, new HttpException(409));

        return response()->json(['ok' => true]);
    }

    public function reportIf(Request $request): JsonResponse
    {
        report_if($request->boolean('busy'), new HttpException(409));

        return response()->json(['ok' => true]);
    }

    public function ifTrue(): JsonResponse
    {
        throw_if(true, new HttpException(409));
    }

    public function ifFalse(): JsonResponse
    {
        throw_if(false, new HttpException(409));

        return response()->json(['ok' => true]);
    }

    public function unlessFalse(): JsonResponse
    {
        throw_unless(false, new HttpException(409));
    }

    public function unlessTrue(): JsonResponse
    {
        throw_unless(true, new HttpException(409));

        return response()->json(['ok' => true]);
    }

    public function abortIfTrue(): JsonResponse
    {
        abort_if(true, 403);
    }

    public function abortIfFalse(): JsonResponse
    {
        abort_if(false, 403);

        return response()->json(['ok' => true]);
    }

    public function abortUnlessTrue(): JsonResponse
    {
        abort_unless(true, 403);

        return response()->json(['ok' => true]);
    }

    /**
     * @throws HttpException
     */
    private function refuseBusy(bool $busy): void
    {
        throw_if($busy, HttpException::class, 409, 'The export is busy.');
    }
}
