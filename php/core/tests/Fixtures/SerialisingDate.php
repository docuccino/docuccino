<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use DateTimeImmutable;
use JsonSerializable;

/**
 * A date-time stating its own JSON form, in the shape every framework's date class states one: a
 * subclass of PHP's own, declaring `jsonSerialize()`. Its bytes are what the date-time mapper's claim
 * is checked against, so the format string is spelled here rather than borrowed from the mapper.
 */
final class SerialisingDate extends DateTimeImmutable implements JsonSerializable
{
    public const FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public function jsonSerialize(): string
    {
        return $this->format(self::FORMAT);
    }
}
