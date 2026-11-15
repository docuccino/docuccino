<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ExportFault;
use App\Exceptions\ExportLockedException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An application helper written as a function: runs the work it is handed and swallows any Exception it
 * throws, logging its message.
 */
function swallow(callable $work): void
{
    try {
        $work();
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::warning($e->getMessage());
    }
}

/** Hands the work on to {@see swallow()}, so its own body names no place the work runs. */
function relay(callable $work): void
{
    swallow($work);
}

// Guard helpers written as functions, each to return `never` and `void`.

function neverHttp(): never
{
    throw new HttpException(409, 'The export already exists.');
}

function voidHttp(): void
{
    throw new HttpException(409, 'The export already exists.');
}

function neverAbort(): never
{
    abort(404);
}

function voidAbort(): void
{
    abort(404);
}

function neverCustom(): never
{
    throw new ExportLockedException(423, 'The export is locked.');
}

function voidCustom(): void
{
    throw new ExportLockedException(423, 'The export is locked.');
}

function neverValidation(): never
{
    throw ValidationException::withMessages(['name' => 'The name is taken.']);
}

function voidValidation(): void
{
    throw ValidationException::withMessages(['name' => 'The name is taken.']);
}

function neverCarried(): never
{
    throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
}

function voidCarried(): void
{
    throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
}

/** Refuses with whatever the guard it calls refuses with. */
function neverNested(): never
{
    neverCustom();
}

// Response helpers written as functions: one answering with its own status, one with the case it is handed.

function gone(): JsonResponse
{
    return response()->json(['error' => 'gone'], 410);
}

function fault(ExportFault $fault): JsonResponse
{
    return new JsonResponse(['error' => $fault->name], $fault->value);
}
