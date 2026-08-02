<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Laravel\Integrations\Support\RuleParsing;

/**
 * Derives a request {@see RuleSet} from a Data class so the SHARED validation chain documents the
 * request body/query — a spatie `#[Max(100)]` ends up identical to `'max:100'` on a FormRequest.
 * Each property contributes: a presence rule (`required`, or `sometimes` for an `Optional`/`Lazy`
 * marker), `nullable` when the type admits null, a base type rule inferred from the property type
 * (unless a spatie type attribute already stated one), and every recovered spatie validation token
 * ({@see DataClassReflector::validationTokens()}). The input key honours `#[MapInputName]`/`#[MapName]`.
 */
final class DataValidationRules
{
    /** Rule names that already fix a scalar type, so no type rule is synthesised alongside them. */
    private const TYPE_RULES = ['string', 'integer', 'int', 'numeric', 'boolean', 'bool', 'array'];

    public function __construct(private readonly DataClassReflector $reflector = new DataClassReflector) {}

    public function build(string $fqcn, ClassMetadata $metadata): RuleSet
    {
        $fields = [];
        foreach ($metadata->properties as $property) {
            $tokens = $this->reflector->validationTokens($fqcn, $property->name);
            $rules = array_map(RuleParsing::token(...), $tokens);

            $rules = [...$this->presence($fqcn, $property->name, $property->type, $rules), ...$rules];

            $fields[$this->reflector->inputName($fqcn, $property->name)] = $rules;
        }

        return new RuleSet($fields);
    }

    /**
     * The presence/nullability/type rules synthesised from the property type, prepended ahead of the
     * spatie attribute rules and only when they are not already stated by an attribute.
     *
     * @param  list<ValidationRule>  $attributeRules
     * @return list<ValidationRule>
     */
    private function presence(string $fqcn, string $property, DType $type, array $attributeRules): array
    {
        $named = array_map(static fn (ValidationRule $rule): string => $rule->name, $attributeRules);
        $out = [];

        $optional = $this->reflector->isPropertyOptional($fqcn, $property);
        if ($optional && ! in_array('sometimes', $named, true)) {
            $out[] = ValidationRule::of('sometimes');
        } elseif (! $optional && ! in_array('required', $named, true) && ! in_array('present', $named, true)) {
            $out[] = ValidationRule::of('required');
        }

        if (self::isNullable($type) && ! in_array('nullable', $named, true)) {
            $out[] = ValidationRule::of('nullable');
        }

        $typeRule = self::typeRule($type);
        if ($typeRule !== null && array_intersect(self::TYPE_RULES, $named) === []) {
            $out[] = ValidationRule::of($typeRule);
        }

        return $out;
    }

    private static function typeRule(DType $type): ?string
    {
        $type = self::unwrapNull($type);

        if ($type instanceof ScalarT) {
            return match ($type->scalar) {
                ScalarT::INT => 'integer',
                ScalarT::FLOAT => 'numeric',
                ScalarT::BOOL => 'boolean',
                default => 'string',
            };
        }

        if ($type instanceof ListT || $type instanceof MapT || $type instanceof ArrayShapeT) {
            return 'array';
        }

        return null;
    }

    /** The sole non-null member of a nullable union, else the type itself. */
    private static function unwrapNull(DType $type): DType
    {
        if (! $type instanceof UnionT) {
            return $type;
        }

        $members = array_values(array_filter(
            $type->members,
            static fn (DType $member): bool => ! $member instanceof NullT,
        ));

        return count($members) === 1 ? $members[0] : $type;
    }

    private static function isNullable(DType $type): bool
    {
        if (! $type instanceof UnionT) {
            return false;
        }

        foreach ($type->members as $member) {
            if ($member instanceof NullT) {
                return true;
            }
        }

        return false;
    }
}
