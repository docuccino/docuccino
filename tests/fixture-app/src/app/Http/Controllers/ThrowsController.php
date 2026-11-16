<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ExportLockedException;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ExportProbeQuery;
use App\Services\ManifestDeclaredQuery;
use App\Services\ManifestRelayQuery;
use App\Services\ManifestReviewQuery;
use App\Services\OrderService;
use App\Services\PayloadValidator;
use App\Support\Attempts;
use App\Support\Concerns\GuardsProbeState;
use App\Support\ProbeGuards;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    use GuardsProbeState;

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
     * Case 8': the same subtraction across a call whose every declared
     * exception the catch takes. The guard throws nothing it does not
     * declare, so only the literal RuntimeException escapes the action.
     */
    public function tryCatchDeclared(bool $offline, bool $oversized): JsonResponse
    {
        try {
            $this->guardProbeReachable($offline, $oversized);
        } catch (\App\Exceptions\ExportOfflineException $e) {
            return response()->json(['caught' => $e->getMessage()]);
        }

        throw new \RuntimeException('escaping path');
    }

    /**
     * Case 8'a: the same catch around a declaring call that runs a closure the
     * action hands it. The catch takes the guard's own exception and nothing
     * the closure throws, so the order service's two escape the action.
     */
    public function tryCatchDeclaredClosure(OrderService $orders, bool $offline): JsonResponse
    {
        try {
            $this->whileProbeReachable($offline, function () use ($orders): void {
                $orders->place(1, 5);
            });
        } catch (\App\Exceptions\ExportOfflineException $e) {
            return response()->json(['caught' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8'b: a catch that takes what the CLOSURE throws. The call keeps a
     * throw point of its own, and the exception the closure raises is still
     * one the action turns into a 200; only the literal RuntimeException
     * escapes.
     */
    public function tryCatchClosure(ConnectionInterface $connection, bool $flag): JsonResponse
    {
        try {
            $connection->transaction(function () use ($flag): void {
                if ($flag) {
                    throw new \App\Exceptions\OutOfStockException('caught path');
                }
            });
        } catch (\App\Exceptions\OutOfStockException $e) {
            return response()->json(['caught' => $e->getMessage()]);
        }

        throw new \RuntimeException('escaping path');
    }

    /**
     * Case 8'': the same catch around a call that declares NOTHING, so what
     * it throws is found by descending into it — OutOfStockException one
     * level down, RuntimeException two. The catch takes the first and the
     * second escapes.
     */
    public function caughtUndeclared(OrderService $orders): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\App\Exceptions\OutOfStockException $e) {
            return response()->json(['caught' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''': the catch takes everything placeDeclared() DECLARES, and
     * reserve()'s RuntimeException two levels down is what it does not —
     * hidden behind the docblock exactly as in case 6, so only the literal
     * after the try surfaces.
     */
    public function caughtDeclaredWithResidue(OrderService $orders, bool $retry): JsonResponse
    {
        try {
            $orders->placeDeclared(1, 5);
        } catch (\App\Exceptions\OutOfStockException $e) {
            Log::warning($e->getMessage());
        }

        if ($retry) {
            throw new \LogicException('retry is not supported');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''a: a catch of a base class both descended exceptions extend.
     * The literal after the try is what survives.
     */
    public function caughtUndeclaredByParent(OrderService $orders, bool $retry): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\Exception $e) {
            Log::warning($e->getMessage());
        }

        if ($retry) {
            throw new \LogicException('retry is not supported');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''b: a catch of the interface every exception implements.
     */
    public function caughtUndeclaredByInterface(OrderService $orders, bool $retry): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\Throwable $e) {
            Log::warning($e->getMessage());
        }

        if ($retry) {
            throw new \LogicException('retry is not supported');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''c: one catch naming both descended classes.
     */
    public function caughtUndeclaredMulti(OrderService $orders, bool $retry): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\App\Exceptions\OutOfStockException|\RuntimeException $e) {
            Log::warning($e->getMessage());
        }

        if ($retry) {
            throw new \LogicException('retry is not supported');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''d: a catch that rethrows what it caught — the exception still
     * leaves the action, through the `throw $e`.
     */
    public function caughtUndeclaredRethrown(OrderService $orders): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\App\Exceptions\OutOfStockException $e) {
            report($e);

            throw $e;
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''d': the same rethrow from a catch wider than anything it names,
     * so the `throw $e` says only `Exception` — what it lets out is whatever
     * the try raised, both descended exceptions.
     */
    public function caughtWideRethrown(OrderService $orders): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\Exception $e) {
            report($e);

            throw $e;
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''d'': the transaction idiom — roll back whatever happened, then
     * let it out unchanged.
     */
    public function caughtRolledBack(OrderService $orders): JsonResponse
    {
        DB::beginTransaction();

        try {
            $orders->place(1, 5);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''d''b: the same idiom around a call that declares what it
     * throws, which is what the rethrow lets out — and not what it hides.
     */
    public function caughtRolledBackDeclared(OrderService $orders): JsonResponse
    {
        DB::beginTransaction();

        try {
            $orders->placeDeclared(1, 5);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''d''c: the same idiom around a `throw` written in the try.
     */
    public function caughtRolledBackLiteral(OrderService $orders, int $qty): JsonResponse
    {
        DB::beginTransaction();

        try {
            if ($qty > 10) {
                throw new \App\Exceptions\OutOfStockException('too many');
            }
            $orders->reserve(1, $qty);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''d''': a rethrow on one path only — what the catch keeps on the
     * other does not change what can leave.
     */
    public function caughtSometimesRethrown(OrderService $orders, bool $strict): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\Exception $e) {
            if ($strict) {
                throw $e;
            }

            return response()->json(['caught' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''d'''': a catch naming a class that does not exist takes
     * nothing, so both descended exceptions leave.
     */
    public function caughtUnknownClass(OrderService $orders): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\App\Exceptions\NoSuchException $e) {
            return response()->json(['caught' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''d''''': a thrown class that does not exist is kept, even under
     * a catch of everything an exception can be.
     */
    public function caughtUnknownThrown(OrderService $orders): JsonResponse
    {
        try {
            $orders->placeUnknown();
        } catch (\Exception $e) {
            return response()->json(['caught' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''l: a catch that hands what it caught to the framework's
     * report(), whose body passes it on to the exception handler — nothing
     * this build can read shows it is not let out again, so both descended
     * exceptions leave.
     */
    public function caughtAndReported(OrderService $orders): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\Exception $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''m: the report-then-rethrow helper — the catch says no
     * `throw`, and the method it hands the exception to does.
     */
    public function caughtHandedToRethrower(OrderService $orders): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\Exception $e) {
            $orders->failWith($e);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''n: the same helper around a call that DECLARES what it
     * throws, so no point survives the catch and the hand-off is all that
     * says the declared class leaves.
     */
    public function caughtDeclaredHandedToRethrower(OrderService $orders): JsonResponse
    {
        try {
            $orders->placeDeclared(1, 5);
        } catch (\App\Exceptions\OutOfStockException $e) {
            $orders->failWith($e);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''o: a static helper that rethrows what it is handed.
     */
    public function caughtHandedToStaticRethrower(OrderService $orders): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\Exception $e) {
            OrderService::escalate($e);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''p: a helper that only logs the message of what it is
     * handed, which its body shows — so the catch still takes both, and the
     * literal after the try is what survives.
     */
    public function caughtHandedToLogger(OrderService $orders, ProbeGuards $guards, bool $retry): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\Exception $e) {
            $guards->note($e);
        }

        if ($retry) {
            throw new \LogicException('retry is not supported');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''e: a catch that translates what it caught into another
     * exception, which is what leaves.
     */
    public function caughtUndeclaredTranslated(OrderService $orders): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\App\Exceptions\OutOfStockException $e) {
            throw new \LogicException('cannot place the order', 0, $e);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''f: nested tries. The inner catch translates OutOfStockException
     * to a LogicException; the outer one takes the RuntimeException from two
     * levels down, and leaves the translation alone.
     */
    public function caughtUndeclaredNested(OrderService $orders): JsonResponse
    {
        try {
            try {
                $orders->place(1, 5);
            } catch (\App\Exceptions\OutOfStockException $e) {
                throw new \LogicException('cannot place the order', 0, $e);
            }
        } catch (\RuntimeException $e) {
            return response()->json(['queued' => true]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''g: a `finally` with no catch takes nothing.
     */
    public function undeclaredInFinallyOnly(OrderService $orders): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } finally {
            Cache::forget('placing');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''h: a catch NARROWER than what is thrown. reserve() throws a
     * plain RuntimeException, which no UnexpectedValueException catch takes,
     * so both descended exceptions still leave.
     */
    public function caughtUndeclaredNarrower(OrderService $orders): JsonResponse
    {
        try {
            $orders->place(1, 5);
        } catch (\UnexpectedValueException $e) {
            return response()->json(['caught' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''i: the catch written inside the callee, around a deeper
     * undeclared call — the same subtraction one level down.
     */
    public function caughtInsideCallee(OrderService $orders): JsonResponse
    {
        $orders->placeLeniently(1, 5);

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''j: a catch around a call that runs a closure, where the throw
     * is written inside the closure. One of its two exceptions is taken.
     */
    public function caughtClosureThrow(ConnectionInterface $connection, bool $locked): void
    {
        try {
            $connection->transaction(function () use ($locked): void {
                if ($locked) {
                    throw new \App\Exceptions\ExportLockedException(423, 'The export is locked.');
                }

                throw \App\Exceptions\ExportUnsupportedException::forFormat('tsv');
            });
        } catch (\App\Exceptions\ExportLockedException $e) {
            Log::warning($e->getMessage());
        }
    }

    /**
     * Case 8''k: a catch around a call only the registry can read — the
     * static findOrFail's 404 is taken and turned into a 410.
     */
    public function caughtFindOrFail(int $id): UserResource
    {
        try {
            $user = User::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(410, 'gone');
        }

        return new UserResource($user);
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
     * Case 10d: the same defaulted status behind a PUBLIC constructor. The class
     * pins nothing — any caller may pass another — but THIS construction leaves
     * the slot empty, so PHP passes the default and the response really is a 409.
     */
    public function defaultedHttpStatusAtThrowSite(): void
    {
        throw new \App\Exceptions\ExportBlockedException;
    }

    /**
     * Case 10d': the same construction one hop away, inside the factory the throw
     * names. Identical code, so it owes the identical status.
     */
    public function defaultedHttpStatusInFactory(): void
    {
        throw \App\Exceptions\ExportBlockedException::blocked();
    }

    /**
     * Case 10c': the status written at the throw with its argument NAMED rather
     * than counted, which a position-only reader sees no status in at all.
     */
    public function namedHttpStatusAtThrowSite(): void
    {
        throw new \App\Exceptions\ExportLockedException(statusCode: 423, message: 'The export is locked.');
    }

    /**
     * Case 10h: a constructor that normalises the status it was handed, so the
     * default is not what every instance carries and neither is what a caller
     * puts in the slot. The degraded answer is no status at all.
     */
    public function movedHttpStatus(): void
    {
        throw \App\Exceptions\ExportPartialException::none();
    }

    /**
     * Case 10h': the same class of defect one statement later — the constructor
     * reuses the status parameter after forwarding it, so the body's end scope
     * names a value the parent never received.
     */
    public function supersededHttpStatus(): void
    {
        throw \App\Exceptions\ExportSupersededException::superseded();
    }

    /**
     * Case 10i: a class whose factory builds it two ways, so neither the class nor
     * the factory names one status. Nothing states which this is — the population
     * the unread-status notice exists for.
     */
    public function unreadHttpStatus(bool $retryable): void
    {
        throw \App\Exceptions\ExportConflictException::whenRetryable($retryable);
    }

    /**
     * Case 10j: a vendor exception thrown deliberately. It is an API error the
     * application raises, so it stands — but its status is written in a file the
     * analysis does not read, and the author cannot be told to go and change it.
     */
    public function vendorHttpStatusAtThrowSite(): void
    {
        throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('The export is already running.');
    }

    /**
     * Case 10k: a vendor method DECLARING it throws a vendor HttpException
     * subclass. Nothing here is the application's — not the throw, not the class,
     * not the status — so it is plumbing, and silent.
     */
    public function vendorDeclaredHttpStatus(SessionGuard $guard): void
    {
        $guard->basic();
    }

    /**
     * Case 10e: a class that states no status of its own — the framework's
     * constructor runs — thrown through the static factory that builds it. The
     * status is one hop away, in the factory the throw names.
     */
    public function factoryHttpStatus(): void
    {
        throw \App\Exceptions\ExportUnsupportedException::forFormat('tsv');
    }

    /**
     * Case 10f: one factory of a class with a status PER factory, taking the
     * constructor default the sibling below overrides.
     */
    public function factoryDefaultedStatus(): void
    {
        throw \App\Exceptions\ExportConflictException::duplicateName('quarterly');
    }

    /**
     * Case 10g: its sibling, which passes its own — the same class documenting a
     * different status on a different operation.
     */
    public function factoryOverriddenStatus(): void
    {
        throw \App\Exceptions\ExportConflictException::notPermitted();
    }

    /**
     * Case 10l: the throw is written in a TRAIT and reaches the action as a
     * declared exception, so the throw point carries no construction to fold.
     * The class builds itself exactly one way, which is the only thing left
     * that can answer.
     */
    public function traitThrownStatus(bool $stale): void
    {
        $this->guardProbeState($stale);
    }

    /**
     * Case 10l': the same class reached by a rethrow, which says nothing about
     * how the exception was built either.
     */
    public function rethrownStatus(bool $stale): void
    {
        try {
            $this->guardProbeState($stale);
        } catch (\App\Exceptions\ProbeStaleException $e) {
            throw $e;
        }
    }

    /**
     * Case 10m: the throw is inside a closure the action hands to a callee, so
     * the action's own throw point is the CALL. The status is written at the
     * throw, one scope in.
     */
    public function closureThrownStatus(ConnectionInterface $connection): void
    {
        $connection->transaction(function (): void {
            throw new \App\Exceptions\ExportLockedException(423, 'The export is locked.');
        });
    }

    /**
     * Case 10m': the same, with the throw naming a factory — the shape a guarded
     * write really takes, where the status is two hops from the analysed method.
     */
    public function closureFactoryThrownStatus(ConnectionInterface $connection): void
    {
        $connection->transaction(function (): void {
            throw \App\Exceptions\ExportUnsupportedException::forFormat('tsv');
        });
    }

    /**
     * Case 10m'': the closure held in a local before it is handed over, which is
     * the same closure one assignment away.
     */
    public function heldClosureThrownStatus(ConnectionInterface $connection): void
    {
        $reject = function (): void {
            throw new \App\Exceptions\ExportLockedException(423, 'The export is locked.');
        };

        $connection->transaction($reject);
    }

    /**
     * Case 10n: the same throw in an ARROW function, which PHPStan models with no
     * statement result — so there are no throw points to hand over, and the hop
     * reads its one expression instead. The same closure as above, spelled with
     * `fn`.
     */
    public function arrowThrownStatus(ConnectionInterface $connection): void
    {
        $connection->transaction(fn (): never => throw new \App\Exceptions\ExportLockedException(423, 'The export is locked.'));
    }

    /**
     * Case 10n': the budget the closure hop shares with descent. Each nesting
     * spends one, so the throw three closures in is the last one read and the
     * one behind it is out of budget — the containment, written where it can be
     * counted rather than described.
     *
     * The in-budget throw is guarded, and written BEFORE the closure that is out
     * of budget, because `transaction()` is generic over what its callback
     * returns from Laravel 13 on: a closure that only ever throws returns
     * `never`, which makes the call `never` and every statement after it dead
     * code with no throw point of its own. Put the counted throw last and this
     * row counts nothing at all on one half of the matrix — see
     * docs/design/inference-embedding.md §6, the closure hop.
     */
    public function nestedClosureThrownStatus(ConnectionInterface $connection, bool $locked): void
    {
        $connection->transaction(function () use ($connection, $locked): void {
            $connection->transaction(function () use ($connection, $locked): void {
                $connection->transaction(function () use ($connection, $locked): void {
                    if ($locked) {
                        throw new \App\Exceptions\ExportLockedException(423, 'The export is locked.');
                    }

                    $connection->transaction(function (): void {
                        throw new \App\Exceptions\ExportLockedException(410, 'The export is gone.');
                    });
                });
            });
        });
    }

    /**
     * Case 10o: a construction that PRESENTED itself and would not fold — the
     * status is chosen at run time. What the class's own factory agrees on is no
     * evidence for this response, so the honest answer is no status at all.
     */
    public function runtimeStatusAtThrowSite(int $chosen): void
    {
        throw new \App\Exceptions\ExportBlockedException('The export is blocked.', $chosen);
    }

    /**
     * Case 10p: a subclass whose only factory is the base's. `new static(503)` up
     * there builds THIS class, so the throw names a status even though nothing
     * here or in the class itself repeats it.
     */
    public function inheritedFactoryStatus(): void
    {
        throw \App\Exceptions\ExportRelocatedException::unavailable();
    }

    /**
     * Case 10p': the same base under a subclass that adds a factory of its own,
     * reached where the site carries no construction at all. The class is built
     * at 503 and at 413, so its own agreement cannot say which this is.
     */
    public function inheritedAgreementStatus(bool $offline, bool $oversized): void
    {
        $this->guardProbeReachable($offline, $oversized);
    }

    /**
     * Case 10p'': the same class reached by a RETHROW, which subtracts the guard's own declared throw and
     * leaves a `throw $e` that builds nothing. Nothing on the way here says which of the two statuses this
     * is, and the class agrees on neither — the one shape left where only the class could have answered.
     */
    public function rethrownAgreementStatus(bool $offline, bool $oversized): void
    {
        try {
            $this->guardProbeReachable($offline, $oversized);
        } catch (\App\Exceptions\ExportOfflineException $e) {
            throw $e;
        }
    }

    /**
     * Case 10q: the construction one assignment behind the throw, which is how a
     * body that decorates the exception before throwing it is written.
     */
    public function heldConstructionAtThrowSite(): void
    {
        $blocked = new \App\Exceptions\ExportBlockedException('The export is blocked.', 451);

        throw $blocked;
    }

    /**
     * Case 10q': the same shape with the status chosen at run time. The site DID
     * present a construction, so the class's own 409 is no evidence for it.
     */
    public function heldRuntimeConstructionAtThrowSite(int $chosen): void
    {
        $blocked = new \App\Exceptions\ExportBlockedException('The export is blocked.', $chosen);

        throw $blocked;
    }

    /**
     * Case 10r: two closures handed to one call on ONE line. They are two bodies
     * and two errors, and a reader keying them by line answers the second for
     * both.
     */
    public function pairedClosureThrownStatus(ProbeGuards $guards): void
    {
        $guards->either(function (): void { throw new \App\Exceptions\ExportLockedException(423, 'The export is locked.'); }, function (): void { throw \App\Exceptions\ExportUnsupportedException::forFormat('tsv'); });
    }

    /**
     * Case 10s: a status pinned through a constant the application declares in
     * another file, which is the file that decides what this route publishes.
     */
    public function constantPinnedStatus(): void
    {
        throw new \App\Exceptions\ExportArchivedException;
    }

    /**
     * Case 10t: a factory the class gets from a TRAIT, so the `new static(…)`
     * that builds it is written in a file PHP reports as this class's own.
     */
    public function traitFactoryStatus(): void
    {
        throw \App\Exceptions\ExportThrottledException::throttled();
    }

    /**
     * Case 10u: a constant reaching the parent that is no HTTP status, so the
     * fold refuses it — and the file it is declared in still decided what this
     * route publishes.
     */
    public function unreadableConstantStatus(): void
    {
        throw new \App\Exceptions\ExportRelayedException;
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

    /**
     * Case 1c: the same two calls with a status chosen at run time. The exception
     * they raise is the framework's own, and the expression that will not fold is
     * this line — so what says whether anyone can act on it is the file the FOLD
     * read, never where the exception class is declared.
     */
    public function dynamicAbortStatus(int $chosen): void
    {
        abort($chosen);
    }

    /** Case 1c': the same at `abort_if`, whose status sits one argument along. */
    public function dynamicAbortIfStatus(int $chosen, bool $flag): void
    {
        abort_if($flag, $chosen);
    }

    /**
     * Case 1d: the framework's own HttpException, constructed HERE with a status
     * chosen at run time, so the constant the notice asks for goes on this line.
     */
    public function dynamicVendorConstructionStatus(int $chosen): void
    {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($chosen, 'The export cannot continue.');
    }

    /**
     * Case 10v: an unreadable status a call away. The throw is written in an
     * injected collaborator, so the notice about it has to name that file and
     * that line rather than the controller line the route entered by.
     */
    public function deepUnreadHttpStatus(ExportProbeQuery $query): void
    {
        $query->results(true);
    }

    /**
     * Case 10w: the named-factory idiom thrown from the action's own body. The
     * class states no status, the factory the throw names states 404.
     */
    public function manifestStatusAtAction(): void
    {
        throw \App\Exceptions\ManifestRejectedException::notFound();
    }

    /**
     * Case 10w': the same throw one undeclared call away, so descent is what has
     * to reach the factory.
     */
    public function manifestStatusOneCallAway(ManifestReviewQuery $query): void
    {
        $query->results(true);
    }

    /**
     * Case 10w'': the same throw two undeclared calls away.
     */
    public function manifestStatusTwoCallsAway(ManifestRelayQuery $query): void
    {
        $query->results(true);
    }

    /**
     * Case 10x: the same throw behind a callee that DECLARES it, which is how most
     * applications write a guard. The action's throw point carries the concrete
     * type and no construction.
     */
    public function manifestStatusDeclaredByCallee(ManifestDeclaredQuery $query): void
    {
        $query->results(true);
    }

    /**
     * Case 10x': the same declared guard naming the factory {@see manifestStatusAtAction} writes at the
     * action itself. Two spellings of one construction, so they owe one answer.
     */
    public function manifestStatusDeclaredNotFound(ManifestDeclaredQuery $query): void
    {
        $query->missingResults(true);
    }

    /**
     * Case 10y: the throw written outside the descend scope, in a modular PSR-4
     * root the application autoloads and analyses but the engine never descends
     * into. The exception class is the application's own.
     */
    public function modularThrowSiteStatus(\Modules\Billing\LedgerReviewQuery $query): void
    {
        $query->results(true);
    }

    /**
     * Case 10y': the throw written HERE but the exception class declared outside
     * the descend scope — the other half of the same axis.
     */
    public function modularExceptionClassStatus(): void
    {
        throw \Modules\Billing\LedgerRejectedException::notFound();
    }

    /**
     * Case 10y'': the modular exception class reached through a declared callee,
     * so neither the class nor the throw is inside the descend scope.
     */
    public function modularDeclaredStatus(\Modules\Billing\LedgerReviewQuery $query): void
    {
        $query->declaredResults(true);
    }

    /**
     * Case 8''q: work handed to Laravel's rescue(), which catches every
     * Throwable around the call it makes of it. Nothing the work throws can
     * reach the response; only the action's own LogicException escapes.
     */
    public function rescuedClosure(OrderService $orders, bool $retry): JsonResponse
    {
        rescue(function () use ($orders): void {
            $orders->place(1, 5);
        }, report: false);

        if ($retry) {
            throw new \LogicException('retry is not supported');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''r: work handed to the application's own helper, which swallows
     * any Exception around its call of it.
     */
    public function swallowedByHelper(OrderService $orders, bool $retry): JsonResponse
    {
        Attempts::quietly(function () use ($orders): void {
            $orders->place(1, 5);
        });

        if ($retry) {
            throw new \LogicException('retry is not supported');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''s: the same helper as an instance method.
     */
    public function swallowedByHelperOnInstance(OrderService $orders, Attempts $attempts, bool $retry): JsonResponse
    {
        $attempts->quietlyOn(function () use ($orders): void {
            $orders->place(1, 5);
        });

        if ($retry) {
            throw new \LogicException('retry is not supported');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''t: the same helper as a function, autoloaded the way an
     * application loads its helpers file.
     */
    public function swallowedByHelperFunction(OrderService $orders, bool $retry): JsonResponse
    {
        \App\Support\swallow(function () use ($orders): void {
            $orders->place(1, 5);
        });

        if ($retry) {
            throw new \LogicException('retry is not supported');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''u: a helper whose catch reports and rethrows: everything the
     * work throws still leaves.
     */
    public function rethrownByHelper(OrderService $orders): JsonResponse
    {
        Attempts::reporting(function () use ($orders): void {
            $orders->place(1, 5);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''v: a helper that takes only OutOfStockException; the
     * RuntimeException the work raises two levels down still leaves.
     */
    public function narrowlySwallowedByHelper(OrderService $orders): JsonResponse
    {
        Attempts::unlessOutOfStock(function () use ($orders): void {
            $orders->place(1, 5);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''w: a helper that runs the work again from inside its catch,
     * where nothing takes what the second run throws.
     */
    public function retriedByHelper(OrderService $orders): JsonResponse
    {
        Attempts::retryingOnce(function () use ($orders): void {
            $orders->place(1, 5);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''x: a helper whose catch hands what it caught to a method of
     * its own that rethrows it — so the catch takes nothing, and everything
     * the work throws still leaves.
     */
    public function failedByHelper(OrderService $orders, Attempts $attempts): JsonResponse
    {
        $attempts->guarded(function () use ($orders): void {
            $orders->place(1, 5);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Case 8''y: a helper that hands the work on to another helper, so its
     * own body names no place the work runs and no catch of its own is
     * weighed — everything the work throws is kept.
     */
    public function relayedByHelperFunction(OrderService $orders): JsonResponse
    {
        \App\Support\relay(function () use ($orders): void {
            $orders->place(1, 5);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Case 10s: a refusal whose status is the one its REASON maps to — the
     * constructor hands the parent `$reason->status()`, and the enum case
     * passed here decides it through a `match ($this)`.
     */
    public function enumReasonStatus(): void
    {
        throw new \App\Exceptions\Refused(\App\Enums\TokenRefusal::Expired, 'The token has expired.');
    }

    /**
     * Case 10s': the same class with another case, which maps to another status.
     */
    public function otherEnumReasonStatus(): void
    {
        throw new \App\Exceptions\Refused(\App\Enums\TokenRefusal::AlreadyRotated, 'Rotate its replacement.');
    }

    /**
     * Case 10s'': the reason is only known at run time, and its cases map to
     * two statuses.
     */
    public function runtimeEnumReasonStatus(\App\Enums\TokenRefusal $reason): void
    {
        throw new \App\Exceptions\Refused($reason, 'Refused.');
    }

    /**
     * Case 10s''': a reason chosen at run time, narrowed to the cases that
     * map to one status before it is thrown.
     */
    public function narrowedEnumReasonStatus(\App\Enums\TokenRefusal $reason): void
    {
        if ($reason === \App\Enums\TokenRefusal::AlreadyRotated) {
            return;
        }

        throw new \App\Exceptions\Refused($reason, 'Refused.');
    }

    /**
     * Case 10t: an exception whose status is its enum's BACKING value.
     */
    public function enumValueStatus(): void
    {
        throw new \App\Exceptions\ExportFaultException(\App\Enums\ExportFault::Gone);
    }

    /**
     * Case 10t': the same, with the case looked up from its value.
     */
    public function enumFromStatus(): void
    {
        throw new \App\Exceptions\ExportFaultException(\App\Enums\ExportFault::from(423));
    }

    /** Case 10t'': a value no case is backed by, so `from()` throws before the refusal is built. */
    public function enumFromNoCase(): void
    {
        throw new \App\Exceptions\ExportFaultException(\App\Enums\ExportFault::from(999));
    }

    /** Case 10t''': a string for an int-backed enum, in a file declaring `strict_types`. */
    public function enumFromString(): void
    {
        throw new \App\Exceptions\ExportFaultException(\App\Enums\ExportFault::from('423'));
    }

    /** Case 10t-runtime: a value the request chooses, which may name no case. */
    public function enumFromRuntime(int $code): void
    {
        throw new \App\Exceptions\ExportFaultException(\App\Enums\ExportFault::from($code));
    }

    /** Case 10t-try: `tryFrom()` answers null for a value no case is backed by. */
    public function enumTryFrom(): void
    {
        throw new \App\Exceptions\ExportFaultException(\App\Enums\ExportFault::tryFrom(423) ?? \App\Enums\ExportFault::Gone);
    }

    /** Case 10t-lookup: a case looked up from a constant, beside a status that does not come from it. */
    public function enumLookedUp(): void
    {
        abort(404, \App\Enums\ExportFault::from(410)->name);
    }

    /** Case 10u: abort() with a case's backing value. */
    public function abortEnumValue(): void
    {
        abort(\App\Enums\ExportFault::Locked->value);
    }

    /** Case 10u': abort() with the status a case's method maps it to. */
    public function abortEnumStatus(): void
    {
        abort(\App\Enums\TokenRefusal::AlreadyRotated->status());
    }

    /** Case 10u'': abort() through a method returning the case's own value. */
    public function abortEnumStatusCode(): void
    {
        abort(\App\Enums\ExportFault::Gone->statusCode());
    }

    /** Case 10u-early: abort() through a method that chooses by early return. */
    public function abortEnumEarlyReturn(): void
    {
        abort(\App\Enums\TokenRefusal::Expired->httpStatus());
    }

    /** Case 10v: a named constructor choosing the case itself. */
    public function enumReasonInFactory(): void
    {
        throw \App\Exceptions\RotationRefused::expired();
    }

    /** Case 10v': a named constructor handed the case. */
    public function enumReasonThroughFactory(): void
    {
        throw \App\Exceptions\RotationRefused::because(\App\Enums\TokenRefusal::AlreadyRotated);
    }

    /**
     * A guard helper refusing with an `HttpException` with its status written, written as a private method returning `never`.
     */
    public function neverHelperHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseNeverHttp();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with an `HttpException` with its status written, written as a static method returning `never`.
     */
    public function neverStaticHelperHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            self::refuseNeverHttpStatically();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with an `HttpException` with its status written, written as a private method returning `void`.
     */
    public function voidHelperHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseVoidHttp();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with an `HttpException` with its status written, written as a static method returning `void`.
     */
    public function voidStaticHelperHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            self::refuseVoidHttpStatically();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with `abort(404)`, written as a private method returning `never`.
     */
    public function neverHelperAbort(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseNeverAbort();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with `abort(404)`, written as a static method returning `never`.
     */
    public function neverStaticHelperAbort(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            self::refuseNeverAbortStatically();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with `abort(404)`, written as a private method returning `void`.
     */
    public function voidHelperAbort(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseVoidAbort();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with `abort(404)`, written as a static method returning `void`.
     */
    public function voidStaticHelperAbort(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            self::refuseVoidAbortStatically();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with an exception whose status is written at the `throw`, written as a private method returning `never`.
     */
    public function neverHelperCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseNeverCustom();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with an exception whose status is written at the `throw`, written as a static method returning `never`.
     */
    public function neverStaticHelperCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            self::refuseNeverCustomStatically();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with an exception whose status is written at the `throw`, written as a private method returning `void`.
     */
    public function voidHelperCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseVoidCustom();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with an exception whose status is written at the `throw`, written as a static method returning `void`.
     */
    public function voidStaticHelperCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            self::refuseVoidCustomStatically();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with a `ValidationException`, written as a private method returning `never`.
     */
    public function neverHelperValidation(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseNeverValidation();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with a `ValidationException`, written as a static method returning `never`.
     */
    public function neverStaticHelperValidation(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            self::refuseNeverValidationStatically();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with a `ValidationException`, written as a private method returning `void`.
     */
    public function voidHelperValidation(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseVoidValidation();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A guard helper refusing with a `ValidationException`, written as a static method returning `void`.
     */
    public function voidStaticHelperValidation(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            self::refuseVoidValidationStatically();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A `never` guard helper that throws one of two errors, chosen by its argument, and its `void` twin.
     */
    public function neverHelperEither(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseEitherNever($request->boolean('gone'));
        }

        return response()->json(['ok' => true]);
    }

    public function voidHelperEither(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseEitherVoid($request->boolean('gone'));
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A `never` guard helper that refuses by calling another.
     */
    public function neverHelperNested(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseNeverNested();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A `never` guard helper that declares what it throws, which is read as declared.
     */
    public function neverHelperDeclared(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseNeverDeclared();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Guard helpers that say they never return only in a docblock: `@return never`, `@phpstan-return never`
     * and `@return noreturn`.
     */
    public function neverHelperDocblock(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseDocumentedNever();
        }

        return response()->json(['ok' => true]);
    }

    public function neverHelperPhpstanDocblock(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refusePhpstanDocumentedNever();
        }

        return response()->json(['ok' => true]);
    }

    public function neverHelperNoreturn(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->refuseDocumentedNoreturn();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A `never` guard helper called inside a catch that takes one of the two errors it throws.
     */
    public function neverHelperCaught(Request $request): JsonResponse
    {
        try {
            $this->refuseEitherNever($request->boolean('gone'));
        } catch (ExportLockedException) {
            return response()->json(['ok' => false]);
        }
    }

    private function refuseNeverHttp(): never
    {
        throw new HttpException(409, 'The export already exists.');
    }

    private function refuseVoidHttp(): void
    {
        throw new HttpException(409, 'The export already exists.');
    }

    private function refuseNeverAbort(): never
    {
        abort(404);
    }

    private function refuseVoidAbort(): void
    {
        abort(404);
    }

    private function refuseNeverCustom(): never
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    private function refuseVoidCustom(): void
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    private function refuseNeverValidation(): never
    {
        throw ValidationException::withMessages(['name' => 'The name is taken.']);
    }

    private function refuseVoidValidation(): void
    {
        throw ValidationException::withMessages(['name' => 'The name is taken.']);
    }

    private static function refuseNeverHttpStatically(): never
    {
        throw new HttpException(409, 'The export already exists.');
    }

    private static function refuseVoidHttpStatically(): void
    {
        throw new HttpException(409, 'The export already exists.');
    }

    private static function refuseNeverAbortStatically(): never
    {
        abort(404);
    }

    private static function refuseVoidAbortStatically(): void
    {
        abort(404);
    }

    private static function refuseNeverCustomStatically(): never
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    private static function refuseVoidCustomStatically(): void
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    private static function refuseNeverValidationStatically(): never
    {
        throw ValidationException::withMessages(['name' => 'The name is taken.']);
    }

    private static function refuseVoidValidationStatically(): void
    {
        throw ValidationException::withMessages(['name' => 'The name is taken.']);
    }

    private function refuseEitherNever(bool $gone): never
    {
        if ($gone) {
            throw new HttpException(410, 'The export is gone.');
        }

        throw new ExportLockedException(423, 'The export is locked.');
    }

    private function refuseEitherVoid(bool $gone): void
    {
        if ($gone) {
            throw new HttpException(410, 'The export is gone.');
        }

        throw new ExportLockedException(423, 'The export is locked.');
    }

    private function refuseNeverNested(): never
    {
        $this->refuseNeverCustom();
    }

    /**
     * @throws ExportLockedException
     */
    private function refuseNeverDeclared(): never
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    /**
     * @return never
     */
    private function refuseDocumentedNever()
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    /**
     * @phpstan-return never
     */
    private function refusePhpstanDocumentedNever()
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }

    /**
     * @return noreturn
     */
    private function refuseDocumentedNoreturn()
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }
}
