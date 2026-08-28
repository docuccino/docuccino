<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Attributes\IgnoreParam;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Provenance\Source;
use Docuccino\Core\Support\PlainText;

/**
 * The one report for an ignore that dropped nothing — `#[IgnoreParam]` naming a parameter this
 * operation does not document, `#[IgnoreResponse]` naming a status no producer would have written.
 *
 * A subtraction has no evidence of its own: a parameter that was never there and a parameter that was
 * dropped both leave the same document, so an author who typo'd a name, or kept one through a rename,
 * sees exactly what a working declaration produces and believes the field is hidden when it is
 * published. Both members say the same three things — the declaration as written, that it took no
 * effect, and what the operation DOES document so the typo is visible beside it — so they say them
 * from here rather than twice.
 *
 * Warning, not info: the document is true either way (nothing was dropped, so nothing is missing from
 * it), but the author asked for a subtraction and did not get one, which is a request refused rather
 * than a build that widened. It is also the level the neighbouring refusals already use —
 * `attribute.ignore-param-location`, `attribute.error-component-unread` — for declarations that
 * likewise reached nothing.
 *
 * Only a declaration written on the ACTION ITSELF is reported, never the route's inherited set: one on
 * a controller is ordinary — an author drops a paging key some of its actions take, or an error status
 * some of them answer with — and reporting it per route would fire on every action that was never
 * wrong. That is the same measurement `DeclaredErrorComponentsExtension` made for
 * `#[ErrorComponent]` — six reports of one declaration — and the reason the callers pass
 * `AttributeSet::direct()`.
 */
final class UnmatchedIgnore
{
    /** Past this, the list stops being read and starts being scrolled. Same cap, and the same reason, as a contract failure's own lists. */
    private const int MAX_PUBLISHED = 8;

    /**
     * A name that matched no parameter. `$published` is what the operation is left documenting, as
     * `in:name` keys — read AFTER the pass has done its removals, because the remedy has to name what
     * the document actually publishes rather than what it held mid-build.
     *
     * @param  list<string>  $published
     */
    public static function parameter(IgnoreParam $ignore, array $published, ?Source $source, ?string $routeSignature): Diagnostic
    {
        $declaration = $ignore->in === null
            ? sprintf('#[IgnoreParam(name: "%s")]', PlainText::of($ignore->name))
            : sprintf('#[IgnoreParam(name: "%s", in: "%s")]', PlainText::of($ignore->name), PlainText::of($ignore->in));

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.ignore-param-unmatched',
            message: $declaration.' dropped nothing: this operation documents no such parameter. '.self::naming('parameter', $published),
            source: $source,
            routeSignature: $routeSignature,
            help: 'Correct the name to one this operation documents, or delete the declaration — a parameter that was renamed keeps its old spelling only in the attribute. A key only some of a controller\'s actions take belongs on the class, where an action that never documented it is not a mistake.',
        );
    }

    /**
     * A status nothing would have written. `$published` is the statuses the operation is left
     * documenting.
     *
     * @param  list<string>  $published
     */
    public static function response(int $status, array $published, ?Source $source, ?string $routeSignature): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.ignore-response-unmatched',
            message: sprintf(
                '#[IgnoreResponse(status: %d)] dropped nothing: no producer would have written a %d response for this operation. %s',
                $status,
                $status,
                self::naming('response', $published),
            ),
            source: $source,
            routeSignature: $routeSignature,
            help: 'Correct the status to one this operation documents, or delete the declaration. An ignore names one status, so a response the document publishes as a RANGE — `3XX` for a redirect nothing pins to a code — is not one it can drop. A status only some of a controller\'s actions answer with belongs on the class, where an action that never answers with it is not a mistake.',
        );
    }

    /**
     * What the operation does publish, capped. Every value goes through {@see PlainText}: a parameter
     * name can be recovered from a validation rule key or a query string an application composes, so it
     * is not a name this build wrote.
     *
     * @param  list<string>  $published
     */
    private static function naming(string $kind, array $published): string
    {
        if ($published === []) {
            return sprintf('It documents no %s at all.', $kind === 'parameter' ? 'parameters' : 'responses');
        }

        $shown = array_slice($published, 0, self::MAX_PUBLISHED);
        $extra = count($published) - count($shown);

        return sprintf(
            'It documents %s%s.',
            implode(', ', array_map(PlainText::of(...), $shown)),
            $extra > 0 ? sprintf(' and %d more', $extra) : '',
        );
    }
}
