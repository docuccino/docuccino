<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Marks a manifest error that can say which parts of the manifest it faulted on. */
interface SignalsManifestFault
{
    /** @return array<string, string> */
    public function faults(): array;
}
