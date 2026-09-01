<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PayloadValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Spike C — exception-flow analysis targets.
 *
 * Each action maps to a numbered fixture case in the spike task. The spike
 * script analyses this file and measures NOISE (useless "anything can throw"
 * points) and MISSES (real exceptions not surfaced) against the 3-layer design.
 */
class ThrowsController extends Controller
{
    use AuthorizesRequests;

    /**
     * Case 1: direct abort() + abort_if(). Both are implicit Throwable throw
     * points that only Layer 2 (KnownThrowers) can turn into HttpException with
     * a constant-folded status (403 from abort_if arg1, 404 from abort arg0).
     */
    public function abortAction(int $id): void
    {
        abort_if($id === 0, 403);

        abort(404, 'not found');
    }

    /**
     * Case 1b: the same two calls with their status named rather than counted, which is the form a
     * position-counting reader would see no status in at all. Statuses distinct from case 1's so the
     * recovered value can only have come from the named argument.
     */
    public function namedAbortAction(int $id): void
    {
        abort_if($id === 0, code: 418);

        abort(code: 451, message: 'gone');
    }

    /**
     * Case 2: $this->authorize(...) via AuthorizesRequests. No @throws upstream,
     * so PHPStan can only see an implicit Throwable — Layer 2 maps authorize →
     * AuthorizationException (403).
     */
    public function authorizeAction(User $user): JsonResponse
    {
        $this->authorize('update', $user);

        return response()->json(['ok' => true]);
    }

    /**
     * Case 3: findOrFail → ModelNotFoundException (404). Spike A saw firstOrFail
     * surface as an EXPLICIT throw point via Larastan's stub; verify findOrFail
     * does the same (Layer 1 catches it; Layer 2 is a redundant safety net).
     */
    public function findOrFailAction(int $id): UserResource
    {
        return new UserResource(User::findOrFail($id));
    }

    /**
     * Case 4: inline $request->validate([...]) → ValidationException (422).
     * validate() is a macro on Request — a stress test for whether PHPStan even
     * emits a throw point for it (if not, Layer 2 must call-walk, not just read
     * throw points).
     */
    public function validateAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        return response()->json($data);
    }

    /**
     * Case 5: delegates to OrderService::place() — no @throws anywhere. The two
     * escaping exceptions (OutOfStockException at depth 1, RuntimeException at
     * depth 2) are only recoverable via Layer 3 bounded descent.
     */
    public function deepUndeclared(OrderService $orders): JsonResponse
    {
        $orders->place(1, 5);

        return response()->json(['ok' => true]);
    }

    /**
     * Case 6: same as case 5 but the callee has @throws OutOfStockException.
     * Tests whether Layer 1 surfaces the declared exception at the call site
     * WITHOUT descent (and whether the undeclared deeper RuntimeException is
     * silently lost — the docblock-trust failure mode).
     */
    public function deepDeclared(OrderService $orders): JsonResponse
    {
        $orders->placeDeclared(1, 5);

        return response()->json(['ok' => true]);
    }

    /**
     * Case 7: a vendor call PHPStan can only mark as can-contain-any-throwable
     * (Cache facade). Not in KnownThrowers, not project code — the pure noise
     * class the design drops. Must be COUNTED as dropped, not silently lost.
     */
    public function anyThrowableNoise(): JsonResponse
    {
        $value = Cache::get('some-key');

        return response()->json(['value' => $value]);
    }

    /**
     * Case 8: try/catch that CATCHES OutOfStockException but lets a literal
     * RuntimeException escape. Both are explicit typed throw points, so catch
     * subtraction is crisply observable (OutOfStock removed, RuntimeException
     * survives). NB findOrFail's coarse Throwable point (case 3) can NOT be
     * subtracted by a specific catch — documented separately in the findings.
     */
    public function tryCatch(bool $flag): JsonResponse
    {
        try {
            if ($flag) {
                throw new \App\Exceptions\OutOfStockException('caught path');
            }

            throw new \RuntimeException('escaping path');
        } catch (\App\Exceptions\OutOfStockException $e) {
            return response()->json(['caught' => $e->getMessage()]);
        }
    }

    /**
     * Case 10: a domain exception that IS an HTTP status, pinned in its own
     * parent::__construct() through a private constructor's default — the
     * static-factory idiom, where the default is the only value any instance
     * can carry.
     */
    public function pinnedHttpStatus(): void
    {
        throw \App\Exceptions\ExportRejectedException::forColumns(['sku']);
    }

    /**
     * Case 10b: the same pin written as a literal, two classes up, through an
     * abstract base that adds no constructor of its own.
     */
    public function inheritedHttpStatus(): void
    {
        throw new \App\Exceptions\PortalUnavailableException;
    }

    /**
     * Case 10c: a subclass that adds no constructor, so the framework's own runs
     * and the status is the argument this throw writes.
     */
    public function httpStatusAtThrowSite(): void
    {
        throw new \App\Exceptions\ExportLockedException(423, 'The export is locked.');
    }

    /**
     * Case 10d: the same defaulted status behind a PUBLIC constructor. Any caller
     * may pass another, so the default is a guess and the status stays unread —
     * the degraded answer plus its diagnostic.
     */
    public function unreadHttpStatus(): void
    {
        throw new \App\Exceptions\ExportBlockedException;
    }

    /**
     * Case 9: the app's OWN validate(), which the KnownThrowers registry keys
     * ValidationException/422 on by bare method name. The callee is project code
     * the engine can read, so what it actually throws (OutOfStockException) has
     * to win — a name-keyed guess must never overrule a body we analysed.
     */
    public function projectValidate(PayloadValidator $validator): JsonResponse
    {
        return response()->json($validator->validate(['sku' => 'abc-1']));
    }
}
