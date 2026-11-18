<?php

declare(strict_types=1);

namespace App\Support;

use UnexpectedValueException;

/**
 * A value object parsing a stored code through a `from()` of its own. Unlike an enum's, what it may throw is
 * whatever its body decides, so nothing says the stored value is the only way it fails.
 */
final readonly class ConsignmentCode
{
    private function __construct(public int $code) {}

    /**
     * @throws UnexpectedValueException
     */
    public static function from(int $code): self
    {
        return $code > 0 ? new self($code) : throw new UnexpectedValueException('A consignment code is positive.');
    }
}
