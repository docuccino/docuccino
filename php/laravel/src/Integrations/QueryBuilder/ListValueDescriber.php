<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Extensions\Schema\DocSummary;
use Docuccino\Core\Inference\ClassMetadata;
use Illuminate\Support\Str;
use ReflectionMethod;
use Throwable;

/**
 * The prose the subject model already carries for a sort/include value: an include name answers
 * from its relation method's docblock summary, a sort name from the column's `@property` prose the
 * engine recovered — the same text the serialized column shows, so the two can never disagree.
 * Dotted include paths stay undescribed here (the segment lives on a related model this resolver
 * deliberately never hops to); an entry's own comment outranks both, resolved by the caller.
 *
 * Every file this reads is already in the fragment-cache key: the model hierarchy's files travel on
 * the ClassMetadata dependency set the extension records.
 */
final class ListValueDescriber
{
    public function __construct(
        private readonly string $model,
        private readonly ClassMetadata $metadata,
    ) {}

    /** The relation method's docblock summary for a single-segment include name, else null. */
    public function include(string $name): ?string
    {
        if (str_contains($name, '.')) {
            return null;
        }

        foreach (array_unique([$name, Str::camel($name)]) as $method) {
            if (! method_exists($this->model, $method)) {
                continue;
            }

            try {
                $reflection = new ReflectionMethod($this->model, $method);
            } catch (Throwable) {
                continue;
            }

            if (! $reflection->isPublic() || $reflection->isStatic()) {
                continue;
            }

            return DocSummary::of($reflection->getDocComment());
        }

        return null;
    }

    /** The column's `@property` docblock prose, exactly as a response body would describe it. */
    public function sort(string $column): ?string
    {
        foreach ($this->metadata->properties as $property) {
            if ($property->name === $column) {
                return $property->summary;
            }
        }

        return null;
    }
}
