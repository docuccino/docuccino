<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Facades\Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Responses made through a real-time facade of the response factory, imported as Laravel documents it and
 * written out in full, beside an action that uses no facade at all: a real-time facade anywhere in a file
 * is part of how every action in it is analysed.
 */
class RealTimeFacadeController extends Controller
{
    public function json(): JsonResponse
    {
        return ResponseFactory::json(['error' => 'locked'], 422);
    }

    public function noContent(): Response
    {
        return ResponseFactory::noContent(205);
    }

    public function make(): Response
    {
        return \Facades\Illuminate\Contracts\Routing\ResponseFactory::make('', 410);
    }

    public function plain(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
