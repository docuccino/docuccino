<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FrameworkErrors;

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Patch\Contribution;

/**
 * Tier 2 of the error-response chain (design §6): Laravel's stock JSON error shapes, keyed by the
 * exception the framework renders to each status. `422` grafts the field-keyed `errors` map onto
 * `{message}`; `401`/`403`/`404` are `{message}` alone.
 *
 * Ordered LATE so it runs after the inferred-handler tier (FIRST) and any active preset (EARLY),
 * but before the terminal fallback (LAST): a real handler or preset always wins; this only documents
 * the framework exceptions neither covered. Matching is subtype-aware (a subclass of a mapped base
 * exception inherits its shape); an exception outside the table is declined so the chain continues.
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final class FrameworkErrorsExceptionToResponse implements ExceptionToResponse
{
    private const VALIDATION = 'Illuminate\\Validation\\ValidationException';

    private const AUTHENTICATION = 'Illuminate\\Auth\\AuthenticationException';

    private const AUTHORIZATION = 'Illuminate\\Auth\\Access\\AuthorizationException';

    private const MODEL_NOT_FOUND = 'Illuminate\\Database\\Eloquent\\ModelNotFoundException';

    private const NOT_FOUND_HTTP = 'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException';

    /**
     * The per-exception mapping table (design coverage standard: a dataset test drives EVERY entry).
     * Maintained against Laravel's stock JSON rendering: base exception FQCN → `[status, shape]`.
     *
     * @return array<string, array{status: string, description: string, shape: array<string, mixed>}>
     */
    public static function table(): array
    {
        return [
            self::VALIDATION => ['status' => '422', 'description' => 'Unprocessable Entity', 'shape' => self::validationShape()],
            self::AUTHENTICATION => ['status' => '401', 'description' => 'Unauthenticated', 'shape' => self::messageShape()],
            self::AUTHORIZATION => ['status' => '403', 'description' => 'Forbidden', 'shape' => self::messageShape()],
            self::MODEL_NOT_FOUND => ['status' => '404', 'description' => 'Not Found', 'shape' => self::messageShape()],
            self::NOT_FOUND_HTTP => ['status' => '404', 'description' => 'Not Found', 'shape' => self::messageShape()],
        ];
    }

    public function supports(ThrownException $exception, RouteContext $context): bool
    {
        return $this->match($exception->exceptionFqcn) !== null;
    }

    public function producer(): string
    {
        return 'integration:framework-errors';
    }

    public function toResponse(
        ThrownException $exception,
        RouteContext $context,
        ComponentRegistry $components,
    ): ?ResponseDraft {
        $entry = $this->match($exception->exceptionFqcn);
        if ($entry === null) {
            return null;
        }

        $contribution = Contribution::integration('framework-errors');
        $draft = new ResponseDraft($entry['status']);
        $draft->setDescription($entry['description'], $contribution);

        foreach ($entry['shape'] as $keyword => $value) {
            $draft->content('application/json')->set($keyword, $value, $contribution);
        }

        return $draft;
    }

    /**
     * The table entry for an exception FQCN, matched subtype-aware (a subclass inherits the mapped
     * base's shape), or null when the exception is outside the framework-defaults table.
     *
     * @return array{status: string, description: string, shape: array<string, mixed>}|null
     */
    private function match(string $fqcn): ?array
    {
        foreach (self::table() as $base => $entry) {
            if ($fqcn === $base || is_a($fqcn, $base, true)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function messageShape(): array
    {
        return [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string']],
            'required' => ['message'],
        ];
    }

    /**
     * The 422 body: `{message}` plus the field-keyed `errors` map Laravel renders from the validator.
     *
     * @return array<string, mixed>
     */
    private static function validationShape(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'required' => ['message', 'errors'],
        ];
    }
}
