<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExportFault;
use App\Http\Controllers\Concerns\RefusesRequests;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function App\Support\fault;
use function App\Support\gone;
use function App\Support\neverNested;
use function App\Support\noteFailure;

/**
 * Helpers an application writes as functions, global ones from its own `helpers.php` and namespaced ones
 * from `App\Support`, beside the same bodies written as methods: guards refusing a request the action
 * otherwise answers with a 200, responses an action returns, and a catch handing what it took to a helper
 * that only logs its message. The guard's method twin comes from a trait, so the controller raises no error
 * itself and stays outside the throw corpus.
 */
class FunctionHelperController extends Controller
{
    use RefusesRequests;

    public function globalHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            export_conflict();
        }

        return response()->json(['ok' => true]);
    }

    public function globalAbort(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            export_missing();
        }

        return response()->json(['ok' => true]);
    }

    public function globalCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            export_locked();
        }

        return response()->json(['ok' => true]);
    }

    public function globalValidation(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            export_name_taken();
        }

        return response()->json(['ok' => true]);
    }

    public function globalCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            export_throttled();
        }

        return response()->json(['ok' => true]);
    }

    public function globalGone(): JsonResponse
    {
        return export_gone();
    }

    public function globalFault(): JsonResponse
    {
        return export_fault(ExportFault::Gone);
    }

    public function namespacedNested(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            neverNested();
        }

        return response()->json(['ok' => true]);
    }

    public function namespacedGone(): JsonResponse
    {
        return gone();
    }

    public function namespacedFault(): JsonResponse
    {
        return fault(ExportFault::Locked);
    }

    public function methodHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedVoidHttp();
        }

        return response()->json(['ok' => true]);
    }

    public function methodCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->throttled();
        }

        return response()->json(['ok' => true]);
    }

    public function methodGone(): JsonResponse
    {
        return $this->gone();
    }

    public function methodFault(): JsonResponse
    {
        return $this->fault(ExportFault::Locked);
    }

    public function caughtNotedByFunction(Request $request): JsonResponse
    {
        try {
            $this->sharedVoidHttp();
        } catch (HttpException $e) {
            noteFailure($e);
        }

        return response()->json(['ok' => true]);
    }

    public function caughtNotedByMethod(Request $request): JsonResponse
    {
        try {
            $this->sharedVoidHttp();
        } catch (HttpException $e) {
            $this->note($e);
        }

        return response()->json(['ok' => true]);
    }

    private function throttled(): void
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }

    private function gone(): JsonResponse
    {
        return response()->json(['error' => 'gone'], 410);
    }

    private function fault(ExportFault $fault): JsonResponse
    {
        return new JsonResponse(['error' => $fault->name], $fault->value);
    }

    private function note(\Throwable $e): void
    {
        Log::warning($e->getMessage());
    }
}
