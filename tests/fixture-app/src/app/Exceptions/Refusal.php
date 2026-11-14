<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Contracts\RefusalReason;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * A request refused for a reason, answered with the status that reason maps to.
 */
abstract class Refusal extends HttpException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly RefusalReason $reason,
        string $detail = '',
        array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason->status(), $detail, $previous, $headers);
    }
}
