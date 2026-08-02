<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ProblemDetails;

/**
 * The RFC 9457 (`application/problem+json`) shapes the Problem Details preset hoists: the shared
 * `ProblemDetails` object every problem response builds on, plus the per-status reusable response
 * bodies with their examples. Pure data (no I/O) so a dataset test can drive every entry.
 */
final class ProblemDetailsSchema
{
    public const MEDIA_TYPE = 'application/problem+json';

    /** The identity the shared component dedupes under, regardless of how many responses reference it. */
    public const SCHEMA_ID = 'docuccino:problem-details';

    public const SCHEMA_NAME = 'ProblemDetails';

    /**
     * The RFC 9457 members (`type`, `title`, `status`, `detail`, `instance`). An app may extend a
     * problem with its own members, so the object stays open (no `additionalProperties: false`).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'format' => 'uri', 'default' => 'about:blank'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
                'instance' => ['type' => 'string', 'format' => 'uri'],
            ],
        ];
    }

    /**
     * The per-status response component table (design coverage standard: a dataset test drives EVERY
     * entry). Each maps a framework exception FQCN to the reusable `#/components/responses/Problem*`
     * it hoists: its component name, HTTP status, human title, and an RFC 9457 example. `422`
     * additionally grafts the field-keyed `errors` map onto the shared schema.
     *
     * @return array<string, array{component: string, status: string, title: string, description: string, validation: bool}>
     */
    public static function table(): array
    {
        return [
            'Illuminate\\Validation\\ValidationException' => [
                'component' => 'ProblemValidation', 'status' => '422', 'title' => 'Unprocessable Entity',
                'description' => 'Validation failed', 'validation' => true,
            ],
            'Illuminate\\Auth\\AuthenticationException' => [
                'component' => 'ProblemUnauthenticated', 'status' => '401', 'title' => 'Unauthorized',
                'description' => 'Authentication is required', 'validation' => false,
            ],
            'Illuminate\\Auth\\Access\\AuthorizationException' => [
                'component' => 'ProblemForbidden', 'status' => '403', 'title' => 'Forbidden',
                'description' => 'This action is unauthorized', 'validation' => false,
            ],
            'Illuminate\\Database\\Eloquent\\ModelNotFoundException' => [
                'component' => 'ProblemNotFound', 'status' => '404', 'title' => 'Not Found',
                'description' => 'The resource was not found', 'validation' => false,
            ],
            'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException' => [
                'component' => 'ProblemNotFound', 'status' => '404', 'title' => 'Not Found',
                'description' => 'The resource was not found', 'validation' => false,
            ],
        ];
    }

    /**
     * The reusable response body for a table entry: the shared `ProblemDetails` (via `$ref`), the
     * problem media type, a per-status example, and — for 422 — the grafted `errors` map.
     *
     * @param  array{component: string, status: string, title: string, description: string, validation: bool}  $entry
     * @param  array<string, mixed>  $problemRef  the `{"$ref": …}` to the shared ProblemDetails schema
     * @return array<string, mixed>
     */
    public static function response(array $entry, array $problemRef): array
    {
        $schema = $problemRef;
        $example = self::example($entry);

        if ($entry['validation']) {
            $schema = [
                'allOf' => [
                    $problemRef,
                    [
                        'type' => 'object',
                        'properties' => [
                            'errors' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                    ],
                ],
            ];
            $example['errors'] = ['field' => ['The field is invalid.']];
        }

        return [
            'description' => $entry['description'],
            'content' => [
                self::MEDIA_TYPE => [
                    'schema' => $schema,
                    'example' => $example,
                ],
            ],
        ];
    }

    /**
     * The inline problem response for an HttpException whose status is only known at document time
     * (dynamic status hint) — no shared component, just the ProblemDetails body under that status.
     *
     * @param  array<string, mixed>  $problemRef
     * @return array<string, mixed>
     */
    public static function dynamicResponse(int $status, array $problemRef): array
    {
        return [
            'description' => 'Error',
            'content' => [
                self::MEDIA_TYPE => [
                    'schema' => $problemRef,
                    'example' => ['type' => 'about:blank', 'title' => 'Error', 'status' => $status],
                ],
            ],
        ];
    }

    /**
     * @param  array{component: string, status: string, title: string, description: string, validation: bool}  $entry
     * @return array<string, mixed>
     */
    private static function example(array $entry): array
    {
        return [
            'type' => 'about:blank',
            'title' => $entry['title'],
            'status' => (int) $entry['status'],
            'detail' => $entry['description'].'.',
        ];
    }
}
