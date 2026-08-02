<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

/**
 * The single source of truth for how Laravel's stock exceptions map to HTTP responses — shared by the
 * framework-errors tier (`FrameworkErrorsExceptionToResponse`, plain JSON) and the Problem Details
 * preset (`ProblemDetailsSchema`, RFC 9457) so the two presentations can never drift on the status a
 * given exception produces or the reason phrase for that status.
 *
 * Reason phrases are the RFC 9110 §15 canonical phrases — used verbatim as the framework-error
 * response *description* and the problem-details *title*. This resolves the historical 401 split
 * (framework-errors said "Unauthenticated", problem-details said "Unauthorized"): the RFC 9110 reason
 * phrase for 401 is **Unauthorized**, so that is the one canonical value both now use.
 */
final class FrameworkExceptionTable
{
    /**
     * Base exception FQCN → its HTTP status and whether it carries a field-keyed `errors` map (the
     * validation shape). Matched subtype-aware, so a subclass inherits its base's mapping.
     *
     * @var array<string, array{status: string, validation: bool}>
     */
    private const EXCEPTIONS = [
        'Illuminate\\Validation\\ValidationException' => ['status' => '422', 'validation' => true],
        'Illuminate\\Auth\\AuthenticationException' => ['status' => '401', 'validation' => false],
        'Illuminate\\Auth\\Access\\AuthorizationException' => ['status' => '403', 'validation' => false],
        'Illuminate\\Database\\Eloquent\\ModelNotFoundException' => ['status' => '404', 'validation' => false],
        'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException' => ['status' => '404', 'validation' => false],
    ];

    /**
     * HTTP status → RFC 9110 §15 reason phrase, for every status the mapped exceptions produce. The
     * ONE map both the framework-errors tier and the problem-details preset read, so a status's human
     * label is identical across the two presentations. The 401 phrase is the RFC 9110 §15.5.2 value
     * **Unauthorized**, which resolves the historical split (framework-errors previously said
     * "Unauthenticated" while problem-details said "Unauthorized"). (PHP coerces the numeric-string
     * keys to int.)
     *
     * @var array<int, string>
     */
    private const REASON_PHRASES = [
        '401' => 'Unauthorized',
        '403' => 'Forbidden',
        '404' => 'Not Found',
        '422' => 'Unprocessable Entity',
    ];

    /**
     * The mapped exception FQCNs in table order (drives the dataset tests over EVERY entry).
     *
     * @return list<string>
     */
    public static function exceptions(): array
    {
        return array_keys(self::EXCEPTIONS);
    }

    /**
     * The `{status, validation}` facts for an exception FQCN, matched subtype-aware (a subclass
     * inherits its base's mapping), or null when the exception is outside the table.
     *
     * @return array{status: string, validation: bool}|null
     */
    public static function match(string $fqcn): ?array
    {
        foreach (self::EXCEPTIONS as $base => $facts) {
            if ($fqcn === $base || is_a($fqcn, $base, true)) {
                return $facts;
            }
        }

        return null;
    }

    /** The RFC 9110 reason phrase for an HTTP status (a generic `Error` for an unlisted status). */
    public static function reason(string $status): string
    {
        return self::REASON_PHRASES[$status] ?? 'Error';
    }
}
