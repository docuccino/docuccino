<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A domain refusal that IS a 422 because its framework parent is: its own constructor only chooses the
 * message, and the status is the literal Symfony's constructor passes on.
 */
final class ManifestDeferredException extends UnprocessableEntityHttpException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The manifest is deferred: '.$reason);
    }
}
