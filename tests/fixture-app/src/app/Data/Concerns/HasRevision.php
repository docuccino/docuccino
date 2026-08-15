<?php

declare(strict_types=1);

namespace App\Data\Concerns;

/**
 * A shared audit field, factored into a trait the way an app with a dozen Data classes does. PHP
 * flattens a trait into the class that uses it, so reflection reports `$revision` as declared by that
 * class — this file's name is reachable only by walking the trait list, which is exactly what a
 * fragment cache has to do to notice this line changing.
 */
trait HasRevision
{
    /** The revision the record was read at. */
    public int $revision = 0;
}
