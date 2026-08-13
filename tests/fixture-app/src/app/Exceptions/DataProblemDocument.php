<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Data\ProblemDocumentData;
use Illuminate\Http\Request;
use Spatie\LaravelData\Optional;

/**
 * The Data-object counterpart of {@see ProblemResponse::fromProblem()}: a factory that answers the DTO
 * rather than a response, so callers can render it themselves. This is the shape a real app's
 * `ProblemDetailsResponse::make()` takes, and it puts a call hop between the renderer's arm and the `new`
 * that actually builds the body — the object is never constructed where the response is produced.
 *
 * Every member's value therefore reads off one of THESE parameters: a bound {@see InvoiceProblem} case's
 * accessors, a plain string, or an argument the caller may simply not pass. The `?? new Optional` tail is
 * the idiomatic way to say "absent unless supplied", and it is the reason an omitted argument has to leave
 * the recovered body rather than widen inside it. Only ever analysed.
 */
final class DataProblemDocument
{
    /**
     * @param  list<string>|null  $errors
     */
    public static function make(
        InvoiceProblem $problem,
        string $detail,
        Request $request,
        ?array $errors = null,
    ): ProblemDocumentData {
        return new ProblemDocumentData(
            type: $problem->value,
            title: $problem->title(),
            status: $problem->status(),
            detail: $detail,
            instance: $request->getPathInfo(),
            errors: $errors ?? new Optional,
        );
    }
}
