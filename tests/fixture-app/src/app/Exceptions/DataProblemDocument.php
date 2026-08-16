<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Data\ProblemDocumentData;
use App\Support\TraceContext;
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
     * `$errors` accepts a forwarded `Optional` as well as a value or null, the way a factory does once one
     * of its callers is re-rendering a document that already carries the marker.
     *
     * @param  list<string>|Optional|null  $errors
     */
    public static function make(
        InvoiceProblem $problem,
        string $detail,
        Request $request,
        array|Optional|null $errors = null,
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

    /**
     * The same tail written over a READ rather than over the parameter: `instance` is there when the tracer
     * has an id for this request and gone when it does not, and no caller can know which — handing in a
     * tracer is not handing in a trace id.
     */
    public static function traced(
        InvoiceProblem $problem,
        string $detail,
        TraceContext $trace,
    ): ProblemDocumentData {
        return new ProblemDocumentData(
            type: $problem->value,
            title: $problem->title(),
            status: $problem->status(),
            detail: $detail,
            instance: $trace->currentId() ?? new Optional,
        );
    }
}
