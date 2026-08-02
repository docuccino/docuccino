<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;
use Docuccino\Core\Support\Fqcn;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Reflects a `spatie/laravel-data` Data class into the presentation facts the schema/request
 * mappers need, keeping every touch of the (optional) spatie surface in one place. Spatie's own
 * attribute/marker classes are referenced by FQCN string — the integration carries no hard
 * dependency on the package (it is only ever exercised when the host app has it installed, the
 * `class_exists` registration guard). Docuccino's own `#[Hidden]`/`#[SchemaName]`/`#[SchemaId]`
 * are honoured alongside spatie's.
 */
final class DataClassReflector
{
    public const DATA = 'Spatie\\LaravelData\\Data';

    public const DATA_COLLECTION = 'Spatie\\LaravelData\\DataCollection';

    /**
     * The interface every collectable shares — a plain `DataCollection` AND the paginated variants,
     * which do NOT extend `DataCollection` but do implement this. The collection trigger tests it.
     */
    public const BASE_COLLECTABLE = 'Spatie\\LaravelData\\Contracts\\BaseDataCollectable';

    public const OPTIONAL = 'Spatie\\LaravelData\\Optional';

    public const LAZY = 'Spatie\\LaravelData\\Lazy';

    private const SPATIE_HIDDEN = 'Spatie\\LaravelData\\Attributes\\Hidden';

    private const MAP_OUTPUT_NAME = 'Spatie\\LaravelData\\Attributes\\MapOutputName';

    private const MAP_INPUT_NAME = 'Spatie\\LaravelData\\Attributes\\MapInputName';

    private const MAP_NAME = 'Spatie\\LaravelData\\Attributes\\MapName';

    private const PAGINATED_COLLECTION = 'Spatie\\LaravelData\\PaginatedDataCollection';

    private const CURSOR_PAGINATED_COLLECTION = 'Spatie\\LaravelData\\CursorPaginatedDataCollection';

    private const VALIDATION_NS = 'Spatie\\LaravelData\\Attributes\\Validation\\';

    /**
     * Spatie validation attribute short-name → Laravel rule name. The recovered token is fed through
     * the SHARED validation chain, so a spatie `#[Max(100)]` documents identically to `'max:100'` on
     * a FormRequest. Kept deliberately curated (the common DSL floor); an unmapped attribute is
     * ignored rather than guessed.
     *
     * @var array<string, string>
     */
    private const RULE_MAP = [
        'Required' => 'required', 'Nullable' => 'nullable', 'Sometimes' => 'sometimes',
        'Present' => 'present', 'Prohibited' => 'prohibited', 'Filled' => 'filled',
        'Email' => 'email', 'Url' => 'url', 'ActiveUrl' => 'active_url', 'Uuid' => 'uuid',
        'Ulid' => 'ulid', 'Numeric' => 'numeric', 'IntegerType' => 'integer', 'StringType' => 'string',
        'BooleanType' => 'boolean', 'ArrayType' => 'array', 'Alpha' => 'alpha', 'AlphaNumeric' => 'alpha_num',
        'AlphaDash' => 'alpha_dash', 'Date' => 'date', 'Json' => 'json', 'Ip' => 'ip',
        'Max' => 'max', 'Min' => 'min', 'Size' => 'size', 'Between' => 'between',
        'In' => 'in', 'NotIn' => 'not_in', 'Regex' => 'regex', 'DateFormat' => 'date_format',
        'MaxDigits' => 'max_digits', 'MinDigits' => 'min_digits', 'DigitsBetween' => 'digits_between',
        'StartsWith' => 'starts_with', 'EndsWith' => 'ends_with',
    ];

    /** Rules that carry comma-separated parameters (`max:100`, `in:a,b`); others are bare (`required`). */
    private const VALUE_RULES = [
        'max', 'min', 'size', 'between', 'in', 'not_in', 'regex', 'date_format',
        'max_digits', 'min_digits', 'digits_between', 'starts_with', 'ends_with',
    ];

    /** Whether an FQCN is a concrete spatie Data class (the schema mapper's trigger). */
    public static function isData(string $fqcn): bool
    {
        return $fqcn !== self::DATA && is_a($fqcn, self::DATA, true);
    }

    /** Whether an FQCN is any spatie collectable (plain or paginated) — rendered as array/envelope. */
    public static function isDataCollection(string $fqcn): bool
    {
        return is_a($fqcn, self::BASE_COLLECTABLE, true);
    }

    /**
     * The property names hidden from OUTPUT: class-level `#[Hidden(...)]` plus any property carrying
     * spatie's or Docuccino's property-level `#[Hidden]`.
     *
     * @return array{hidden: list<string>, schemaName: ?string, schemaId: ?string}
     */
    public function classFacts(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return ['hidden' => [], 'schemaName' => null, 'schemaId' => null];
        }

        $reflection = new ReflectionClass($fqcn);

        $hidden = [];
        foreach ($reflection->getAttributes(DocuccinoHidden::class) as $attribute) {
            $hidden = [...$hidden, ...$attribute->newInstance()->properties];
        }

        $schemaName = null;
        foreach ($reflection->getAttributes(SchemaName::class) as $attribute) {
            $schemaName = $attribute->newInstance()->name;
        }

        $schemaId = null;
        foreach ($reflection->getAttributes(SchemaId::class) as $attribute) {
            $schemaId = $attribute->newInstance()->id;
        }

        return ['hidden' => $hidden, 'schemaName' => $schemaName, 'schemaId' => $schemaId];
    }

    /** Whether the named property is hidden from output (property-level spatie/Docuccino `#[Hidden]`). */
    public function isPropertyHidden(string $fqcn, string $property): bool
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return false;
        }

        return $reflection->getAttributes(self::SPATIE_HIDDEN) !== []
            || $reflection->getAttributes(DocuccinoHidden::class) !== [];
    }

    /**
     * Whether a property is optional in the (de)serialised shape: its declared type unions in
     * spatie's `Optional` or `Lazy` marker (`public string|Optional $foo`). Such a property is
     * absent from `required` on output and non-required on input.
     */
    public function isPropertyOptional(string $fqcn, string $property): bool
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return false;
        }

        foreach ($this->typeNames($reflection) as $name) {
            if (is_a($name, self::OPTIONAL, true) || is_a($name, self::LAZY, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The paginated-collection kind of a `DataCollection` FQCN, for envelope selection:
     * `length`/`cursor` for the paginated variants, `simple` for a plain `DataCollection`.
     */
    public function collectionKind(string $fqcn): string
    {
        if (is_a($fqcn, self::CURSOR_PAGINATED_COLLECTION, true)) {
            return 'cursor';
        }

        return is_a($fqcn, self::PAGINATED_COLLECTION, true) ? 'length' : 'simple';
    }

    /**
     * Laravel rule tokens recovered from a property's spatie validation attributes — read statically
     * via {@see \ReflectionAttribute::getArguments()} (never instantiated, so no user expression runs).
     * Fed through the shared validation chain by {@see DataValidationRules}.
     *
     * @return list<string>
     */
    public function validationTokens(string $fqcn, string $property): array
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return [];
        }

        $tokens = [];
        foreach ($reflection->getAttributes() as $attribute) {
            $name = $attribute->getName();
            if (! str_starts_with($name, self::VALIDATION_NS)) {
                continue;
            }

            $rule = self::RULE_MAP[Fqcn::short($name)] ?? null;
            if ($rule === null) {
                continue;
            }

            if (! in_array($rule, self::VALUE_RULES, true)) {
                $tokens[] = $rule;

                continue;
            }

            $parameters = $this->scalarArguments($attribute->getArguments());
            $tokens[] = $parameters === '' ? $rule : $rule.':'.$parameters;
        }

        return $tokens;
    }

    /**
     * Flatten an attribute's raw arguments into a comma-joined scalar parameter string
     * (`Between(1, 10)` → `"1,10"`, `In(['a','b'])` → `"a,b"`); non-scalar args are dropped.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private function scalarArguments(array $arguments): string
    {
        $flat = [];
        array_walk_recursive($arguments, static function (mixed $value) use (&$flat): void {
            if (is_scalar($value)) {
                $flat[] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        });

        return implode(',', $flat);
    }

    /**
     * The named types a property declares (flattening a union/intersection), for marker detection.
     *
     * @return list<string>
     */
    private function typeNames(ReflectionProperty $property): array
    {
        $type = $property->getType();

        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        $names = [];
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType) {
                    $names[] = $member->getName();
                }
            }
        }

        return $names;
    }

    /** The OUTPUT key for a property, honouring `#[MapOutputName]` / `#[MapName]` (else the name). */
    public function outputName(string $fqcn, string $property): string
    {
        return $this->mappedName($fqcn, $property, self::MAP_OUTPUT_NAME) ?? $property;
    }

    /** The INPUT key for a property, honouring `#[MapInputName]` / `#[MapName]` (else the name). */
    public function inputName(string $fqcn, string $property): string
    {
        return $this->mappedName($fqcn, $property, self::MAP_INPUT_NAME) ?? $property;
    }

    private function mappedName(string $fqcn, string $property, string $directional): ?string
    {
        $reflection = $this->property($fqcn, $property);
        if ($reflection === null) {
            return null;
        }

        // The directional map (MapInputName/MapOutputName) wins over the symmetric MapName.
        foreach ([$directional, self::MAP_NAME] as $attributeClass) {
            $attributes = $reflection->getAttributes($attributeClass);
            if ($attributes === []) {
                continue;
            }

            $instance = $attributes[0]->newInstance();
            $value = $directional === self::MAP_OUTPUT_NAME
                ? ($instance->output ?? null)
                : ($instance->input ?? null);

            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    private function property(string $fqcn, string $property): ?ReflectionProperty
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        $reflection = new ReflectionClass($fqcn);

        return $reflection->hasProperty($property) ? $reflection->getProperty($property) : null;
    }
}
