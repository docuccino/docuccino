<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Queries\ListingFilterValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The controller half of the Validator-in-Queries proof: the inline validation rules live in
 * {@see ListingFilterValidation} one hop away (index → ListingFilterValidation::validate →
 * Validator::make), so the engine's bounded descent must reach the `Validator::make()` rule array
 * there. Zero doc annotations on purpose. Only ever analysed — never dispatched.
 */
class ValidatedListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = (new ListingFilterValidation())->validate($request->query());

        return response()->json($filters);
    }
}
