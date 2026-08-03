<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Documents a request whose action type-hints a `spatie/laravel-data` Data object. The Data class is
 * found by reflecting the action parameters (never constructed), its properties + spatie validation
 * attributes are recovered into a rule set ({@see DataValidationRules}) and run through the SHARED
 * validation chain, then applied as a request body (write verbs, `multipart/form-data` once a file
 * rule appears) or query parameters (read verbs) — exactly like the FormRequest path, so the two
 * request-recovery routes converge on one representation.
 */
final class DataRequestExtension implements OperationExtension
{
    public function __construct(
        private readonly DataValidationRules $rules = new DataValidationRules,
        private readonly RuleOrdering $ordering = new RuleOrdering,
        private readonly RecoveredRequest $request = new RecoveredRequest,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Request;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $data = $this->dataParameter($context);
        if ($data === null) {
            return;
        }

        [$fqcn, $file] = $data;
        if ($file !== null) {
            $context->recordDependencyFiles([$file]);
        }

        $metadata = $context->engine->classMetadata(new ClassRef($fqcn));
        $ruleSet = $this->rules->build($fqcn, $metadata);
        if ($ruleSet->isEmpty()) {
            return;
        }

        $result = $context->validation()->convert($this->ordering->order($ruleSet), $context->converter());
        if ($result->isEmpty()) {
            return;
        }

        $this->request->apply($operation, $context, $result, 'spatie-data');
    }

    /**
     * The first Data-typed action parameter as `[fqcn, file]`, or null when the action takes none.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function dataParameter(RouteContext $context): ?array
    {
        $class = $context->actionRef->class;
        if ($class === null) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($class, $context->actionRef->method);
        } catch (Throwable) {
            return null;
        }

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();
            if (DataClassReflector::isData($name)) {
                return [$name, $this->classFile($name)];
            }
        }

        return null;
    }

    private function classFile(string $fqcn): ?string
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        $file = (new ReflectionClass($fqcn))->getFileName();

        return $file === false ? null : $file;
    }
}
