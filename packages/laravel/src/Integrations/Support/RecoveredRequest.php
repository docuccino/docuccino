<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Patch\Contribution;

/**
 * Applies a recovered validation schema to an operation the one way both request-recovery routes
 * share (design §Phase 4). The FormRequest / inline-`validate()` path and the spatie-Data path
 * recover a rule set differently, but once converted through the shared chain they converge here:
 * diagnostics are drained, then body verbs (POST/PUT/PATCH) get a request body under the recovered
 * media type and read verbs (GET/HEAD) get query parameters. The only per-route difference is the
 * provenance producer, passed in.
 */
final class RecoveredRequest
{
    private const READ_VERBS = ['get', 'head'];

    /**
     * Drain the schema's diagnostics and write it as a request body (write verbs) or query parameters
     * (read verbs), attributed to `integration:<producer>`.
     */
    public function apply(OperationDraft $operation, RouteContext $context, ValidationSchema $result, string $producer): void
    {
        foreach ($result->diagnostics as $diagnostic) {
            $context->components->addDiagnostic($diagnostic);
        }

        $contribution = Contribution::integration($producer, $context->actionSource());

        if (in_array($context->httpMethod(), self::READ_VERBS, true)) {
            $this->applyQueryParameters($operation, $result, $contribution);

            return;
        }

        $this->applyRequestBody($operation, $result, $contribution);
    }

    private function applyRequestBody(OperationDraft $operation, ValidationSchema $result, Contribution $contribution): void
    {
        $required = is_array($result->schema['required'] ?? null) && $result->schema['required'] !== [];

        $body = ['content' => [$result->mediaType => ['schema' => $result->schema]]];
        if ($required) {
            $body = ['required' => true] + $body;
        }

        $operation->set('requestBody', $body, $contribution);
    }

    private function applyQueryParameters(OperationDraft $operation, ValidationSchema $result, Contribution $contribution): void
    {
        $properties = $result->schema['properties'] ?? null;
        if (! is_array($properties)) {
            return;
        }

        $required = is_array($result->schema['required'] ?? null) ? $result->schema['required'] : [];

        foreach ($properties as $name => $schema) {
            if (! is_string($name) || ! is_array($schema)) {
                continue;
            }

            $parameter = $operation->parameter('query', $name);
            $parameter->setRequired(in_array($name, $required, true), $contribution);

            $description = $schema['description'] ?? null;
            if (is_string($description)) {
                $parameter->setDescription($description, $contribution);
                unset($schema['description']);
            }

            foreach ($schema as $keyword => $value) {
                $parameter->schema()->set((string) $keyword, $value, $contribution);
            }
        }
    }
}
