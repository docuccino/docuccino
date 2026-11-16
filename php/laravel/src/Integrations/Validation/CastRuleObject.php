<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Inference\ConstValue;
use Illuminate\Validation\ValidationRuleParser;
use ReflectionClass;
use ReflectionMethod;
use Stringable;
use Throwable;

/**
 * One of Laravel's own rule objects that its validator reads as the rule string it casts to, rather than
 * running it as a check: `Rule::string()->min(2)`, `Rule::acceptedIf('kind', 'business')`. Written with values
 * at the call, it is built and read by Laravel's own parser. Building runs only Laravel's constructor, `Rule::`
 * static and builder methods, on literal values: never a macro, a callable or a model, so nothing of the
 * application's runs.
 */
final class CastRuleObject
{
    private const RULES = 'Illuminate\\Validation\\Rules\\';

    /**
     * The objects Laravel runs as rules of their own rather than casting them to a string
     * ({@see ValidationRuleParser}).
     */
    private const RUN_AS_RULES = [
        'Illuminate\\Contracts\\Validation\\Rule',
        'Illuminate\\Contracts\\Validation\\ValidationRule',
        'Illuminate\\Contracts\\Validation\\InvokableRule',
        'Illuminate\\Contracts\\Validation\\CompilableRules',
    ];

    /** The trait that resolves a model class written as a table by constructing the model, application code. */
    private const DATABASE_RULE = 'Illuminate\\Validation\\Rules\\DatabaseRule';

    /** Rule objects whose constructor takes a keyed array, which a folded array, its keys gone, cannot rebuild. */
    private const KEYED_CONSTRUCTORS = ['Illuminate\\Validation\\Rules\\Dimensions'];

    /** Whether Laravel validates with a rule object of this class as its string: one of its own, no check itself. */
    public static function casts(string $class): bool
    {
        $class = ltrim($class, '\\');
        if (! str_starts_with($class, self::RULES) || ! class_exists($class) || ! is_a($class, Stringable::class, true)) {
            return false;
        }

        foreach (self::RUN_AS_RULES as $contract) {
            if (is_a($class, $contract, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The rule strings `new $class(...$args)`, with its chained calls, stands for, or null where an argument is
     * no literal or a step is not one this builds.
     *
     * @param  list<ConstValue>  $args
     * @param  list<array{method: string, args: list<ConstValue>}>  $chain
     * @return list<string>|null
     */
    public static function fromNew(string $class, array $args, array $chain): ?array
    {
        $class = ltrim($class, '\\');
        if (! self::buildable($class) || ($args !== [] && in_array($class, self::KEYED_CONSTRUCTORS, true))) {
            return null;
        }

        $constructor = (new ReflectionClass($class))->getConstructor();
        $values = self::literals($args);
        if ($values === null || ($constructor !== null && ! self::takesNoCallable($constructor))) {
            return null;
        }

        try {
            $rule = new $class(...$values);
        } catch (Throwable) {
            return null;
        }

        return self::chained($rule, $chain);
    }

    /**
     * The rule strings a `Rule::` call, with its chained calls, stands for, where the static builds one of these
     * objects ({@see casts()}); null otherwise.
     *
     * @param  string  $factory  `Illuminate\\Validation\\Rule::acceptedIf`
     * @param  list<ConstValue>  $args
     * @param  list<array{method: string, args: list<ConstValue>}>  $chain
     * @return list<string>|null
     */
    public static function fromFactory(string $factory, array $args, array $chain): ?array
    {
        $method = RuleFactories::staticOf($factory);
        $class = $method === null ? null : RuleFactories::builtClass($method->getName());
        if ($method === null || $class === null || ! self::buildable($class) || ($args !== [] && in_array($class, self::KEYED_CONSTRUCTORS, true))) {
            return null;
        }

        $rule = self::invoke($method, null, $args);

        return is_object($rule) && self::buildable($rule::class) ? self::chained($rule, $chain) : null;
    }

    /**
     * The rule strings Laravel's validator reads a rule object as, split where its own parser splits them.
     *
     * @return list<string>
     */
    public static function strings(Stringable $rule): array
    {
        $rules = (new ValidationRuleParser([]))->explode(['field' => [$rule]])->rules;
        $parsed = is_array($rules) && is_array($rules['field'] ?? null) ? $rules['field'] : [];

        $strings = [];
        foreach ($parsed as $piece) {
            $string = is_string($piece) || $piece instanceof Stringable ? trim((string) $piece) : '';
            if ($string !== '') {
                $strings[] = $string;
            }
        }

        return $strings;
    }

    /**
     * @param  list<array{method: string, args: list<ConstValue>}>  $chain
     * @return list<string>|null
     */
    private static function chained(object $rule, array $chain): ?array
    {
        foreach ($chain as $call) {
            // A method the class does not declare is a macro, which is the application's code.
            $reflection = new ReflectionClass($rule);
            if (! $reflection->hasMethod($call['method'])) {
                return null;
            }

            $method = $reflection->getMethod($call['method']);
            $rule = $method->isPublic() && ! $method->isStatic() ? self::invoke($method, $rule, $call['args']) : null;
            if (! is_object($rule) || ! self::buildable($rule::class)) {
                return null;
            }
        }

        return $rule instanceof Stringable ? self::strings($rule) : null;
    }

    /**
     * What a method returns for the literal values written at its call, or null where one is no literal, it
     * takes a callable or it throws.
     *
     * @param  list<ConstValue>  $args
     */
    private static function invoke(ReflectionMethod $method, ?object $on, array $args): mixed
    {
        $values = self::literals($args);
        if ($values === null || count($values) < $method->getNumberOfRequiredParameters() || ! self::takesNoCallable($method)) {
            return null;
        }

        try {
            return $method->invoke($on, ...$values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @phpstan-assert-if-true class-string<Stringable> $class
     */
    private static function buildable(string $class): bool
    {
        if (! self::casts($class)) {
            return false;
        }

        for ($scope = $class; $scope !== false; $scope = get_parent_class($scope)) {
            if (in_array(self::DATABASE_RULE, class_uses($scope) ?: [], true)) {
                return false;
            }
        }

        return true;
    }

    /** Whether no parameter is declared to take a callable, which a literal string can be. */
    private static function takesNoCallable(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = strtolower((string) $parameter->getType());
            if (str_contains($type, 'callable') || str_contains($type, 'closure')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The values a list of arguments states, or null unless each is a scalar or a list of them.
     *
     * @param  list<ConstValue>  $args
     * @return list<mixed>|null
     */
    private static function literals(array $args): ?array
    {
        $values = [];
        foreach ($args as $arg) {
            if ($arg->isScalar()) {
                $values[] = $arg->scalar;

                continue;
            }

            $items = $arg->isArray() ? self::literals($arg->items) : null;
            if ($items === null) {
                return null;
            }

            $values[] = $items;
        }

        return $values;
    }
}
