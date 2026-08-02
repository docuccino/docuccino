<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Maps a polymorphic morph — a union of two or more Eloquent models, as `MorphTo<Post|Video>`
 * surfaces once inference resolves the related type — to an OAS `oneOf` of the variant schemas plus
 * a `discriminator` (design §Phase 4). The discriminator `propertyName` is the morph-type field
 * (`type`) and its `mapping` comes from `Relation::morphMap()` (the alias each model serialises as);
 * a model with no morph-map alias falls back to its FQCN (Laravel's default morph type) and raises
 * an info diagnostic. Runs ahead of the core union mapper so a model union becomes a discriminated
 * `oneOf` rather than a bare `anyOf`; a nullable morph keeps a `null` branch.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class MorphToSchema implements TypeToSchema
{
    private const DISCRIMINATOR_PROPERTY = 'type';

    public function supports(DType $type): bool
    {
        return $type instanceof UnionT && count($this->modelMembers($type)) >= 2;
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof UnionT) {
            return null;
        }

        $models = $this->modelMembers($type);
        if (count($models) < 2) {
            return null;
        }

        $variants = [];
        $mapping = [];
        foreach ($models as $model) {
            $ref = $context->convert($model);
            $variants[] = $ref;

            $alias = $this->morphAlias($model->fqcn);
            if ($alias === null) {
                $context->diagnostic(new Diagnostic(
                    severity: Severity::Info,
                    code: 'eloquent.unmapped-morph',
                    message: sprintf('Morph variant %s has no Relation::morphMap() alias; using its FQCN as the discriminator value.', $model->fqcn),
                    help: 'Register an alias in Relation::enforceMorphMap([...]) so the discriminator value is stable across refactors.',
                ));
            }

            $key = $alias ?? $model->fqcn;
            if (is_string($ref['$ref'] ?? null)) {
                $mapping[$key] = $ref['$ref'];
            }
        }

        if ($this->isNullable($type)) {
            $variants[] = ['type' => 'null'];
        }

        return new SchemaResult([
            'oneOf' => $variants,
            'discriminator' => ['propertyName' => self::DISCRIMINATOR_PROPERTY, 'mapping' => $mapping],
        ], 0.9);
    }

    /**
     * The Eloquent-model members of a union (morph variants), preserving declaration order.
     *
     * @return list<ClassT>
     */
    private function modelMembers(UnionT $type): array
    {
        $models = [];
        foreach ($type->members as $member) {
            if ($member instanceof ClassT && EloquentModelReflector::isModel($member->fqcn)) {
                $models[] = $member;
            }
        }

        return $models;
    }

    private function isNullable(UnionT $type): bool
    {
        foreach ($type->members as $member) {
            if ($member instanceof NullT) {
                return true;
            }
        }

        return false;
    }

    /** The morph-map alias a model serialises its type as, or null when it is unmapped. */
    private function morphAlias(string $fqcn): ?string
    {
        $alias = array_search($fqcn, Relation::morphMap(), true);

        return is_string($alias) ? $alias : null;
    }
}
