<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Integrations\Support\RuleParsing;

/**
 * Derives a request {@see RuleSet} from a Data class so the SHARED validation chain documents the
 * request body/query — a spatie `#[Max(100)]` ends up identical to `'max:100'` on a FormRequest.
 * Each property contributes: a presence rule (`required`, or `sometimes` for an `Optional`/`Lazy`
 * marker or a defaulted property), `nullable` when the type admits null, a base type rule inferred
 * from the (marker-stripped) property type (unless a spatie type attribute already stated one; an
 * enum type contributes its backing values), and every recovered spatie validation token
 * ({@see DataClassReflector::validationTokens()}). Nested Data / Data-collection properties recurse
 * into dotted `author.name` / `items.*.title` rules. The input key honours `#[MapInputName]`/`#[MapName]`
 * (incl. mapper classes). `#[Computed]`/`#[WithoutValidation]` properties are excluded.
 */
final class DataValidationRules
{
    /** Rule names that already fix a scalar type, so no type rule is synthesised alongside them. */
    private const TYPE_RULES = ['string', 'integer', 'int', 'numeric', 'boolean', 'bool', 'array'];

    public function __construct(private readonly DataClassReflector $reflector = new DataClassReflector) {}

    public function reflector(): DataClassReflector
    {
        return $this->reflector;
    }

    public function build(string $fqcn, ClassMetadata $metadata, TypeEngine $engine): RuleSet
    {
        return new RuleSet($this->fieldsFor($fqcn, $metadata, $engine, '', [$fqcn]));
    }

    /**
     * @param  list<string>  $visiting  the recursion chain of Data FQCNs (cycle guard)
     * @return array<string, list<ValidationRule>>
     */
    private function fieldsFor(string $fqcn, ClassMetadata $metadata, TypeEngine $engine, string $prefix, array $visiting): array
    {
        $fields = [];
        foreach ($metadata->properties as $property) {
            if ($this->reflector->isExcludedFromRequest($fqcn, $property->name)) {
                continue;
            }

            $key = $prefix.$this->reflector->inputName($fqcn, $property->name);
            $stripped = DataSchema::stripMarkers($property->type);

            $nested = $this->nestedData($fqcn, $property->name, self::unwrapNull($stripped), $engine, $visiting);
            if ($nested !== null) {
                [$childFqcn, $isCollection, $childMetadata] = $nested;
                $fields[$key] = $this->presence($fqcn, $property->name, $stripped, [], $isCollection ? 'array' : null);
                $fields = [...$fields, ...$this->fieldsFor($childFqcn, $childMetadata, $engine, $key.($isCollection ? '.*.' : '.'), [...$visiting, $childFqcn])];

                continue;
            }

            $tokens = $this->reflector->validationTokens($fqcn, $property->name);
            $attributeRules = array_map(RuleParsing::token(...), $tokens);

            $fields[$key] = [...$this->presence($fqcn, $property->name, $stripped, $attributeRules, null), ...$attributeRules];
        }

        return $fields;
    }

    /**
     * The item Data class (and whether it is a collection) a nested-Data property recurses into, or
     * null when the property is not a nested Data / Data-collection. Guards against cycles.
     *
     * @param  list<string>  $visiting
     * @return array{0: string, 1: bool, 2: ClassMetadata}|null
     */
    private function nestedData(string $fqcn, string $property, DType $stripped, TypeEngine $engine, array $visiting): ?array
    {
        // #[DataCollectionOf(SongData::class)] — the item class named explicitly (no docblock generic).
        $declared = $this->reflector->dataCollectionOf($fqcn, $property);
        if ($declared !== null && DataClassReflector::isData($declared)) {
            return $this->descend($declared, true, $engine, $visiting);
        }

        if ($stripped instanceof ListT && $stripped->value instanceof ClassT && DataClassReflector::isData($stripped->value->fqcn)) {
            return $this->descend($stripped->value->fqcn, true, $engine, $visiting);
        }

        if ($stripped instanceof ClassT && DataClassReflector::isDataCollection($stripped->fqcn)) {
            $item = $stripped->typeArgs[0] ?? null;

            return $item instanceof ClassT && DataClassReflector::isData($item->fqcn)
                ? $this->descend($item->fqcn, true, $engine, $visiting)
                : null;
        }

        if ($stripped instanceof ClassT && DataClassReflector::isData($stripped->fqcn)) {
            return $this->descend($stripped->fqcn, false, $engine, $visiting);
        }

        return null;
    }

    /**
     * @param  list<string>  $visiting
     * @return array{0: string, 1: bool, 2: ClassMetadata}|null
     */
    private function descend(string $childFqcn, bool $isCollection, TypeEngine $engine, array $visiting): ?array
    {
        if (in_array($childFqcn, $visiting, true)) {
            return null;
        }

        return [$childFqcn, $isCollection, $engine->classMetadata(new ClassRef($childFqcn))];
    }

    /**
     * The presence/nullability/type rules synthesised from the property type, prepended ahead of any
     * spatie attribute rules and only when not already stated by one. `required` is skipped for a
     * nullable, Optional/Lazy, defaulted or prohibited property (Laravel's own rule inference).
     *
     * @param  list<ValidationRule>  $attributeRules
     * @return list<ValidationRule>
     */
    private function presence(string $fqcn, string $property, DType $stripped, array $attributeRules, ?string $forceType): array
    {
        $named = array_map(static fn (ValidationRule $rule): string => $rule->name, $attributeRules);
        $out = [];

        $optional = $this->reflector->isPropertyOptional($fqcn, $property);
        $defaulted = $this->reflector->propertyDefault($fqcn, $property)['hasDefault'];
        $nullable = $stripped instanceof UnionT && $stripped->containsNull();
        $prohibited = $this->reflector->isProhibited($fqcn, $property);

        if (($optional || $defaulted) && ! in_array('sometimes', $named, true)) {
            $out[] = ValidationRule::of('sometimes');
        } elseif (! $optional && ! $defaulted && ! $nullable && ! $prohibited
            && ! in_array('required', $named, true) && ! in_array('present', $named, true)) {
            $out[] = ValidationRule::of('required');
        }

        if ($nullable && ! in_array('nullable', $named, true)) {
            $out[] = ValidationRule::of('nullable');
        }

        if ($forceType !== null) {
            $out[] = ValidationRule::of($forceType);

            return $out;
        }

        $enum = $this->enumRule($stripped);
        if ($enum !== null && array_intersect(self::TYPE_RULES, $named) === []) {
            $out[] = $enum;

            return $out;
        }

        $typeRule = self::typeRule($stripped);
        if ($typeRule !== null && array_intersect(self::TYPE_RULES, $named) === []) {
            $out[] = ValidationRule::of($typeRule);
        }

        return $out;
    }

    /** An `enum` rule (backing values + FQCN note) for an enum-typed property, else null. */
    private function enumRule(DType $stripped): ?ValidationRule
    {
        $type = self::unwrapNull($stripped);
        if (! $type instanceof EnumT) {
            return null;
        }

        $values = array_map(strval(...), EnumReflection::values($type->fqcn));

        return $values === [] ? null : ValidationRule::of('enum', $values, $type->fqcn);
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

        $stripped = $type->without(static fn (DType $member): bool => $member instanceof NullT);

        return $stripped instanceof UnionT ? $type : $stripped;
    }
}
