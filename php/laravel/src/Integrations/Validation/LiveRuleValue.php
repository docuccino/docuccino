<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Laravel\Integrations\FormRequest\ConstValueToRules;
use Illuminate\Validation\ValidationRuleParser;
use ReflectionObject;
use Stringable;
use UnitEnum;

/**
 * A rule value that already exists — an attribute's argument, built by PHP — as the {@see ConstValue} the
 * `new` that built it folds to, so {@see ConstValueToRules} reads `#[Rule(new Enum(X::class))]` exactly as it
 * reads `new Enum(X::class)` in a rules array. A framework rule object keeps its constructor's arguments in
 * its own state, and is read back from there. One Laravel casts to a rule string is that string, and a
 * `Password` the rules it runs; any other object is its class alone.
 */
final class LiveRuleValue
{
    private const RULES = 'Illuminate\\Validation\\Rules\\';

    /**
     * The properties a framework rule object keeps its constructor's arguments in, in argument order, for
     * every object {@see ConstValueToRules} reads by its arguments.
     */
    private const ARGUMENTS = [
        self::RULES.'Enum' => ['type'],
        self::RULES.'In' => ['values'],
        self::RULES.'NotIn' => ['values'],
        self::RULES.'ArrayRule' => ['keys'],
        // Read by name alone: the table and column decide nothing the document states.
        self::RULES.'Exists' => [],
        self::RULES.'Unique' => [],
        self::RULES.'ExcludeIf' => ['condition'],
        self::RULES.'ExcludeUnless' => ['condition'],
        self::RULES.'ExcludeWith' => ['anotherField'],
        self::RULES.'ExcludeWithout' => ['fields'],
    ];

    /**
     * Where `->only()`/`->except()` leave an enum rule's cases. Laravel consults `except` only while `only`
     * is empty, so the first non-empty one is the whole narrowing.
     */
    private const ENUM_SELECTORS = ['only', 'except'];

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

    /** The checks a `Password` runs on the value after its own rules, none of which a schema states. */
    private const PASSWORD_CHECKS = ['mixedCase', 'letters', 'numbers', 'symbols', 'uncompromised'];

    public static function of(mixed $value): ConstValue
    {
        return match (true) {
            $value === null || is_scalar($value) => ConstValue::scalar($value),
            $value instanceof UnitEnum => ConstValue::enumCase($value::class, $value->name),
            is_array($value) => ConstValue::array(array_map(self::of(...), array_values($value))),
            is_object($value) => self::object($value),
            default => ConstValue::unknown('a value no rule list can hold'),
        };
    }

    private static function object(object $rule): ConstValue
    {
        $class = $rule::class;
        if ($class === self::RULES.'Password') {
            return self::password($rule);
        }

        if (! isset(self::ARGUMENTS[$class]) && self::castToString($rule)) {
            return ConstValue::scalar((string) $rule);
        }

        $args = [];
        foreach (self::ARGUMENTS[$class] ?? [] as $property) {
            $args[] = self::state($rule, $property);
        }

        $value = ConstValue::instance($class, $args);
        if ($class !== self::RULES.'Enum') {
            return $value;
        }

        foreach (self::ENUM_SELECTORS as $selector) {
            $cases = self::state($rule, $selector);
            if ($cases->items !== []) {
                return $value->withChainedCall($selector, [$cases]);
            }
        }

        return $value;
    }

    /**
     * A framework rule object Laravel validates with as its string cast: one of its own, not run as a rule
     * itself, that has one. Only the framework's are cast here, so no application code runs.
     *
     * @phpstan-assert-if-true Stringable $rule
     */
    private static function castToString(object $rule): bool
    {
        if (! str_starts_with($rule::class, self::RULES) || ! $rule instanceof Stringable) {
            return false;
        }

        foreach (self::RUN_AS_RULES as $contract) {
            if ($rule instanceof $contract) {
                return false;
            }
        }

        return true;
    }

    /**
     * A `Password` as the rules Laravel validates the value with first — `string`, `min`, any `max`, and the
     * rules it was handed — then, where it also runs a check of its own on the value (letters, symbols, a
     * breach lookup), itself, which the fold leaves out as a rule it cannot read.
     */
    private static function password(object $rule): ConstValue
    {
        $items = [ConstValue::scalar('string')];
        foreach (['min', 'max'] as $bound) {
            $value = self::state($rule, $bound)->scalar;
            if (is_int($value) && $value > 0) {
                $items[] = ConstValue::scalar($bound.':'.$value);
            }
        }

        $items = [...$items, ...self::state($rule, 'customRules')->items];

        foreach (self::PASSWORD_CHECKS as $check) {
            if (self::state($rule, $check)->scalar !== false) {
                $items[] = ConstValue::instance($rule::class, []);

                break;
            }
        }

        return ConstValue::array($items);
    }

    /** One property of a rule object's state, or unknown where the installed class keeps no such property. */
    private static function state(object $rule, string $property): ConstValue
    {
        $reflection = new ReflectionObject($rule);

        return $reflection->hasProperty($property)
            ? self::of($reflection->getProperty($property)->getValue($rule))
            : ConstValue::unknown('a rule object whose state this build cannot read');
    }
}
