<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;

/**
 * Documents a request from its validation rules (design §Phase 4 — FormRequest + inline validate).
 * It recovers a rule set statically — a FormRequest's `rules()` analysed as a constant array, else
 * an inline `$request->validate([...])` / `Validator::make(...)` traced in the action body — orders
 * it into Laravel's effect sequence, and runs it through the shared rule chain. Body verbs
 * (POST/PUT/PATCH) get a request body under the recovered media type (JSON, or multipart once a
 * file rule appears); read verbs (GET/HEAD) get query parameters. Attributes still override, since
 * this writes at the integration layer.
 */
final class ValidationRequestExtension implements OperationExtension
{
    public function __construct(
        private readonly FormRequestRules $formRequest = new FormRequestRules,
        private readonly RuleOrdering $ordering = new RuleOrdering,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Request;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $rules = $this->recover($context);
        if ($rules === null || $rules->isEmpty()) {
            return;
        }

        $result = $context->validation()->convert($this->ordering->order($rules), $context->converter());
        if ($result->isEmpty()) {
            return;
        }

        foreach ($result->diagnostics as $diagnostic) {
            $context->components->addDiagnostic($diagnostic);
        }

        if ($this->isReadVerb($context)) {
            $this->applyQueryParameters($operation, $context, $result);

            return;
        }

        $this->applyRequestBody($operation, $context, $result);
    }

    private function recover(RouteContext $context): ?RuleSet
    {
        $fromFormRequest = $this->formRequest->recover($context);
        if ($fromFormRequest !== null && ! $fromFormRequest->isEmpty()) {
            return $fromFormRequest;
        }

        $visitor = new InlineRulesVisitor;
        $context->trace($visitor);

        $inline = $visitor->ruleSet();

        return $inline->isEmpty() ? null : $inline;
    }

    private function isReadVerb(RouteContext $context): bool
    {
        return in_array($context->httpMethod(), ['get', 'head'], true);
    }

    private function applyRequestBody(OperationDraft $operation, RouteContext $context, ValidationSchema $result): void
    {
        $required = is_array($result->schema['required'] ?? null) && $result->schema['required'] !== [];

        $body = [
            'content' => [$result->mediaType => ['schema' => $result->schema]],
        ];
        if ($required) {
            $body = ['required' => true] + $body;
        }

        $operation->set('requestBody', $body, Contribution::integration('form-request', $context->actionSource()));
    }

    private function applyQueryParameters(OperationDraft $operation, RouteContext $context, ValidationSchema $result): void
    {
        $properties = $result->schema['properties'] ?? null;
        if (! is_array($properties)) {
            return;
        }

        $required = is_array($result->schema['required'] ?? null) ? $result->schema['required'] : [];
        $contribution = Contribution::integration('form-request', $context->actionSource());

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
