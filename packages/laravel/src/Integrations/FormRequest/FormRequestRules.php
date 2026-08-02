<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\ReturnSite;
use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Recovers a request {@see RuleSet} from a FormRequest type-hinted on the action, without executing
 * anything: the FormRequest class is found by reflecting the action parameters, then its `rules()`
 * method is analysed by the type engine so its literal rule array surfaces as a constant array shape
 * ({@see ShapeToRuleSet}). The FormRequest file joins the route's cache dependencies.
 */
final class FormRequestRules
{
    public function __construct(
        private readonly ShapeToRuleSet $shapes = new ShapeToRuleSet,
    ) {}

    public function recover(RouteContext $context): ?RuleSet
    {
        $class = $context->actionRef->class;
        if ($class === null) {
            return null;
        }

        $formRequest = $this->formRequestParameter($class, $context->actionRef->method);
        if ($formRequest === null) {
            return null;
        }

        $reflection = new ReflectionClass($formRequest);
        if (! $reflection->hasMethod('rules')) {
            return null;
        }

        $file = (string) $reflection->getFileName();
        $line = $reflection->getMethod('rules')->getStartLine();

        $analysis = $context->engine->analyzeAction(new ActionRef($file, $formRequest, 'rules', $line > 0 ? $line : 0));
        $context->recordDependencyFiles($analysis->dependencyFiles);

        $type = $this->firstReturnType($analysis->returns);
        if ($type === null) {
            return null;
        }

        return $this->shapes->convert($type);
    }

    /**
     * @return class-string<LaravelFormRequest>|null
     */
    private function formRequestParameter(string $controller, string $method): ?string
    {
        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (Throwable) {
            return null;
        }

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();
            if (is_subclass_of($name, LaravelFormRequest::class)) {
                /** @var class-string<LaravelFormRequest> $name */
                return $name;
            }
        }

        return null;
    }

    /**
     * The first non-empty return type of `rules()` (its literal array shape).
     *
     * @param  list<ReturnSite>  $returns
     */
    private function firstReturnType(array $returns): ?DType
    {
        foreach ($returns as $return) {
            return $return->type;
        }

        return null;
    }
}
