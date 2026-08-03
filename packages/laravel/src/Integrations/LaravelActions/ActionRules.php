<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Laravel\Integrations\FormRequest\ShapeToRuleSet;
use ReflectionClass;

/**
 * Recovers a request {@see RuleSet} from a laravel-actions action's own `rules()` method, without
 * executing anything — the action-class analogue of a Form Request's `rules()`. The method is
 * analysed by the type engine so its literal rule array surfaces as a constant array shape
 * ({@see ShapeToRuleSet}); the action file joins the route's cache dependencies. Returns null when the
 * action defines no `rules()` (an action may validate purely through `authorize()`, or not at all).
 */
final class ActionRules
{
    public function __construct(
        private readonly ShapeToRuleSet $shapes = new ShapeToRuleSet,
    ) {}

    public function recover(RouteContext $context): ?RuleSet
    {
        $class = $context->actionRef->class;
        // Only recover rules() when the package would actually run it for the dispatched method:
        // an explicitly-registered method or a WithAttributes action never validates at runtime, so
        // documenting a request body from rules() there would describe an endpoint that does not exist.
        if ($class === null || ! LaravelAction::dispatchesValidation($class, $context->actionRef->method) || ! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->hasMethod('rules')) {
            return null;
        }

        $line = $reflection->getMethod('rules')->getStartLine();
        $analysis = $context->engine->analyzeAction(new ActionRef(
            (string) $reflection->getFileName(),
            $class,
            'rules',
            $line > 0 ? $line : 0,
        ));
        $context->recordDependencyFiles($analysis->dependencyFiles);

        foreach ($analysis->returns as $return) {
            return $this->shapes->convert($return->type);
        }

        return null;
    }
}
