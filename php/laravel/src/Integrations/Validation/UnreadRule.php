<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Illuminate\Validation\ValidationRuleParser;

/**
 * What leaving out a rule the build saw but could not read does to the rules beside it. A rule object can
 * only refuse values, so without it the field is documented wider than the endpoint, which is still true.
 * A rule whose content is unknown — `Rule::when(…)`, a value from a call, a spread — can be `nullable`,
 * `sometimes` or `exclude`, which loosen the rules beside it, so none of them hold on their own.
 *
 * The object half is Laravel's own grammar ({@see ValidationRuleParser}): a closure or a rule contract is
 * run as a check, conditional and nested rules expand to more rules, and any other object is cast to a
 * rule string.
 */
final class UnreadRule
{
    /** Rule objects that expand to other rules rather than checking the value. */
    private const EXPANDING_OBJECTS = [
        'Illuminate\\Validation\\ConditionalRules',
        'Illuminate\\Contracts\\Validation\\CompilableRules',
        'Illuminate\\Validation\\Rules\\ExcludeIf',
        'Illuminate\\Validation\\Rules\\ExcludeUnless',
    ];

    /** The contracts Laravel runs as a check on the value. */
    private const CHECKS = [
        'Closure',
        'Illuminate\\Contracts\\Validation\\Rule',
        'Illuminate\\Contracts\\Validation\\ValidationRule',
        'Illuminate\\Contracts\\Validation\\InvokableRule',
    ];

    /**
     * `Rule::` statics that do not build a check: conditional and nested rules, the exclude rules, and
     * the macro plumbing, which builds whatever was registered.
     */
    private const EXPANDING_FACTORIES = ['when', 'unless', 'foreach', 'compile', 'excludeif', 'excludeunless', 'macro', 'mixin', 'hasmacro', 'flushmacros', '__callstatic'];

    private const RULE = 'Illuminate\\Validation\\Rule';

    private const RULES_NAMESPACE = 'Illuminate\\Validation\\Rules\\';

    /**
     * Whether a rule object of this class only refuses values. A class this build cannot load is taken
     * as a rule object, which is what a `new` in a rules list is; a loaded class that is none of Laravel's
     * own and implements no rule contract is cast to a rule string nothing here can read.
     */
    public static function objectNarrows(string $class): bool
    {
        $class = ltrim($class, '\\');

        foreach (self::EXPANDING_OBJECTS as $expanding) {
            if (is_a($class, $expanding, true)) {
                return false;
            }
        }

        if (! class_exists($class)) {
            return true;
        }

        foreach (self::CHECKS as $check) {
            if (is_a($class, $check, true)) {
                return true;
            }
        }

        return str_starts_with($class, self::RULES_NAMESPACE);
    }

    /**
     * Whether a static call written in a rules list builds a rule that only refuses values: a `Rule::`
     * factory Laravel declares, other than the conditional and nested ones, or a factory on one of its
     * rule classes (`Password::min(8)`). A macro or an application's own helper could return anything.
     */
    public static function factoryNarrows(string $factory): bool
    {
        $separator = strrpos($factory, '::');
        if ($separator === false) {
            return false;
        }

        $class = ltrim(substr($factory, 0, $separator), '\\');
        $method = substr($factory, $separator + 2);

        if (str_starts_with($class, self::RULES_NAMESPACE)) {
            return true;
        }

        return strcasecmp($class, self::RULE) === 0
            && ! in_array(strtolower($method), self::EXPANDING_FACTORIES, true)
            && method_exists(self::RULE, $method);
    }
}
