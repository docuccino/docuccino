<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Patch\Contribution;

/**
 * Applies a {@see ValidationSchema} to an operation the one way every request-schema source shares:
 * diagnostics are drained, then body verbs (POST/PUT/PATCH) get a request body under the schema's
 * media type and read verbs (GET/HEAD) get query parameters. Its input is a core value object
 * (a ValidationSchema), not framework code — the HTTP verb → body-or-query decision is generic OAS
 * assembly, so it lives in core; the adapter's recovery extensions (FormRequest/inline, spatie-Data,
 * laravel-actions) each recover a rule set differently, then converge on this one applier, passing
 * only the provenance producer that distinguishes them.
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
