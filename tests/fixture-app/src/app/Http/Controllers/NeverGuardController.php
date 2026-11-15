<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RefusesRequests;
use App\Http\Requests\RefusableRequest;
use App\Services\RequestGuard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function App\Support\neverAbort;
use function App\Support\neverCarried;
use function App\Support\neverCustom;
use function App\Support\neverHttp;
use function App\Support\neverValidation;
use function App\Support\voidAbort;
use function App\Support\voidCarried;
use function App\Support\voidCustom;
use function App\Support\voidHttp;
use function App\Support\voidValidation;

/**
 * Guard helpers written outside the controller, refusing a request the action otherwise answers with a 200.
 * Each is written twice, returning `never` and returning `void`, as a trait method, a method on an injected
 * service, a function and a method on the form request; and a helper throwing a response for the framework
 * to send, which this controller also writes as a private and a static method; and a trait method declaring
 * it may throw anything. The framework answers the same either way, so each pair documents the same
 * responses. Only ever analysed, never dispatched.
 */
class NeverGuardController extends Controller
{
    use RefusesRequests;

    public function traitNeverHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedNeverHttp();
        }

        return response()->json(['ok' => true]);
    }

    public function serviceNeverHttp(Request $request, RequestGuard $guard): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $guard->neverHttp();
        }

        return response()->json(['ok' => true]);
    }

    public function functionNeverHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            neverHttp();
        }

        return response()->json(['ok' => true]);
    }

    public function traitVoidHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedVoidHttp();
        }

        return response()->json(['ok' => true]);
    }

    public function serviceVoidHttp(Request $request, RequestGuard $guard): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $guard->voidHttp();
        }

        return response()->json(['ok' => true]);
    }

    public function functionVoidHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            voidHttp();
        }

        return response()->json(['ok' => true]);
    }

    public function traitNeverAbort(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedNeverAbort();
        }

        return response()->json(['ok' => true]);
    }

    public function serviceNeverAbort(Request $request, RequestGuard $guard): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $guard->neverAbort();
        }

        return response()->json(['ok' => true]);
    }

    public function functionNeverAbort(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            neverAbort();
        }

        return response()->json(['ok' => true]);
    }

    public function traitVoidAbort(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedVoidAbort();
        }

        return response()->json(['ok' => true]);
    }

    public function serviceVoidAbort(Request $request, RequestGuard $guard): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $guard->voidAbort();
        }

        return response()->json(['ok' => true]);
    }

    public function functionVoidAbort(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            voidAbort();
        }

        return response()->json(['ok' => true]);
    }

    public function traitNeverCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedNeverCustom();
        }

        return response()->json(['ok' => true]);
    }

    public function serviceNeverCustom(Request $request, RequestGuard $guard): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $guard->neverCustom();
        }

        return response()->json(['ok' => true]);
    }

    public function functionNeverCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            neverCustom();
        }

        return response()->json(['ok' => true]);
    }

    public function traitVoidCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedVoidCustom();
        }

        return response()->json(['ok' => true]);
    }

    public function serviceVoidCustom(Request $request, RequestGuard $guard): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $guard->voidCustom();
        }

        return response()->json(['ok' => true]);
    }

    public function functionVoidCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            voidCustom();
        }

        return response()->json(['ok' => true]);
    }

    public function traitNeverValidation(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedNeverValidation();
        }

        return response()->json(['ok' => true]);
    }

    public function serviceNeverValidation(Request $request, RequestGuard $guard): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $guard->neverValidation();
        }

        return response()->json(['ok' => true]);
    }

    public function functionNeverValidation(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            neverValidation();
        }

        return response()->json(['ok' => true]);
    }

    public function traitVoidValidation(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedVoidValidation();
        }

        return response()->json(['ok' => true]);
    }

    public function serviceVoidValidation(Request $request, RequestGuard $guard): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $guard->voidValidation();
        }

        return response()->json(['ok' => true]);
    }

    public function functionVoidValidation(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            voidValidation();
        }

        return response()->json(['ok' => true]);
    }

    public function traitNeverCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedNeverCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function serviceNeverCarried(Request $request, RequestGuard $guard): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $guard->neverCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function functionNeverCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            neverCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function traitVoidCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedVoidCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function serviceVoidCarried(Request $request, RequestGuard $guard): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $guard->voidCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function functionVoidCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            voidCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function privateNeverCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->neverCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function staticNeverCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            self::staticallyNeverCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function privateVoidCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->voidCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function staticVoidCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            self::staticallyVoidCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function traitNeverAnything(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedNeverAnything();
        }

        return response()->json(['ok' => true]);
    }

    public function traitVoidAnything(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedVoidAnything();
        }

        return response()->json(['ok' => true]);
    }

    public function formRequestNever(RefusableRequest $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $request->refuseNever();
        }

        return response()->json(['ok' => true]);
    }

    public function formRequestVoid(RefusableRequest $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $request->refuseVoid();
        }

        return response()->json(['ok' => true]);
    }

    private function neverCarried(): never
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }

    private function voidCarried(): void
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }

    private static function staticallyNeverCarried(): never
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }

    private static function staticallyVoidCarried(): void
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }
}
