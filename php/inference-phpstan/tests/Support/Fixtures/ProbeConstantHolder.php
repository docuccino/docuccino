<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

/** A class whose constants are declared across three files, so `self::` and `parent::` name two of them. */
final class ProbeConstantHolder extends ProbeConstantBase
{
    public const OWN = 409;
}
