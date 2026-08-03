<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Inference\ActionRef;
use ReflectionClass;

/**
 * Analyses a class's `rules()` method into a {@see RuleSet}, without executing anything: reflect the
 * class, feed its `rules()` to the type engine so the literal rule array surfaces as a constant array
 * shape ({@see ShapeToRuleSet}), and record the analysed file as a route cache dependency. The one
 * recovery tail the FormRequest and laravel-actions integrations converge on — they differ only in
 * how they resolve WHICH class carries the rules().
 */
final class RulesFromClass
{
    public function __construct(
        private readonly ShapeToRuleSet $shapes = new ShapeToRuleSet,
    ) {}

    public function analyse(RouteContext $context, string $class): ?RuleSet
    {
        if (! class_exists($class)) {
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
