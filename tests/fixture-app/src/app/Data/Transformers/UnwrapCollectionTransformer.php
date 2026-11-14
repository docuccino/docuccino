<?php

declare(strict_types=1);

namespace App\Data\Transformers;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

/** Serialises a nested collection of Data objects as the bare list, never `{"data": […]}`. */
final class UnwrapCollectionTransformer implements Transformer
{
    /**
     * @return list<mixed>
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): array
    {
        $items = [];
        foreach (is_iterable($value) ? $value : [] as $item) {
            $items[] = $item instanceof Data ? $item->toArray() : $item;
        }

        return $items;
    }
}
