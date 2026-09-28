<?php

declare(strict_types=1);

namespace App\Problems;

/** A base object whose constructor assigns each attribute to the property of the same name. */
abstract class DataObject
{
    /** @param  array<string, mixed>  $attributes */
    public function __construct(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }
}
