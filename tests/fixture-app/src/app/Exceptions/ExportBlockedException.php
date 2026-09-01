<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The same defaulted status behind a PUBLIC constructor, which is the common way the idiom is written and
 * the point at which the default stops being a fact for the CLASS: any caller may pass another one. One
 * construction is a different question — a `new` that leaves the slot empty passes the 409 — and the two
 * spellings of that construction are here so a document cannot answer them differently.
 */
final class ExportBlockedException extends HttpException
{
    public function __construct(string $message = 'The export is blocked.', int $statusCode = 409)
    {
        parent::__construct($statusCode, $message);
    }

    public static function blocked(): self
    {
        return new self;
    }
}
