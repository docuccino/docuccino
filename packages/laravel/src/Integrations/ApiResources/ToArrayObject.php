<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;
use ReflectionMethod;
use Throwable;

/**
 * Analyses a resource method (`toArray`, or JSON:API's `toAttributes`/`toRelationships`/…) into an
 * OAS object schema. The method's literal return array surfaces from the engine as an
 * {@see ArrayShapeT} (the value types are Larastan-informed — `$this->column` resolves through the
 * resource's model `@mixin`), and each field is converted through the chain.
 *
 * Conditional fields (`whenLoaded`/`when`/`whenNotNull`/`mergeWhen`) return an
 * `Illuminate\Http\Resources\MissingValue` at runtime, so the engine types the value as
 * `T|MissingValue`: the marker makes the property optional and is stripped, folding the wrapped `T`
 * when recoverable (else the property degrades to permissive `{}` + optional).
 */
final class ToArrayObject
{
    /**
     * Build the object schema for `$fqcn::$method`, or null when the method has no analysable array
     * shape (so the caller can degrade to a bare `{type: object}`).
     *
     * @return array<string, mixed>|null
     */
    public function analyze(string $fqcn, string $method, SchemaContext $context): ?array
    {
        try {
            $reflection = new ReflectionMethod($fqcn, $method);
        } catch (Throwable) {
            return null;
        }

        if ($reflection->isAbstract()) {
            return null;
        }

        $line = $reflection->getStartLine();
        $analysis = $context->engine()->analyzeAction(new ActionRef(
            (string) $reflection->getFileName(),
            $fqcn,
            $method,
            $line > 0 ? $line : 0,
        ));

        // The resource method's analysed files are fragment-cache dependencies: editing the resource's
        // toArray (or any file its return shape traced) must invalidate the warm fragment (design §10).
        $context->dependsOn(...$analysis->dependencyFiles);

        $shape = null;
        foreach ($analysis->returns as $return) {
            if ($return->type instanceof ArrayShapeT && ! $return->type->isList) {
                $shape = $return->type;
                break;
            }
        }

        if ($shape === null) {
            return null;
        }

        $properties = [];
        $required = [];
        foreach ($shape->fields as $field) {
            $key = (string) $field->key;
            [$type, $conditional] = self::stripMissing($field->type);

            $properties[$key] = $context->convert($type);

            if (! $field->optional && ! $conditional && ! self::isNullable($type)) {
                $required[] = $key;
            }
        }

        $object = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $object['required'] = $required;
        }

        return $object;
    }

    /**
     * Strip the `MissingValue` marker from a conditional field's type. Returns the recoverable type
     * (the wrapped value when a single member survives, else the original) and whether the marker
     * was present (→ optional).
     *
     * @return array{0: DType, 1: bool}
     */
    private static function stripMissing(DType $type): array
    {
        if (! $type instanceof UnionT) {
            return [$type, false];
        }

        $survivors = array_values(array_filter(
            $type->members,
            static fn (DType $member): bool => ! ($member instanceof ClassT && is_a($member->fqcn, ResourceReflector::MISSING_VALUE, true)),
        ));

        $conditional = count($survivors) !== count($type->members);
        if (! $conditional) {
            return [$type, false];
        }

        return [count($survivors) === 1 ? $survivors[0] : UnionT::of($survivors), true];
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
