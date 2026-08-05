<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Recovers a request {@see RuleSet} from a FormRequest type-hinted on the action, without executing
 * anything: the FormRequest class is found by reflecting the action parameters, then its `rules()`
 * is analysed into a rule set via the shared {@see RulesFromClass} recovery tail.
 */
final class FormRequestRules
{
    public function __construct(
        private readonly RulesFromClass $rules = new RulesFromClass,
    ) {}

    public function recover(RouteContext $context): ?RuleSet
    {
        $formRequest = self::classFor($context);
        if ($formRequest === null) {
            return null;
        }

        return $this->rules->analyse($context, $formRequest);
    }

    /**
     * The FormRequest class type-hinted on the route's action, or null when none is — the shared
     * lookup the rule recovery and the implicit-403 authorize signal both use.
     *
     * @return class-string<LaravelFormRequest>|null
     */
    public static function classFor(RouteContext $context): ?string
    {
        $class = $context->actionRef->class;
        if ($class === null) {
            return null;
        }

        return self::formRequestParameter($class, $context->actionRef->method);
    }

    /**
     * @return class-string<LaravelFormRequest>|null
     */
    private static function formRequestParameter(string $controller, string $method): ?string
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
}
