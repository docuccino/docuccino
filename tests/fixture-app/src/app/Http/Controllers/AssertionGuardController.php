<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ListingMisconfiguredException;
use App\Exceptions\UnsupportedRadiusException;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Throws with no status that assert what the application already guarantees — a user the route's middleware
 * put there, a class a subclass is required to name — written every way an application writes one: a
 * literal `throw` behind an `if`, a `??`, an `||` or an early return, a helper returning `never` or `void`,
 * `throw_if()` and `throw_unless()`, a closure. Beside them, the same throws behind a test of a VALUE, which a
 * request can fail, and exceptions carrying a status behind a type test. Each action otherwise answers 200.
 * Only ever analysed, never dispatched.
 */
class AssertionGuardController extends Controller
{
    /** A list builder's shared query, whose subclass must name the class each row is built as. */
    public function listWithNeverHelper(): JsonResponse
    {
        $rowClass = $this->dataClass() ?? $this->missingDataClass();

        return response()->json(['ok' => true, 'class' => $rowClass]);
    }

    public function listWithVoidHelper(): JsonResponse
    {
        $rowClass = $this->dataClass();
        if ($rowClass === null) {
            $this->missingDataClassVoid();
        }

        return response()->json(['ok' => true, 'class' => $rowClass]);
    }

    /** The same helper throwing a `RuntimeException`: an assertion by the `??` it is reached past alone. */
    public function listWithRuntimeHelper(): JsonResponse
    {
        $rowClass = $this->dataClass() ?? $this->missingDataClassRuntime();

        return response()->json(['ok' => true, 'class' => $rowClass]);
    }

    public function unlessUser(Request $request): JsonResponse
    {
        $user = $request->user();

        // The route runs behind `auth`, which admits a signed-in user only.
        throw_unless($user instanceof User, RuntimeException::class, 'The route runs behind auth.');

        return response()->json(['ok' => true, 'name' => $user->name]);
    }

    public function ifNoUser(Request $request): JsonResponse
    {
        throw_if($request->user() === null, RuntimeException::class, 'The route runs behind auth.');

        return response()->json(['ok' => true]);
    }

    public function unlessUuid(Request $request): JsonResponse
    {
        $id = $request->route('id');

        throw_unless(is_string($id) && Str::isUuid($id), LogicException::class, 'A record is referenced by its uuid.');

        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** The same test throwing a `RuntimeException`: `Str::isUuid()` reads the value, which a request decides. */
    public function unlessUuidRuntime(Request $request): JsonResponse
    {
        $id = $request->route('id');

        throw_unless(is_string($id) && Str::isUuid($id), RuntimeException::class, 'Not a uuid.');

        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function coalesceUser(Request $request): JsonResponse
    {
        $user = $request->user() ?? throw new RuntimeException('The route runs behind auth.');

        return response()->json(['ok' => true, 'id' => $user->getAuthIdentifier()]);
    }

    public function ifNotUser(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            Log::error('No user behind auth.');

            throw new RuntimeException('The route runs behind auth.');
        }

        return response()->json(['ok' => true, 'name' => $user->name]);
    }

    public function earlyReturnUser(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User) {
            return response()->json(['ok' => true, 'name' => $user->name]);
        }

        throw new RuntimeException('The route runs behind auth.');
    }

    public function orThrowUser(Request $request): JsonResponse
    {
        $user = $request->user();
        $user instanceof User || throw new RuntimeException('The route runs behind auth.');

        return response()->json(['ok' => true]);
    }

    public function voidHelperBehindGuard(Request $request): JsonResponse
    {
        if (! $request->user() instanceof User) {
            $this->refuseWithoutUser();
        }

        return response()->json(['ok' => true]);
    }

    public function neverHelperBehindGuard(Request $request): JsonResponse
    {
        if (! $request->user() instanceof User) {
            $this->refuseWithoutUserNever();
        }

        return response()->json(['ok' => true]);
    }

    public function closureBehindGuard(Request $request): JsonResponse
    {
        $user = $request->user() ?? value(fn () => throw new RuntimeException('The route runs behind auth.'));

        return response()->json(['ok' => true, 'id' => $user->getAuthIdentifier()]);
    }

    public function arrowWithOwnGuard(): JsonResponse
    {
        $verified = User::query()->get()->map(
            fn (User $user): string => (string) ($user->email_verified_at ?? throw new RuntimeException('Only verified users are listed.')),
        );

        return response()->json(['ok' => true, 'verified' => $verified]);
    }

    /** A `LogicException` behind a test of a value: PHP's defect family is an assertion whatever guards it. */
    public function logicBehindValue(Request $request): JsonResponse
    {
        if ($request->boolean('legacy')) {
            throw new InvalidArgumentException('Legacy listings are no longer read.');
        }

        return response()->json(['ok' => true]);
    }

    /** The application's own class in PHP's defect family, by its parent. */
    public function misconfiguredListing(Request $request): JsonResponse
    {
        if ($request->boolean('legacy')) {
            throw new ListingMisconfiguredException('Legacy listings name no row class.');
        }

        return response()->json(['ok' => true]);
    }

    /** PHP's defect family, which the application renders at a status of its own. */
    public function radiusOutOfRange(Request $request): JsonResponse
    {
        throw_if($request->float('radius') <= 0.0, UnsupportedRadiusException::class, 'The radius must be above zero.');

        return response()->json(['ok' => true]);
    }

    /** A test of a value, which a request decides: the error it refuses with is published. */
    public function valueGuard(Request $request): JsonResponse
    {
        if ($request->integer('count') > 100) {
            throw new RuntimeException('Too many.');
        }

        return response()->json(['ok' => true]);
    }

    /** A unique violation on another index than the one handled is re-thrown, and is a test of a value. */
    public function rethrowOtherIndex(Request $request): JsonResponse
    {
        try {
            User::query()->create(['email' => $request->string('email')->toString()]);
        } catch (UniqueConstraintViolationException $e) {
            throw_unless(str_contains($e->getMessage(), 'users_email_unique'), $e);

            throw new HttpException(422, 'That email is taken.');
        }

        return response()->json(['ok' => true]);
    }

    /** One class thrown behind a type test and behind a test of a value: the second is a response. */
    public function sameClassTwoSites(Request $request): JsonResponse
    {
        if (! $request->user() instanceof User) {
            throw new RuntimeException('The route runs behind auth.');
        }

        if ($request->integer('count') > 100) {
            throw new RuntimeException('Too many.');
        }

        return response()->json(['ok' => true]);
    }

    /** The same two sites, in the other order. */
    public function sameClassTwoSitesReversed(Request $request): JsonResponse
    {
        if ($request->integer('count') > 100) {
            throw new RuntimeException('Too many.');
        }

        if (! $request->user() instanceof User) {
            throw new RuntimeException('The route runs behind auth.');
        }

        return response()->json(['ok' => true]);
    }

    /** An exception carrying a status, behind a type test: a refusal the request gets. */
    public function unlessUserAuthenticated(Request $request): JsonResponse
    {
        throw_unless($request->user() instanceof User, AuthenticationException::class, 'Sign in first.');

        return response()->json(['ok' => true]);
    }

    /** …and behind a test of a value. */
    public function ifSystemAuthenticated(Request $request): JsonResponse
    {
        throw_if($request->boolean('system'), AuthenticationException::class, 'A system user cannot sign in.');

        return response()->json(['ok' => true]);
    }

    public function unlessUserHttp(Request $request): JsonResponse
    {
        throw_unless($request->user() instanceof User, HttpException::class, 403, 'Staff only.');

        return response()->json(['ok' => true]);
    }

    /** @return class-string|null */
    protected function dataClass(): ?string
    {
        return null;
    }

    private function missingDataClass(): never
    {
        throw new LogicException(static::class.' must declare dataClass().');
    }

    private function missingDataClassVoid(): void
    {
        throw new LogicException(static::class.' must declare dataClass().');
    }

    private function missingDataClassRuntime(): never
    {
        throw new RuntimeException(static::class.' must declare dataClass().');
    }

    private function refuseWithoutUser(): void
    {
        throw new RuntimeException('The route runs behind auth.');
    }

    private function refuseWithoutUserNever(): never
    {
        throw new RuntimeException('The route runs behind auth.');
    }
}
